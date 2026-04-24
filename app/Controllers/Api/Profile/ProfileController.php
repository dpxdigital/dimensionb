<?php

namespace App\Controllers\Api\Profile;

use App\Controllers\Api\BaseApiController;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

class ProfileController extends BaseApiController
{
    // GET /v1/users/:id — public profile
    public function show($id = null): ResponseInterface
    {
        $db   = db_connect();
        $user = $db->table('users')->where('id', (int) $id)->get()->getRowArray();

        if (! $user) {
            return $this->error('User not found.', 404);
        }

        $model    = new UserModel();
        $safe     = $model->safeUser($user);
        $withInt  = $model->withInterests($safe);

        return $this->success([
            'id'              => (string) $withInt['id'],
            'name'            => $withInt['name'],
            'avatar_url'      => $withInt['avatar_url'] ?? null,
            'cover_url'       => $withInt['cover_url'] ?? null,
            'bio'             => $withInt['bio'] ?? null,
            'location'        => $withInt['location'] ?? null,
            'city'            => $withInt['city'] ?? null,
            'interests'       => $withInt['interests'] ?? [],
            'followers_count' => (int) ($withInt['followers_count'] ?? 0),
            'following_count' => (int) ($withInt['following_count'] ?? 0),
            'listings_count'  => (int) ($withInt['listings_count'] ?? 0),
            'trust_level'     => $withInt['trust_level'] ?? null,
            'trust_label'     => $withInt['trust_label'] ?? null,
            'is_vendor'       => (bool) ($withInt['is_vendor'] ?? false),
        ]);
    }

    // POST /v1/users/:id/block
    public function block($id = null): ResponseInterface
    {
        $blockerId = $this->authUserId();
        $blockedId = (int) $id;

        if ($blockerId === $blockedId) {
            return $this->error('Cannot block yourself.', 422);
        }

        $db = db_connect();
        $db->table('connections')->where('requester_id', $blockerId)->where('receiver_id', $blockedId)->delete();
        $db->table('connections')->where('requester_id', $blockedId)->where('receiver_id', $blockerId)->delete();

        $db->table('connections')->insert([
            'requester_id' => $blockerId,
            'receiver_id'  => $blockedId,
            'status'       => 'blocked',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        return $this->success(null, 'User blocked.');
    }
}
