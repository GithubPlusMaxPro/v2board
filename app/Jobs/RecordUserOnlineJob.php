<?php

namespace App\Jobs;

use App\Services\UserOnlineService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class RecordUserOnlineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;
    protected $rows;

    public function __construct(array $data, array $server, string $type, int $observedAt)
    {
        $this->onQueue('stat');
        $this->rows = [];
        $day = CarbonImmutable::createFromTimestamp($observedAt, config('app.timezone'))->startOfDay()->getTimestamp();
        ksort($data, SORT_NUMERIC);
        foreach ($data as $userId => $ips) {
            if (!ctype_digit((string)$userId) || $userId < 1 || !is_array($ips)) continue;
            $ips = UserOnlineService::normalizeIps($ips); sort($ips);
            foreach ($ips as $ip) {
                $this->rows[] = ['user_id' => (int)$userId, 'record_at' => $day, 'server_type' => $type,
                    'server_id' => $server['id'], 'server_name' => mb_substr((string)($server['name'] ?? ''), 0, 255),
                    'ip' => $ip, 'first_seen' => $observedAt, 'last_seen' => $observedAt];
            }
        }
    }

    public function handle()
    {
        if (!$this->rows) return;
        DB::transaction(function () {
            $grammar = DB::connection()->getQueryGrammar();
            $mysql = DB::connection()->getDriverName() === 'mysql';
            $updates = ['server_name'];
            foreach (['first_seen', 'last_seen'] as $column) {
                $incoming = $mysql ? 'VALUES(' . $grammar->wrap($column) . ')' : $grammar->wrap('excluded.' . $column);
                $function = $column === 'first_seen' ? ($mysql ? 'LEAST' : 'MIN') : ($mysql ? 'GREATEST' : 'MAX');
                $updates[$column] = DB::raw($function . '(' . $grammar->wrap('v2_user_online_log.' . $column) . ', ' . $incoming . ')');
            }
            foreach (array_chunk($this->rows, 50) as $chunk) {
                DB::table('v2_user_online_log')->upsert($chunk, ['record_at', 'user_id', 'server_type', 'server_id', 'ip'], $updates);
            }
        }, 3);
    }
}
