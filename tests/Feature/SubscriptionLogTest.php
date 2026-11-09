<?php

namespace Tests\Feature;

use App\Http\Middleware\SubscriptionLog as SubscriptionLogMiddleware;
use App\Models\SubscriptionLog;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\Helper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SubscriptionLogTest extends TestCase
{
    private $adminBase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'app.key' => str_repeat('k', 32), 'v2board' => []]);
        Cache::flush();
        foreach (app('router')->getRoutes() as $route) {
            if (strpos($route->getActionName(), 'PermissionController@current') !== false) {
                $this->adminBase = '/' . substr($route->uri(), 0, -strlen('permission/current'));
            }
        }
        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            foreach (['email', 'password', 'token', 'uuid'] as $column) $table->string($column);
            $table->text('admin_permissions')->nullable();
            foreach (['is_admin', 'is_staff', 'banned', 'u', 'd'] as $column) $table->integer($column)->default(0);
            $table->bigInteger('transfer_enable')->default(1073741824);
            foreach (['plan_id', 'expired_at', 'device_limit', 'group_id'] as $column) $table->integer($column)->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        foreach (['shadowsocks', 'vmess', 'trojan', 'tuic', 'hysteria', 'vless', 'anytls', 'v2node'] as $type) {
            Schema::create('v2_server_' . $type, function (Blueprint $table) {
                $table->increments('id'); $table->integer('sort')->nullable();
            });
        }
        require_once database_path('migrations/2026_11_09_000000_create_subscription_log_table.php');
        (new \CreateSubscriptionLogTable())->up();
    }

    private function account(bool $admin = false, $permissions = null): User
    {
        return User::create(['email' => uniqid('user') . '@example.com', 'password' => 'hashed-password',
            'token' => Helper::guid(), 'uuid' => Helper::guid(true), 'is_admin' => $admin ? 1 : 0,
            'admin_permissions' => $permissions])->fresh();
    }

    private function auth(User $user): string
    {
        return (new AuthService($user))->generateAuthData(Request::create('/'))['auth_data'];
    }

    public function testAddressAndDownloadAreStoredSeparatelyWithoutCredentials(): void
    {
        $user = $this->account();
        $before = time();
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::5'])
            ->withHeaders(['authorization' => $this->auth($user), 'User-Agent' => 'TestBrowser/1.0'])
            ->getJson('/api/v1/user/getSubscribe')->assertOk()->assertJsonStructure(['data' => ['subscribe_url']]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('User-Agent', 'TestClient/2.0')->get('/api/v1/client/subscribe?token=' . $user->token)->assertOk();
        $logs = SubscriptionLog::orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(['address', 'download'], $logs->pluck('action')->all());
        $this->assertSame(['2001:db8::5', '203.0.113.7'], $logs->pluck('ip')->all());
        $this->assertSame(['TestBrowser/1.0', 'TestClient/2.0'], $logs->pluck('user_agent')->all());
        foreach ($logs as $log) {
            $this->assertSame($user->id, $log->user_id);
            $this->assertSame(200, $log->status_code);
            $this->assertGreaterThanOrEqual($before, $log->created_at);
            $this->assertStringNotContainsString($user->token, $log->toJson());
            $this->assertStringNotContainsString($user->uuid, $log->toJson());
        }
    }

    public function testInvalidCredentialsAndUnrelatedRequestsDoNotCreateHistory(): void
    {
        $this->getJson('/api/v1/user/getSubscribe')->assertForbidden();
        $this->getJson('/api/v1/client/subscribe?token=invalid')->assertForbidden();
        $this->assertSame(0, SubscriptionLog::count());
    }

    public function testCustomSubscriptionPathAndTemporaryTokensAreRecordedOnce(): void
    {
        config(['v2board.subscribe_path' => '/custom-sub', 'v2board.show_subscribe_method' => 1]);
        // Rebuild the web routes using the same configured-path registration as production.
        Route::namespace('App\\Http\\Controllers')->group(base_path('routes/web.php'));
        $user = $this->account();
        $url = Helper::getSubscribeUrl($user->token);
        $this->get($url)->assertOk();
        $this->getJson($url)->assertForbidden(); // One-time token was consumed.
        $this->assertSame(1, SubscriptionLog::count());
        $this->assertSame($user->id, SubscriptionLog::first()->user_id);
        $this->assertSame('download', SubscriptionLog::first()->action);
    }

    public function testHistoryIsPaginatedFilteredAndIsolatedByUser(): void
    {
        $admin = $this->account(true); $user = $this->account(); $other = $this->account();
        foreach (['address', 'download', 'download'] as $action) {
            SubscriptionLog::create(['user_id' => $user->id, 'action' => $action, 'ip' => '2001:db8::1',
                'user_agent' => '<script>alert(1)</script>', 'status_code' => 200, 'created_at' => time()]);
        }
        SubscriptionLog::create(['user_id' => $other->id, 'action' => 'download', 'ip' => '203.0.113.1',
            'user_agent' => 'Other', 'status_code' => 200, 'created_at' => time()]);
        $url = $this->adminBase . 'user/getSubscribeLog?id=' . $user->id;
        $this->withHeader('authorization', $this->auth($admin));
        $this->getJson($url . '&page_size=2')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 3)->assertJsonPath('data.0.id', 3)->assertJsonPath('data.1.id', 2);
        $this->getJson($url . '&page_size=2&current=2')->assertOk()->assertJsonPath('data.0.id', 1)->assertJsonCount(1, 'data');
        $this->getJson($url . '&action=download&ip=2001:db8::1')->assertOk()->assertJsonPath('total', 2);
        $this->getJson($url . '&ip=203.0.113.1')->assertOk()->assertJsonPath('total', 0);
        $this->getJson($url . '&page_size=101')->assertUnprocessable();
        $this->getJson($url . '&current=0')->assertUnprocessable();
        $this->getJson($url . '&action=unknown')->assertUnprocessable();
        $this->getJson($url . '&ip=bad-ip')->assertUnprocessable();
        $this->getJson($this->adminBase . 'user/getSubscribeLog')->assertUnprocessable();
        $this->getJson($this->adminBase . 'user/getSubscribeLog?id=99999')->assertNotFound();
    }

    public function testViewingHistoryRequiresItsOwnPermissionAndExcludesPrivilegedAccounts(): void
    {
        $user = $this->account(); $super = $this->account(true); $staff = $this->account(); $staff->update(['is_staff' => 1]);
        $manager = $this->account(true, ['user.view']);
        $url = $this->adminBase . 'user/getSubscribeLog?id=';
        $this->withHeader('authorization', $this->auth($user))->getJson($url . $user->id)->assertForbidden();
        $this->withHeader('authorization', $this->auth($manager))->getJson($url . $user->id)->assertForbidden();
        $manager->update(['admin_permissions' => ['user.view', 'user.subscribe_log']]);
        $this->getJson($url . $user->id)->assertOk();
        $this->getJson($url . $super->id)->assertNotFound();
        $this->getJson($url . $staff->id)->assertNotFound();
        $this->withHeader('authorization', $this->auth($super))->getJson($url . $staff->id)->assertOk();
    }

    public function testAdminAuditPageQueriesAcrossUsersAndFiltersByIdentityAndTime(): void
    {
        $admin = $this->account(true); $first = $this->account(); $second = $this->account();
        foreach ([[$first, 'address', 1000], [$second, 'download', 2000], [$first, 'download', 3000]] as $item) {
            SubscriptionLog::create(['user_id' => $item[0]->id, 'action' => $item[1], 'ip' => '2001:db8::1',
                'user_agent' => 'AuditClient', 'status_code' => 200, 'created_at' => $item[2]]);
        }
        $url = $this->adminBase . 'subscriptionLog/fetch';
        $this->withHeader('authorization', $this->auth($admin));
        $this->getJson($url . '?page_size=2')->assertOk()->assertJsonPath('total', 3)
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.user_id', $first->id)
            ->assertJsonPath('data.0.email', $first->email)->assertJsonPath('data.1.email', $second->email);
        $this->getJson($url . '?page_size=2&current=2')->assertOk()->assertJsonPath('data.0.created_at', 1000);
        $this->getJson($url . '?user_id=' . $second->id)->assertOk()->assertJsonPath('total', 1);
        $this->getJson($url . '?email=' . urlencode($first->email))->assertOk()->assertJsonPath('total', 2);
        $this->getJson($url . '?start_at=1000&end_at=2000')->assertOk()->assertJsonPath('total', 2);
        $this->getJson($url . '?user_id=' . $first->id . '&action=download&ip=2001:db8::1&start_at=2000')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.created_at', 3000);
        $this->getJson($url . '?ip=203.0.113.1')->assertOk()->assertJsonPath('total', 0);
        $this->getJson($url . '?user_id=99999')->assertOk()->assertJsonPath('total', 0);
        $data = $this->getJson($url)->assertOk()->json('data.0');
        foreach (['password', 'token', 'uuid', 'admin_permissions'] as $secret) $this->assertArrayNotHasKey($secret, $data);
    }

    public function testGlobalAuditRequiresAdminPermissionAndScopesRestrictedAdmins(): void
    {
        $user = $this->account(); $super = $this->account(true); $staff = $this->account();
        $staff->update(['is_staff' => 1]); $manager = $this->account(true, ['user.view']);
        foreach ([$user->id, $super->id, $staff->id, 99999] as $userId) {
            SubscriptionLog::create(['user_id' => $userId, 'action' => 'download', 'ip' => '203.0.113.1',
                'user_agent' => 'Client', 'status_code' => 200, 'created_at' => time()]);
        }
        $url = $this->adminBase . 'subscriptionLog/fetch';
        $this->getJson($url)->assertForbidden();
        $this->withHeader('authorization', $this->auth($user))->getJson($url)->assertForbidden();
        $this->withHeader('authorization', $this->auth($manager))->getJson($url)->assertForbidden();
        $manager->update(['admin_permissions' => ['user.subscribe_log']]);
        $this->getJson($url)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.user_id', $user->id);
        $this->getJson($url . '?user_id=' . $super->id)->assertOk()->assertJsonPath('total', 0);
        $this->getJson($url . '?email=' . urlencode($staff->email))->assertOk()->assertJsonPath('total', 0);
        $this->withHeader('authorization', $this->auth($super))->getJson($url)->assertOk()
            ->assertJsonPath('total', 4)->assertJsonPath('data.0.user_id', 99999)->assertJsonPath('data.0.email', null);
    }

    public function testAuditFiltersValidatePaginationAndTimeRange(): void
    {
        $this->withHeader('authorization', $this->auth($this->account(true)));
        $url = $this->adminBase . 'subscriptionLog/fetch?';
        foreach (['current=0', 'page_size=101', 'user_id=-1', 'ip=bad-ip', 'action=unknown',
            'start_at=-1', 'end_at=invalid', 'start_at=2000&end_at=1000'] as $query) {
            $this->getJson($url . $query)->assertUnprocessable();
        }
    }

    public function testWriteFailureDoesNotChangeTheSubscriptionResponse(): void
    {
        $user = $this->account();
        Schema::drop('v2_subscription_log');
        $logger = \Mockery::mock();
        Log::shouldReceive('channel')->with('daily')->andReturn($logger);
        $logger->shouldReceive('warning')->once()->with('Subscription history could not be saved', [
            'user_id' => $user->id, 'action' => 'download',
        ]);
        $this->get('/api/v1/client/subscribe?token=' . $user->token)->assertOk();
    }

    public function testKnownUserFailuresAreRecordedAndRethrown(): void
    {
        $user = $this->account();
        $request = Request::create('/test', 'GET', ['user' => $user]);
        $exception = new HttpException(503, 'Temporarily unavailable');
        try {
            (new SubscriptionLogMiddleware())->handle($request, function () use ($exception) { throw $exception; }, 'download');
            $this->fail('Expected the original exception');
        } catch (HttpException $caught) {
            $this->assertSame($exception, $caught);
        }
        $this->assertSame(503, SubscriptionLog::first()->status_code);
    }

    public function testLongUnicodeUserAgentsAreBoundedAndMigrationCanBeRepeated(): void
    {
        (new \CreateSubscriptionLogTable())->up();
        $user = $this->account();
        $this->withHeader('User-Agent', str_repeat('测', 1500))
            ->get('/api/v1/client/subscribe?token=' . $user->token)->assertOk();
        $this->assertSame(1024, mb_strlen(SubscriptionLog::first()->user_agent));
    }
}
