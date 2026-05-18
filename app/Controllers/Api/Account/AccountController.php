<?php

namespace App\Controllers\Api\Account;

use App\Controllers\Api\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

class AccountController extends BaseApiController
{
    // ── POST /v1/account/data/delete ─────────────────────────────────────────
    // Body: { "types": ["posts","saved_listings","rsvps",...] }

    public function deleteData(): ResponseInterface
    {
        $userId = $this->authUserId();
        $input  = $this->inputJson();
        $types  = $input['types'] ?? [];

        $allowed = [
            'posts', 'saved_listings', 'rsvps', 'activity',
            'notifications', 'submissions',
        ];

        if (empty($types) || !is_array($types)) {
            return $this->error('No data types selected.', 400);
        }

        $types = array_intersect($types, $allowed);
        if (empty($types)) {
            return $this->error('Invalid data types.', 400);
        }

        $db      = \Config\Database::connect();
        $deleted = [];

        foreach ($types as $type) {
            switch ($type) {
                case 'posts':
                    $db->table('posts')
                        ->where('user_id', $userId)
                        ->delete();
                    $deleted[] = 'posts';
                    break;

                case 'saved_listings':
                    $db->table('listing_saves')
                        ->where('user_id', $userId)
                        ->delete();
                    $deleted[] = 'saved_listings';
                    break;

                case 'rsvps':
                    $db->table('listing_rsvps')
                        ->where('user_id', $userId)
                        ->delete();
                    $deleted[] = 'rsvps';
                    break;

                case 'activity':
                    $db->table('activity_log')
                        ->where('user_id', $userId)
                        ->delete();
                    $deleted[] = 'activity';
                    break;

                case 'notifications':
                    $db->table('notifications')
                        ->where('user_id', $userId)
                        ->delete();
                    $deleted[] = 'notifications';
                    break;

                case 'submissions':
                    // Only delete pending/rejected — approved submissions stay as listings
                    $db->table('submissions')
                        ->where('user_id', $userId)
                        ->whereIn('status', ['pending', 'rejected'])
                        ->delete();
                    $deleted[] = 'submissions';
                    break;
            }
        }

        return $this->success(
            ['deleted' => $deleted],
            'Selected data has been permanently deleted.'
        );
    }
}
