<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UnifyAccountActivityLogs extends Migration
{
    public function up()
    {
        $newColumns = !Schema::hasColumn('v2_log', 'scope');
        if ($newColumns) {
            Schema::table('v2_log', function (Blueprint $table) {
                $table->unsignedBigInteger('source_activity_id')->nullable();
                $table->integer('user_id')->nullable();
                $table->string('email', 254)->nullable();
                $table->char('session_hash', 64)->nullable();
                $table->string('scope', 10)->nullable();
                $table->string('action', 120)->nullable();
                $table->unsignedBigInteger('target_id')->nullable();
                $table->string('user_agent', 1024)->nullable();
                $table->unsignedSmallInteger('status_code')->nullable();
            });
        }
        $this->ensureIndexes($newColumns);

        if (Schema::hasTable('v2_account_activity_log')) {
            DB::table('v2_account_activity_log')->select([
                'id', 'user_id', 'email', 'session_hash', 'scope', 'action', 'method',
                'target_id', 'ip', 'user_agent', 'status_code', 'created_at',
            ])->chunkById(500, function ($rows) {
                $records = $rows->map(function ($row) {
                    $record = (array)$row;
                    $record['source_activity_id'] = $record['id'];
                    $record['title'] = $record['method'] . ' /' . $record['action'];
                    $record['uri'] = '/' . $record['action'];
                    $record['level'] = 'INFO';
                    $record['updated_at'] = $record['created_at'];
                    unset($record['id']);
                    return $record;
                })->all();
                DB::table('v2_log')->upsert($records, ['source_activity_id'], ['source_activity_id']);
            });
        }

        DB::table('v2_log')->whereNull('scope')->where('method', 'POST')
            ->where('title', 'like', 'POST api/v1/%')->select(['id', 'title', 'data'])
            ->chunkById(500, function ($rows) {
                DB::transaction(function () use ($rows) {
                    foreach ($rows as $row) {
                        if (!preg_match('~^POST api/v1/[^/]+/([A-Za-z0-9_./-]{1,120})$~D', $row->title, $path)) continue;
                        $data = json_decode($row->data ?? '', true);
                        $actor = $data['user'] ?? null;
                        $email = $actor['email'] ?? null;
                        DB::table('v2_log')->where('id', $row->id)->update([
                            'scope' => 'legacy',
                            'action' => $path[1],
                            'user_id' => $this->positiveId($actor['id'] ?? null, 2147483647),
                            'email' => is_string($email) && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                            'target_id' => $this->positiveId($data['id'] ?? null, PHP_INT_MAX),
                        ]);
                    }
                });
            });
    }

    public function down()
    {
        // The combined table includes events created after migration. Keep them on rollback.
    }

    private function ensureIndexes(bool $newColumns): void
    {
        $existing = [];
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            $existing = collect(DB::select('SHOW INDEX FROM v2_log'))->pluck('Key_name')->all();
        } elseif ($driver === 'sqlite') {
            $existing = collect(DB::select("PRAGMA index_list('v2_log')"))->pluck('name')->all();
        } elseif (!$newColumns) {
            return;
        }

        $indexes = [
            'log_scope_time' => ['scope', 'created_at', 'id'],
            'log_session_time' => ['session_hash', 'created_at', 'id'],
            'log_user_time' => ['user_id', 'created_at', 'id'],
            'log_created_at' => ['created_at'],
        ];
        Schema::table('v2_log', function (Blueprint $table) use ($existing, $indexes) {
            if (!in_array('log_source_activity', $existing, true)) {
                $table->unique('source_activity_id', 'log_source_activity');
            }
            foreach ($indexes as $name => $columns) {
                if (!in_array($name, $existing, true)) $table->index($columns, $name);
            }
        });
    }

    private function positiveId($value, int $max): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
        return $id === false ? null : $id;
    }
}
