<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthService;
use App\Utils\CacheKey;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserLoginAccessTest extends TestCase
{
    private $adminBase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'app.key' => str_repeat('k', 32), 'v2board' => [
                'user_login_enable' => 0, 'password_limit_enable' => 0, 'frontend_theme' => 'default',
            ]]);
        Cache::flush();
        foreach (app('router')->getRoutes() as $route) {
            if (strpos($route->getActionName(), 'PermissionController@current') !== false) {
                $this->adminBase = '/' . substr($route->uri(), 0, -strlen('permission/current'));
            }
        }
        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            foreach (['email', 'password', 'uuid', 'token'] as $column) $table->string($column);
            foreach (['password_algo', 'password_salt', 'admin_permissions'] as $column) $table->text($column)->nullable();
            foreach (['is_admin', 'is_staff', 'banned', 'last_login_at'] as $column) $table->integer($column)->default(0);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function account(bool $admin = false, $permissions = null): User
    {
        return User::create(['email' => uniqid('user') . '@example.com',
            'password' => password_hash('test-password', PASSWORD_BCRYPT, ['cost' => 4]),
            'token' => uniqid('token'), 'uuid' => uniqid('uuid'), 'is_admin' => $admin ? 1 : 0,
            'is_staff' => 0, 'banned' => 0, 'admin_permissions' => $permissions])->fresh();
    }

    private function credentials(User $user, array $extra = []): array
    {
        return array_merge(['email' => $user->email, 'password' => 'test-password'], $extra);
    }

    public function testPausedHomepageReplacesAllPublicHashRoutesWithoutExposingAdminPath(): void
    {
        config(['v2board.app_name' => 'Example Site']);
        $response = $this->get('/')->assertOk()->assertSee('用户中心暂未开放')->assertSee('Example Site');
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertDontSee('/theme/default/assets/umi.js');
        $response->assertDontSee(str_replace('/api/v1', '', rtrim($this->adminBase, '/')));
    }

    public function testEnablingPublicLoginRestoresTheConfiguredTheme(): void
    {
        config(['v2board.user_login_enable' => 1, 'theme.default' => [
            'theme_color' => 'default', 'theme_sidebar' => 'light', 'theme_header' => 'light',
            'background_url' => '', 'custom_html' => '',
        ]]);
        $this->get('/')->assertOk()->assertSee('/theme/default/assets/umi.js')->assertDontSee('用户中心暂未开放');
    }

    public function testOrdinaryUserLoginIsBlockedWithoutIssuingASession(): void
    {
        $user = $this->account();
        $this->postJson('/api/v1/passport/auth/login', $this->credentials($user))
            ->assertForbidden()->assertJson(['login_disabled' => true]);
        $this->assertSame([], (new AuthService($user))->getSessions());
        $this->postJson('/api/v1/passport/auth/login', $this->credentials($user, ['admin_login' => 0, 'is_admin' => 1]))->assertForbidden();
    }

    public function testFullAndRestrictedAdminsCanStillLoginWhilePaused(): void
    {
        foreach ([null, [], ['ticket.view']] as $permissions) {
            $admin = $this->account(true, $permissions);
            $response = $this->postJson('/api/v1/passport/auth/login', $this->credentials($admin, ['admin_login' => 1]))
                ->assertOk()->assertJsonStructure(['data' => ['auth_data', 'admin_access']]);
            $this->assertSame(1, (int)$response->json('data.is_admin'));
        }
        $adminPage = str_replace('/api/v1', '', rtrim($this->adminBase, '/'));
        $this->get($adminPage)->assertOk()->assertSee('/assets/admin/login.css');
    }

    public function testReopeningPublicLoginAllowsOrdinaryUsersButNotAtTheAdminForm(): void
    {
        $user = $this->account(); config(['v2board.user_login_enable' => 1]);
        $response = $this->postJson('/api/v1/passport/auth/login', $this->credentials($user))->assertOk();
        $this->assertSame(0, (int)$response->json('data.is_admin'));
        $sessions = (new AuthService($user))->getSessions();
        $this->postJson('/api/v1/passport/auth/login', $this->credentials($user, ['admin_login' => 1]))->assertUnprocessable();
        $this->assertSame($sessions, (new AuthService($user))->getSessions());
    }

    public function testStaffFlagAloneDoesNotBypassThePause(): void
    {
        $staff = $this->account(); $staff->update(['is_staff' => 1]);
        $this->postJson('/api/v1/passport/auth/login', $this->credentials($staff))->assertForbidden();
    }

    public function testRegistrationCannotCreateAnAccountWhilePaused(): void
    {
        $this->postJson('/api/v1/passport/auth/register', ['email' => 'new@example.com', 'password' => 'test-password', 'admin_login' => 1])
            ->assertForbidden()->assertJson(['login_disabled' => true]);
        $this->assertSame(0, User::count());
    }

    public function testExistingQuickLoginCodesCannotBypassThePause(): void
    {
        $user = $this->account(); $key = CacheKey::get('TEMP_TOKEN', 'test-code');
        Cache::put($key, $user->id, 60);
        $this->getJson('/api/v1/passport/auth/token2Login?verify=test-code')->assertForbidden()->assertJson(['login_disabled' => true]);
        $this->assertTrue(Cache::has($key));
        config(['v2board.user_login_enable' => 1]);
        $this->getJson('/api/v1/passport/auth/token2Login?verify=test-code')->assertOk();
        $this->assertFalse(Cache::has($key));
    }

    public function testLoggedInUsersCannotMintQuickLoginCodesAfterThePause(): void
    {
        config(['v2board.user_login_enable' => 1]);
        $user = $this->account();
        $token = (new AuthService($user))->generateAuthData(Request::create('/'))['auth_data'];
        config(['v2board.user_login_enable' => 0]);
        $this->withHeader('authorization', $token)->postJson('/api/v1/passport/auth/getQuickLoginUrl')->assertForbidden();
        $admin = $this->account(true, []);
        $token = (new AuthService($admin))->generateAuthData(Request::create('/'))['auth_data'];
        $this->withHeader('authorization', $token)->postJson('/api/v1/passport/auth/getQuickLoginUrl')->assertOk();
    }

    public function testTheSettingIsExposedAndOnlyConfigManagersCanSaveIt(): void
    {
        $admin = $this->account(true); $token = (new AuthService($admin))->generateAuthData(Request::create('/'))['auth_data'];
        $this->withHeader('authorization', $token)->getJson($this->adminBase . 'config/fetch?key=site')->assertOk()
            ->assertJsonPath('data.site.user_login_enable', 0);
        File::partialMock()->shouldReceive('put')->once()->withArgs(function ($path, $content) {
            return $path === base_path('config/v2board.php') && strpos($content, "'user_login_enable' => 1") !== false;
        })->andReturn(100);
        Artisan::shouldReceive('call')->once()->with('config:cache')->andReturn(0);
        $this->postJson($this->adminBase . 'config/save', ['user_login_enable' => 1])->assertOk();
        $this->postJson($this->adminBase . 'config/save', ['user_login_enable' => 2])->assertUnprocessable();
        $viewer = $this->account(true, ['config.view']);
        $token = (new AuthService($viewer))->generateAuthData(Request::create('/'))['auth_data'];
        $this->withHeader('authorization', $token)->postJson($this->adminBase . 'config/save', ['user_login_enable' => 1])->assertForbidden();
    }
}
