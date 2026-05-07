<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AdminAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();
        $path    = $request->getUri()->getPath();

        // Allow login/logout through without session check
        // Use str_ends_with to handle subdirectory installs (e.g. /api/manager/login)
        $trimmedPath = rtrim($path, '/');
        if (str_ends_with($trimmedPath, '/manager/login') || str_ends_with($trimmedPath, '/manager/logout')) {
            return;
        }

        // Auto-logout after 2 hours of inactivity
        $lastActivity = $session->get('admin_last_activity');
        if ($lastActivity && (time() - $lastActivity) > 7200) {
            $session->destroy();
            return redirect()->to(site_url('manager/login'))->with('error', 'Session expired. Please log in again.');
        }

        if (! $session->get('admin_logged_in')) {
            return redirect()->to(site_url('manager/login'));
        }

        $session->set('admin_last_activity', time());

        // Role-based access control
        $role = $session->get('admin_role');
        if ($role !== 'super_admin' && $arguments !== null) {
            $uri = (string) $request->getUri();

            // Moderators only access moderation + chat
            $allowed = ['/manager/moderation', '/manager/chat'];
            $isAllowed = false;
            foreach ($allowed as $path) {
                if (str_contains($uri, $path)) {
                    $isAllowed = true;
                    break;
                }
            }
            // Also allow /manager dashboard itself
            if (str_ends_with(rtrim($uri, '/'), '/manager')) {
                $isAllowed = true;
            }

            if (! $isAllowed && in_array('super_only', (array) $arguments, true)) {
                return redirect()->to(site_url('manager'))->with('error', 'Access denied.');
            }
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
