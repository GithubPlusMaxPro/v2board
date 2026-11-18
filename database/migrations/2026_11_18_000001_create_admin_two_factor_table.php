<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAdminTwoFactorTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('v2_admin_two_factor')) return;
        Schema::create('v2_admin_two_factor', function (Blueprint $table) {
            $table->integer('user_id')->primary();
            $table->text('secret')->nullable();
            $table->unsignedBigInteger('last_step')->nullable();
            $table->text('recovery_hashes')->nullable();
            $table->boolean('required')->default(true);
            $table->char('revision', 32);
            $table->foreign('user_id')->references('id')->on('v2_user')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('v2_admin_two_factor');
    }
}
