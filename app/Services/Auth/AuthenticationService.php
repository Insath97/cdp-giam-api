<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Audit\AuditLoggerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthenticationService
{
    public function __construct(
        protected AuditLoggerService $auditLogger
    ) {}

    public function login(string $login, string $password, Request $request, bool $remember = false): User
    {
        // 1. Locate user by username or email
        $user = User::where('username', $login)
            ->orWhere('email', $login)
            ->first();

        if (! $user) {
            // Log unauthenticated failed attempt
            $this->auditLogger->log(
                action: 'LOGIN_FAILED',
                entityType: 'User',
                entityId: $login,
                beforeData: null,
                afterData: null,
                status: 'FAILED',
                metadata: ['reason' => 'User not found', 'login_handle' => $login],
                actorUserId: null,
                request: $request
            );

            throw ValidationException::withMessages([
                'login' => ['These credentials do not match our records.'],
            ]);
        }

        // 2. Check Brute-force Lockout
        if ($user->lockout_until && $user->lockout_until->isFuture()) {
            $secondsRemaining = max(1, (int) ceil(now()->diffInSeconds($user->lockout_until)));
            $timeStr = $secondsRemaining >= 60
                ? ceil($secondsRemaining / 60) . ' minute(s)'
                : "{$secondsRemaining} second(s)";

            $this->auditLogger->log(
                action: 'LOGIN_LOCKED',
                entityType: 'User',
                entityId: (string) $user->id,
                beforeData: null,
                afterData: null,
                status: 'WARNING',
                metadata: [
                    'reason' => 'Account locked out',
                    'seconds_remaining' => $secondsRemaining,
                    'lockout_until' => $user->lockout_until->toIso8601String(),
                ],
                actorUserId: $user->id,
                request: $request
            );

            throw new HttpException(
                429,
                "Too many failed login attempts. Please try again in {$timeStr}."
            );
        }

        // 3. Verify Password
        if (! Hash::check($password, $user->password)) {
            $newAttempts = $user->failed_login_attempts + 1;
            $lockoutUntil = null;
            $lockoutSeconds = (int) config('auth.lockout_seconds', 900);

            if ($newAttempts >= 5) {
                $lockoutUntil = now()->addSeconds($lockoutSeconds);
            }

            $user->update([
                'failed_login_attempts' => $newAttempts,
                'lockout_until' => $lockoutUntil,
            ]);

            $this->auditLogger->log(
                action: 'LOGIN_FAILED',
                entityType: 'User',
                entityId: (string) $user->id,
                beforeData: null,
                afterData: null,
                status: 'FAILED',
                metadata: [
                    'reason' => 'Invalid password',
                    'failed_attempts' => $newAttempts,
                    'locked' => $lockoutUntil !== null,
                ],
                actorUserId: $user->id,
                request: $request
            );

            if ($newAttempts >= 5) {
                $timeStr = $lockoutSeconds >= 60
                    ? ceil($lockoutSeconds / 60) . ' minutes'
                    : "{$lockoutSeconds} seconds";

                throw new HttpException(
                    429,
                    "Too many failed login attempts. Please try again in {$timeStr}."
                );
            }

            throw ValidationException::withMessages([
                'login' => ['These credentials do not match our records.'],
            ]);
        }

        // 4. Verify Account Status (is_active and can_login)
        if (! $user->is_active) {
            $this->auditLogger->log(
                action: 'LOGIN_BLOCKED',
                entityType: 'User',
                entityId: (string) $user->id,
                beforeData: null,
                afterData: null,
                status: 'WARNING',
                metadata: ['reason' => 'Account is inactive'],
                actorUserId: $user->id,
                request: $request
            );

            throw new HttpException(403, 'Your account is inactive. Please contact system administration.');
        }

        if (! $user->can_login) {
            $this->auditLogger->log(
                action: 'LOGIN_BLOCKED',
                entityType: 'User',
                entityId: (string) $user->id,
                beforeData: null,
                afterData: null,
                status: 'WARNING',
                metadata: ['reason' => 'Login access is revoked (can_login = 0)'],
                actorUserId: $user->id,
                request: $request
            );

            throw new HttpException(403, 'Your login access is currently disabled. Please contact system administration.');
        }

        // 5. Successful Login
        $user->update([
            'failed_login_attempts' => 0,
            'lockout_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        Auth::login($user, $remember);
        $request->session()->regenerate();

        $this->auditLogger->log(
            action: 'LOGIN_SUCCESS',
            entityType: 'User',
            entityId: (string) $user->id,
            beforeData: null,
            afterData: [
                'last_login_at' => $user->last_login_at->toIso8601String(),
                'last_login_ip' => $user->last_login_ip,
            ],
            status: 'SUCCESS',
            metadata: ['auth_guard' => 'web'],
            actorUserId: $user->id,
            request: $request
        );

        return $user->load(['employee', 'roles', 'permissions']);
    }

    public function logout(Request $request): void
    {
        $user = Auth::user();

        if ($user) {
            $this->auditLogger->log(
                action: 'LOGOUT',
                entityType: 'User',
                entityId: (string) $user->id,
                beforeData: null,
                afterData: null,
                status: 'SUCCESS',
                actorUserId: $user->id,
                request: $request
            );
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
