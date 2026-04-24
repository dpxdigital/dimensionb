<?php

namespace App\Filters;

use App\Libraries\JWTHandler;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $header = $request->getHeaderLine('Authorization');

        if (empty($header) || ! str_starts_with($header, 'Bearer ')) {
            return response()->setStatusCode(401)->setJSON([
                'status'  => 'error',
                'message' => 'Missing or malformed Authorization header',
                'data'    => null,
            ]);
        }

        $token   = substr($header, 7);
        $handler = new JWTHandler();
        $payload = $handler->validateToken($token);

        if ($payload === null) {
            return response()->setStatusCode(401)->setJSON([
                'status'  => 'error',
                'message' => 'Invalid or expired access token',
                'data'    => null,
            ]);
        }

        // Make the authenticated user ID available to every controller
        $request->userId = (int) $payload->sub;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null) {}
}
