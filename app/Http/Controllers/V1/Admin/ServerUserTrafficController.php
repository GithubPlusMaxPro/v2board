<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\StatServerUser;
use App\Services\AdminPermissionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServerUserTrafficController extends Controller
{
    public function fetch(Request $request)
    {
        $params = $request->validate([
            'group_by' => 'sometimes|in:detail,server,user',
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
        $groupBy = $params['group_by'] ?? 'detail';
        $total = (int)$summary->count;
        if ($groupBy === 'detail') {
            $rows = $query->select('v2_stat_server_user.*', 'v2_user.email')
                ->orderBy('v2_stat_server_user.record_at', 'DESC')->orderBy('v2_stat_server_user.id', 'DESC')
                ->forPage($current, $pageSize)->get();
            foreach ($rows as $row) {
                $row->setAttribute('record_date', CarbonImmutable::createFromTimestamp($row->record_at, $timezone)->toDateString());
            }
        } else {
            $groups = ['v2_stat_server_user.server_type', 'v2_stat_server_user.server_id'];
            if ($groupBy === 'user') $groups[] = 'v2_stat_server_user.user_id';
            $grouped = (clone $query)->toBase()->select($groups)->groupBy($groups)
                ->selectRaw('MAX(v2_stat_server_user.id) AS latest_id, COUNT(*) AS record_count, '
                    . 'COUNT(DISTINCT v2_stat_server_user.user_id) AS user_count, '
                    . 'SUM(v2_stat_server_user.u) AS u, SUM(v2_stat_server_user.d) AS d, '
                    . 'SUM(v2_stat_server_user.billed_u) AS billed_u, SUM(v2_stat_server_user.billed_d) AS billed_d');
            if ($groupBy === 'user') $grouped->selectRaw('MAX(v2_user.email) AS email');
            $total = DB::query()->fromSub(clone $grouped, 'traffic_groups')->count();
            $rows = $grouped->orderByRaw('(SUM(v2_stat_server_user.u) + SUM(v2_stat_server_user.d)) DESC')
                ->orderBy('v2_stat_server_user.server_type')->orderBy('v2_stat_server_user.server_id');
            if ($groupBy === 'user') $rows->orderBy('v2_stat_server_user.user_id');
            $rows = $rows->forPage($current, $pageSize)->get();
            $names = StatServerUser::whereIn('id', $rows->pluck('latest_id'))->pluck('server_name', 'id');
            foreach ($rows as $row) {
                $row->server_name = $names[$row->latest_id] ?? null;
                unset($row->latest_id);
                foreach (['server_id', 'user_id', 'record_count', 'user_count', 'u', 'd', 'billed_u', 'billed_d'] as $column) {
                    if (isset($row->{$column})) $row->{$column} = (int)$row->{$column};
                }
            }
        }
        return response(['data' => $rows, 'total' => $total, 'summary' => $totals, 'group_by' => $groupBy,
            'current' => $current, 'page_size' => $pageSize, 'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(), 'timezone' => $timezone]);
    }
}
