<?php

namespace App\Controllers\Api\Chat;

use App\Controllers\Api\BaseApiController;
use App\Libraries\FCMNotificationService;
use App\Models\ConnectionModel;
use App\Models\ConversationModel;
use CodeIgniter\HTTP\ResponseInterface;

class GroupController extends BaseApiController
{
    private ConversationModel $conversations;
    private ConnectionModel   $connections;

    public function __construct()
    {
        $this->conversations = new ConversationModel();
        $this->connections   = new ConnectionModel();
    }

    // ── POST /v1/chat/group ───────────────────────────────────────────────────

    public function create(): ResponseInterface
    {
        $userId = $this->authUserId();
        $input  = $this->inputJson();

        $rules = [
            'name'       => 'required|max_length[50]',
            'member_ids' => 'required',
        ];
        if (! $this->validateData($input, $rules)) {
            return $this->validationError($this->validator->getErrors());
        }

        $memberIds = array_map('intval', (array) $input['member_ids']);
        $memberIds = array_unique(array_filter($memberIds, fn($id) => $id !== $userId));

        if (count($memberIds) < 1) {
            return $this->error('At least 1 other member is required.', 422);
        }
        if (count($memberIds) > 49) {
            return $this->error('Maximum 49 other members (50 total including you).', 422);
        }

        // Verify all member IDs are valid users (connection not required for groups)
        $db = db_connect();
        foreach ($memberIds as $memberId) {
            $exists = $db->table('users')->where('id', $memberId)->where('is_active', 1)->countAllResults();
            if (! $exists) {
                return $this->error("User #{$memberId} not found.", 422);
            }
        }

        $db     = db_connect();
        $now    = date('Y-m-d H:i:s');
        $avatar = $input['avatar_url'] ?? null;

        $db->table('conversations')->insert([
            'type'            => 'group',
            'created_by'      => $userId,
            'name'            => trim($input['name']),
            'avatar_url'      => $avatar,
            'last_message_at' => $now,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        $convId = (int) $db->insertID();

        // Insert creator as admin
        $memberRows = [
            ['conversation_id' => $convId, 'user_id' => $userId, 'is_admin' => 1, 'joined_at' => $now],
        ];
        foreach ($memberIds as $mid) {
            $memberRows[] = ['conversation_id' => $convId, 'user_id' => $mid, 'is_admin' => 0, 'joined_at' => $now];
        }
        $db->table('conversation_members')->insertBatch($memberRows);

        // Get creator name for system message
        $creatorName = $db->table('users')->select('name')->where('id', $userId)->get()->getRowArray()['name'] ?? 'Someone';

        $db->table('messages')->insert([
            'conversation_id' => $convId,
            'sender_id'       => $userId,
            'type'            => 'system',
            'body'            => "{$creatorName} created the group",
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        // Notify all members
        $fcm = new FCMNotificationService();
        foreach ($memberIds as $mid) {
            $fcm->notifyAddedToGroup($mid, $convId, trim($input['name']));
        }

        $conv    = $this->conversations->find($convId);
        $members = $this->conversations->getMembersOf($convId);

        return $this->success(
            $this->formatGroupWithMembers($conv, $members),
            'Group created',
            201
        );
    }

    // ── PUT /v1/chat/group/:id ────────────────────────────────────────────────

    public function update($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $convId = (int) $id;

        if (! $this->conversations->isAdmin($convId, $userId)) {
            return $this->error('Only the group admin can update the group.', 403);
        }

        $input = $this->inputJson();
        $rules = [
            'name'       => 'permit_empty|max_length[50]',
            'avatar_url' => 'permit_empty|valid_url|max_length[500]',
        ];
        if (! $this->validateData($input, $rules)) {
            return $this->validationError($this->validator->getErrors());
        }

        $update = array_filter([
            'name'       => isset($input['name'])       ? trim($input['name'])       : null,
            'avatar_url' => isset($input['avatar_url']) ? $input['avatar_url']       : null,
        ], fn($v) => $v !== null);

        if (! empty($update)) {
            $this->conversations->update($convId, $update);
        }

        $conv    = $this->conversations->find($convId);
        $members = $this->conversations->getMembersOf($convId);

        return $this->success($this->formatGroupWithMembers($conv, $members));
    }

    // ── POST /v1/chat/group/:id/members ───────────────────────────────────────

    public function addMembers($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $convId = (int) $id;

        if (! $this->conversations->isAdmin($convId, $userId)) {
            return $this->error('Only the group admin can add members.', 403);
        }

        $input     = $this->inputJson();
        $newIds    = array_map('intval', (array) ($input['member_ids'] ?? []));
        $newIds    = array_unique(array_filter($newIds, fn($mid) => $mid > 0));

        if (empty($newIds)) {
            return $this->error('member_ids is required.', 422);
        }

        $db           = db_connect();
        $currentCount = $db->table('conversation_members')
            ->where('conversation_id', $convId)->countAllResults();

        if ($currentCount + count($newIds) > 50) {
            return $this->error('Adding these members would exceed the 50-member limit.', 422);
        }

        foreach ($newIds as $mid) {
            if (! $this->connections->isConnected($userId, $mid)) {
                return $this->error("You must be connected with user #{$mid}.", 422);
            }
        }

        $now        = date('Y-m-d H:i:s');
        $adminName  = $db->table('users')->select('name')->where('id', $userId)->get()->getRowArray()['name'] ?? 'Admin';
        $rows       = [];
        $convName   = $this->conversations->find($convId)['name'] ?? 'the group';

        foreach ($newIds as $mid) {
            // Skip if already a member
            $alreadyIn = $db->table('conversation_members')
                ->where('conversation_id', $convId)
                ->where('user_id', $mid)
                ->countAllResults() > 0;
            if ($alreadyIn) {
                continue;
            }

            $rows[] = ['conversation_id' => $convId, 'user_id' => $mid, 'is_admin' => 0, 'joined_at' => $now];

            $memberName = $db->table('users')->select('name')->where('id', $mid)->get()->getRowArray()['name'] ?? "User #{$mid}";
            $db->table('messages')->insert([
                'conversation_id' => $convId,
                'sender_id'       => $userId,
                'type'            => 'system',
                'body'            => "{$adminName} added {$memberName}",
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }

        if (! empty($rows)) {
            $db->table('conversation_members')->insertBatch($rows);
        }

        // Notify new members
        $fcm = new FCMNotificationService();
        foreach ($newIds as $mid) {
            $fcm->notifyAddedToGroup($mid, $convId, $convName);
        }

        return $this->success(null, 'Members added');
    }

    // ── DELETE /v1/chat/group/:id/members/:userId ─────────────────────────────

    public function removeMember($id = null, $targetUserId = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $convId = (int) $id;
        $target = (int) $targetUserId;

        if (! $this->conversations->isAdmin($convId, $userId)) {
            return $this->error('Only the group admin can remove members.', 403);
        }

        // Cannot remove yourself via this endpoint
        if ($target === $userId) {
            return $this->error('Use the leave endpoint to leave the group.', 422);
        }

        $db         = db_connect();
        $adminName  = $db->table('users')->select('name')->where('id', $userId)->get()->getRowArray()['name'] ?? 'Admin';
        $targetName = $db->table('users')->select('name')->where('id', $target)->get()->getRowArray()['name'] ?? "User #{$target}";

        $db->table('conversation_members')
            ->where('conversation_id', $convId)
            ->where('user_id', $target)
            ->delete();

        $db->table('messages')->insert([
            'conversation_id' => $convId,
            'sender_id'       => $userId,
            'type'            => 'system',
            'body'            => "{$adminName} removed {$targetName}",
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        return $this->success(null, 'Member removed');
    }

    // ── DELETE /v1/chat/group/:id/leave ───────────────────────────────────────

    public function leave($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $convId = (int) $id;

        if (! $this->conversations->isMember($convId, $userId)) {
            return $this->error('You are not a member of this group.', 404);
        }

        $db           = db_connect();
        $memberCount  = $db->table('conversation_members')
            ->where('conversation_id', $convId)->countAllResults();
        $isAdmin      = $this->conversations->isAdmin($convId, $userId);

        // Admin with other members must transfer role first
        if ($isAdmin && $memberCount > 1) {
            return $this->error('Transfer the admin role before leaving the group.', 422);
        }

        $userName = $db->table('users')->select('name')->where('id', $userId)->get()->getRowArray()['name'] ?? 'Someone';
        $now      = date('Y-m-d H:i:s');

        $db->table('conversation_members')
            ->where('conversation_id', $convId)
            ->where('user_id', $userId)
            ->delete();

        $remaining = $db->table('conversation_members')
            ->where('conversation_id', $convId)->countAllResults();

        if ($remaining === 0) {
            // Clean up the entire conversation
            $db->table('messages')->where('conversation_id', $convId)->delete();
            $db->table('conversations')->where('id', $convId)->delete();
        } else {
            $db->table('messages')->insert([
                'conversation_id' => $convId,
                'sender_id'       => $userId,
                'type'            => 'system',
                'body'            => "{$userName} left the group",
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }

        return $this->success(null, 'You have left the group');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function formatGroupWithMembers(array $conv, array $members): array
    {
        return [
            'id'           => (string) $conv['id'],
            'type'         =>          $conv['type'],
            'name'         =>          $conv['name'],
            'avatar_url'   =>          $conv['avatar_url'] ?? null,
            'created_at'   =>          $conv['created_at'],
            'unread_count' => 0,
            'member_count' => count($members),
            'members'      => array_map(static fn($m) => [
                'id'         => (string) $m['id'],
                'name'       =>          $m['name'],
                'avatar_url' =>          $m['avatar_url'] ?? null,
                'is_admin'   => (bool) ($m['is_admin'] ?? false),
            ], $members),
        ];
    }
}
