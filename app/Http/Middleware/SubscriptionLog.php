<?php

namespace App\Http\Middleware;

use App\Models\SubscriptionLog as SubscriptionLogModel;
use Closure;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SubscriptionLog
{
    public function handle($request, Closure $next, $action)
    {
        $createdAt = time();
        $status = 500;
        try {
            $response = $next($request);
            $status = $response->getStatusCode();
            return $response;
        } catch (\Throwable $e) {
            if ($e instanceof HttpExceptionInterface) $status = $e->getStatusCode();
            throw $e;
        } finally {
            // Authentication runs first, so records are always linked to a verified user.
            $user = $request->input('user');
            if (isset($user['id'])) {
                try {
                    SubscriptionLogModel::create([
                        'user_id' => $user['id'],
                        'action' => $action,
                        'ip' => $request->ip() ?? '',
                        'user_agent' => mb_substr(mb_convert_encoding((string)$request->userAgent(), 'UTF-8', 'UTF-8'), 0, 1024),
                        'status_code' => $status,
                        'created_at' => $createdAt,
                    ]);
                } catch (\Throwable $e) {
                    // Logging must not break subscriptions or expose tokens through SQL errors.
                    try {
                        Log::channel('daily')->warning('Subscription history could not be saved', [
                            'user_id' => $user['id'], 'action' => $action,
                        ]);
                    } catch (\Throwable $ignored) {
                    }
                }
            }
        }
    }
}
