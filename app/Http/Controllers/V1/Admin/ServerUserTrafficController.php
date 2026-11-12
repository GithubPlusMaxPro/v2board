<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\StatServerUser;
use App\Services\AdminPermissionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class ServerUserTrafficController extends Controller
{
    public function fetch(Request $request)
    {
        $params = $request->validate([
            'user_id' => 'sometimes|nullable|integer|min:1',
            'email' => 'sometimes|nullable|string|max:254',
            'server_type' => 'sometimes|nullable|in:shadowsocks,vmess,trojan,tuic,hysteria,vless,anytls,v2node',
            'server_id' => 'sometimes|nullable|integer|min:1',
            'start_date' => 'sometimes|nullable|date_format:Y-m-d',
            'end_date' => 'sometimes|nullable|date_format:Y-m-d',
            'current' => 'sometimes|integer|min:1|max:1000000',
            'page_size' => 'sometimes|integer|min:1|max:100',
        ]);
        $timezone = config('app.timezone');
        $start = CarbonImmutable::parse($params['start_date'] ?? $params['end_date'] ?? 'today', $timezone)->startOfDay();
        $end = CarbonImmutable::parse($params['end_date'] ?? $start->toDateString(), $timezone)->startOfDay();
        if ($end < $start || $start->diffInDays($end) > 365) abort(422, '请选择不超过一年的有效日期范围');
        $current = (int)($params['current'] ?? 1);
        $pageSize = (int)($params['page_size'] ?? 20);
        $query = StatServerUser::query()->leftJoin('v2_user', 'v2_user.id', '=', 'v2_stat_server_user.user_id')
            ->where('v2_stat_server_user.record_at', '>=', $start->getTimestamp())
            ->where('v2_stat_server_user.record_at', '<', $end->addDay()->getTimestamp());
        if (!AdminPermissionService::isSuper($request->user)) {
            $query->where('v2_user.is_admin', 0)->where('v2_user.is_staff', 0);
        }
        foreach (['user_id', 'server_type', 'server_id'] as $filter) {
            if (!empty($params[$filter])) $query->where('v2_stat_server_user.' . $filter, $params[$filter]);
        }
        if (!empty($params['email'])) $query->where('v2_user.email', $params['email']);
        $summary = (clone $query)->selectRaw(
            'COUNT(*) AS count, '
            . 'COALESCE(SUM(v2_stat_server_user.u), 0) AS u, '
            . 'COALESCE(SUM(v2_stat_server_user.d), 0) AS d, '
            . 'COALESCE(SUM(v2_stat_server_user.billed_u), 0) AS billed_u, '
            . 'COALESCE(SUM(v2_stat_server_user.billed_d), 0) AS billed_d'
        )->first();
        $totals = [];
        foreach (['u', 'd', 'billed_u', 'billed_d'] as $column) $totals[$column] = (int)$summary->{$column};
        $totals['total'] = $totals['u'] + $totals['d'];
        $totals['billed_total'] = $totals['billed_u'] + $totals['billed_d'];
        $rows = $query->select('v2_stat_server_user.*', 'v2_user.email')
            ->orderBy('v2_stat_server_user.record_at', 'DESC')->orderBy('v2_stat_server_user.id', 'DESC')
            ->forPage($current, $pageSize)->get();
        foreach ($rows as $row) {
            $row->setAttribute('record_date', CarbonImmutable::createFromTimestamp($row->record_at, $timezone)->toDateString());
        }
        return response(['data' => $rows, 'total' => (int)$summary->count, 'summary' => $totals,
            'current' => $current, 'page_size' => $pageSize, 'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(), 'timezone' => $timezone]);
    }
}
