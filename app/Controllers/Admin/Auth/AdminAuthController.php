<?php

namespace App\Controllers\Admin\Auth;

use App\Controllers\Admin\BaseAdminController;

class AdminAuthController extends BaseAdminController
{
    public function loginForm()
    {
        if (session('admin_logged_in')) {
            return redirect()->to('/manager');
        }
        return view('admin/auth/login', ['error' => session()->getFlashdata('error')]);
    }

    public function login()
    {
        $email    = trim($this->request->getPost('email') ?? '');
        $password = $this->request->getPost('password') ?? '';

        if (empty($email) || empty($password)) {
            return redirect()->back()->with('error', 'Email and password are required.');
        }

        $admin = db_connect()->table('admin_users')
            ->where('email', $email)
            ->where('is_active', 1)
            ->get()->getRowArray();

        if ($admin === null || ! password_verify($password, $admin['password'])) {
            // Audit failed attempt
            db_connect()->table('admin_audit_log')->insert([
                'admin_id'   => 0,
                'action'     => 'login_failed',
                'detail'     => "Failed login attempt for: {$email}",
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return redirect()->back()->with('error', 'Invalid credentials.');
        }

        session()->set([
            'admin_logged_in'    => true,
            'admin_id'           => $admin['id'],
            'admin_name'         => $admin['name'],
            'admin_role'         => $admin['role'],
            'admin_last_activity' => time(),
        ]);

        db_connect()->table('admin_users')
            ->where('id', $admin['id'])
            ->update(['last_login' => date('Y-m-d H:i:s')]);

        $this->audit('login');

        return redirect()->to('/manager');
    }

    public function logout()
    {
        $this->audit('logout');
        session()->destroy();
        return redirect()->to('/manager/login');
    }
}
