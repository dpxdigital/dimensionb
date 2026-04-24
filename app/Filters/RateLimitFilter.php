<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Rate limit: 60 requests per minute per authenticated user (falls back to IP).
 * Uses CI4 cache (Redis if configured, file cache otherwise).
 */
class RateLimitFilter implements FilterInterface
{
    private const LIMIT  = 60;
    private const WINDOW = 60; // seconds

    public function before(RequestInterface $request, $arguments = null)
    {
        // Key on user_id when available, otherwise on IP
        $identity = $request->userId ?? $request->getIPAddress();
        $cacheKey = 'rate_limit_' . md5((string) $identity);

        $cache = \Config\Services::cache();
        $hits  = (int) ($cache->get($cacheKey) ?? 0);

        if ($hits >= self::LIMIT) {
            return response()->setStatusCode(429)->setJSON([
                'status'  => 'error',
                'message' => 'Too many requests. Please slow down.',
                'data'    => null,
            ])->setHeader('Retry-After', (string) self::WINDOW)
              ->setHeader('X-RateLimit-Limit', (string) self::LIMIT)
              ->setHeader('X-RateLimit-Remaining', '0');
        }

        // Increment; set TTL only on first hit (preserves the window start)
        if ($hits === 0) {
            $cache->save($cacheKey, 1, self::WINDOW);
        } else {
            // Decrement TTL: get remaining TTL, then re-save
            $remaining = $cache->getMetaData($cacheKey)['expire'] ?? (time() + self::WINDOW);
            $ttl       = max(1, (int) ($remaining - time()));
            $cache->save($cacheKey, $hits + 1, $ttl);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Optionally inject X-RateLimit headers into every response
        $identity = $request->userId ?? $request->getIPAddress();
        $cacheKey = 'rate_limit_' . md5((string) $identity);
        $cache    = \Config\Services::cache();
        $hits     = (int) ($cache->get($cacheKey) ?? 0);
        $remaining = max(0, self::LIMIT - $hits);

        $response->setHeader('X-RateLimit-Limit', (string) self::LIMIT);
        $response->setHeader('X-RateLimit-Remaining', (string) $remaining);
    }
}
