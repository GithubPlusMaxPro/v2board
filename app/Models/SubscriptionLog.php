<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionLog extends Model
{
    protected $table = 'v2_subscription_log';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['user_id' => 'integer', 'created_at' => 'integer', 'status_code' => 'integer'];
}
