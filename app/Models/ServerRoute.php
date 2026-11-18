<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerRoute extends Model
{
    public const ACTIONS = ['block', 'block_ip', 'block_port', 'protocol', 'dns', 'route', 'route_ip', 'default_out'];

    protected $table = 'v2_server_route';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
