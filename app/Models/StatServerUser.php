<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatServerUser extends Model
{
    protected $table = 'v2_stat_server_user';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = [
        'server_id' => 'integer', 'user_id' => 'integer', 'record_at' => 'integer',
        'u' => 'integer', 'd' => 'integer', 'billed_u' => 'integer', 'billed_d' => 'integer',
    ];
}
