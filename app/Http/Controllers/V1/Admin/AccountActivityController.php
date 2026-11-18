<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Log;
use Illuminate\Http\Request;

class AccountActivityController extends Controller
{
    public function detail(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|min:1']);
        $log = Log::where('scope', 'legacy')->findOrFail($params['id'], ['data', 'context']);
        $data = json_decode($log->data ?? '', true);
        $context = json_decode($log->context ?? '', true);
        $details = $this->safeDetails($data);
        $actor = $this->safeDetails(is_array($data) ? ($data['user'] ?? null) : null);
        if ($actor) $details['user'] = $actor;

        return response([
            'data' => (object)$details,
            'context' => (object)$this->safeDetails($context),
        ])->header('Cache-Control', 'private, no-store');
    }

    private function safeDetails($fields): array
    {
        if (!is_array($fields)) return [];

        $details = [];
        foreach (['id', 'user_id', 'actor_id', 'target_id', 'plan_id', 'group_id', 'server_id',
            'route_id', 'order_id', 'payment_id', 'ticket_id', 'invite_user_id', 'status', 'sort', 'quantity'] as $field) {
            $value = $fields[$field] ?? null;
            if (is_int($value) || is_string($value)) {
                $integer = filter_var($value, FILTER_VALIDATE_INT);
                if ($integer !== false) $details[$field] = $integer;
            }
        }
        foreach (['is_admin', 'is_super_admin', 'is_staff', 'banned', 'enabled', 'required', 'show', 'paid', 'auto_renewal'] as $field) {
            $value = $fields[$field] ?? null;
            if (in_array($value, [true, false, 0, 1, '0', '1'], true)) $details[$field] = (bool)$value;
        }
        $email = $fields['email'] ?? null;
        if (is_string($email) && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $details['email'] = $email;
        }

        $permissions = config('admin_permissions.labels', []);
        foreach (['permissions', 'admin_permissions', 'before', 'after'] as $field) {
            if (!isset($fields[$field]) || !is_array($fields[$field])) continue;
            $details[$field] = array_values(array_filter($fields[$field], function ($value) use ($permissions) {
                return is_string($value) && isset($permissions[$value]);
            }));
        }
        return $details;
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
        ]);
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
