<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSubscriptionLogTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('v2_subscription_log')) return;
        Schema::create('v2_subscription_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id');
            $table->string('action', 16);
            $table->string('ip', 45);
            $table->string('user_agent', 1024)->default('');
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('created_at');
            $table->index(['user_id', 'id'], 'subscription_log_user_id');
            $table->index('created_at', 'subscription_log_created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('v2_subscription_log');
    }
}
