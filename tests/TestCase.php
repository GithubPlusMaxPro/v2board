<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function createTwoFactorTable(): void
    {
        require_once database_path('migrations/2026_11_18_000001_create_admin_two_factor_table.php');
        (new \CreateAdminTwoFactorTable())->up();
    }

    protected function authToken(\App\Models\User $user): string
    {
        if ($user->is_admin) {
            $this->createTwoFactorTable();
            (new \App\Services\AdminTwoFactor())->changePolicy($user, false);
        }
        return (new \App\Services\AuthService($user))->generateAuthData(
            \Illuminate\Http\Request::create('/'), null, $user->is_admin ? 'admin' : 'user'
        )['auth_data'];
    }
}
