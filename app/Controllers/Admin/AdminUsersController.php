<?php

namespace App\Controllers\Admin;

class AdminUsersController extends BaseAdminController
{
    // GET /manager/admin-users
    public function index()
    {
        if (! $this->isSuperAdmin()) {
            return redirect()->to('/manager')->with('error', 'Access denied.');
        }

        $db         = db_connect();
        $adminUsers = $db->table('admin_users')
            ->select('id, name, email, role, is_active, last_login, created_at')
            ->orderBy('created_at', 'DESC')
            ->get()->getResultArray();

        return $this->renderView('admin/admin_users/index', [
            'pageTitle'  => 'Admin Users',
            'adminUsers' => $adminUsers,
        ]);
    }

    // GET /manager/admin-users/create
    public function create()
    {
        if (! $this->isSuperAdmin()) {
            return redirect()->to('/manager')->with('error', 'Access denied.');
        }

        return $this->renderView('admin/admin_users/create', [
            'pageTitle' => 'Create Admin User',
        ]);
    }

    // POST /manager/admin-users/create
    public function store()
    {
        if (! $this->isSuperAdmin()) {
            return redirect()->to('/manager')->with('error', 'Access denied.');
        }

        $name     = trim($this->request->getPost('name') ?? '');
        $email    = trim($this->request->getPost('email') ?? '');
        $password = $this->request->getPost('password') ?? '';
        $role     = $this->request->getPost('role') ?? '';

        $errors = [];
        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email is required.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if (! in_array($role, ['super_admin', 'moderator'], true)) {
            $errors[] = 'Role must be super_admin or moderator.';
        }

        if ($errors) {
            return redirect()->back()->withInput()->with('error', implode(' ', $errors));
        }

        $db = db_connect();

        // Check duplicate email
        if ($db->table('admin_users')->where('email', $email)->countAllResults() > 0) {
            return redirect()->back()->withInput()->with('error', 'An admin user with that email already exists.');
        }

        $db->table('admin_users')->insert([
            'name'       => $name,
            'email'      => $email,
            'password'   => password_hash($password, PASSWORD_BCRYPT),
            'role'       => $role,
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $newId = $db->insertID();
        $this->audit('admin_user_created', 'admin_user', (int) $newId, "{$name} ({$role})");

        return redirect()->to('/manager/admin-users')->with('success', "Admin user '{$name}' created successfully.");
    }

    // POST /manager/admin-users/:id/toggle
    public function toggleStatus(int $id)
    {
        if (! $this->isSuperAdmin()) {
            return $this->jsonResponse(['error' => 'Access denied'], 403);
        }

        $currentAdminId = (int) session('admin_id');
        if ($id === $currentAdminId) {
            return $this->jsonResponse(['error' => 'You cannot toggle your own account.'], 400);
        }

        $db        = db_connect();
        $adminUser = $db->table('admin_users')->where('id', $id)->get()->getRowArray();

        if (! $adminUser) {
            return $this->jsonResponse(['error' => 'Admin user not found'], 404);
        }

        $newActive = $adminUser['is_active'] ? 0 : 1;
        $db->table('admin_users')->where('id', $id)->update(['is_active' => $newActive]);

        $this->audit(
            $newActive ? 'admin_user_activated' : 'admin_user_deactivated',
            'admin_user',
            $id,
            $adminUser['name']
        );

        return $this->jsonResponse(['status' => $newActive ? 'active' : 'inactive']);
    }

    // POST /manager/admin-users/:id/delete
    public function delete(int $id)
    {
        if (! $this->isSuperAdmin()) {
            return redirect()->to('/manager')->with('error', 'Access denied.');
        }

        $currentAdminId = (int) session('admin_id');
        if ($id === $currentAdminId) {
            return redirect()->back()->with('error', 'You cannot delete your own account.');
        }

        $db        = db_connect();
        $adminUser = $db->table('admin_users')->where('id', $id)->get()->getRowArray();

        if (! $adminUser) {
            return redirect()->back()->with('error', 'Admin user not found.');
        }

        $db->table('admin_users')->where('id', $id)->delete();

        $this->audit('admin_user_deleted', 'admin_user', $id, $adminUser['name']);

        return redirect()->to('/manager/admin-users')->with('success', "Admin user '{$adminUser['name']}' deleted.");
    }
}
