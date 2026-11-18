<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Log;
use App\Services\AccountActivityDetails;
use Illuminate\Http\Request;

class AccountActivityController extends Controller
{
    public function detail(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|min:1']);
        $log = Log::whereNotNull('scope')->findOrFail($params['id'], ['scope', 'action', 'data', 'context']);
        $data = json_decode($log->data ?? '', true);
        $context = json_decode($log->context ?? '', true);
        $details = AccountActivityDetails::fields($data);
        if ($log->scope === 'legacy') {
            $actor = AccountActivityDetails::fields(is_array($data) ? ($data['user'] ?? null) : null);
            if ($actor) $details['user'] = $actor;
        }
        if (is_array($data) && in_array($log->scope, ['admin', 'legacy'], true) && $log->action === 'server/route/save') {
            $details += AccountActivityDetails::serverRoute($data);
        }
        if ($log->scope === 'admin' && $log->action === 'config/save' && isset($data['changes']) && is_array($data['changes'])) {
            $details['changes'] = (object)AccountActivityDetails::configDetails($data['changes']);
        }

        return response([
            'data' => (object)$details,
            'context' => (object)AccountActivityDetails::fields($context),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function fetch(Request $request)
    {
        $params = $request->validate([
            'current' => 'sometimes|integer|min:1|max:1000000',
            'page_size' => 'sometimes|integer|min:1|max:100',
            'user_id' => 'sometimes|integer|min:1',
            'email' => 'sometimes|email|max:254',
            'scope' => 'sometimes|in:login,admin,user,legacy',
            'ip' => 'sometimes|ip',
            'session_hash' => 'sometimes|regex:/^[a-f0-9]{64}$/D',
            'start_at' => 'sometimes|integer|min:0',
            'end_at' => 'sometimes|integer|min:0',
        ]);
        if (isset($params['start_at'], $params['end_at']) && $params['end_at'] < $params['start_at']) {
            abort(422, '结束时间不能早于开始时间');
        }

        $query = Log::whereNotNull('scope')->select([
            'id', 'user_id', 'email', 'session_hash', 'scope', 'action', 'method',
            'target_id', 'ip', 'user_agent', 'status_code', 'created_at',
        ])->selectRaw("CASE WHEN scope = 'legacy' OR data IS NOT NULL THEN 1 ELSE 0 END AS has_details");
        foreach (['user_id', 'email', 'scope', 'ip', 'session_hash'] as $field) {
            if (isset($params[$field])) $query->where($field, $params[$field]);
        }
        if (isset($params['start_at'])) $query->where('created_at', '>=', $params['start_at']);
        if (isset($params['end_at'])) $query->where('created_at', '<=', $params['end_at']);

        $current = (int)($params['current'] ?? 1);
        $pageSize = (int)($params['page_size'] ?? 20);
        return response([
            'total' => $query->count(),
            'data' => $query->orderByDesc('created_at')->orderByDesc('id')->forPage($current, $pageSize)->get(),
            'current' => $current,
            'page_size' => $pageSize,
        ])->header('Cache-Control', 'private, no-store');
    }
}
