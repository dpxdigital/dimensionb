<?php

namespace App\Controllers\Api;

class AppContentController extends BaseApiController
{
    // ── GET /app/legal/{type} ─────────────────────────────────────────────────
    // type: 'privacy-policy' | 'terms'
    // No auth required — public endpoint

    public function legal(string $type): void
    {
        $allowed = ['privacy-policy', 'terms'];
        if (! in_array($type, $allowed, true)) {
            $this->jsonError('Not found.', 404);
            return;
        }

        $db  = db_connect();
        $row = $db->table('app_content')
            ->where('content_key', $type)
            ->get()->getRowArray();

        if (! $row) {
            $this->jsonSuccess([
                'title'      => $this->defaultTitle($type),
                'content'    => '',
                'updated_at' => null,
            ]);
            return;
        }

        $this->jsonSuccess([
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
