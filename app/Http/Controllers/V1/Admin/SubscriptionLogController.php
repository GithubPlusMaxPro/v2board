<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionLog;
use App\Services\AdminPermissionService;
use Illuminate\Http\Request;

class SubscriptionLogController extends Controller
{
    public function fetch(Request $request)
    {
        $params = $request->validate([
            'user_id' => 'sometimes|nullable|integer|min:1',
            'email' => 'sometimes|nullable|string|max:254',
            'current' => 'sometimes|integer|min:1|max:1000000',
            'page_size' => 'sometimes|integer|min:1|max:100',
            'action' => 'sometimes|nullable|in:address,download',
            'ip' => 'sometimes|nullable|ip',
            'start_at' => 'sometimes|nullable|integer|min:0',
            'end_at' => 'sometimes|nullable|integer|min:0',
        ]);
        if (isset($params['start_at'], $params['end_at']) && $params['end_at'] < $params['start_at']) {
            abort(422, '结束时间不能早于开始时间');
        }
        $current = (int)($params['current'] ?? 1);
        $pageSize = (int)($params['page_size'] ?? 20);
        $query = SubscriptionLog::query()
            ->leftJoin('v2_user', 'v2_user.id', '=', 'v2_subscription_log.user_id');
        if (!AdminPermissionService::isSuper($request->user)) {
            $query->where('v2_user.is_admin', 0)->where('v2_user.is_staff', 0);
        }
        foreach (['user_id', 'action', 'ip'] as $filter) {
            if (!empty($params[$filter])) $query->where('v2_subscription_log.' . $filter, $params[$filter]);
        }
        if (!empty($params['email'])) $query->where('v2_user.email', $params['email']);
        if (isset($params['start_at'])) $query->where('v2_subscription_log.created_at', '>=', $params['start_at']);
        if (isset($params['end_at'])) $query->where('v2_subscription_log.created_at', '<=', $params['end_at']);
        $total = $query->count();
        $logs = $query->select('v2_subscription_log.*', 'v2_user.email')
            ->orderBy('v2_subscription_log.id', 'DESC')->forPage($current, $pageSize)->get();
        return response([
            'data' => $logs,
            'total' => $total,
            'current' => $current,
            'page_size' => $pageSize,
        ]);
    }
}
