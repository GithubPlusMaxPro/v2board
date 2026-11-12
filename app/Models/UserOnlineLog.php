<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserOnlineLog extends Model
{
    protected $table = 'v2_user_online_log';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['user_id' => 'integer', 'server_id' => 'integer', 'record_at' => 'integer',
        'first_seen' => 'integer', 'last_seen' => 'integer'];
}
