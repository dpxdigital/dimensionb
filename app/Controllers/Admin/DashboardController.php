<?php

namespace App\Controllers\Admin;

class DashboardController extends BaseAdminController
{
    public function index()
    {
        $db = db_connect();

        $pendingSubmissions = $db->table('submissions')->where('status', 'pending')->countAllResults();
        $pendingReports     = $db->table('moderation_queue')->where('status', 'pending')->countAllResults();

        $stats = [
            'total_users'        => $db->table('users')->countAllResults(),
            'active_listings'    => $db->table('listings')->where('status', 'active')->countAllResults(),
            'pending_moderation' => $pendingSubmissions + $pendingReports,
            'live_today'         => $db->table('live_sessions')->where('DATE(started_at)', date('Y-m-d'))->countAllResults(),
            'new_users_week'     => $db->table('users')->where('created_at >=', 'DATE_SUB(NOW(), INTERVAL 7 DAY)', false)->countAllResults(),
            'messages_24h'       => $db->table('messages')->where('created_at >=', 'DATE_SUB(NOW(), INTERVAL 24 HOUR)', false)->countAllResults(),
            'total_rsvps'        => $db->table('listing_rsvps')->countAllResults(),
            'submissions_week'   => $db->table('submissions')->where('created_at >=', 'DATE_SUB(NOW(), INTERVAL 7 DAY)', false)->countAllResults(),
        ];

        // Recent audit log entries
        $recentAudit = $db->table('admin_audit_log aal')
            ->select('aal.*, au.name AS admin_name')
            ->join('admin_users au', 'au.id = aal.admin_id', 'left')
            ->orderBy('aal.created_at', 'DESC')
            ->limit(20)
            ->get()->getResultArray();

        // New users last 7 days (for chart)
        $newUsersChart = [];
        for ($i = 6; $i >= 0; $i--) {
            $date  = date('Y-m-d', strtotime("-{$i} days"));
            $count = $db->table('users')->where('DATE(created_at)', $date)->countAllResults();
            $newUsersChart[] = ['date' => date('M j', strtotime($date)), 'count' => $count];
        }

        return $this->renderView('admin/dashboard/index', compact('stats', 'recentAudit', 'newUsersChart'));
    }
}
