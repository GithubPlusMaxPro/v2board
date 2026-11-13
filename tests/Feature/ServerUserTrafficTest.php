<?php

namespace Tests\Feature;

use App\Jobs\StatServerUserJob;
use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use App\Models\StatServerUser;
use App\Models\User;
use App\Services\AdminPermissionService;
use App\Services\AuthService;
use App\Services\UserService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ServerUserTrafficTest extends TestCase
{
    private $url;
    private $day;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'app.key' => str_repeat('k', 32), 'app.timezone' => 'Asia/Shanghai']);
        Cache::flush();
        $this->day = CarbonImmutable::parse('2026-11-12', 'Asia/Shanghai')->getTimestamp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-12 12:00:00', 'Asia/Shanghai'));
        foreach (app('router')->getRoutes() as $route) {
            if (strpos($route->getActionName(), 'ServerUserTrafficController@fetch') !== false) $this->url = '/' . $route->uri();
        }
        $this->assertNotEmpty($this->url);
        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            foreach (['email', 'password', 'token', 'uuid'] as $column) $table->string($column);
            foreach (['is_admin', 'is_staff', 'banned', 'u', 'd'] as $column) $table->integer($column)->default(0);
            $table->text('admin_permissions')->nullable();
            $table->integer('created_at')->nullable(); $table->integer('updated_at')->nullable();
        });
        require_once database_path('migrations/2026_11_12_000000_create_stat_server_user_tables.php');
        (new \CreateStatServerUserTables())->up();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function account(bool $admin = false, $permissions = null): User
    {
        return User::create(['email' => uniqid('account') . '@example.com', 'password' => 'secret-hash',
            'uuid' => uniqid('uuid'), 'token' => uniqid('token'), 'is_admin' => $admin ? 1 : 0,
            'admin_permissions' => $permissions])->fresh();
    }

    private function authorize(User $user): void
    {
        $this->withHeader('authorization', (new AuthService($user))->generateAuthData(Request::create('/'))['auth_data']);
    }

    private function job(array $data, int $id = 1, string $type = 'vmess', $rate = 1, $at = null): StatServerUserJob
    {
        return new StatServerUserJob($data, ['id' => $id, 'name' => 'Node ' . $id, 'rate' => $rate], $type, $at ?? $this->day + 3600);
    }

    public function testDailyCountersKeepUsersNodesProtocolsAndRatesSeparate(): void
    {
        $this->job([1 => [3, 5], 2 => [20, 30]], 1, 'vmess', 0.5)->handle();
        $this->job([1 => [7, 11]], 1, 'vmess', 2)->handle();
        $this->job([1 => [100, 200]], 1, 'vless', 1)->handle();
        $this->job([1 => [300, 400]], 2)->handle();
        $this->assertSame(4, StatServerUser::count());
        $row = StatServerUser::where('server_type', 'vmess')->where('server_id', 1)->where('user_id', 1)->first();
        $this->assertSame(10, $row->u); $this->assertSame(16, $row->d);
        $this->assertSame(15, $row->billed_u); $this->assertSame(24, $row->billed_d);
        $this->assertSame($this->day, $row->record_at);
        $this->assertSame(20, StatServerUser::where('user_id', 2)->first()->u);
    }

    public function testSerializedQueueRetriesDoNotDoubleCountButNewReportsAccumulate(): void
    {
        $job = $this->job([1 => [100, 200]]);
        $retry = unserialize(serialize($job));
        $job->handle(); $retry->handle();
        $this->assertSame(100, StatServerUser::first()->u);
        $this->assertSame(1, DB::table('v2_stat_server_user_batch')->count());
        $this->job([1 => [100, 200]])->handle();
        $this->assertSame(200, StatServerUser::first()->u);
        $this->assertSame(2, DB::table('v2_stat_server_user_batch')->count());
    }

    public function testDelayedJobKeepsReportingDayAcrossMidnight(): void
    {
        $job = $this->job([1 => [10, 20]], 1, 'vmess', 1, $this->day + 86399);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-13 12:00:00', 'Asia/Shanghai'));
        $job->handle();
        $this->job([1 => [30, 40]], 1, 'vmess', 1, $this->day + 86400)->handle();
        $this->assertSame(2, StatServerUser::count());
        $this->assertSame(10, StatServerUser::where('record_at', $this->day)->first()->u);
        $this->assertSame(30, StatServerUser::where('record_at', $this->day + 86400)->first()->u);
    }

    public function testFailedBatchRollsBackAllChunksAndCanRetryExactlyOnce(): void
    {
        DB::unprepared("CREATE TRIGGER reject_counter BEFORE INSERT ON v2_stat_server_user WHEN NEW.user_id = 60 BEGIN SELECT RAISE(ABORT, 'write failed'); END");
        $job = $this->job(array_fill(1, 120, [10, 20]));
        try {
            $job->handle();
            $this->fail('Expected a write failure');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertSame(0, StatServerUser::count());
            $this->assertSame(0, DB::table('v2_stat_server_user_batch')->count());
        }
        DB::unprepared('DROP TRIGGER reject_counter');
        $job->handle(); $job->handle();
        $this->assertSame(120, StatServerUser::count());
        $this->assertSame(1200, (int)StatServerUser::sum('u'));
        $this->assertSame(1, DB::table('v2_stat_server_user_batch')->count());
    }

    public function testZeroTrafficIsSkippedAndInvalidCountersCannotCorruptTotals(): void
    {
        $this->job([1 => [0, 0]])->handle();
        $this->assertSame(0, StatServerUser::count());
        $this->assertSame(0, DB::table('v2_stat_server_user_batch')->count());
        try {
            $this->job([1 => [10, 20], 2 => [-1, 20]])->handle();
            $this->fail('Expected invalid traffic to be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(0, StatServerUser::count());
            $this->assertSame(0, DB::table('v2_stat_server_user_batch')->count());
        }
    }

    public function testExistingTrafficEntryQueuesNewStatisticsWithoutReplacingBilling(): void
    {
        Bus::fake([StatServerUserJob::class, TrafficFetchJob::class, StatUserJob::class, StatServerJob::class]);
        (new UserService())->trafficFetch(['id' => 1, 'name' => 'Report node', 'rate' => 2], 'vmess', [1 => [30, 40]]);
        foreach ([StatServerUserJob::class, TrafficFetchJob::class, StatUserJob::class, StatServerJob::class] as $class) Bus::assertDispatched($class, 1);
        Bus::assertDispatched(StatServerUserJob::class, function ($job) {
            $this->assertSame('stat', $job->queue);
            $job->handle();
            $this->assertSame(60, StatServerUser::first()->billed_u);
            return true;
        });
    }

    public function testQueryDefaultsToTodayAndTotalsIncludeEveryPage(): void
    {
        $first = $this->account(); $second = $this->account();
        $this->job([$first->id => [100, 200], $second->id => [300, 400]], 1, 'vmess', 2)->handle();
        $this->job([$first->id => [1000, 2000]], 1, 'vmess', 2, $this->day - 1)->handle();
        $this->authorize($this->account(true));
        $this->getJson($this->url . '?page_size=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('total', 2)->assertJsonPath('summary.total', 1000)->assertJsonPath('summary.billed_total', 2000)
            ->assertJsonPath('start_date', '2026-11-12')->assertJsonPath('data.0.record_date', '2026-11-12')
            ->assertJsonPath('timezone', 'Asia/Shanghai');
        $this->getJson($this->url . '?page_size=1&current=2')->assertOk()->assertJsonPath('summary.total', 1000)->assertJsonCount(1, 'data');
        $this->getJson($this->url . '?start_date=2026-11-11&end_date=2026-11-12')->assertOk()
            ->assertJsonPath('total', 3)->assertJsonPath('summary.total', 4000);
    }

    public function testNodeUserAndDateFiltersCombineWithoutExposingCredentials(): void
    {
        $first = $this->account(); $second = $this->account();
        $this->job([$first->id => [10, 20], $second->id => [100, 200]])->handle();
        $this->job([$first->id => [30, 40]], 1, 'vless')->handle();
        $this->authorize($this->account(true));
        $query = '?server_type=vmess&server_id=1&user_id=' . $first->id . '&email=' . urlencode($first->email);
        $response = $this->getJson($this->url . $query)->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('summary.total', 30)->assertJsonPath('data.0.email', $first->email);
        foreach (['password', 'token', 'uuid', 'admin_permissions'] as $key) $this->assertArrayNotHasKey($key, $response->json('data.0'));
        $this->getJson($this->url . '?server_type=vless')->assertOk()->assertJsonPath('summary.total', 70);
        $this->getJson($this->url . '?user_id=99999')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('summary.total', 0);
    }

    public function testNodeIdWorksWithoutTypeAndKeepsPaginationAndTotalsScoped(): void
    {
        $first = $this->account(); $second = $this->account();
        $this->job([$first->id => [10, 20], $second->id => [30, 40]], 1, 'vmess')->handle();
        $this->job([$first->id => [50, 60]], 1, 'vless')->handle();
        $this->job([$first->id => [1000, 2000]], 2, 'vmess')->handle();
        $this->job([$first->id => [3000, 4000]], 1, 'vmess', 1, $this->day - 1)->handle();
        $this->authorize($this->account(true));
        $this->getJson($this->url . '?server_id=1&page_size=2')->assertOk()
            ->assertJsonPath('total', 3)->assertJsonCount(2, 'data')->assertJsonPath('summary.total', 210);
        $this->getJson($this->url . '?server_id=1&page_size=2&current=2')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('summary.total', 210)->assertJsonPath('data.0.server_id', 1);
        $this->getJson($this->url . '?server_id=1&server_type=')->assertOk()->assertJsonPath('total', 3);
        $this->getJson($this->url . '?server_id=1&server_type=vmess')->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('summary.total', 100);
        $this->getJson($this->url . '?server_id=1&user_id=' . $second->id)->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('summary.total', 70);
        $this->getJson($this->url . '?server_id=99999')->assertOk()
            ->assertJsonPath('total', 0)->assertJsonPath('summary.total', 0);
    }

    public function testDedicatedPermissionLimitsRestrictedAdminsToOrdinaryUsers(): void
    {
        $user = $this->account(); $super = $this->account(true); $staff = $this->account(); $staff->update(['is_staff' => 1]);
        $this->job([$user->id => [10, 20], $super->id => [100, 200], $staff->id => [1000, 2000], 99999 => [1, 2]])->handle();
        $this->getJson($this->url)->assertForbidden();
        $this->authorize($user); $this->getJson($this->url)->assertForbidden();
        $manager = $this->account(true, ['server.view', 'user.view', 'stat.view']);
        $this->authorize($manager); $this->getJson($this->url)->assertForbidden();
        $manager->update(['admin_permissions' => ['traffic.view']]);
        $this->getJson($this->url)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('summary.total', 30);
        $this->getJson($this->url . '?server_id=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('summary.total', 30);
        $this->getJson($this->url . '?user_id=' . $super->id)->assertOk()->assertJsonPath('total', 0);
        $this->authorize($super);
        $this->getJson($this->url)->assertOk()->assertJsonPath('total', 4)->assertJsonPath('summary.total', 3333);
        $this->assertSame(['traffic.view'], AdminPermissionService::normalize(['traffic.view']));
    }

    public function testInvalidFiltersAndUnboundedDateRangesAreRejected(): void
    {
        $this->authorize($this->account(true));
        foreach (['server_id=0', 'server_id=-1', 'server_id=invalid', 'server_type=invalid', 'user_id=0', 'current=0', 'page_size=101',
            'start_date=2026-02-30', 'start_date=bad', 'start_date=2026-11-12&end_date=2026-11-11',
            'start_date=2024-01-01&end_date=2026-01-01'] as $query) $this->getJson($this->url . '?' . $query)->assertUnprocessable();
    }

    public function testNodeGroupsCombineDaysAndPaginateNodesBeforeExpandingUsers(): void
    {
        $first = $this->account(); $second = $this->account();
        $this->job([$first->id => [10, 20], $second->id => [30, 40]], 1, 'vmess', 2)->handle();
        $this->job([$first->id => [50, 60]], 1, 'vmess', 3, $this->day - 1)->handle();
        $this->job([$first->id => [1, 2]], 1, 'vless')->handle();
        $this->job([$first->id => [3, 4]], 2)->handle();
        $this->authorize($this->account(true));
        $range = '?start_date=2026-11-11&end_date=2026-11-12';
        $this->getJson($this->url . $range . '&group_by=server&page_size=1')->assertOk()
            ->assertJsonPath('total', 3)->assertJsonCount(1, 'data')->assertJsonPath('group_by', 'server')
            ->assertJsonPath('data.0.server_type', 'vmess')->assertJsonPath('data.0.server_id', 1)
            ->assertJsonPath('data.0.user_count', 2)->assertJsonPath('data.0.record_count', 3)
            ->assertJsonPath('data.0.u', 90)->assertJsonPath('data.0.d', 120)
            ->assertJsonPath('data.0.billed_u', 230)->assertJsonPath('data.0.billed_d', 300)
            ->assertJsonPath('summary.total', 220)->assertJsonPath('summary.billed_total', 540);
        $this->getJson($this->url . $range . '&group_by=server&page_size=1&current=2')->assertOk()
            ->assertJsonPath('data.0.server_id', 2)->assertJsonPath('summary.total', 220);
        $this->getJson($this->url . $range . '&group_by=user&server_type=vmess&server_id=1&page_size=1')->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('data.0.user_id', $first->id)
            ->assertJsonPath('data.0.email', $first->email)->assertJsonPath('data.0.u', 60)
            ->assertJsonPath('data.0.d', 80)->assertJsonPath('data.0.billed_u', 170)
            ->assertJsonPath('summary.total', 210)->assertJsonPath('summary.billed_total', 530);
        $this->getJson($this->url . $range . '&group_by=user&server_type=vmess&server_id=1&page_size=1&current=2')->assertOk()
            ->assertJsonPath('data.0.user_id', $second->id)->assertJsonPath('data.0.u', 30);
        $this->getJson($this->url . $range . '&group_by=server&email=' . urlencode($second->email))->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.user_count', 1)->assertJsonPath('summary.total', 70);
        $this->getJson($this->url . '?group_by=server&server_id=99999')->assertOk()
            ->assertJsonPath('total', 0)->assertJsonCount(0, 'data')->assertJsonPath('summary.total', 0);
        $this->getJson($this->url . '?group_by=invalid')->assertUnprocessable();
    }

    public function testGroupedTrafficKeepsRestrictedUserVisibilityAndDeletedUserSnapshots(): void
    {
        $user = $this->account(); $super = $this->account(true); $staff = $this->account(); $staff->update(['is_staff' => 1]);
        $this->job([$user->id => [10, 20], $super->id => [100, 200], $staff->id => [1000, 2000], 99999 => [1, 2]])->handle();
        $this->authorize($this->account(true, ['traffic.view']));
        foreach (['server', 'user'] as $group) {
            $response = $this->getJson($this->url . '?group_by=' . $group)->assertOk()
                ->assertJsonPath('total', 1)->assertJsonPath('data.0.user_count', 1)->assertJsonPath('summary.total', 30);
            foreach (['password', 'token', 'uuid', 'admin_permissions'] as $key) $this->assertArrayNotHasKey($key, $response->json('data.0'));
        }
        $this->getJson($this->url . '?group_by=server&user_id=' . $super->id)->assertOk()->assertJsonPath('total', 0);
        $this->authorize($super);
        $this->getJson($this->url . '?group_by=server')->assertOk()
            ->assertJsonPath('data.0.user_count', 4)->assertJsonPath('summary.total', 3333);
        $this->getJson($this->url . '?group_by=user&user_id=99999')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.email', null)->assertJsonPath('data.0.server_name', 'Node 1');
    }

    public function testRepeatingMigrationDoesNotRemoveExistingStatistics(): void
    {
        $this->job([1 => [10, 20]])->handle();
        (new \CreateStatServerUserTables())->up();
        $this->assertSame(1, StatServerUser::count());
        $this->assertSame(1, DB::table('v2_stat_server_user_batch')->count());
    }
}
