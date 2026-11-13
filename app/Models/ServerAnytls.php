<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerAnytls extends Model
{
    protected $table = 'v2_server_anytls';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['server_token'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'group_id' => 'array',
        'route_id' => 'array',
        'padding_scheme' => 'array',
        'tags' => 'array'
    ];
}
