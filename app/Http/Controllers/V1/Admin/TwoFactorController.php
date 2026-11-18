<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminPermissionService;
use App\Services\AdminTwoFactor;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TwoFactorController extends Controller
{
    public function status(Request $request, AdminTwoFactor $twoFactor)
    {
        $data = $request->validate(['id' => 'sometimes|required|integer|min:1']);
        $actorId = $request->user['id'];
        $target = $this->target($request->user, $data['id'] ?? null);
        return $this->result($twoFactor->status($target) + ['operator_enabled' => $twoFactor->enabled($actorId)]);
    }

    public function begin(Request $request, AdminTwoFactor $twoFactor)
    {
        $data = $request->validate($this->operatorRules() + ['id' => 'sometimes|required|integer|min:1']);
        return $this->change($request, $data['id'] ?? null, function ($actor, $target, $session) use ($twoFactor, $data) {
            return $twoFactor->begin($actor, $target, $data['password'], $data['otp'] ?? null, $session);
        });
    }

    public function enable(Request $request, AdminTwoFactor $twoFactor)
    {
        $data = $request->validate([
            'id' => 'sometimes|required|integer|min:1',
            'setup' => 'required|string|max:2048',
            'otp' => 'required|string|regex:/^\d{6}$/D',
            'required' => 'sometimes|boolean',
        ]);
        return $this->change($request, $data['id'] ?? null, function ($actor, $target, $session) use ($twoFactor, $data) {
            return ['recovery_codes' => $twoFactor->enable(
                $actor, $target, $data['setup'], $data['otp'], $session, (bool)($data['required'] ?? true)
            )];
        });
    }

    public function disable(Request $request, AdminTwoFactor $twoFactor)
    {
        $data = $request->validate(['id' => 'prohibited', 'password' => 'required|string|max:4096', 'otp' => 'required|string|max:20']);
        return $this->change($request, null, function ($user) use ($twoFactor, $data) {
            $twoFactor->disable($user, $data['password'], $data['otp']);
            return ['enabled' => false];
        });
    }

    public function policy(Request $request, AdminTwoFactor $twoFactor)
    {
        $data = $request->validate($this->operatorRules() + ['id' => 'required|integer|min:1', 'required' => 'required|boolean']);
        return $this->change($request, $data['id'], function ($actor, $target) use ($twoFactor, $data) {
            $twoFactor->confirmOperator($actor, $data['password'], $data['otp'] ?? null);
            return $twoFactor->changePolicy($target, (bool)$data['required']);
        });
    }

    public function reset(Request $request, AdminTwoFactor $twoFactor)
    {
        $data = $request->validate($this->operatorRules() + ['id' => 'required|integer|min:1', 'required' => 'sometimes|boolean']);
        return $this->change($request, $data['id'], function ($actor, $target) use ($twoFactor, $data) {
            $twoFactor->confirmOperator($actor, $data['password'], $data['otp'] ?? null);
            return $twoFactor->reset($target, (bool)($data['required'] ?? $twoFactor->status($target->id)['required']));
        });
    }

    private function operatorRules(): array
    {
        return ['password' => 'required|string|max:4096', 'otp' => 'nullable|string|max:20'];
    }

    private function target(array $actor, ?int $targetId): int
    {
        if ($targetId === null) return $actor['id'];
        abort_unless($targetId !== (int)$actor['id'] && AdminPermissionService::isSuper($actor), 403, '只有超级管理员可以管理其他管理员的两步验证。');
        abort_unless(User::where('is_admin', 1)->whereKey($targetId)->exists(), 404, '管理员不存在。');
        return $targetId;
    }

    private function change(Request $request, ?int $targetId, callable $action)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        $data = DB::transaction(function () use ($request, $authorization, $targetId, $action) {
            $actorId = $request->user['id'];
            $selectedId = $this->target($request->user, $targetId);
            $users = User::whereIn('id', [$actorId, $selectedId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $users->get($actorId);
            $target = $users->get($selectedId);
            abort_unless($actor && $actor->is_admin && AuthService::decryptAuthData($authorization, true), 403, '请重新登录。');
            abort_unless($target && $target->is_admin, 404, '管理员不存在。');
            abort_if($targetId !== null && !AdminPermissionService::isSuper($actor->toArray()), 403, '只有超级管理员可以管理其他管理员的两步验证。');
            return $action($actor, $target, hash('sha256', $authorization));
        });
        return $this->result($data);
    }

    private function result(array $data)
    {
        return response(['data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
