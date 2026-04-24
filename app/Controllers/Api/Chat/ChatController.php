<?php

namespace App\Controllers\Api\Chat;

use App\Controllers\Api\BaseApiController;
use App\Libraries\FCMNotificationService;
use App\Libraries\NotificationHelper;
use App\Models\ConnectionModel;
use App\Models\ConversationModel;
use App\Models\MessageModel;
use CodeIgniter\HTTP\ResponseInterface;

class ChatController extends BaseApiController
{
    private ConversationModel $conversations;
    private MessageModel      $messages;
    private ConnectionModel   $connections;

    public function __construct()
    {
        $this->conversations = new ConversationModel();
        $this->messages      = new MessageModel();
        $this->connections   = new ConnectionModel();
    }

    // ── GET /v1/chat/conversations ────────────────────────────────────────────

    public function conversations(): ResponseInterface
    {
        $userId = $this->authUserId();
        $page   = max(1, (int) ($this->request->getGet('page') ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        $total = $this->conversations->countInboxForUser($userId);
        $rows  = $this->conversations->getInboxForUser($userId, $limit, $offset);

        return $this->success(
            array_map([ConversationModel::class, 'formatRow'], $rows),
            'OK',
            200,
            [
                'current_page' => $page,
                'per_page'     => $limit,
                'total'        => $total,
                'last_page'    => (int) ceil($total / $limit),
            ]
        );
    }

    // ── GET /v1/chat/conversations/:id ───────────────────────────────────────

    public function show($convId = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $id     = (int) $convId;

        if (! $this->conversations->isMember($id, $userId)) {
            return $this->error('Conversation not found.', 404);
        }

        $conv    = $this->conversations->find($id);
        $members = $this->conversations->getMembersOf($id);

        return $this->success($this->formatConversationWithMembers($conv, $members));
    }

    // ── POST /v1/chat/conversations ───────────────────────────────────────────

    public function startDm(): ResponseInterface
    {
        $userId = $this->authUserId();
        $input  = $this->inputJson();

        if (! $this->validateData($input, ['receiver_id' => 'required|integer|greater_than[0]'])) {
            return $this->validationError($this->validator->getErrors());
        }

        $receiverId = (int) $input['receiver_id'];

        if ($receiverId === $userId) {
            return $this->error('You cannot message yourself.', 422);
        }

        // Return existing DM if it already exists
        $existing = $this->conversations->findDirectBetween($userId, $receiverId);
        if ($existing !== null) {
            $members = $this->conversations->getMembersOf((int) $existing['id']);
            return $this->success($this->formatConversationWithMembers($existing, $members));
        }

        $db = db_connect();
        $db->table('conversations')->insert([
            'type'            => 'direct',
            'created_by'      => $userId,
            'last_message_at' => date('Y-m-d H:i:s'),
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);
        $convId = (int) $db->insertID();

        $db->table('conversation_members')->insertBatch([
            ['conversation_id' => $convId, 'user_id' => $userId,     'is_admin' => 0, 'joined_at' => date('Y-m-d H:i:s')],
            ['conversation_id' => $convId, 'user_id' => $receiverId, 'is_admin' => 0, 'joined_at' => date('Y-m-d H:i:s')],
        ]);

        $conv    = $this->conversations->find($convId);
        $members = $this->conversations->getMembersOf($convId);

        return $this->success($this->formatConversationWithMembers($conv, $members), 'Conversation started', 201);
    }

    // ── GET /v1/chat/conversations/:id/messages ───────────────────────────────

    public function messages($convId = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $id     = (int) $convId;

        if (! $this->conversations->isMember($id, $userId)) {
            return $this->error('Conversation not found.', 404);
        }

        $cursor = $this->request->getGet('cursor') ? (int) $this->request->getGet('cursor') : null;

        [$rows, $hasMore] = $this->messages->getForConversation($id, $cursor);

        // Attach listing previews for listing_share messages
        $formatted = array_map(function ($row) {
            $preview = null;
            if ($row['type'] === 'listing_share' && ! empty($row['listing_id'])) {
                $preview = $this->getListingPreview((int) $row['listing_id']);
            }
            return MessageModel::formatRow($row, $preview);
        }, $rows);

        // Update last_read_at and insert read receipts
        $this->markConversationRead($id, $userId);

        $nextCursor = $hasMore && ! empty($rows) ? (int) end($rows)['id'] : null;

        return $this->success(
            $formatted,
            'OK',
            200,
            ['next_cursor' => $nextCursor, 'has_more' => $hasMore]
        );
    }

    // ── POST /v1/chat/conversations/:id/messages ──────────────────────────────

    public function sendMessage($convId = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $id     = (int) $convId;

        if (! $this->conversations->isMember($id, $userId)) {
            return $this->error('Conversation not found.', 404);
        }

        $input = $this->inputJson();

        // Validate type
        $validTypes = ['text', 'image', 'file', 'listing_share'];
        $type = $input['type'] ?? 'text';
        if (! in_array($type, $validTypes, true)) {
            return $this->error('Invalid message type.', 422);
        }

        // Type-specific validation
        $insertData = [
            'conversation_id' => $id,
            'sender_id'       => $userId,
            'type'            => $type,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ];

        switch ($type) {
            case 'text':
                if (! $this->validateData($input, ['body' => 'required|max_length[2000]'])) {
                    return $this->validationError($this->validator->getErrors());
                }
                $insertData['body'] = trim($input['body']);
                break;

            case 'image':
            case 'file':
                if (! $this->validateData($input, ['file_url' => 'required|valid_url|max_length[500]'])) {
                    return $this->validationError($this->validator->getErrors());
                }
                $fileName = $input['file_name'] ?? basename($input['file_url']);
                $fileMime = $input['file_mime'] ?? null;
                $fileSize = isset($input['file_size']) ? (int) $input['file_size'] : null;

                if (MessageModel::isBlockedFile($fileName, $fileMime)) {
                    return $this->error('File type not allowed.', 422);
                }
                if ($fileSize !== null && $fileSize > 10 * 1024 * 1024) {
                    return $this->error('File exceeds 10 MB limit.', 422);
                }

                $insertData['file_url']  = $input['file_url'];
                $insertData['file_name'] = $fileName;
                $insertData['file_size'] = $fileSize;
                $insertData['file_mime'] = $fileMime;
                $insertData['body']      = $input['body'] ?? null;
                break;

            case 'listing_share':
                if (! $this->validateData($input, ['listing_id' => 'required|integer|greater_than[0]'])) {
                    return $this->validationError($this->validator->getErrors());
                }
                $listingId = (int) $input['listing_id'];
                $listing   = db_connect()->table('listings')->where('id', $listingId)->get()->getRowArray();
                if ($listing === null) {
                    return $this->error('Listing not found.', 404);
                }
                $insertData['listing_id'] = $listingId;
                $insertData['body']       = $listing['title'];
                break;
        }

        $db = db_connect();
        $db->table('messages')->insert($insertData);
        $msgId = (int) $db->insertID();

        // Update conversation last_message_at
        $db->table('conversations')->where('id', $id)->update(['last_message_at' => date('Y-m-d H:i:s')]);

        // Read receipt for sender
        $db->table('message_read_receipts')->insert([
            'message_id' => $msgId,
            'user_id'    => $userId,
            'read_at'    => date('Y-m-d H:i:s'),
        ]);

        // Notify other non-muted members
        $this->notifyOtherMembers($id, $userId, $type, $insertData['body'] ?? null);

        $msg = $db->table('messages m')
            ->select('m.*, u.id AS sender_user_id, u.name AS sender_name, u.avatar_url AS sender_avatar')
            ->join('users u', 'u.id = m.sender_id', 'left')
            ->where('m.id', $msgId)
            ->get()->getRowArray();

        $preview = ($type === 'listing_share' && ! empty($insertData['listing_id']))
            ? $this->getListingPreview((int) $insertData['listing_id'])
            : null;

        return $this->success(['message' => MessageModel::formatRow($msg, $preview)], 'Message sent', 201);
    }

    // ── DELETE /v1/chat/messages/:id ──────────────────────────────────────────

    public function deleteMessage($msgId = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $id     = (int) $msgId;

        $msg = $this->messages->find($id);
        if ($msg === null || (int) $msg['sender_id'] !== $userId) {
            return $this->error('Message not found.', 404);
        }
        if ((bool) $msg['is_deleted']) {
            return $this->error('Message already deleted.', 409);
        }

        $forAll = $this->messages->canDeleteForAll($id, $userId);
        $this->messages->update($id, [
            'is_deleted'     => 1,
            'deleted_for_all' => (int) $forAll,
            'body'           => 'This message was deleted',
        ]);

        return $this->success(['deleted_for_all' => $forAll], 'Message deleted');
    }

    // ── POST /v1/chat/messages/:id/react ──────────────────────────────────────

    public function reactToMessage($msgId = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $id     = (int) $msgId;
        $input  = $this->inputJson();

        if (! $this->validateData($input, ['emoji' => 'required|max_length[10]'])) {
            return $this->validationError($this->validator->getErrors());
        }

        $msg = $this->messages->find($id);
        if ($msg === null) {
            return $this->error('Message not found.', 404);
        }

        if (! $this->conversations->isMember((int) $msg['conversation_id'], $userId)) {
            return $this->error('Access denied.', 403);
        }

        $emoji = $input['emoji'];
        $db    = db_connect();

        // Toggle: delete if exists, insert if not
        $existing = $db->table('message_reactions')
            ->where('message_id', $id)
            ->where('user_id', $userId)
            ->where('emoji', $emoji)
            ->get()->getRowArray();

        if ($existing) {
            $db->table('message_reactions')->where('id', $existing['id'])->delete();
        } else {
            $db->table('message_reactions')->insert([
                'message_id' => $id,
                'user_id'    => $userId,
                'emoji'      => $emoji,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $reactions = $this->messages->getReactions($id);
        return $this->success(['reactions' => $reactions]);
    }

    // ── PUT /v1/chat/conversations/:id/mute ───────────────────────────────────

    public function muteConversation($convId = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $id     = (int) $convId;

        $member = db_connect()->table('conversation_members')
            ->where('conversation_id', $id)
            ->where('user_id', $userId)
            ->get()->getRowArray();

        if ($member === null) {
            return $this->error('Conversation not found.', 404);
        }

        $newMuted = ! (bool) $member['is_muted'];
        db_connect()->table('conversation_members')
            ->where('conversation_id', $id)
            ->where('user_id', $userId)
            ->update(['is_muted' => (int) $newMuted]);

        return $this->success(['is_muted' => $newMuted]);
    }

    // ── POST /v1/chat/conversations/:id/report ────────────────────────────────

    public function reportConversation($convId = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $id     = (int) $convId;

        if (! $this->conversations->isMember($id, $userId)) {
            return $this->error('Conversation not found.', 404);
        }

        $input = $this->inputJson();

        // Grab last 10 messages for context
        [$last10, ] = $this->messages->getForConversation($id, null, 10);

        db_connect()->table('moderation_queue')->insert([
            'reference_type' => 'conversation',
            'reference_id'   => $id,
            'reported_by'    => $userId,
            'reason'         => $input['reason'] ?? null,
            'status'         => 'pending',
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        return $this->success(null, 'Report submitted');
    }

    // ── GET /v1/chat/conversations/:id/media ──────────────────────────────────

    public function media($convId = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $id     = (int) $convId;

        if (! $this->conversations->isMember($id, $userId)) {
            return $this->error('Conversation not found.', 404);
        }

        $page   = max(1, (int) ($this->request->getGet('page') ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        $db    = db_connect();
        $total = $db->table('messages')
            ->whereIn('type', ['image', 'file'])
            ->where('conversation_id', $id)
            ->where('is_deleted', 0)
            ->countAllResults();

        $rows = $db->table('messages m')
            ->select('m.*, u.id AS sender_user_id, u.name AS sender_name, u.avatar_url AS sender_avatar')
            ->join('users u', 'u.id = m.sender_id', 'left')
            ->whereIn('m.type', ['image', 'file'])
            ->where('m.conversation_id', $id)
            ->where('m.is_deleted', 0)
            ->orderBy('m.created_at', 'DESC')
            ->limit($limit, $offset)
            ->get()->getResultArray();

        return $this->success(
            array_map(fn($r) => MessageModel::formatRow($r), $rows),
            'OK',
            200,
            [
                'current_page' => $page,
                'per_page'     => $limit,
                'total'        => $total,
                'last_page'    => (int) ceil($total / $limit),
            ]
        );
    }

    // ── PUT /v1/chat/conversations/:id/read ──────────────────────────────────

    public function markRead($convId = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $id     = (int) $convId;

        if (! $this->conversations->isMember($id, $userId)) {
            return $this->error('Conversation not found.', 404);
        }

        $this->markConversationRead($id, $userId);

        return $this->success(null, 'Conversation marked as read');
    }

    // ── POST /v1/chat/conversations/:id/block ─────────────────────────────────

    public function blockFromConversation($convId = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $id     = (int) $convId;

        if (! $this->conversations->isMember($id, $userId)) {
            return $this->error('Conversation not found.', 404);
        }

        $conv = $this->conversations->find($id);
        if ($conv['type'] !== 'direct') {
            return $this->error('Block is only available for direct conversations.', 422);
        }

        // Find the other member
        $otherMember = db_connect()->table('conversation_members')
            ->where('conversation_id', $id)
            ->where('user_id !=', $userId)
            ->get()->getRowArray();

        if ($otherMember !== null) {
            $otherId = (int) $otherMember['user_id'];

            // Update or insert block in connections
            $existingConn = db_connect()->table('connections')
                ->groupStart()
                    ->where('requester_id', $userId)->where('receiver_id', $otherId)
                ->groupEnd()
                ->orGroupStart()
                    ->where('requester_id', $otherId)->where('receiver_id', $userId)
                ->groupEnd()
                ->get()->getRowArray();

            if ($existingConn) {
                db_connect()->table('connections')->where('id', $existingConn['id'])->update(['status' => 'blocked']);
            } else {
                db_connect()->table('connections')->insert([
                    'requester_id' => $userId,
                    'receiver_id'  => $otherId,
                    'status'       => 'blocked',
                    'created_at'   => date('Y-m-d H:i:s'),
                    'updated_at'   => date('Y-m-d H:i:s'),
                ]);
            }
        }

        // Remove current user from conversation
        db_connect()->table('conversation_members')
            ->where('conversation_id', $id)
            ->where('user_id', $userId)
            ->delete();

        return $this->success(null, 'User blocked');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function markConversationRead(int $convId, int $userId): void
    {
        $db = db_connect();

        // Update last_read_at
        $db->table('conversation_members')
            ->where('conversation_id', $convId)
            ->where('user_id', $userId)
            ->update(['last_read_at' => date('Y-m-d H:i:s')]);

        // Insert read receipts for unread messages
        $unread = $db->table('messages m')
            ->select('m.id')
            ->join('message_read_receipts rr', "rr.message_id = m.id AND rr.user_id = {$userId}", 'left')
            ->where('m.conversation_id', $convId)
            ->where('rr.id IS NULL', null, false)
            ->where('m.sender_id !=', $userId)
            ->get()->getResultArray();

        foreach ($unread as $row) {
            $db->table('message_read_receipts')
                ->ignore(true)
                ->insert([
                    'message_id' => (int) $row['id'],
                    'user_id'    => $userId,
                    'read_at'    => date('Y-m-d H:i:s'),
                ]);
        }
    }

    private function notifyOtherMembers(int $convId, int $senderId, string $type, ?string $body): void
    {
        $members = db_connect()->table('conversation_members cm')
            ->select('cm.user_id, cm.is_muted, u.name')
            ->join('users u', 'u.id = cm.user_id')
            ->where('cm.conversation_id', $convId)
            ->where('cm.user_id !=', $senderId)
            ->get()->getResultArray();

        $senderName = db_connect()->table('users')->select('name')->where('id', $senderId)->get()->getRowArray()['name'] ?? 'Someone';

        $conv = $this->conversations->find($convId);
        $isGroup = $conv && $conv['type'] === 'group';

        $fcm = new FCMNotificationService();

        foreach ($members as $m) {
            if ((bool) $m['is_muted']) {
                continue;
            }

            if ($isGroup) {
                $fcm->notifyNewGroupMessage((int) $m['user_id'], $convId, $conv['name'] ?? 'Group');
            } else {
                $fcm->notifyNewMessage((int) $m['user_id'], $convId, $senderName);
            }
        }
    }

    private function getListingPreview(int $listingId): ?array
    {
        $row = db_connect()->table('listings l')
            ->select('l.id, l.title, l.thumbnail_url, l.trust_level, o.name AS org_name')
            ->join('organisations o', 'o.id = l.org_id', 'left')
            ->where('l.id', $listingId)
            ->get()->getRowArray();

        if ($row === null) {
            return null;
        }

        return [
            'id'           => (int) $row['id'],
            'title'        =>       $row['title'],
            'thumbnailUrl' =>       $row['thumbnail_url'] ?? null,
            'orgName'      =>       $row['org_name']      ?? null,
            'trustLevel'   =>       $row['trust_level'],
        ];
    }

    // ── GET /v1/users/search?q=name ──────────────────────────────────────────

    public function searchUsers(): ResponseInterface
    {
        $userId = $this->authUserId();
        $q      = trim((string) ($this->request->getGet('q') ?? ''));

        if (strlen($q) < 2) {
            return $this->success([], 'OK');
        }

        $rows = db_connect()
            ->table('users')
            ->select('id, name, avatar_url, location')
            ->like('name', $q)
            ->where('id !=', $userId)
            ->where('is_active', 1)
            ->limit(20)
            ->get()->getResultArray();

        $results = array_map(static fn($u) => [
            'id'         => (string) $u['id'],
            'name'       => $u['name'],
            'avatar_url' => $u['avatar_url'] ?? null,
            'location'   => $u['location'] ?? null,
        ], $rows);

        return $this->success($results);
    }

    private function formatConversationWithMembers(array $conv, array $members): array
    {
        return [
            'id'        => (int) $conv['id'],
            'type'      =>       $conv['type'],
            'name'      =>       $conv['name'] ?? null,
            'avatarUrl' =>       $conv['avatar_url'] ?? null,
            'createdAt' =>       $conv['created_at'],
            'members'   => array_map(static fn($m) => [
                'id'        => (int) $m['id'],
                'name'      =>       $m['name'],
                'avatarUrl' =>       $m['avatar_url'] ?? null,
                'isAdmin'   => (bool) ($m['is_admin'] ?? false),
            ], $members),
        ];
    }
}
