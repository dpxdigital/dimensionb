<?php

namespace App\Commands;

use App\Libraries\FCMNotificationService;
use App\Libraries\NotificationHelper;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Cron: 0 8 * * *
 * Sends event reminders for RSVPed listings occurring today.
 */
class SendDeadlineReminders extends BaseCommand
{
    protected $group       = 'Dimensions';
    protected $name        = 'dimensions:deadline-reminders';
    protected $description = 'Send push notifications for listings happening today.';

    public function run(array $params): void
    {
        $db   = db_connect();
        $today = date('Y-m-d');

        // Find listings happening today that have RSVPs
        $rows = $db->table('listing_rsvps lr')
            ->select('lr.user_id, lr.listing_id, l.title, u.pref_event_reminders')
            ->join('listings l', 'l.id = lr.listing_id')
            ->join('users u', 'u.id = lr.user_id')
            ->where('DATE(l.date)', $today)
            ->where('u.pref_event_reminders', 1)
            ->get()->getResultArray();

        if (empty($rows)) {
            CLI::write('No deadline reminders to send.', 'green');
            return;
        }

        $count = 0;
        foreach ($rows as $row) {
            NotificationHelper::createAndSend(
                (int) $row['user_id'],
                'event_reminder',
                'Event Reminder',
                "\"{$row['title']}\" is happening today!",
                (int) $row['listing_id'],
                'listing'
            );
            $count++;
        }

        CLI::write("Sent $count deadline reminder(s).", 'green');
    }
}
