<?php

namespace App\Services;

use App\Models\User;
use App\Utils\Helper;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AdminTwoFactor
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function enabled(int $userId): bool
    {
        return $this->factor($userId)->whereNotNull('secret')->exists();
    }

    public function status(int $userId): array
    {
        $factor = $this->factor($userId)->first(['secret', 'required']);
        return ['enabled' => isset($factor->secret), 'required' => (bool)($factor->required ?? true)];
    }

    public function allowsSession(int $userId, ?string $proof): bool
    {
        $factor = $this->factor($userId)->first(['secret', 'required']);
        return isset($factor->secret)
            ? $proof !== null && hash_equals(hash('sha256', $factor->secret), $proof)
            : !(bool)($factor->required ?? true);
    }

    public function login(Request $request, User $verified): array
    {
        abort_unless($verified->is_admin, 403, '请使用管理员账号登录后台。');

        return DB::transaction(function () use ($request, $verified) {
            $user = User::lockForUpdate()->find($verified->id);
            abort_if(!$user || !$user->is_admin || $user->banned || $user->password !== $verified->password, 422, '请重新登录。');
            $factor = $this->factor($user->id)->first();
            $proof = null;
            if (isset($factor->secret)) {
                $this->verify($user->id, $factor, $request->input('otp'));
                $proof = hash('sha256', $factor->secret);
            } elseif ($user->is_admin && ($factor->required ?? true)) {
                return ['two_factor_setup_required' => true] + $this->prepare($user, '', 'enrollment', $factor);
            }
            return (new AuthService($user))->generateAuthData($request, $proof, 'admin');
        });
    }

    public function begin(User $actor, User $target, string $password, ?string $operatorCode, string $session): array
    {
        $factor = $this->factor($target->id)->first(['secret', 'required', 'revision']);
        $delegated = $actor->id !== $target->id;
        abort_if(!$delegated && isset($factor->secret), 422, '两步验证已开启。');
        $this->confirmOperator($actor, $password, $operatorCode);
        return $this->prepare($target, $session, $delegated ? 'delegated' : 'self', $factor);
    }

    private function prepare(User $target, string $session, string $mode, ?object $factor): array
    {
        $secret = '';
        foreach (unpack('C*', random_bytes(32)) as $byte) {
            $secret .= self::ALPHABET[$byte & 31];
        }
        return [
            'secret' => $secret,
            'required' => (bool)($factor->required ?? true),
            'setup' => Crypt::encryptString(json_encode([
                'id' => $target->id, 'session' => $session, 'secret' => $secret, 'mode' => $mode,
                'expires' => time() + 600, 'password' => hash('sha256', $target->password),
                'revision' => $factor->revision ?? null,
            ])),
        ];
    }

    public function enroll(string $setup, string $code): array
    {
        $pending = $this->decodeSetup($setup);
        abort_unless(($pending['mode'] ?? '') === 'enrollment', 422, '设置已失效，请重新登录。');
        return DB::transaction(function () use ($pending, $code) {
            $target = User::lockForUpdate()->find($pending['id'] ?? 0);
            abort_unless($target && $target->is_admin && !$target->banned, 422, '设置已失效，请重新登录。');
            return [
                'user' => $target,
                'recovery_codes' => $this->bind($target, $pending, $code, '', 'enrollment', true),
            ];
        });
    }

    public function enable(User $actor, User $target, string $setup, string $code, string $session, bool $required): array
    {
        $mode = $actor->id === $target->id ? 'self' : 'delegated';
        return $this->bind($target, $this->decodeSetup($setup), $code, $session, $mode, $required);
    }

    private function decodeSetup(string $setup): array
    {
        try {
            $pending = json_decode(Crypt::decryptString($setup), true);
        } catch (DecryptException $e) {
            abort(422, '设置已失效，请重新开始。');
        }
        abort_unless(is_array($pending), 422, '设置已失效，请重新开始。');
        return $pending;
    }

    private function bind(User $target, array $pending, string $code, string $session, string $mode, bool $required): array
    {
        $factor = $this->factor($target->id)->first(['secret', 'required', 'revision']);
        abort_unless(($pending['id'] ?? null) === $target->id
            && ($pending['session'] ?? null) === $session && ($pending['expires'] ?? 0) >= time()
            && ($pending['password'] ?? null) === hash('sha256', $target->password)
            && ($pending['mode'] ?? null) === $mode
            && ($pending['revision'] ?? null) === ($factor->revision ?? null),
            422, '设置已失效，请重新开始。');
        $this->checkLimit($target->id, 'setup');
        $step = $this->matchingStep($pending['secret'], $code);
        abort_if($step === null, 422, '验证码有误或已过期。');

        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = bin2hex(random_bytes(10));
        }
        DB::table('v2_admin_two_factor')->updateOrInsert(['user_id' => $target->id], [
            'secret' => Crypt::encryptString($pending['secret']),
            'last_step' => $step,
            'recovery_hashes' => json_encode(array_map([$this, 'recoveryHash'], $codes)),
            'required' => $mode === 'delegated' ? $required : (bool)($factor->required ?? true),
            'revision' => bin2hex(random_bytes(16)),
        ]);
        app(RateLimiter::class)->clear('admin-2fa:setup:' . $target->id);
        (new AuthService($target))->removeAllSession('admin');
        return $codes;
    }

    public function disable(User $user, string $password, string $code): void
    {
        $factor = $this->factor($user->id)->first();
        abort_unless(isset($factor->secret), 422, '两步验证尚未开启。');
        abort_if($factor->required, 422, '两步验证由超级管理员设为必需。');
        $this->verifyPassword($user, $password);
        $this->verify($user->id, $factor, $code);
        $this->reset($user, false);
    }

    public function changePolicy(User $user, bool $required): array
    {
        DB::table('v2_admin_two_factor')->updateOrInsert(['user_id' => $user->id], [
            'required' => $required, 'revision' => bin2hex(random_bytes(16)),
        ]);
        if ($required && !$this->enabled($user->id)) (new AuthService($user))->removeAllSession('admin');
        return $this->status($user->id);
    }

    public function reset(User $user, bool $required): array
    {
        DB::table('v2_admin_two_factor')->updateOrInsert(['user_id' => $user->id], [
            'secret' => null, 'last_step' => null, 'recovery_hashes' => null, 'required' => $required,
            'revision' => bin2hex(random_bytes(16)),
        ]);
        (new AuthService($user))->removeAllSession('admin');
        return ['enabled' => false, 'required' => $required];
    }

    public function confirmOperator(User $actor, string $password, ?string $code): void
    {
        $this->verifyPassword($actor, $password);
        $factor = $this->factor($actor->id)->first();
        if (isset($factor->secret)) $this->verify($actor->id, $factor, $code);
    }

    private function factor(int $userId)
    {
        return DB::table('v2_admin_two_factor')->where('user_id', $userId);
    }

    private function verifyPassword(User $user, string $password): void
    {
        $this->checkLimit($user->id, 'password');
        abort_unless(Helper::multiPasswordVerify($user->password_algo, $user->password_salt, $password, $user->password),
            422, '当前密码有误。');
        app(RateLimiter::class)->clear('admin-2fa:password:' . $user->id);
    }

    private function verify(int $userId, object $factor, ?string $code): void
    {
        abort_if(!$code, 422, '请输入两步验证码或恢复码。');
        $this->checkLimit($userId, 'verify');
        $step = $this->matchingStep(Crypt::decryptString($factor->secret), $code);
        if ($step !== null && $step > $factor->last_step) {
            $this->factor($userId)->update(['last_step' => $step]);
        } else {
            $hashes = json_decode($factor->recovery_hashes, true);
            $index = array_search($this->recoveryHash(strtolower($code)), $hashes, true);
            abort_if($index === false, 422, '验证码或恢复码有误、已过期或已使用。');
            unset($hashes[$index]);
            $this->factor($userId)->update(['recovery_hashes' => json_encode(array_values($hashes))]);
        }
        app(RateLimiter::class)->clear('admin-2fa:verify:' . $userId);
    }

    private function checkLimit(int $userId, string $action): void
    {
        $key = 'admin-2fa:' . $action . ':' . $userId;
        $limiter = app(RateLimiter::class);
        abort_if($limiter->tooManyAttempts($key, 5), 429, '验证尝试次数过多，请稍后重试。');
        $limiter->hit($key, 300);
    }

    private function recoveryHash(string $code): string
    {
        return hash('sha256', $code);
    }

    private function matchingStep(string $secret, string $code): ?int
    {
        if (!preg_match('/^\d{6}$/D', $code)) return null;
        $key = '';
        $bits = 0;
        $buffer = 0;
        foreach (str_split($secret) as $character) {
            $buffer = ($buffer << 5) | strpos(self::ALPHABET, $character);
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $key .= chr($buffer >> $bits);
                $buffer &= (1 << $bits) - 1;
            }
        }
        $now = intdiv(time(), 30);
        foreach ([$now, $now - 1, $now + 1] as $step) {
            $hash = hash_hmac('sha1', pack('N2', intdiv($step, 4294967296), $step % 4294967296), $key, true);
            $offset = ord($hash[19]) & 15;
            $number = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
            if (hash_equals(sprintf('%06d', $number % 1000000), $code)) return $step;
        }
        return null;
    }
}
