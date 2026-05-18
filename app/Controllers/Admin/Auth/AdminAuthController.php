<?php

namespace App\Controllers\Admin\Auth;

use App\Controllers\Admin\BaseAdminController;

class AdminAuthController extends BaseAdminController
{
    public function loginForm()
    {
        if (session('admin_logged_in')) {
            return redirect()->to(site_url('manager'));
        }
        return view('admin/auth/login', ['error' => session()->getFlashdata('error')]);
    }

    public function login()
    {
        $email    = trim($this->request->getPost('email') ?? '');
        $password = $this->request->getPost('password') ?? '';

        if (empty($email) || empty($password)) {
            return redirect()->to(site_url('manager/login'))->with('error', 'Email and password are required.');
        }

        // Brute-force protection: 5 failed attempts per IP per 15 minutes
        $cache      = \Config\Services::cache();
        $ip         = $this->request->getIPAddress();
        $lockKey    = 'admin_fail_' . md5($ip);
        $attempts   = (int) ($cache->get($lockKey) ?? 0);

        if ($attempts >= 5) {
            return redirect()->to(site_url('manager/login'))
                ->with('error', 'Too many failed attempts. Please wait 15 minutes before trying again.');
        }

        $admin = db_connect()->table('admin_users')
            ->where('email', $email)
            ->where('is_active', 1)
            ->get()->getRowArray();

        if ($admin === null || ! password_verify($password, $admin['password'])) {
            // Increment failure counter (TTL 15 min)
            $ttl = 900;
            $meta = $cache->getMetaData($lockKey);
            if ($meta && isset($meta['expire'])) {
                $ttl = max(1, (int)($meta['expire'] - time()));
            }
            $cache->save($lockKey, $attempts + 1, $ttl);

            try {
                db_connect()->table('admin_audit_log')->insert([
                    'admin_id'   => null,
                    'action'     => 'login_failed',
                    'detail'     => "Failed login attempt for: {$email} from IP: {$ip}",
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $_) {}
            return redirect()->to(site_url('manager/login'))->with('error', 'Invalid credentials.');
        }

        // Clear failure counter on successful login
        $cache->delete($lockKey);

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

        return redirect()->to(site_url('manager'));
    }

    public function logout()
    {
        $this->audit('logout');
        session()->destroy();
        return redirect()->to(site_url('manager/login'));
    }
}
