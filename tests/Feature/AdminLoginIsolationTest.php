<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AdminTwoFactor;
use App\Services\AuthService;
use App\Utils\CacheKey;
use Firebase\JWT\JWT;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminLoginIsolationTest extends TestCase
{
    private $adminBase;
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    private const RECOVERY = '0123456789abcdef0123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'app.key' => str_repeat('k', 32), 'v2board.password_limit_enable' => 0]);
        Cache::flush();
        foreach (app('router')->getRoutes() as $route) {
            if (strpos($route->getActionName(), 'PermissionController@current') !== false) {
                $this->adminBase = '/' . substr($route->uri(), 0, -strlen('permission/current'));
            }
        }
        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            foreach (['email', 'password', 'token', 'uuid'] as $column) $table->string($column);
            foreach (['password_algo', 'password_salt', 'remarks'] as $column) $table->string($column)->nullable();
            foreach (['is_admin', 'is_staff', 'banned', 'u', 'd', 'balance', 'commission_balance', 'commission_type', 'transfer_enable', 'auto_renewal', 'remind_expire', 'remind_traffic', 'last_login_at', 'telegram_id'] as $column) $table->integer($column)->default(0);
            foreach (['invite_user_id', 'plan_id', 'group_id', 'expired_at', 'device_limit', 'speed_limit', 'discount', 'commission_rate'] as $column) $table->integer($column)->nullable();
            $table->text('admin_permissions')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        $this->createTwoFactorTable();
    }

    private function account(bool $admin = true): User
    {
        return User::create(['email' => uniqid('user') . '@example.com', 'is_admin' => (int)$admin,
            'password' => password_hash('test-password', PASSWORD_BCRYPT, ['cost' => 4]),
            'token' => uniqid('token'), 'uuid' => uniqid('uuid')])->fresh();
    }

    private function login(User $user, bool $admin = false, array $extra = [])
    {
        return $this->postJson('/api/v1/passport/auth/' . ($admin ? 'adminLogin' : 'login'),
            $extra + ['email' => $user->email, 'password' => 'test-password']);
    }

    private function bind(User $user): void
    {
        DB::table('v2_admin_two_factor')->insert(['user_id' => $user->id,
            'secret' => Crypt::encryptString(self::SECRET), 'last_step' => intdiv(time(), 30) - 2,
            'recovery_hashes' => json_encode([hash('sha256', self::RECOVERY)]),
            'required' => true, 'revision' => str_repeat('a', 32)]);
    }

    private function otp(): string
    {
        $hash = hash_hmac('sha1', pack('N2', 0, intdiv(time(), 30)), '12345678901234567890', true);
        $offset = ord($hash[19]) & 15;
        return sprintf('%06d', (unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff) % 1000000);
    }

    public function testUserLoginDoesNotRequireAdminEnrollmentOrGrantAdminAccess(): void
    {
        $user = $this->account();
        $token = $this->login($user, false, ['scope' => 'admin', 'is_admin' => 1])->assertOk()
            ->assertJsonPath('data.is_admin', 0)->assertJsonPath('data.admin_access', null)->json('data.auth_data');
        $this->withHeader('authorization', $token)->getJson('/api/v1/user/checkLogin')
            ->assertOk()->assertJsonMissing(['is_admin' => true]);
        $this->getJson('/api/v1/user/info')->assertOk()->assertJsonPath('data.admin_access', null);
        $this->getJson($this->adminBase . 'permission/current')->assertForbidden();
        $this->login($user, true)->assertOk()->assertJsonPath('data.two_factor_setup_required', true)
            ->assertJsonPath('data.auth_data', null);
        $this->assertNotFalse(AuthService::decryptAuthData($token));
    }

    public function testBoundAdminUsesPasswordOnlyInUserPortalAndOtpInAdminPortal(): void
    {
        $user = $this->account(); $this->bind($user);
        $factor = DB::table('v2_admin_two_factor')->first();
        $token = $this->login($user)->assertOk()->json('data.auth_data');
        $this->assertEquals($factor, DB::table('v2_admin_two_factor')->first());
        $this->withHeader('authorization', $token)->getJson($this->adminBase . 'permission/current')->assertForbidden();
        $this->login($user, true)->assertUnprocessable()->assertJsonPath('data.auth_data', null);
        $this->login($user, true, ['otp' => 'invalid'])->assertUnprocessable();
        $otp = $this->otp();
        $adminToken = $this->login($user, true, ['otp' => $otp])->assertOk()
            ->assertJsonPath('data.is_admin', 1)->json('data.auth_data');
        $this->withHeader('authorization', $adminToken)->getJson($this->adminBase . 'permission/current')->assertOk();
        $this->getJson('/api/v1/user/checkLogin')->assertOk()->assertJsonPath('data.is_admin', true);
        $this->login($user, true, ['otp' => $otp])->assertUnprocessable();

        $userSessions = $this->withHeader('authorization', $token)->getJson('/api/v1/user/getActiveSession')->assertOk()->json('data');
        $this->assertSame([$token], array_values(array_column($userSessions, 'auth_data')));
        $adminSessions = $this->withHeader('authorization', $adminToken)->getJson('/api/v1/user/getActiveSession')->assertOk()->json('data');
        $adminSession = array_key_first($adminSessions);
        $this->assertSame([$adminToken], array_values(array_column($adminSessions, 'auth_data')));
        $this->withHeader('authorization', $token)->postJson('/api/v1/user/removeActiveSession', ['session_id' => $adminSession])
            ->assertOk()->assertJsonPath('data', false);
        $this->withHeader('authorization', $adminToken)->getJson($this->adminBase . 'permission/current')->assertOk();
        // A user credential in the legacy parameter must not override the scope check.
        $this->getJson($this->adminBase . 'permission/current?auth_data=' . $token)->assertForbidden();
    }

    public function testQuickLinksOnlyIssueUserSessionsAndAreConsumedOnce(): void
    {
        $user = $this->account(); $this->bind($user);
        $adminToken = $this->login($user, true, ['otp' => self::RECOVERY])->assertOk()->json('data.auth_data');
        $url = $this->withHeader('authorization', $adminToken)->postJson('/api/v1/passport/auth/getQuickLoginUrl')
            ->assertOk()->json('data');
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        parse_str(substr($fragment, strpos($fragment, '?') + 1), $query);
        $path = '/api/v1/passport/auth/token2Login?verify=' . $query['verify'];
        $token = $this->getJson($path)->assertOk()->assertJsonPath('data.is_admin', 0)->json('data.auth_data');
        $this->getJson($path)->assertStatus(500);
        $this->withHeader('authorization', $token)->getJson('/api/v1/user/checkLogin')->assertOk();
        (new AdminTwoFactor())->reset($user, false);
        $this->getJson($this->adminBase . 'permission/current')->assertForbidden();
        $this->getJson('/api/v1/user/checkLogin')->assertOk();
        $this->withHeader('authorization', $adminToken)->getJson($this->adminBase . 'permission/current')->assertForbidden();
    }

    public function testLegacyAndCachedUserTokensCannotGainAdminAccessEvenWhenTwoFactorIsOptional(): void
    {
        $user = $this->account();
        (new AdminTwoFactor())->changePolicy($user, false);
        $token = $this->login($user)->assertOk()->json('data.auth_data');
        Cache::put($token, $user->toArray(), 3600);
        $this->withHeader('authorization', $token)->getJson($this->adminBase . 'permission/current')->assertForbidden();
        $legacy = JWT::encode(['id' => $user->id, 'session' => 'legacy'], config('app.key'), 'HS256');
        Cache::put(CacheKey::get('USER_SESSIONS', $user->id), ['legacy' => ['auth_data' => $legacy]]);
        Cache::put($legacy, $user->toArray(), 3600);
        $this->withHeader('authorization', $legacy)->getJson('/api/v1/user/checkLogin')->assertOk();
        $this->getJson($this->adminBase . 'permission/current')->assertForbidden();
        $adminToken = $this->login($user, true)->assertOk()->json('data.auth_data');
        $this->withHeader('authorization', $adminToken)->getJson($this->adminBase . 'permission/current')->assertOk();
        (new AdminTwoFactor())->changePolicy($user, true);
        $this->getJson($this->adminBase . 'permission/current')->assertForbidden();
        $this->withHeader('authorization', $legacy)->getJson('/api/v1/user/checkLogin')->assertOk();
    }

    public function testOrdinaryUsersAndWrongPasswordsCannotUseAdminLogin(): void
    {
        $this->login($this->account(false), true)->assertForbidden();
        $admin = $this->account();
        foreach ([false, true] as $adminPortal) {
            $this->login($admin, $adminPortal, ['password' => 'wrong-password'])->assertStatus(500)
                ->assertJsonPath('data.auth_data', null);
        }
    }
}
