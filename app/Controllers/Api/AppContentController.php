<?php

namespace App\Controllers\Api;

class AppContentController extends BaseApiController
{
    // ── GET /app/legal/{type} ─────────────────────────────────────────────────
    // type: 'privacy-policy' | 'terms'
    // No auth required — public endpoint

    public function legal(string $type): \CodeIgniter\HTTP\ResponseInterface
    {
        $allowed = ['privacy-policy', 'terms'];
        if (! in_array($type, $allowed, true)) {
            return $this->error('Not found.', 404);
        }

        $db  = db_connect();
        $row = $db->table('app_content')
            ->where('content_key', $type)
            ->get()->getRowArray();

        if (! $row) {
            return $this->success([
                'title'      => $this->defaultTitle($type),
                'content'    => '',
                'updated_at' => null,
            ]);
        }

        return $this->success([
            'title'      => $row['title'] ?? $this->defaultTitle($type),
            'content'    => $row['content'] ?? '',
            'updated_at' => $row['updated_at'],
        ]);
    }

    private function defaultTitle(string $type): string
    {
        return match ($type) {
            'privacy-policy' => 'Privacy Policy',
            'terms'          => 'Terms and Conditions',
            default          => $type,
        };
    }
}
