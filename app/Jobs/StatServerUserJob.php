<?php

namespace App\Jobs;

use App\Utils\Helper;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class StatServerUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;
    protected $data;
    protected $server;
    protected $protocol;
    protected $recordAt;
    protected $reportId;

    public function __construct(array $data, array $server, string $protocol, int $receivedAt)
    {
        $this->onQueue('stat');
        $this->data = $data;
        $this->server = $server;
        $this->protocol = $protocol;
        // Capture the reporting day before queueing; retries and midnight delays keep this day.
        $this->recordAt = CarbonImmutable::createFromTimestamp($receivedAt, config('app.timezone'))->startOfDay()->getTimestamp();
        $this->reportId = Helper::guid(true);
    }

    public function handle()
    {
        $rate = $this->server['rate'] ?? null;
        if (!is_numeric($rate) || !is_finite((float)$rate) || $rate < 0) throw new \InvalidArgumentException('Invalid node rate');
        $rows = [];
        $now = time();
        ksort($this->data, SORT_NUMERIC);
        foreach ($this->data as $userId => $traffic) {
            if (!ctype_digit((string)$userId) || $userId < 1 || !is_array($traffic)) {
                throw new \InvalidArgumentException('Invalid user traffic');
            }
            $u = filter_var($traffic[0] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $d = filter_var($traffic[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($u === false || $d === false || $u * $rate > PHP_INT_MAX || $d * $rate > PHP_INT_MAX) {
                throw new \InvalidArgumentException('Invalid traffic counters');
            }
            if ($u === 0 && $d === 0) continue;
            $rows[] = [
                'record_at' => $this->recordAt, 'server_type' => $this->protocol,
                'server_id' => $this->server['id'], 'user_id' => (int)$userId,
                'server_name' => mb_substr((string)($this->server['name'] ?? ''), 0, 255),
                'u' => $u, 'd' => $d,
                // Use the same per-report integer truncation as the existing Redis accounting.
                'billed_u' => (int)($u * $rate), 'billed_d' => (int)($d * $rate),
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        if (!$rows) return;
        DB::transaction(function () use ($rows, $now) {
            // The marker and all counter increments commit together, even after a worker crash.
            $inserted = DB::table('v2_stat_server_user_batch')->insertOrIgnore([
                'id' => $this->reportId, 'record_at' => $this->recordAt, 'created_at' => $now,
            ]);
            if (!$inserted) return;
            $grammar = DB::connection()->getQueryGrammar();
            $updates = ['server_name', 'updated_at'];
            foreach (['u', 'd', 'billed_u', 'billed_d'] as $column) {
                $incoming = DB::connection()->getDriverName() === 'mysql'
                    ? 'VALUES(' . $grammar->wrap($column) . ')'
                    : $grammar->wrap('excluded.' . $column);
                $updates[$column] = DB::raw($grammar->wrap('v2_stat_server_user.' . $column) . ' + ' . $incoming);
            }
            foreach (array_chunk($rows, 50) as $chunk) {
                DB::table('v2_stat_server_user')->upsert($chunk,
                    ['record_at', 'server_type', 'server_id', 'user_id'], $updates);
            }
        }, 3);
    }
}
