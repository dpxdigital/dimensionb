<?php

namespace App\Controllers\Admin;

use CodeIgniter\Controller;

abstract class BaseAdminController extends Controller
{
    protected function adminUser(): array
    {
        return [
            'id'   => session('admin_id'),
            'name' => session('admin_name'),
            'role' => session('admin_role'),
        ];
    }

    protected function audit(string $action, string $referenceType = '', ?int $referenceId = null, string $detail = ''): void
    {
        $adminId = session('admin_id');
        if (! $adminId) {
            return;
        }
        db_connect()->table('admin_audit_log')->insert([
            'admin_id'       => (int) $adminId,
            'action'         => $action,
            'reference_type' => $referenceType ?: null,
            'reference_id'   => $referenceId,
            'detail'         => $detail ?: null,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
    }

    protected function isSuperAdmin(): bool
    {
        return session('admin_role') === 'super_admin';
    }

    protected function renderView(string $view, array $data = []): string
    {
        $data['adminUser'] = $this->adminUser();
        return view($view, $data);
    }

    protected function jsonResponse(mixed $data, int $status = 200): \CodeIgniter\HTTP\Response
    {
        return $this->response->setStatusCode($status)->setJSON($data);
    }
}
