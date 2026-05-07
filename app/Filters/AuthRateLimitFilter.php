<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Strict rate limit for authentication endpoints: 10 attempts per 15 minutes per IP.
 * Prevents brute-force attacks on login, register, and password reset.
 */
class AuthRateLimitFilter implements FilterInterface
{
    private const LIMIT  = 10;
    private const WINDOW = 900; // 15 minutes

    public function before(RequestInterface $request, $arguments = null)
    {
        $ip       = $request->getIPAddress();
        $cacheKey = 'auth_rl_' . md5($ip);
        $cache    = \Config\Services::cache();
        $hits     = (int) ($cache->get($cacheKey) ?? 0);

        if ($hits >= self::LIMIT) {
            return response()->setStatusCode(429)->setJSON([
                'status'  => 'error',
                'message' => 'Too many attempts. Please wait 15 minutes before trying again.',
                'data'    => null,
            ])->setHeader('Retry-After', (string) self::WINDOW)
              ->setHeader('X-RateLimit-Limit', (string) self::LIMIT)
              ->setHeader('X-RateLimit-Remaining', '0');
        }

        if ($hits === 0) {
            $cache->save($cacheKey, 1, self::WINDOW);
        } else {
            $remaining = $cache->getMetaData($cacheKey)['expire'] ?? (time() + self::WINDOW);
            $ttl       = max(1, (int) ($remaining - time()));
            $cache->save($cacheKey, $hits + 1, $ttl);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null) {}
}
