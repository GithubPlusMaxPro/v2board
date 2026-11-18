<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAccountActivityLogTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('v2_account_activity_log') || Schema::hasColumn('v2_log', 'scope')) return;
        Schema::create('v2_account_activity_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id')->nullable();
            $table->string('email', 254)->nullable();
            $table->char('session_hash', 64)->nullable();
            $table->string('scope', 10);
            $table->string('action', 120);
            $table->string('method', 10);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('ip', 45);
            $table->string('user_agent', 1024);
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('created_at');
            $table->index(['email', 'id'], 'activity_log_email');
            $table->index(['user_id', 'id'], 'activity_log_user');
            $table->index(['session_hash', 'id'], 'activity_log_session');
            $table->index(['scope', 'id'], 'activity_log_scope');
            $table->index('created_at', 'activity_log_created');
        });
    }

    public function down()
    {
        Schema::dropIfExists('v2_account_activity_log');
    }
}
