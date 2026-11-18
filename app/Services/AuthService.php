<?php

namespace App\Services;

use App\Utils\CacheKey;
use App\Utils\Helper;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class AuthService
{
    private $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function generateAuthData(Request $request, ?string $twoFactorProof = null, string $scope = 'user')
    {
        $guid = Helper::guid();
        $claims = ['id' => $this->user->id, 'session' => $guid, 'scope' => $scope];
        if ($twoFactorProof !== null) $claims['two_factor'] = $twoFactorProof;
        $authData = JWT::encode($claims, config('app.key'), 'HS256');
        self::addSession($this->user->id, $guid, [
            'scope' => $scope,
            'ip' => $request->ip(),
            'login_at' => time(),
            'ua' => $request->userAgent(),
            'auth_data' => $authData
        ]);
        $activity = $request->attributes->get('account_activity_login');
        if ($activity) {
            $activity->account = [
                'id' => $this->user->id,
                'email' => $this->user->email,
                'session_hash' => hash('sha256', $authData),
            ];
        }
        return [
            'token' => $this->user->token,
            'is_admin' => $scope === 'admin' ? (int)$this->user->is_admin : 0,
            'admin_access' => $scope === 'admin' && $this->user->is_admin ? AdminPermissionService::access($this->user->toArray()) : null,
            'auth_data' => $authData
        ];
    }

    public static function decryptAuthData($jwt, ?string $requiredScope = null)
    {
        try {
            if (!is_string($jwt) || $jwt === '') return false;
            $data = (array)JWT::decode($jwt, new Key(config('app.key'), 'HS256'));
            // Legacy credentials remain user sessions and cannot enter the admin API.
            $scope = $data['scope'] ?? 'user';
            if (!in_array($scope, ['user', 'admin'], true)
                || ($requiredScope !== null && $scope !== $requiredScope)) return false;
            if (!self::checkSession($data['id'], $data['session'])) return false;
            $user = User::select(['id', 'email', 'is_admin', 'is_staff', 'banned', 'admin_permissions'])->find($data['id']);
            if (!$user || $user->banned) return false;
            if ($scope === 'admin' && (!$user->is_admin
                || !(new AdminTwoFactor())->allowsSession($user->id, $data['two_factor'] ?? null))) return false;
            $identity = $user->toArray();
            $identity['auth_scope'] = $scope;
            if ($scope === 'user') {
                $identity['is_admin'] = 0;
                unset($identity['admin_permissions']);
            }
            return $identity;
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function checkSession($userId, $session)
    {
        $sessions = (array)Cache::get(CacheKey::get("USER_SESSIONS", $userId)) ?? [];
        if (!in_array($session, array_keys($sessions))) return false;
        return true;
    }

    private static function addSession($userId, $guid, $meta)
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $userId);
        $sessions = (array)Cache::get($cacheKey, []);
        $sessions[$guid] = $meta;
        if (!Cache::put(
            $cacheKey,
            $sessions
        )) return false;
        return true;
    }

    public function getSessions(?string $scope = null)
    {
        $sessions = (array)Cache::get(CacheKey::get("USER_SESSIONS", $this->user->id), []);
        return $scope === null ? $sessions : array_filter($sessions, function ($session) use ($scope) {
            return ($session['scope'] ?? 'user') === $scope;
        });
    }

    public function removeSession($sessionId, ?string $scope = null)
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $this->user->id);
        $sessions = (array)Cache::get($cacheKey, []);
        if ($scope !== null && ($sessions[$sessionId]['scope'] ?? 'user') !== $scope) return false;
        if (isset($sessions[$sessionId]['auth_data'])) Cache::forget($sessions[$sessionId]['auth_data']);
        unset($sessions[$sessionId]);
        if (!Cache::put(
            $cacheKey,
            $sessions
        )) return false;
        return true;
    }

    public function removeAllSession(?string $scope = null)
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $this->user->id);
        $sessions = (array)Cache::get($cacheKey, []);
        foreach ($sessions as $guid => $meta) {
            if ($scope !== null && ($meta['scope'] ?? 'user') !== $scope) continue;
            unset($sessions[$guid]);
            if (isset($meta['auth_data'])) {
                Cache::forget($meta['auth_data']);
            }
        }
        return $sessions ? Cache::put($cacheKey, $sessions) : Cache::forget($cacheKey);
    }
}
