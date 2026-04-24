<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;

/**
 * Base controller for all API endpoints.
 * Provides consistent JSON response helpers and input access.
 */
abstract class BaseApiController extends ResourceController
{
    protected $format = 'json';

    // ── Response helpers ──────────────────────────────────────────────────────

    protected function success(mixed $data = null, string $message = 'OK', int $code = 200, ?array $pagination = null): \CodeIgniter\HTTP\Response
    {
        $body = [
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
        ];
        if ($pagination !== null) {
            $body['pagination'] = $pagination;
        }
        return $this->respond($body, $code);
    }

    protected function error(string $message, int $code = 400, mixed $data = null): \CodeIgniter\HTTP\Response
    {
        return $this->respond([
            'status'  => 'error',
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    protected function validationError(array $errors): \CodeIgniter\HTTP\Response
    {
        return $this->respond([
            'status'  => 'error',
            'message' => 'Validation failed',
            'data'    => null,
            'errors'  => $errors,
        ], 422);
    }

    // ── Input helpers ─────────────────────────────────────────────────────────

    /**
     * Returns JSON body decoded as array.
     * Falls back to POST form data if Content-Type is not JSON.
     */
    protected function inputJson(): array
    {
        $body = $this->request->getJSON(true);
        if (! empty($body)) {
            return $body;
        }
        return $this->request->getPost() ?? [];
    }

    protected function authUserId(): int
    {
        return (int) ($this->request->userId ?? 0);
    }
}
