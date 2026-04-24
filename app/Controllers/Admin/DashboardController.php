<?php

namespace App\Controllers\Admin;

class DashboardController extends BaseAdminController
{
    public function index()
    {
        $db    = db_connect();
        $today = date('Y-m-d');

        $stats = [
            'total_users'         => $db->table('users')->countAllResults(),
            'total_listings'      => $db->table('listings')->countAllResults(),
            'active_live'         => $db->table('live_sessions')->where('status', 'active')->countAllResults(),
            'total_conversations' => $db->table('conversations')->countAllResults(),
            'new_users_today'     => $db->table('users')->where('DATE(created_at)', $today)->countAllResults(),
            'pending_submissions' => $db->table('submissions')->where('status', 'pending')->countAllResults(),
            'pending_reports'     => $db->table('moderation_queue')->where('status', 'pending')->countAllResults(),
            'total_messages'      => $db->table('messages')->countAllResults(),
        ];

        // Recent activity: last 20 audit log entries
        $recentActivity = $db->table('admin_audit_log aal')
            ->select('aal.*, au.name AS admin_name')
            ->join('admin_users au', 'au.id = aal.admin_id', 'left')
            ->orderBy('aal.created_at', 'DESC')
            ->limit(20)
            ->get()->getResultArray();

        // New users last 7 days
        $newUsersChart = [];
        for ($i = 6; $i >= 0; $i--) {
            $date  = date('Y-m-d', strtotime("-{$i} days"));
            $count = $db->table('users')->where('DATE(created_at)', $date)->countAllResults();
            $newUsersChart[] = ['date' => date('M j', strtotime($date)), 'count' => $count];
        }

        return $this->renderView('admin/dashboard/index', compact('stats', 'recentActivity', 'newUsersChart'));
    }
}
