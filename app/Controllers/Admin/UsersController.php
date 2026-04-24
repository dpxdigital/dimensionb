<?php

namespace App\Controllers\Admin;

class UsersController extends BaseAdminController
{
    private const PER_PAGE = 25;

    public function index()
    {
        $db     = db_connect();
        $search = trim($this->request->getGet('q') ?? '');
        $page   = max(1, (int) ($this->request->getGet('page') ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $query = $db->table('users u')
            ->select('u.id, u.name, u.email, u.phone, u.location, u.is_active, u.created_at,
                      (SELECT COUNT(*) FROM listing_saves WHERE user_id = u.id) AS save_count,
                      (SELECT COUNT(*) FROM listing_rsvps WHERE user_id = u.id) AS rsvp_count,
                      (SELECT COUNT(*) FROM submissions WHERE user_id = u.id) AS submission_count');

        if ($search !== '') {
            $query->groupStart()
                  ->like('u.name', $search)
                  ->orLike('u.email', $search)
                  ->groupEnd();
        }

        $total   = $db->table('users u');
        if ($search !== '') {
            $total->groupStart()->like('name', $search)->orLike('email', $search)->groupEnd();
        }
        $total = $total->countAllResults();

        $users = $query->orderBy('u.created_at', 'DESC')->limit(self::PER_PAGE, $offset)->get()->getResultArray();

        $lastPage = (int) ceil($total / self::PER_PAGE);

        return $this->renderView('admin/users/index', compact('users', 'total', 'page', 'lastPage', 'search'));
    }

    public function show($id)
    {
        $db   = db_connect();
        $user = $db->table('users u')
            ->select('u.*')
            ->where('u.id', (int) $id)
            ->get()->getRowArray();

        if (! $user) {
            return redirect()->to('/manager/users')->with('error', 'User not found.');
        }

        $interests   = $db->table('user_interests ui')->join('categories c', 'c.id = ui.category_id')->select('c.name')->where('ui.user_id', $id)->get()->getResultArray();
        $saved       = $db->table('listing_saves ls')->join('listings l', 'l.id = ls.listing_id')->select('l.id, l.title, ls.created_at')->where('ls.user_id', $id)->limit(10)->get()->getResultArray();
        $rsvps       = $db->table('listing_rsvps lr')->join('listings l', 'l.id = lr.listing_id')->select('l.id, l.title, lr.created_at')->where('lr.user_id', $id)->limit(10)->get()->getResultArray();
        $submissions = $db->table('submissions')->where('user_id', $id)->orderBy('created_at', 'DESC')->limit(10)->get()->getResultArray();

        return $this->renderView('admin/users/show', compact('user', 'interests', 'saved', 'rsvps', 'submissions'));
    }

    public function toggleStatus($id)
    {
        $db   = db_connect();
        $user = $db->table('users')->select('id, name, is_active')->where('id', (int) $id)->get()->getRowArray();

        if (! $user) {
            return $this->jsonResponse(['error' => 'User not found.'], 404);
        }

        $newStatus = $user['is_active'] ? 0 : 1;
        $db->table('users')->where('id', (int) $id)->update(['is_active' => $newStatus]);

        $action = $newStatus ? 'user_activated' : 'user_suspended';
        $this->audit($action, 'user', (int) $id, $user['name']);

        return $this->jsonResponse(['status' => $newStatus ? 'active' : 'suspended']);
    }

    public function delete($id)
    {
        $db   = db_connect();
        $user = $db->table('users')->select('id, name, email')->where('id', (int) $id)->get()->getRowArray();

        if (! $user) {
            return redirect()->to('/manager/users')->with('error', 'User not found.');
        }

        if (! $this->isSuperAdmin()) {
            return redirect()->to('/manager/users')->with('error', 'Access denied.');
        }

        $db->table('users')->where('id', (int) $id)->delete();
        $this->audit('user_deleted', 'user', (int) $id, "{$user['name']} ({$user['email']})");

        return redirect()->to('/manager/users')->with('success', "User \"{$user['name']}\" deleted.");
    }
}
