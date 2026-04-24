<?php

namespace App\Libraries;

/**
 * Minimal JWT handler — HS256 only.
 * No external dependency required.
 */
class JwtLibrary
{
    private string $secret;
    private int    $accessExpiry;
    private int    $refreshExpiry;

    public function __construct()
    {
        $this->secret        = env('JWT_SECRET', 'change-me');
        $this->accessExpiry  = (int) env('JWT_ACCESS_EXPIRY', 900);
        $this->refreshExpiry = (int) env('JWT_REFRESH_EXPIRY', 2592000);
    }

    // ── Token generation ──────────────────────────────────────────────────────

    public function generateAccessToken(int $userId): string
    {
        return $this->encode($userId, $this->accessExpiry, 'access');
    }

    public function generateRefreshToken(int $userId): string
    {
        return $this->encode($userId, $this->refreshExpiry, 'refresh');
    }

    // ── Token verification ────────────────────────────────────────────────────

    /**
     * Returns the payload object on success, null on failure.
     */
    public function decode(string $token): ?object
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$b64Header, $b64Payload, $b64Sig] = $parts;

        $expectedSig = $this->sign("{$b64Header}.{$b64Payload}");
        if (! hash_equals($expectedSig, $b64Sig)) {
            return null;
        }

        $payload = json_decode($this->base64UrlDecode($b64Payload));
        if ($payload === null || ! isset($payload->exp)) {
            return null;
        }

        if ($payload->exp < time()) {
            return null; // expired
        }

        return $payload;
    }

    public function decodeRefreshToken(string $token): ?object
    {
        $payload = $this->decode($token);
        if ($payload === null || ($payload->type ?? '') !== 'refresh') {
            return null;
        }

        return $payload;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function encode(int $userId, int $ttl, string $type): string
    {
        $header  = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->base64UrlEncode(json_encode([
            'sub'  => $userId,
            'type' => $type,
            'iat'  => time(),
            'exp'  => time() + $ttl,
        ]));
        $sig = $this->sign("{$header}.{$payload}");

        return "{$header}.{$payload}.{$sig}";
    }

    private function sign(string $data): string
    {
        return $this->base64UrlEncode(
            hash_hmac('sha256', $data, $this->secret, true)
        );
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
