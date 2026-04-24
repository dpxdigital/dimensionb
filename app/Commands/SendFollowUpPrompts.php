<?php

namespace App\Commands;

use App\Libraries\NotificationHelper;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Cron: 0 10 * * *
 * Sends AI-powered follow-through prompts for listings that ended yesterday.
 */
class SendFollowUpPrompts extends BaseCommand
{
    protected $group       = 'Dimensions';
    protected $name        = 'dimensions:follow-up-prompts';
    protected $description = 'Send follow-up prompts for events that happened yesterday.';

    public function run(array $params): void
    {
        $db        = db_connect();
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        // Find RSVPed listings that happened yesterday
        $rows = $db->table('listing_rsvps lr')
            ->select('lr.user_id, lr.listing_id, l.title, u.pref_event_reminders')
            ->join('listings l', 'l.id = lr.listing_id')
            ->join('users u', 'u.id = lr.user_id')
            ->where('DATE(l.date)', $yesterday)
            ->where('u.pref_event_reminders', 1)
            ->get()->getResultArray();

        if (empty($rows)) {
            CLI::write('No follow-up prompts to send.', 'green');
            return;
        }

        $count = 0;
        foreach ($rows as $row) {
            NotificationHelper::createAndSend(
                (int) $row['user_id'],
                'follow_up_prompt',
                'How did it go?',
                "You attended \"{$row['title']}\" yesterday. Share your experience!",
                (int) $row['listing_id'],
                'listing'
            );
            $count++;
        }

        CLI::write("Sent $count follow-up prompt(s).", 'green');
    }
}
