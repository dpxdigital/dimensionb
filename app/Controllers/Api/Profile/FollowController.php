<?php

namespace App\Controllers\Api\Profile;

use App\Controllers\Api\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

class FollowController extends BaseApiController
{
    // GET /v1/users/:id/follow-status
    public function status($id = null): ResponseInterface
    {
        $userId    = $this->authUserId();
        $targetId  = (int) $id;

        if ($userId === $targetId) {
            return $this->success(['is_following' => false]);
        }

        $db = db_connect();
        $exists = $db->table('user_follows')
            ->where('follower_id', $userId)
            ->where('following_id', $targetId)
            ->countAllResults() > 0;

        return $this->success(['is_following' => $exists]);
    }

    // POST /v1/users/:id/follow
    public function follow($id = null): ResponseInterface
    {
        $userId   = $this->authUserId();
        $targetId = (int) $id;

        if ($userId === $targetId) {
            return $this->error('Cannot follow yourself.', 422);
        }

        $db = db_connect();

        // Check target exists
        $target = $db->table('users')->where('id', $targetId)->get()->getRowArray();
        if (! $target) {
            return $this->error('User not found.', 404);
        }

        // Insert ignore duplicate
        $exists = $db->table('user_follows')
            ->where('follower_id', $userId)
            ->where('following_id', $targetId)
            ->countAllResults() > 0;

        if (! $exists) {
            $db->table('user_follows')->insert([
                'follower_id'  => $userId,
                'following_id' => $targetId,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);

            // Update follower/following counts
            $db->query('UPDATE users SET followers_count = followers_count + 1 WHERE id = ?', [$targetId]);
            $db->query('UPDATE users SET following_count = following_count + 1 WHERE id = ?', [$userId]);
        }

        return $this->success(['is_following' => true], 'Now following.');
    }

    // DELETE /v1/users/:id/follow
    public function unfollow($id = null): ResponseInterface
    {
        $userId   = $this->authUserId();
        $targetId = (int) $id;

        $db = db_connect();

        $deleted = $db->table('user_follows')
            ->where('follower_id', $userId)
            ->where('following_id', $targetId)
            ->delete();

        if ($db->affectedRows() > 0) {
            $db->query('UPDATE users SET followers_count = GREATEST(followers_count - 1, 0) WHERE id = ?', [$targetId]);
            $db->query('UPDATE users SET following_count = GREATEST(following_count - 1, 0) WHERE id = ?', [$userId]);
        }

        return $this->success(['is_following' => false], 'Unfollowed.');
    }
}
