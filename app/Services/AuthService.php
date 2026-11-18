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

    public function generateAuthData(Request $request, ?string $twoFactorProof = null)
    {
        $guid = Helper::guid();
        $claims = ['id' => $this->user->id, 'session' => $guid];
        if ($twoFactorProof !== null) $claims['two_factor'] = $twoFactorProof;
        $authData = JWT::encode($claims, config('app.key'), 'HS256');
        self::addSession($this->user->id, $guid, [
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
            'is_admin' => $this->user->is_admin,
            'admin_access' => $this->user->is_admin ? AdminPermissionService::access($this->user->toArray()) : null,
            'auth_data' => $authData
        ];
    }

    public static function decryptAuthData($jwt, $fresh = false)
    {
        try {
            if (!is_string($jwt) || $jwt === '') {
                return false;
            }
            if ($fresh) {
                $data = (array)JWT::decode($jwt, new Key(config('app.key'), 'HS256'));
                if (!self::checkSession($data['id'], $data['session'])) return false;
                $user = User::select(['id', 'email', 'is_admin', 'is_staff', 'banned', 'admin_permissions'])->find($data['id']);
                if (!$user || $user->banned) return false;
                if ($user->is_admin && !(new AdminTwoFactor())->allowsSession($user->id, $data['two_factor'] ?? null)) return false;
                return $user->toArray();
            }
            if (!Cache::has($jwt)) {
                $data = (array)JWT::decode($jwt, new Key(config('app.key'), 'HS256'));
                if (!self::checkSession($data['id'], $data['session'])) return false;
                $user = User::select([
                    'id',
                    'email',
                    'is_admin',
                    'is_staff'
                ])
                    ->find($data['id']);
                if (!$user) return false;
                Cache::put($jwt, $user->toArray(), 3600);
            }
            $cached = Cache::get($jwt);
            return !empty($cached['is_admin']) ? self::decryptAuthData($jwt, true) : $cached;
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

    public function getSessions()
    {
        return (array)Cache::get(CacheKey::get("USER_SESSIONS", $this->user->id), []);
    }

    public function removeSession($sessionId)
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $this->user->id);
        $sessions = (array)Cache::get($cacheKey, []);
        unset($sessions[$sessionId]);
        if (!Cache::put(
            $cacheKey,
            $sessions
        )) return false;
        return true;
    }

    public function removeAllSession()
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $this->user->id);
        $sessions = (array)Cache::get($cacheKey, []);
        foreach ($sessions as $guid => $meta) {
            if (isset($meta['auth_data'])) {
                Cache::forget($meta['auth_data']);
            }
        }
        return Cache::forget($cacheKey);
    }
}
