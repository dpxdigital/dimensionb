<?php

namespace App\Controllers\Api\Connections;

use App\Controllers\Api\BaseApiController;
use App\Libraries\NotificationHelper;
use App\Models\ConnectionModel;
use App\Models\ConversationModel;
use CodeIgniter\HTTP\ResponseInterface;

class ConnectionsController extends BaseApiController
{
    private ConnectionModel  $connections;
    private ConversationModel $conversations;

    public function __construct()
    {
        $this->connections  = new ConnectionModel();
        $this->conversations = new ConversationModel();
    }

    // ── GET /v1/chat/requests ─────────────────────────────────────────────────

    public function index(): ResponseInterface
    {
        $userId = $this->authUserId();
        $page   = max(1, (int) ($this->request->getGet('page') ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        $db    = db_connect();
        $total = $db->table('connections')
            ->where('receiver_id', $userId)
            ->where('status', 'pending')
            ->countAllResults();

        $rows = $db->table('connections c')
            ->select('c.id, c.receiver_id, c.status, c.context_type, c.context_id, c.created_at,
                      u.id AS requester_id, u.name AS requester_name,
                      u.avatar_url AS requester_avatar, u.location AS requester_location')
            ->join('users u', 'u.id = c.requester_id', 'inner')
            ->where('c.receiver_id', $userId)
            ->where('c.status', 'pending')
            ->orderBy('c.created_at', 'DESC')
            ->limit($limit, $offset)
            ->get()->getResultArray();

        $formatted = array_map(fn($r) => $this->formatRequest($r), $rows);

        return $this->success(
            $formatted,
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

    // ── POST /v1/chat/requests ────────────────────────────────────────────────

    public function send(): ResponseInterface
    {
        $userId = $this->authUserId();
        $input  = $this->inputJson();

        if (! $this->validateData($input, ['receiver_id' => 'required|integer|greater_than[0]'])) {
            return $this->validationError($this->validator->getErrors());
        }

        $receiverId = (int) $input['receiver_id'];

        if ($receiverId === $userId) {
            return $this->error('You cannot send a connection request to yourself.', 422);
        }

        // Check receiver exists
        $receiver = db_connect()->table('users')->where('id', $receiverId)->get()->getRowArray();
        if ($receiver === null) {
            return $this->error('User not found.', 404);
        }

        // Check blocked in either direction
        if ($this->connections->getStatus($userId, $receiverId) === 'blocked') {
            return $this->error('Cannot send request.', 403);
        }

        if (! $this->connections->canSendRequest($userId, $receiverId)) {
            return $this->error(
                'Cannot send connection request. You may already be connected, have a pending request, or a declined request within the last 30 days.',
                422
            );
        }

        // Rate limit: max 20 outbound requests per day
        if (! $this->checkDailyRequestLimit($userId)) {
            return $this->error('Daily connection request limit reached (max 20 per day).', 429);
        }

        // Detect shared context
        [$contextType, $contextId] = $this->detectContext($userId, $receiverId);

        $id = $this->connections->insert([
            'requester_id' => $userId,
            'receiver_id'  => $receiverId,
            'status'       => 'pending',
            'context_type' => $contextType,
            'context_id'   => $contextId,
        ]);

        $row = $this->connections->find((int) $this->connections->db->insertID());

        NotificationHelper::createAndSend(
            $receiverId,
            'connection_request',
            'Connection Request',
            db_connect()->table('users')->select('name')->where('id', $userId)->get()->getRowArray()['name'] . ' wants to connect',
            $userId,
            'user'
        );

        return $this->success(
            ['connection' => ConnectionModel::formatRow($row)],
            'Connection request sent',
            201
        );
    }

    // ── PUT /v1/chat/requests/:id/accept ──────────────────────────────────────

    public function accept($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $conn   = $this->connections->find((int) $id);

        if ($conn === null) {
            return $this->error('Connection request not found.', 404);
        }
        if ((int) $conn['receiver_id'] !== $userId) {
            return $this->error('You cannot accept this request.', 403);
        }
        if ($conn['status'] !== 'pending') {
            return $this->error('This request is no longer pending.', 409);
        }

        $this->connections->update((int) $id, ['status' => 'accepted']);

        // Create or find direct conversation
        $existingConv = $this->conversations->findDirectBetween($userId, (int) $conn['requester_id']);

        if ($existingConv !== null) {
            $convId = (int) $existingConv['id'];
        } else {
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
                ['conversation_id' => $convId, 'user_id' => $userId,               'is_admin' => 0, 'joined_at' => date('Y-m-d H:i:s')],
                ['conversation_id' => $convId, 'user_id' => (int) $conn['requester_id'], 'is_admin' => 0, 'joined_at' => date('Y-m-d H:i:s')],
            ]);

            $db->table('messages')->insert([
                'conversation_id' => $convId,
                'sender_id'       => $userId,
                'type'            => 'system',
                'body'            => 'You are now connected',
                'created_at'      => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);
        }

        NotificationHelper::createAndSend(
            (int) $conn['requester_id'],
            'connection_accepted',
            'Connection Accepted',
            db_connect()->table('users')->select('name')->where('id', $userId)->get()->getRowArray()['name'] . ' accepted your connection request',
            $convId,
            'conversation'
        );

        return $this->success(['conversation_id' => $convId], 'Connection accepted');
    }

    // ── PUT /v1/chat/requests/:id/decline ─────────────────────────────────────

    public function decline($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $conn   = $this->connections->find((int) $id);

        if ($conn === null) {
            return $this->error('Connection request not found.', 404);
        }
        if ((int) $conn['receiver_id'] !== $userId) {
            return $this->error('You cannot decline this request.', 403);
        }
        if ($conn['status'] !== 'pending') {
            return $this->error('This request is no longer pending.', 409);
        }

        $this->connections->update((int) $id, ['status' => 'declined']);

        return $this->success(null, 'Connection request declined');
    }

    // ── GET /v1/users/:id/connection-status ───────────────────────────────────

    public function connectionStatus($targetUserId = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $status = $this->connections->getStatus($userId, (int) $targetUserId);

        return $this->success(['connection_status' => $status]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function checkDailyRequestLimit(int $userId): bool
    {
        $today = date('Y-m-d 00:00:00');
        $count = db_connect()
            ->table('connections')
            ->where('requester_id', $userId)
            ->where('created_at >=', $today)
            ->countAllResults();

        return $count < 20;
    }

    private function detectContext(int $userAId, int $userBId): array
    {
        $db = db_connect();

        // Both RSVPed to the same listing?
        $shared = $db->table('listing_rsvps ra')
            ->select('ra.listing_id')
            ->join('listing_rsvps rb', 'rb.listing_id = ra.listing_id')
            ->where('ra.user_id', $userAId)
            ->where('rb.user_id', $userBId)
            ->limit(1)
            ->get()->getRowArray();

        if ($shared) {
            return ['listing', (int) $shared['listing_id']];
        }

        // Both interested in the same area?
        $sharedInterest = $db->table('user_interests ia')
            ->select('ia.interest')
            ->join('user_interests ib', "ib.interest = ia.interest AND ib.user_id = {$userBId}", 'inner')
            ->where('ia.user_id', $userAId)
            ->limit(1)
            ->get()->getRowArray();

        if ($sharedInterest) {
            return ['interest', null];
        }

        return [null, null];
    }

    private function formatRequest(array $row): array
    {
        $context = null;
        if (! empty($row['context_type']) && ! empty($row['context_id'])) {
            $context = $this->resolveContext($row['context_type'], (int) $row['context_id']);
        }

        $contextLabel = $context ? ($context['label'] ?? null) : null;

        return [
            'id'              => (string) $row['id'],
            'requester_id'    => (string) $row['requester_id'],
            'receiver_id'     => (string) $row['receiver_id'],
            'status'          =>          $row['status'] ?? 'pending',
            'context_type'    =>          $row['context_type'] ?? null,
            'context_id'      =>          isset($row['context_id']) ? (string) $row['context_id'] : null,
            'requester_name'  =>          $row['requester_name'],
            'requester_avatar' =>         $row['requester_avatar'] ?? null,
            'context_label'   =>          $contextLabel,
            'created_at'      =>          $row['created_at'],
        ];
    }

    private function resolveContext(string $type, int $id): ?array
    {
        $db = db_connect();

        if ($type === 'listing') {
            $row = $db->table('listings')->select('id, title')->where('id', $id)->get()->getRowArray();
            if ($row) {
                return ['type' => 'listing', 'id' => (int) $row['id'], 'label' => $row['title']];
            }
        }

        if ($type === 'category') {
            $row = $db->table('categories')->select('id, name')->where('id', $id)->get()->getRowArray();
            if ($row) {
                return ['type' => 'category', 'id' => (int) $row['id'], 'label' => $row['name']];
            }
        }

        return null;
    }
}
