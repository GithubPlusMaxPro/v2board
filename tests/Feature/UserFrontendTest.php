<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserFrontendTest extends TestCase
{
    private $adminBase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'app.key' => str_repeat('k', 32), 'v2board' => [
                'user_frontend_enable' => 0, 'password_limit_enable' => 0, 'frontend_theme' => 'default',
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

    public function testDisabledFrontendDoesNotLoadTheUserApplicationOrExposeAdminPath(): void
    {
        config(['v2board.app_name' => 'Example Site']);
        $response = $this->get('/')->assertOk()->assertSee('用户前端已关闭')->assertSee('Example Site');
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertDontSee('/theme/default/assets/umi.js');
        $response->assertDontSee(str_replace('/api/v1', '', rtrim($this->adminBase, '/')));
    }

    public function testEnablingTheFrontendRestoresTheConfiguredTheme(): void
    {
        config(['v2board.user_frontend_enable' => 1, 'theme.default' => [
            'theme_color' => 'default', 'theme_sidebar' => 'light', 'theme_header' => 'light',
            'background_url' => '', 'custom_html' => '',
        ]]);
        $this->get('/')->assertOk()->assertSee('/theme/default/assets/umi.js')->assertDontSee('用户前端已关闭');
    }

    public function testTheFrontendIsDisabledByDefaultAndTheOldLoginFlagHasNoEffect(): void
    {
        config(['v2board' => []]);
        $user = $this->account();
        foreach ([0, 1] as $oldFlag) {
            config(['v2board.user_login_enable' => $oldFlag]);
            $this->get('/')->assertOk()->assertSee('用户前端已关闭');
            $this->postJson('/api/v1/passport/auth/login', ['email' => $user->email, 'password' => 'test-password'])
                ->assertOk()->assertJsonStructure(['data' => ['auth_data']]);
        }
    }

    public function testPasswordLoginKeepsWorkingForEveryAccountTypeWithEitherFrontendSetting(): void
    {
        $user = $this->account(); $staff = $this->account(); $staff->update(['is_staff' => 1]);
        $admins = [$this->account(true), $this->account(true, []), $this->account(true, ['ticket.view'])];
        foreach ([0, 1] as $enabled) {
            config(['v2board.user_frontend_enable' => $enabled]);
            foreach (array_merge([$user, $staff], $admins) as $account) {
                $this->postJson('/api/v1/passport/auth/login', ['email' => $account->email, 'password' => 'test-password'])
                    ->assertOk()->assertJsonStructure(['data' => ['auth_data']]);
            }
        }
    }

    public function testRegistrationApiIsIndependentOfTheFrontendSetting(): void
    {
        foreach ([0, 1] as $enabled) {
            config(['v2board.user_frontend_enable' => $enabled]);
            $email = 'new' . $enabled . '@example.com';
            $this->postJson('/api/v1/passport/auth/register', ['email' => $email, 'password' => 'test-password'])
                ->assertOk()->assertJsonStructure(['data' => ['auth_data']]);
            $this->assertTrue(User::where('email', $email)->exists());
        }
    }

    public function testQuickLoginApiKeepsWorkingWithEitherFrontendSetting(): void
    {
        $user = $this->account();
        foreach ([0, 1] as $enabled) {
            config(['v2board.user_frontend_enable' => $enabled]);
            $token = (new AuthService($user))->generateAuthData(Request::create('/'))['auth_data'];
            $response = $this->withHeader('authorization', $token)->postJson('/api/v1/passport/auth/getQuickLoginUrl')->assertOk();
            $fragment = parse_url($response->json('data'), PHP_URL_FRAGMENT);
            parse_str(substr($fragment, strpos($fragment, '?') + 1), $query);
            $this->getJson('/api/v1/passport/auth/token2Login?verify=' . $query['verify'])
                ->assertOk()->assertJsonStructure(['data' => ['auth_data']]);
        }
    }

    public function testAdminPageRemainsAvailableWhenTheUserFrontendIsDisabled(): void
    {
        $adminPage = str_replace('/api/v1', '', rtrim($this->adminBase, '/'));
        $this->get($adminPage)->assertOk()->assertSee('/assets/admin/login.css');
    }

    public function testTheFrontendSettingIsExposedAndOnlyConfigManagersCanSaveIt(): void
    {
        $admin = $this->account(true); $token = (new AuthService($admin))->generateAuthData(Request::create('/'))['auth_data'];
        $this->withHeader('authorization', $token)->getJson($this->adminBase . 'config/fetch?key=site')->assertOk()
            ->assertJsonPath('data.site.user_frontend_enable', 0);
        File::partialMock()->shouldReceive('put')->once()->withArgs(function ($path, $content) {
            return $path === base_path('config/v2board.php') && strpos($content, "'user_frontend_enable' => 1") !== false;
        })->andReturn(100);
        Artisan::shouldReceive('call')->once()->with('config:cache')->andReturn(0);
        $this->postJson($this->adminBase . 'config/save', ['user_frontend_enable' => 1])->assertOk();
        $this->postJson($this->adminBase . 'config/save', ['user_frontend_enable' => 2])->assertUnprocessable();
        $viewer = $this->account(true, ['config.view']);
        $token = (new AuthService($viewer))->generateAuthData(Request::create('/'))['auth_data'];
        $this->withHeader('authorization', $token)->postJson($this->adminBase . 'config/save', ['user_frontend_enable' => 1])->assertForbidden();
    }
}
