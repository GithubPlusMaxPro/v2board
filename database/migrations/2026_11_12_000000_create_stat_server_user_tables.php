<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStatServerUserTables extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('v2_stat_server_user')) {
            Schema::create('v2_stat_server_user', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('record_at');
                $table->string('server_type', 16);
                $table->integer('server_id');
                $table->string('server_name', 255)->default('');
                $table->integer('user_id');
                foreach (['u', 'd', 'billed_u', 'billed_d'] as $column) $table->unsignedBigInteger($column)->default(0);
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
                $table->unique(['record_at', 'server_type', 'server_id', 'user_id'], 'stat_server_user_day_node_user');
                $table->index(['user_id', 'record_at'], 'stat_server_user_user_day');
                $table->index(['server_type', 'server_id', 'record_at'], 'stat_server_user_node_day');
            });
        }
        if (!Schema::hasTable('v2_stat_server_user_batch')) {
            Schema::create('v2_stat_server_user_batch', function (Blueprint $table) {
                $table->char('id', 36)->primary();
                $table->unsignedInteger('record_at')->index();
                $table->unsignedInteger('created_at');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('v2_stat_server_user_batch');
        Schema::dropIfExists('v2_stat_server_user');
    }
}
