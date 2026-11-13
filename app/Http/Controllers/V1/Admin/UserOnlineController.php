<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserOnlineLog;
use App\Models\StatServerUser;
use App\Services\AdminPermissionService;
use App\Services\IpLookupService;
use App\Services\UserOnlineService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class UserOnlineController extends Controller
{
    public function fetch(Request $request)
    {
        $params = $request->validate([
            'user_id' => 'sometimes|nullable|integer|min:1',
            'email' => 'sometimes|nullable|string|max:254',
            'sort' => 'sometimes|in:id,traffic',
            'current' => 'sometimes|integer|min:1|max:1000000',
            'page_size' => 'sometimes|integer|min:1|max:100',
        ]);
        $day = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $daily = StatServerUser::where('record_at', $day->getTimestamp())->select('user_id')
            ->selectRaw('SUM(u) AS day_u, SUM(d) AS day_d, SUM(billed_u) AS day_billed_u, SUM(billed_d) AS day_billed_d')->groupBy('user_id');
        $query = User::query()->leftJoinSub($daily, 'daily', function ($join) { $join->on('daily.user_id', '=', 'v2_user.id'); });
        if (!AdminPermissionService::isSuper($request->user)) $query->where('v2_user.is_admin', 0)->where('v2_user.is_staff', 0);
        if (!empty($params['user_id'])) $query->where('v2_user.id', $params['user_id']);
        if (!empty($params['email'])) $query->whereRaw("v2_user.email LIKE ? ESCAPE '!'", ['%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $params['email']) . '%']);
        $total = $query->count();
        if (($params['sort'] ?? 'id') === 'traffic') $query->orderByRaw('(COALESCE(daily.day_u, 0) + COALESCE(daily.day_d, 0)) DESC');
        $current = (int)($params['current'] ?? 1); $pageSize = (int)($params['page_size'] ?? 20);
        $users = $query->select('v2_user.id', 'v2_user.email', 'v2_user.banned')
            ->selectRaw('COALESCE(daily.day_u, 0) AS day_u, COALESCE(daily.day_d, 0) AS day_d, COALESCE(daily.day_billed_u, 0) AS day_billed_u, COALESCE(daily.day_billed_d, 0) AS day_billed_d')
            ->orderBy('v2_user.id', 'DESC')->forPage($current, $pageSize)->get();
        $keys = $users->map(function ($user) { return 'ALIVE_IP_USER_' . $user->id; })->all();
        $cached = $keys ? Cache::many($keys) : [];
        $now = time(); $service = app(UserOnlineService::class); $data = [];
        $summary = ['page_users' => $users->count(), 'page_online' => 0, 'page_total' => 0, 'page_billed_total' => 0];
        foreach ($users as $user) {
            $cache = $cached['ALIVE_IP_USER_' . $user->id] ?? [];
            $online = $service->snapshot(is_array($cache) ? $cache : [], $now);
            $actual = (int)$user->day_u + (int)$user->day_d;
            $billed = (int)$user->day_billed_u + (int)$user->day_billed_d;
            if ($online['unique_ip_count']) $summary['page_online']++;
            $summary['page_total'] += $actual; $summary['page_billed_total'] += $billed;
            $data[] = ['id' => $user->id, 'email' => $user->email, 'banned' => (bool)$user->banned,
                'online_ip_count' => $online['unique_ip_count'], 'online_node_count' => $online['node_count'],
                'ips' => array_slice(array_values(array_unique(array_column($online['items'], 'ip'))), 0, 3),
                'today_total' => $actual, 'today_billed_total' => $billed];
        }
        return response(['data' => $data, 'total' => $total, 'summary' => $summary,
            'current' => $current, 'page_size' => $pageSize, 'date' => $day->toDateString(), 'as_of' => $now]);
    }

    private function findUser(Request $request): User
    {
        $params = $request->validate([
            'user_id' => 'required_without:email|nullable|integer|min:1',
            'email' => 'required_without:user_id|nullable|email|max:254',
        ]);
        $query = User::query();
        if (!AdminPermissionService::isSuper($request->user)) $query->where('is_admin', 0)->where('is_staff', 0);
        if (!empty($params['user_id'])) $query->where('id', $params['user_id']);
        if (!empty($params['email'])) $query->where('email', $params['email']);
        $user = $query->first();
        if (!$user) abort(404, '用户不存在或无权查看');
        return $user;
    }

    public function summary(Request $request)
    {
        $user = $this->findUser($request);
        $day = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $totals = StatServerUser::where('user_id', $user->id)->where('record_at', $day->getTimestamp())
            ->selectRaw('COALESCE(SUM(u), 0) AS u, COALESCE(SUM(d), 0) AS d, COALESCE(SUM(billed_u), 0) AS billed_u, COALESCE(SUM(billed_d), 0) AS billed_d')->first();
        $traffic = [];
        foreach (['u', 'd', 'billed_u', 'billed_d'] as $column) $traffic[$column] = (int)$totals->{$column};
        $traffic['total'] = $traffic['u'] + $traffic['d'];
        $traffic['billed_total'] = $traffic['billed_u'] + $traffic['billed_d'];
        return response(['data' => ['user' => ['id' => $user->id, 'email' => $user->email],
            'current' => app(UserOnlineService::class)->current($user->id), 'today' => $traffic,
            'date' => $day->toDateString(), 'timezone' => config('app.timezone')]]);
    }

    public function history(Request $request)
    {
        $user = $this->findUser($request);
        $params = $request->validate([
            'start_date' => 'sometimes|nullable|date_format:Y-m-d', 'end_date' => 'sometimes|nullable|date_format:Y-m-d',
            'ip' => 'sometimes|nullable|ip', 'server_id' => 'sometimes|nullable|integer|min:1',
            'server_type' => 'sometimes|nullable|in:shadowsocks,vmess,vless,trojan,hysteria,tuic,anytls,v2node',
            'current' => 'sometimes|integer|min:1|max:1000000', 'page_size' => 'sometimes|integer|min:1|max:100',
        ]);
        $timezone = config('app.timezone');
        $start = CarbonImmutable::parse($params['start_date'] ?? $params['end_date'] ?? 'today', $timezone)->startOfDay();
        $end = CarbonImmutable::parse($params['end_date'] ?? $start->toDateString(), $timezone)->startOfDay();
        if ($end < $start || $start->diffInDays($end) > 365) abort(422, '请选择不超过一年的有效日期范围');
        $query = UserOnlineLog::where('user_id', $user->id)->where('record_at', '>=', $start->getTimestamp())
            ->where('record_at', '<', $end->addDay()->getTimestamp());
        foreach (['server_id', 'server_type', 'ip'] as $filter) {
            if (!empty($params[$filter])) $query->where($filter, $filter === 'ip' ? UserOnlineService::normalizeIp($params[$filter]) : $params[$filter]);
        }
        $total = $query->count(); $current = (int)($params['current'] ?? 1); $pageSize = (int)($params['page_size'] ?? 20);
        $rows = $query->orderBy('last_seen', 'DESC')->orderBy('id', 'DESC')->forPage($current, $pageSize)->get();
        foreach ($rows as $row) $row->setAttribute('record_date', CarbonImmutable::createFromTimestamp($row->record_at, $timezone)->toDateString());
        return response(['data' => $rows, 'total' => $total, 'current' => $current, 'page_size' => $pageSize,
            'start_date' => $start->toDateString(), 'end_date' => $end->toDateString()]);
    }

    public function traffic(Request $request)
    {
        $user = $this->findUser($request);
        $request->merge(['user_id' => $user->id, 'email' => $user->email, 'group_by' => 'detail']);
        return app(ServerUserTrafficController::class)->fetch($request);
    }

    public function ipInfo(Request $request)
    {
        $user = $this->findUser($request);
        $params = $request->validate(['ip' => 'required|ip']);
        $ip = UserOnlineService::normalizeIp($params['ip']);
        $current = app(UserOnlineService::class)->current($user->id);
        $allowed = in_array($ip, array_column($current['items'], 'ip'), true)
            || UserOnlineLog::where('user_id', $user->id)->where('ip', $ip)->exists();
        if (!$allowed) abort(404, '该用户没有此 IP 记录');
        return response(['data' => app(IpLookupService::class)->lookup($ip)]);
    }
}
