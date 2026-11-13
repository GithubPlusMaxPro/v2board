<?php

namespace Tests\Feature;

use App\Jobs\RecordUserOnlineJob;
use App\Jobs\StatServerUserJob;
use App\Models\User;
use App\Models\UserOnlineLog;
use App\Services\AuthService;
use App\Services\UserOnlineService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserOnlineTest extends TestCase
{
    private $base;
    private $day;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'app.key' => str_repeat('k', 32), 'app.timezone' => 'Asia/Shanghai',
            'v2board.server_token' => 'node-test-token', 'ip_lookup.enabled' => true]);
        Cache::flush();
        $this->day = CarbonImmutable::now('Asia/Shanghai')->startOfDay()->getTimestamp();
        foreach (app('router')->getRoutes() as $route) {
            if (strpos($route->getActionName(), 'UserOnlineController@summary') !== false) $this->base = '/' . substr($route->uri(), 0, -strlen('summary'));
        }
        $this->assertNotEmpty($this->base);
        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            foreach (['email', 'password', 'token', 'uuid'] as $column) $table->string($column);
            foreach (['is_admin', 'is_staff', 'banned', 'u', 'd'] as $column) $table->integer($column)->default(0);
            $table->text('admin_permissions')->nullable();
            $table->integer('created_at')->nullable(); $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_server_vmess', function (Blueprint $table) {
            $table->increments('id'); $table->string('name'); $table->text('group_id')->default('[]');
        });
        DB::table('v2_server_vmess')->insert(['id' => 1, 'name' => 'Node A']);
        require_once database_path('migrations/2026_11_12_000000_create_stat_server_user_tables.php');
        require_once database_path('migrations/2026_11_12_000001_create_user_online_log_table.php');
        (new \CreateStatServerUserTables())->up(); (new \CreateUserOnlineLogTable())->up();
    }

    private function account(bool $admin = false, $permissions = null): User
    {
        return User::create(['email' => uniqid('user') . '@example.com', 'password' => 'secret-hash',
            'token' => uniqid('token'), 'uuid' => uniqid('uuid'), 'is_admin' => $admin ? 1 : 0,
            'admin_permissions' => $permissions])->fresh();
    }

    private function authorize(User $user): void
    {
        $this->withHeader('authorization', (new AuthService($user))->generateAuthData(Request::create('/'))['auth_data']);
    }

    private function record(int $userId, array $ips, int $at, int $node = 1): RecordUserOnlineJob
    {
        return new RecordUserOnlineJob([$userId => $ips], ['id' => $node, 'name' => 'Node ' . $node], 'vmess', $at);
    }

    public function testCurrentIpsIgnoreStaleNodesAndNormalizeIpv6AndDuplicates(): void
    {
        $now = time();
        Cache::put('ALIVE_IP_USER_12', ['alive_ip' => 99,
            'vmess1' => ['lastupdateAt' => $now, 'aliveips' => ['8.8.8.8_1', '8.8.8.8', '::ffff:8.8.8.8', '2001:4860:4860:0:0:0:0:8888', 'bad']],
            'v2node2' => ['lastupdateAt' => $now - 100, 'server_name' => 'V2 Node', 'aliveips' => ['8.8.8.8']],
            'vmess3' => ['lastupdateAt' => $now - 101, 'aliveips' => ['1.1.1.1']],
        ], 120);
        $current = (new UserOnlineService())->current(12, $now);
        $this->assertSame(2, $current['unique_ip_count']); $this->assertSame(2, $current['node_count']);
        $this->assertCount(3, $current['items']);
        $this->assertContains('2001:4860:4860::8888', array_column($current['items'], 'ip'));
        $this->assertNotContains('1.1.1.1', array_column($current['items'], 'ip'));
        $this->assertSame('Node A', $current['items'][0]['server_name']);
    }

    public function testHistoryIsDailyAndRetriesAndOutOfOrderHeartbeatsAreIdempotent(): void
    {
        $job = $this->record(12, ['8.8.8.8', '8.8.8.8_1', '2001:4860:4860::8888', 'invalid'], $this->day + 100);
        $job->handle(); unserialize(serialize($job))->handle();
        $this->record(12, ['8.8.8.8'], $this->day + 200)->handle();
        $this->record(12, ['8.8.8.8'], $this->day + 50)->handle();
        $this->assertSame(2, UserOnlineLog::count());
        $ip = UserOnlineLog::where('ip', '8.8.8.8')->first();
        $this->assertSame($this->day + 50, $ip->first_seen); $this->assertSame($this->day + 200, $ip->last_seen);
        $this->record(12, ['8.8.8.8'], $this->day + 86400)->handle();
        $this->record(12, ['8.8.8.8'], $this->day + 100, 2)->handle();
        $this->assertSame(4, UserOnlineLog::count());
        (new \CreateUserOnlineLogTable())->up(); $this->assertSame(4, UserOnlineLog::count());
    }

    public function testNodeAliveReportStillUpdatesCacheAndQueuesHistory(): void
    {
        Bus::fake([RecordUserOnlineJob::class]);
        $url = '/api/v1/server/UniProxy/alive?token=node-test-token&node_type=vmess&node_id=1';
        $this->postJson($url, [12 => ['8.8.8.8_1', '2001:4860:4860::8888']])->assertOk();
        $this->assertSame(2, Cache::get('ALIVE_IP_USER_12')['alive_ip']);
        Bus::assertDispatched(RecordUserOnlineJob::class, function ($job) {
            $this->assertSame('stat', $job->queue); $job->handle(); return true;
        });
        $this->assertSame(2, UserOnlineLog::count());
        $this->postJson($url, [12 => []])->assertOk();
        $this->assertSame(0, (new UserOnlineService())->current(12)['unique_ip_count']);
        $this->assertSame(2, UserOnlineLog::count());
    }

    public function testUserSummaryAndTrafficUseSameDailyCountersWithoutExposingSecrets(): void
    {
        $user = $this->account(); $this->authorize($this->account(true, ['online.view']));
        Cache::put('ALIVE_IP_USER_' . $user->id, ['vmess1' => ['aliveips' => ['8.8.8.8'], 'lastupdateAt' => time(), 'server_name' => 'Node A']], 120);
        (new StatServerUserJob([$user->id => [100, 200]], ['id' => 1, 'name' => 'Node A', 'rate' => 2], 'vmess', $this->day + 100))->handle();
        $summary = $this->getJson($this->base . 'summary?user_id=' . $user->id)->assertOk()
            ->assertJsonPath('data.user.id', $user->id)->assertJsonPath('data.current.unique_ip_count', 1)
            ->assertJsonPath('data.today.total', 300)->assertJsonPath('data.today.billed_total', 600);
        $this->assertSame(['id', 'email'], array_keys($summary->json('data.user')));
        $this->getJson($this->base . 'traffic?user_id=' . $user->id)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('summary.total', 300);
        $this->getJson($this->base . 'summary?email=' . urlencode($user->email))->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->getJson($this->base . 'summary?user_id=' . $user->id . '&email=other@example.com')->assertNotFound();
        $this->getJson($this->base . 'summary')->assertUnprocessable();
    }

    public function testHistoryFiltersPaginationAndUserIsolation(): void
    {
        $user = $this->account(); $other = $this->account();
        $this->record($user->id, ['8.8.8.8', '1.1.1.1'], $this->day + 100)->handle();
        $this->record($user->id, ['8.8.8.8'], $this->day + 200, 2)->handle();
        $this->record($other->id, ['8.8.8.8'], $this->day + 300)->handle();
        $this->authorize($this->account(true)); $url = $this->base . 'history?user_id=' . $user->id;
        $this->getJson($url . '&page_size=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('total', 3);
        $this->getJson($url . '&page_size=2&current=2')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($url . '&ip=8.8.8.8&server_id=1')->assertOk()->assertJsonPath('total', 1);
        $this->getJson($url . '&ip=bad')->assertUnprocessable();
        $this->getJson($url . '&start_date=2026-02-30')->assertUnprocessable();
        $this->getJson($url . '&start_date=2025-01-01&end_date=2026-12-31')->assertUnprocessable();
    }

    public function testAllEndpointsRequireDedicatedPermissionAndProtectPrivilegedUsers(): void
    {
        $user = $this->account(); $super = $this->account(true); $staff = $this->account(); $staff->update(['is_staff' => 1]);
        foreach (['summary', 'history', 'traffic', 'ipInfo'] as $action) $this->getJson($this->base . $action . '?user_id=' . $user->id)->assertForbidden();
        $manager = $this->account(true, ['user.view', 'traffic.view']); $this->authorize($manager);
        $this->getJson($this->base . 'summary?user_id=' . $user->id)->assertForbidden();
        $manager->update(['admin_permissions' => ['online.view']]);
        foreach (['summary', 'history', 'traffic', 'ipInfo'] as $action) {
            foreach ([$super, $staff] as $hidden) $this->getJson($this->base . $action . '?user_id=' . $hidden->id . '&ip=8.8.8.8')->assertNotFound();
        }
        $this->getJson($this->base . 'summary?user_id=' . $user->id)->assertOk();
        $this->getJson($this->base . 'summary?user_id=99999')->assertNotFound();
    }

    public function testOnlineOnlyPermissionKeepsHistoricalTrafficDetailsAndPagination(): void
    {
        $user = $this->account(); $other = $this->account();
        $this->authorize($this->account(true, ['online.view']));
        (new StatServerUserJob([$user->id => [10, 20], $other->id => [1000, 2000]],
            ['id' => 1, 'name' => 'Node A', 'rate' => 2], 'vmess', $this->day + 100))->handle();
        (new StatServerUserJob([$user->id => [30, 40]],
            ['id' => 1, 'name' => 'Node A', 'rate' => 3], 'vmess', $this->day - 1))->handle();
        $start = CarbonImmutable::createFromTimestamp($this->day - 86400, 'Asia/Shanghai')->toDateString();
        $end = CarbonImmutable::createFromTimestamp($this->day, 'Asia/Shanghai')->toDateString();
        $url = $this->base . 'traffic?user_id=' . $user->id . '&start_date=' . $start . '&end_date=' . $end
            . '&server_id=1&page_size=1&group_by=server';
        $this->getJson($url)->assertOk()->assertJsonPath('group_by', 'detail')->assertJsonPath('total', 2)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.record_date', $end)
            ->assertJsonPath('data.0.user_id', $user->id)->assertJsonPath('summary.total', 100)
            ->assertJsonPath('summary.billed_total', 270);
        $this->getJson($url . '&current=2')->assertOk()->assertJsonPath('data.0.record_date', $start)
            ->assertJsonPath('data.0.u', 30)->assertJsonPath('summary.total', 100);
        $this->getJson($url . '&server_type=vless')->assertOk()->assertJsonPath('total', 0);
    }

}
