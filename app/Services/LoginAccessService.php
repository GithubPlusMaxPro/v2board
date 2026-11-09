<?php

namespace App\Services;

class LoginAccessService
{
    public static function userLoginEnabled(): bool
    {
        return (int)config('v2board.user_login_enable', 0) === 1;
    }

    public static function assertLoginAllowed(bool $isAdmin): void
    {
        if (!$isAdmin && !self::userLoginEnabled()) {
            abort(response()->json([
                'message' => '普通用户登录暂时暂停，请稍后再试',
                'login_disabled' => true,
            ], 403));
        }
    }
}
