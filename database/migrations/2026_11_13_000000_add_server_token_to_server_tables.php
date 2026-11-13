<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddServerTokenToServerTables extends Migration
{
    private $tables = [
        'v2_server_vmess', 'v2_server_vless', 'v2_server_trojan', 'v2_server_shadowsocks',
        'v2_server_tuic', 'v2_server_hysteria', 'v2_server_anytls', 'v2_server_v2node',
    ];

    public function up()
    {
        foreach ($this->tables as $name) {
            if (!Schema::hasTable($name) || Schema::hasColumn($name, 'server_token')) continue;
            Schema::table($name, function (Blueprint $table) {
                $table->string('server_token', 255)->nullable()
                    ->comment('节点通讯密钥，留空使用全局密钥');
            });
        }
    }

    public function down()
    {
        foreach ($this->tables as $name) {
            if (!Schema::hasTable($name) || !Schema::hasColumn($name, 'server_token')) continue;
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('server_token');
            });
        }
    }
}
