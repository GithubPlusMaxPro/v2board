<?php

namespace App\Http\Middleware;

use App\Models\Log as LogModel;
use App\Services\AccountActivityDetails;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AccountActivity
{
    private const USER_READS = [
        'getSubscribe', 'resetSecurity', 'getQuickLoginUrl', 'unbindTelegram',
    ];

    public function handle(Request $request, Closure $next, string $scope)
    {
        $action = explode('/', $request->route()->uri(), 4)[3];
        $request->attributes->set('account_activity_path', '/' . $action);
        $method = $request->method();
        if ($method === 'HEAD' || ($scope === 'admin' && strpos($action, 'accountActivity/') === 0)
            || ($scope === 'user' && $method === 'GET' && !in_array($action, self::USER_READS, true))
            || ($action === 'auth/token2Login' && (!$request->input('verify') || $request->input('token')))) {
            return $next($request);
        }

        $login = null;
        if ($scope === 'login') {
            // The login FormRequest copies attributes and shares this context object.
            $login = new \stdClass();
            $request->attributes->set('account_activity_login', $login);
        }
        $details = [];
        $configAudit = null;
        if ($scope !== 'login' && $method === 'POST') {
            $details = AccountActivityDetails::fields($request->all());
            if ($scope === 'admin' && $action === 'config/save') {
                // The FormRequest and the middleware share this object.
                $configAudit = new \stdClass();
                $request->attributes->set('account_activity_details', $configAudit);
            }
        }
        $user = $login ? null : $request->input('user');
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        $target = $scope === 'admin' ? $request->input('id') : null;
        $targetId = filter_var($target, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $createdAt = time();
        $status = 500;
        try {
            $response = $next($request);
            $status = $response->getStatusCode();
            return $response;
        } catch (Throwable $e) {
            if ($e instanceof HttpExceptionInterface) $status = $e->getStatusCode();
            throw $e;
        } finally {
            try {
                if ($login) $user = $login->account ?? null;
                $email = $user['email'] ?? ($scope === 'login' && $action === 'auth/login' ? $request->input('email') : null);
                if (isset($configAudit->changes)) $details['changes'] = (object)$configAudit->changes;
                if ($scope === 'admin' && $action === 'server/route/save') {
                    $route = $request->attributes->get('account_activity_route');
                    if (is_array($route)) {
                        $details = $route + $details;
                        $targetId = $route['id'];
                    }
                }
                LogModel::create([
                    'data' => $details ? json_encode($details) : null,
                    'title' => $method . ' /' . $action,
                    'uri' => '/' . $action,
                    'level' => 'INFO',
                    'updated_at' => $createdAt,
                    'user_id' => $user['id'] ?? null,
                    'email' => is_string($email) && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                    'session_hash' => $scope === 'login' ? ($user['session_hash'] ?? null)
                        : (is_string($authorization) ? hash('sha256', $authorization) : null),
                    'scope' => $scope,
                    'action' => $action,
                    'method' => $method,
                    'target_id' => $targetId === false ? null : $targetId,
                    'ip' => $request->ip() ?? '',
                    'user_agent' => mb_substr(mb_convert_encoding((string)$request->userAgent(), 'UTF-8', 'UTF-8'), 0, 1024),
                    'status_code' => $status,
                    'created_at' => $createdAt,
                ]);
            } catch (Throwable $e) {
                try {
                    Log::channel('daily')->warning('Account activity could not be saved', [
                        'scope' => $scope,
                        'action' => $action,
                    ]);
                } catch (Throwable $ignored) {
                }
            }
        }
    }
}
