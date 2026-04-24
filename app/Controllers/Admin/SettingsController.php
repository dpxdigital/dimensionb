<?php

namespace App\Controllers\Admin;

class SettingsController extends BaseAdminController
{
    public function index()
    {
        if (! $this->isSuperAdmin()) {
            return redirect()->to('/manager')->with('error', 'Access denied.');
        }

        $db         = db_connect();
        $categories = $db->table('categories')->orderBy('display_order')->get()->getResultArray();

        // Read last cron run times from cache or a simple log file
        $cronStatus = [
            'deadline_reminders' => $this->getCronLastRun('dimensions:deadline-reminders'),
            'followup_prompts'   => $this->getCronLastRun('dimensions:follow-up-prompts'),
        ];

        return $this->renderView('admin/settings/index', compact('categories', 'cronStatus'));
    }

    // ── Category management ───────────────────────────────────────────────────

    public function saveCategory()
    {
        if (! $this->isSuperAdmin()) {
            return $this->jsonResponse(['error' => 'Access denied.'], 403);
        }

        $id       = (int) ($this->request->getPost('id') ?? 0);
        $name     = trim($this->request->getPost('name') ?? '');
        $slug     = trim($this->request->getPost('slug') ?? '');
        $iconName = trim($this->request->getPost('icon_name') ?? '');
        $isActive = (int) (bool) $this->request->getPost('is_active');

        if (empty($name) || empty($slug)) {
            return $this->jsonResponse(['error' => 'Name and slug are required.'], 422);
        }

        $db = db_connect();
        if ($id > 0) {
            $db->table('categories')->where('id', $id)->update([
                'name'      => $name,
                'slug'      => $slug,
                'icon_name' => $iconName ?: null,
                'is_active' => $isActive,
            ]);
            $this->audit('category_updated', 'category', $id, $name);
        } else {
            $db->table('categories')->insert([
                'name'      => $name,
                'slug'      => $slug,
                'icon_name' => $iconName ?: null,
                'is_active' => 1,
            ]);
            $id = (int) $db->insertID();
            $this->audit('category_created', 'category', $id, $name);
        }

        return $this->jsonResponse(['success' => true, 'id' => $id]);
    }

    // ── JWT rotation ──────────────────────────────────────────────────────────

    public function rotateJwt()
    {
        if (! $this->isSuperAdmin()) {
            return $this->jsonResponse(['error' => 'Access denied.'], 403);
        }

        $newSecret = bin2hex(random_bytes(32));

        // Update .env file
        $envPath = ROOTPATH . '.env';
        if (file_exists($envPath)) {
            $content = file_get_contents($envPath);
            $content = preg_replace('/^JWT_SECRET\s*=.*/m', "JWT_SECRET = {$newSecret}", $content);
            if (! str_contains($content, 'JWT_SECRET')) {
                $content .= "\nJWT_SECRET = {$newSecret}";
            }
            file_put_contents($envPath, $content);
        }

        $this->audit('jwt_secret_rotated', 'settings');

        return $this->jsonResponse(['success' => true, 'preview' => substr($newSecret, 0, 8) . '…']);
    }

    // ── Env variables (read-only, no secrets) ────────────────────────────────

    public function envVars()
    {
        if (! $this->isSuperAdmin()) {
            return $this->jsonResponse(['error' => 'Access denied.'], 403);
        }

        $safe = [
            'CI_ENVIRONMENT' => env('CI_ENVIRONMENT', 'unknown'),
            'app.baseURL'    => env('app.baseURL', 'not set'),
            'DB_DRIVER'      => env('database.default.DBDriver', 'unknown'),
            'DB_HOST'        => env('database.default.hostname', 'unknown'),
            'DB_NAME'        => env('database.default.database', 'unknown'),
        ];

        return $this->jsonResponse($safe);
    }

    private function getCronLastRun(string $command): string
    {
        $cacheKey = 'cron_last_run_' . md5($command);
        return \Config\Services::cache()->get($cacheKey) ?? 'Never';
    }
}
