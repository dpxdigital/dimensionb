<?php

namespace App\Controllers\Admin;

class AppContentController extends BaseAdminController
{
    private const PAGES = [
        'privacy-policy' => 'Privacy Policy',
        'terms'          => 'Terms and Conditions',
    ];

    // ── GET /manager/app-content ──────────────────────────────────────────────

    public function index()
    {
        $db    = db_connect();
        $rows  = $db->table('app_content')->get()->getResultArray();
        $pages = [];
        foreach ($rows as $row) {
            $pages[$row['content_key']] = $row;
        }

        return $this->renderView('admin/app_content/index', [
            'pages'    => self::PAGES,
            'content'  => $pages,
        ]);
    }

    // ── GET /manager/app-content/{key}/edit ───────────────────────────────────

    public function edit(string $key)
    {
        if (! array_key_exists($key, self::PAGES)) {
            return redirect()->to('/manager/app-content')->with('error', 'Page not found.');
        }

        $db  = db_connect();
        $row = $db->table('app_content')->where('content_key', $key)->get()->getRowArray();

        return $this->renderView('admin/app_content/edit', [
            'key'     => $key,
            'title'   => self::PAGES[$key],
            'content' => $row['content'] ?? '',
        ]);
    }

    // ── POST /manager/app-content/{key}/save ──────────────────────────────────

    public function save(string $key)
    {
        if (! array_key_exists($key, self::PAGES)) {
            return redirect()->to('/manager/app-content')->with('error', 'Page not found.');
        }

        $content = trim($this->request->getPost('content') ?? '');
        $title   = trim($this->request->getPost('title') ?? self::PAGES[$key]);
        $now     = date('Y-m-d H:i:s');
        $db      = db_connect();

        $exists = $db->table('app_content')->where('content_key', $key)->countAllResults();

        if ($exists) {
            $db->table('app_content')->where('content_key', $key)->update([
                'title'      => $title,
                'content'    => $content,
                'updated_at' => $now,
            ]);
        } else {
            $db->table('app_content')->insert([
                'content_key' => $key,
                'title'       => $title,
                'content'     => $content,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        $this->audit('app_content_updated', 'app_content', 0, $title);

        return redirect()->to('/manager/app-content')->with('success', self::PAGES[$key] . ' updated.');
    }
}
