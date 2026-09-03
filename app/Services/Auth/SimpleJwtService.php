<?php

namespace App\Services\Auth;

use App\Models\User;

class SimpleJwtService
{
    /**
     * Generate a signed HS256 JWT for the GIAM Principal.
     */
    public static function generateToken(User $user, int $ttlSeconds = 86400): string
    {
        $header = self::base64UrlEncode(json_encode([
            'alg' => 'HS256',
            'typ' => 'JWT',
        ]));

        $payload = self::base64UrlEncode(json_encode([
            'iss' => config('app.url', 'http://127.0.0.1:8000'),
            'sub' => $user->id,
            'username' => $user->username,
            'employee_code' => $user->employee_code,
            'iat' => time(),
            'exp' => time() + $ttlSeconds,
        ]));

        $secret = config('app.key');
        $signature = self::base64UrlEncode(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));

        return "{$header}.{$payload}.{$signature}";
    }

    /**
     * Validate and decode a signed HS256 JWT. Returns User ID if valid.
     */
    public static function validateToken(string $token): ?int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;

        $secret = config('app.key');
        $expectedSig = self::base64UrlEncode(hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $secret, true));

        if (! hash_equals($expectedSig, $sigB64)) {
            return null;
        }

        $payload = json_decode(self::base64UrlDecode($payloadB64), true);
        if (! $payload || ! isset($payload['sub'], $payload['exp'])) {
            return null;
        }

        if (time() > $payload['exp']) {
            return null;
        }

        return (int) $payload['sub'];
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
    }
}
