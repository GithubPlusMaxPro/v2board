<?php
namespace App\Logging;

use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use App\Models\Log as LogModel;

class MysqlLoggerHandler extends AbstractProcessingHandler
{
    public function __construct($level = Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(array $record): void
    {
        $request = request();
        $activityPath = $request->attributes->get('account_activity_path');
        try {
            $context = $record['context'] ?? [];
            $title = $record['message'];
            if (isset($context['exception']) && is_object($context['exception'])) {
                if ($activityPath) {
                    $title = get_class($context['exception']);
                    $context = [];
                } else {
                    $context['exception'] = (array)$context['exception'];
                }
            }

            LogModel::insert([
                'title' => $title,
                'level' => $record['level_name'],
                'host' => $record['request_host'] ?? $request->getSchemeAndHttpHost(),
                'uri' => $activityPath ?? $record['request_uri'] ?? $request->getRequestUri(),
                'method' => $record['request_method'] ?? $request->getMethod(),
                'ip' => $request->getClientIp(),
                'data' => $activityPath ? null : json_encode($request->all()),
                'context' => json_encode($context),
                'created_at' => $record['datetime']->getTimestamp(),
                'updated_at' => $record['datetime']->getTimestamp(),
            ]);
        } catch (\Exception $e) {
            Log::channel('daily')->error('Could not save system log', ['exception_class' => get_class($e)]);
        }
    }
}
