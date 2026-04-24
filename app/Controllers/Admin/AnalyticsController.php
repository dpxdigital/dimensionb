<?php

namespace App\Controllers\Admin;

class AnalyticsController extends BaseAdminController
{
    public function index()
    {
        $db = db_connect();

        // New users per day — last 30 days
        $usersPerDay = [];
        for ($i = 29; $i >= 0; $i--) {
            $date  = date('Y-m-d', strtotime("-{$i} days"));
            $count = $db->table('users')->where('DATE(created_at)', $date)->countAllResults();
            $usersPerDay[] = ['date' => date('M j', strtotime($date)), 'count' => $count];
        }

        // Most popular categories (by listing count)
        $popularCategories = $db->table('listings l')
            ->select('c.name, COUNT(l.id) AS listing_count')
            ->join('categories c', 'c.id = l.category_id', 'left')
            ->groupBy('l.category_id')
            ->orderBy('listing_count', 'DESC')
            ->limit(10)
            ->get()->getResultArray();

        // Most saved listings
        $mostSaved = $db->table('listing_saves ls')
            ->select('l.id, l.title, COUNT(ls.id) AS save_count')
            ->join('listings l', 'l.id = ls.listing_id', 'left')
            ->groupBy('ls.listing_id')
            ->orderBy('save_count', 'DESC')
            ->limit(10)
            ->get()->getResultArray();

        // Most RSVPed listings
        $mostRsvped = $db->table('listing_rsvps lr')
            ->select('l.id, l.title, COUNT(lr.id) AS rsvp_count')
            ->join('listings l', 'l.id = lr.listing_id', 'left')
            ->groupBy('lr.listing_id')
            ->orderBy('rsvp_count', 'DESC')
            ->limit(10)
            ->get()->getResultArray();

        // Live sessions per day — last 30 days
        $livePerDay = [];
        for ($i = 29; $i >= 0; $i--) {
            $date  = date('Y-m-d', strtotime("-{$i} days"));
            $count = $db->table('live_sessions')->where('DATE(started_at)', $date)->countAllResults();
            $livePerDay[] = ['date' => date('M j', strtotime($date)), 'count' => $count];
        }

        // Messages sent per day — last 30 days
        $messagesPerDay = [];
        for ($i = 29; $i >= 0; $i--) {
            $date  = date('Y-m-d', strtotime("-{$i} days"));
            $count = $db->table('messages')->where('DATE(created_at)', $date)->countAllResults();
            $messagesPerDay[] = ['date' => date('M j', strtotime($date)), 'count' => $count];
        }

        // Connection requests per day — last 30 days
        $connectionsPerDay = [];
        for ($i = 29; $i >= 0; $i--) {
            $date  = date('Y-m-d', strtotime("-{$i} days"));
            $count = $db->table('connections')->where('DATE(created_at)', $date)->countAllResults();
            $connectionsPerDay[] = ['date' => date('M j', strtotime($date)), 'count' => $count];
        }

        return $this->renderView('admin/analytics/index', compact(
            'usersPerDay', 'popularCategories', 'mostSaved', 'mostRsvped',
            'livePerDay', 'messagesPerDay', 'connectionsPerDay'
        ));
    }
}
