<?php

namespace App\Libraries;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;
use UnexpectedValueException;

/**
 * JWT access + refresh token handler using firebase/php-jwt.
 *
 * Always reads JWT_SECRET, JWT_ACCESS_EXPIRY, JWT_REFRESH_EXPIRY from .env.
 * Never hardcode secrets here.
 */
class JWTHandler
{
    private string $secret;
    private int    $accessExpiry;
    private int    $refreshExpiry;
    private string $algo = 'HS256';

    public function __construct()
    {
        $this->secret        = env('JWT_SECRET') ?: throw new \RuntimeException('JWT_SECRET not set in .env');
        $this->accessExpiry  = (int) (env('JWT_ACCESS_EXPIRY')  ?: 900);
        $this->refreshExpiry = (int) (env('JWT_REFRESH_EXPIRY') ?: 2592000);
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

    // ── Token validation ──────────────────────────────────────────────────────

    /**
     * Returns the decoded payload object on success.
     * Returns null if the token is invalid, expired, or malformed.
     */
    public function validateToken(string $token): ?object
    {
        try {
            $payload = JWT::decode($token, new Key($this->secret, $this->algo));
            return $payload;
        } catch (ExpiredException | SignatureInvalidException | BeforeValidException | UnexpectedValueException) {
            return null;
        }
    }

    /**
     * Same as validateToken but additionally requires type === 'refresh'.
     */
    public function validateRefreshToken(string $token): ?object
    {
        $payload = $this->validateToken($token);
        if ($payload === null || ($payload->type ?? '') !== 'refresh') {
            return null;
        }
        return $payload;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function encode(int $userId, int $ttl, string $type): string
    {
        $now = time();
        return JWT::encode([
            'sub'  => $userId,
            'type' => $type,
            'iat'  => $now,
            'exp'  => $now + $ttl,
        ], $this->secret, $this->algo);
    }
}
