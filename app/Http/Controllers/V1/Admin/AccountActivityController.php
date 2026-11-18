<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountActivityLog;
use Illuminate\Http\Request;

class AccountActivityController extends Controller
{
    public function fetch(Request $request)
    {
        $params = $request->validate([
            'current' => 'sometimes|integer|min:1|max:1000000',
            'page_size' => 'sometimes|integer|min:1|max:100',
            'user_id' => 'sometimes|integer|min:1',
            'email' => 'sometimes|email|max:254',
            'scope' => 'sometimes|in:login,admin,user',
            'ip' => 'sometimes|ip',
            'session_hash' => 'sometimes|regex:/^[a-f0-9]{64}$/D',
            'start_at' => 'sometimes|integer|min:0',
            'end_at' => 'sometimes|integer|min:0',
        ]);
        if (isset($params['start_at'], $params['end_at']) && $params['end_at'] < $params['start_at']) {
            abort(422, '结束时间不能早于开始时间');
        }

        $query = AccountActivityLog::query();
        foreach (['user_id', 'email', 'scope', 'ip', 'session_hash'] as $field) {
            if (isset($params[$field])) $query->where($field, $params[$field]);
        }
        if (isset($params['start_at'])) $query->where('created_at', '>=', $params['start_at']);
        if (isset($params['end_at'])) $query->where('created_at', '<=', $params['end_at']);

        $current = (int)($params['current'] ?? 1);
        $pageSize = (int)($params['page_size'] ?? 20);
        return response([
            'total' => $query->count(),
            'data' => $query->orderByDesc('id')->forPage($current, $pageSize)->get(),
            'current' => $current,
            'page_size' => $pageSize,
        ])->header('Cache-Control', 'private, no-store');
    }
}
