<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserOnlineLogTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('v2_user_online_log')) return;
        Schema::create('v2_user_online_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id');
            $table->unsignedInteger('record_at');
            $table->string('server_type', 16);
            $table->integer('server_id');
            $table->string('server_name', 255)->default('');
            $table->string('ip', 45);
            $table->unsignedInteger('first_seen');
            $table->unsignedInteger('last_seen');
            $table->unique(['record_at', 'user_id', 'server_type', 'server_id', 'ip'], 'user_online_day_user_node_ip');
            $table->index(['user_id', 'last_seen'], 'user_online_user_last_seen');
            $table->index(['user_id', 'ip'], 'user_online_user_ip');
        });
    }

    public function down()
    {
        Schema::dropIfExists('v2_user_online_log');
    }
}
