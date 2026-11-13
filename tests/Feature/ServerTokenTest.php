<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthService;
use App\Services\ServerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ServerTokenTest extends TestCase
{
    private $base;
    private $global = 'global-node-token-1234';
    private $custom = 'custom-node-token-1234';

    public static function nodeTypes(): array
    {
        return array_map(function ($type) { return [$type]; }, [
            'vmess', 'vless', 'trojan', 'shadowsocks', 'tuic', 'hysteria', 'anytls', 'v2node',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'app.key' => str_repeat('k', 32),
            'v2board.server_token' => $this->global, 'v2board.server_api_url' => 'https://panel.example.com']);
        Cache::flush();
        foreach (app('router')->getRoutes() as $route) {
            if (strpos($route->getActionName(), 'ManageController@getNodes') !== false) {
                $this->base = '/' . substr($route->uri(), 0, -strlen('server/manage/getNodes'));
            }
        }
        $this->assertNotEmpty($this->base);
        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            foreach (['email', 'password', 'token', 'uuid'] as $name) $table->string($name)->nullable();
            foreach (['is_admin', 'is_staff', 'banned', 'u', 'd', 'transfer_enable'] as $name) $table->integer($name)->default(0);
            foreach (['group_id', 'expired_at', 'speed_limit', 'device_limit', 'created_at', 'updated_at'] as $name) $table->integer($name)->nullable();
            $table->text('admin_permissions')->nullable();
        });
        foreach (self::nodeTypes() as [$type]) {
            Schema::create('v2_server_' . $type, function (Blueprint $table) {
                $table->increments('id');
                foreach (['name', 'host', 'port', 'network', 'protocol', 'cipher', 'server_name', 'obfs', 'padding_scheme'] as $name) $table->text($name)->nullable();
                foreach (['group_id', 'route_id', 'tags', 'tls_settings', 'encryption_settings'] as $name) $table->text($name)->nullable();
                foreach (['server_port', 'tls', 'insecure', 'disable_sni', 'zero_rtt_handshake', 'version', 'up_mbps', 'down_mbps', 'parent_id', 'sort', 'created_at', 'updated_at'] as $name) $table->integer($name)->nullable();
                $table->integer('show')->default(1);
                $table->float('rate')->default(1);
            });
        }
        require_once database_path('migrations/2026_11_13_000000_add_server_token_to_server_tables.php');
        (new \AddServerTokenToServerTables())->up();
    }

    private function payload(array $changes = []): array
    {
        return array_merge(['name' => 'Node', 'group_id' => [1], 'host' => 'node.example.com',
            'port' => '443', 'server_port' => 443, 'rate' => 1, 'tls' => 0, 'network' => 'tcp',
            'protocol' => 'vmess', 'cipher' => 'aes-128-gcm', 'insecure' => 0, 'disable_sni' => 0,
            'zero_rtt_handshake' => 0, 'version' => 2], $changes);
    }

    private function node(string $type, array $changes = [])
    {
        $class = get_class($this->model($type));
        return $class::create($this->payload($changes));
    }

    private function model(string $type)
    {
        $models = ['vmess' => 'Vmess', 'vless' => 'Vless', 'trojan' => 'Trojan', 'shadowsocks' => 'Shadowsocks',
            'tuic' => 'Tuic', 'hysteria' => 'Hysteria', 'anytls' => 'Anytls', 'v2node' => 'V2node'];
        $class = 'App\\Models\\Server' . $models[$type];
        return new $class();
    }

    private function authorize(): void
    {
        $admin = User::create(['email' => 'admin@example.com', 'token' => 'session-token',
            'uuid' => 'admin-uuid', 'is_admin' => 1]);
        $this->withHeader('authorization', (new AuthService($admin))->generateAuthData(Request::create('/'))['auth_data']);
    }

    /** @dataProvider nodeTypes */
    public function testAdminCanSetPreserveCopyAndClearToken(string $type): void
    {
        $this->authorize();
        $url = $this->base . 'server/' . $type . '/save';
        $this->postJson($url, $this->payload())->assertOk();
        $node = $this->model($type)->first();
        $this->assertNull($node->server_token);
        $payload = $this->payload(['id' => $node->id, 'server_token' => $this->custom]);
        $this->postJson($url, $payload)->assertOk();
        $this->assertSame($this->custom, $node->fresh()->server_token);
        foreach (['short', str_repeat('x', 256), ['invalid']] as $invalid) {
            $this->postJson($url, array_merge($payload, ['server_token' => $invalid]))->assertUnprocessable();
        }
        unset($payload['server_token']);
        $this->postJson($url, $payload)->assertOk();
        $this->assertSame($this->custom, $node->fresh()->server_token);
        $this->getJson($this->base . 'server/manage/getNodes')->assertOk()->assertJsonPath('data.0.server_token', $this->custom);
        $this->postJson($this->base . 'server/' . $type . '/copy', ['id' => $node->id])->assertOk();
        $this->assertSame($this->custom, $this->model($type)->orderByDesc('id')->first()->server_token);
        $this->postJson($url, array_merge($payload, ['server_token' => '']))->assertOk();
        $this->assertNull($node->fresh()->server_token);
        $this->assertTrue((new ServerService())->checkServerToken($node->fresh(), $this->global));
    }

    /** @dataProvider nodeTypes */
    public function testUniProxyUsesOnlyTheTargetNodesEffectiveToken(string $type): void
    {
        $node = $this->node($type);
        $custom = $this->node($type, ['server_token' => $this->custom]);
        $other = $this->node($type, ['server_token' => 'another-node-token-1234']);
        $url = '/api/v1/server/UniProxy/user?node_type=' . $type;
        $this->getJson($url . '&node_id=' . $node->id . '&token=' . $this->global)->assertOk();
        $node->update(['server_token' => '']);
        $this->getJson($url . '&node_id=' . $node->id . '&token=' . $this->global)->assertOk();
        $this->getJson($url . '&node_id=' . $custom->id . '&token=' . $this->custom)->assertOk();
        foreach ([$this->global, 'another-node-token-1234', 'wrong', ''] as $token) {
            $this->getJson($url . '&node_id=' . $custom->id . '&token=' . $token)->assertStatus(500);
        }
        $this->getJson($url . '&node_id=' . $other->id . '&token=' . $this->custom)->assertStatus(500);
        config(['v2board.server_token' => 'rotated-global-token']);
        $this->getJson($url . '&node_id=' . $custom->id . '&token=' . $this->custom)->assertOk();
        $this->getJson($url . '&node_id=' . $node->id . '&token=' . $this->global)->assertStatus(500);
        $this->getJson($url . '&node_id=' . $node->id . '&token=rotated-global-token')->assertOk();
    }

    public function testLegacyNodeEndpointsAndAliasesHonorOverrides(): void
    {
        foreach (['Deepbwork' => 'vmess', 'ShadowsocksTidalab' => 'shadowsocks', 'TrojanTidalab' => 'trojan'] as $controller => $type) {
            $node = $this->node($type);
            $url = '/api/v1/server/' . $controller . '/user?node_id=' . $node->id;
            $this->getJson($url . '&token=' . $this->global)->assertOk();
            $node->update(['server_token' => $this->custom]);
            $this->getJson($url . '&token=' . $this->global)->assertStatus(500);
            $this->getJson($url . '&token=' . $this->custom)->assertOk();
        }
        $node = $this->node('hysteria', ['server_token' => $this->custom]);
        $this->getJson('/api/v1/server/UniProxy/user?node_type=hysteria2&node_id=' . $node->id . '&token=' . $this->custom)->assertOk();
        $node = $this->node('vmess', ['server_token' => $this->custom]);
        $this->getJson('/api/v1/server/UniProxy/user?node_type=v2ray&node_id=' . $node->id . '&token=' . $this->custom)->assertOk();
    }

    public function testV2NodeConfigAndInstallCommandUseTheSameToken(): void
    {
        $node = $this->node('v2node');
        $url = '/api/v2/server/config?node_id=' . $node->id;
        $this->getJson($url . '&token=' . $this->global)->assertOk()->assertJsonStructure(['server_port', 'base_config']);
        $node->update(['server_token' => $this->custom]);
        $this->getJson($url . '&token=' . $this->global)->assertOk()->assertJsonPath('status', 'fail');
        $this->getJson($url . '&token=' . $this->custom)->assertOk()->assertJsonMissing(['status' => 'fail']);
        $this->getJson($url)->assertOk()->assertJsonPath('message', 'token is null');
        $service = new ServerService();
        $this->assertStringContainsString('--api-key ' . escapeshellarg($this->custom), $service->getAllV2node()[0]['install_command']);
        $node->update(['server_token' => null]);
        $this->assertStringContainsString('--api-key ' . escapeshellarg($this->global), $service->getAllV2node()[0]['install_command']);
        $this->getJson($url . '&token=' . $this->global)->assertOk()->assertJsonStructure(['server_port']);
    }

    public function testTokensAreHiddenFromAllUserFacingNodesAndModelSerialization(): void
    {
        foreach (self::nodeTypes() as [$type]) {
            $node = $this->node($type, ['server_token' => $this->custom]);
            $this->assertArrayNotHasKey('server_token', $node->toArray());
        }
        $user = new User(['group_id' => 1]);
        $nodes = (new ServerService())->getAvailableServers($user);
        $this->assertCount(8, $nodes);
        foreach ($nodes as $node) $this->assertArrayNotHasKey('server_token', $node);
        $this->assertStringNotContainsString($this->custom, json_encode($nodes));
        $this->getJson($this->base . 'server/manage/getNodes')->assertForbidden();
    }

    public function testMigrationCanRunAfterSqlUpdatesWithoutOverwritingTokens(): void
    {
        $node = $this->node('vmess', ['server_token' => $this->custom]);
        (new \AddServerTokenToServerTables())->up();
        $this->assertSame($this->custom, $node->fresh()->server_token);
        $service = new ServerService();
        $this->assertFalse($service->checkServerToken($node, [$this->custom]));
        config(['v2board.server_token' => '']);
        $this->assertFalse($service->checkServerToken([], ''));
    }
}
