<?php

namespace App\Controllers\Api\Live;

use App\Controllers\Api\BaseApiController;
use App\Models\LiveSessionModel;
use CodeIgniter\HTTP\ResponseInterface;

class LiveController extends BaseApiController
{
    private LiveSessionModel $sessions;

    public function __construct()
    {
        $this->sessions = new LiveSessionModel();
    }

    // ── GET /v1/live ──────────────────────────────────────────────────────────

    public function index(): ResponseInterface
    {
        // Purge ended sessions, abandoned active sessions, and overdue scheduled sessions
        try {
            $db = db_connect();
            $db->table('live_sessions')->where('status', 'ended')->delete();
            $db->query("DELETE FROM live_sessions WHERE status = 'active' AND (started_at IS NULL OR started_at < DATE_SUB(NOW(), INTERVAL 8 HOUR))");
            $db->query("DELETE FROM live_sessions WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)");
        } catch (\Throwable $e) {}

        $status    = $this->request->getGet('status') ?? 'active';
        $mySessions = (int) ($this->request->getGet('my_sessions') ?? 0);

        if ($status === 'scheduled' && $mySessions) {
            $userId = $this->authUserId();
            $rows = $this->sessions
                ->where('status', 'scheduled')
                ->where('host_id', $userId)
                ->orderBy('created_at', 'DESC')
                ->findAll(50);
            return $this->success(array_map(fn($r) => LiveSessionModel::format($r), $rows));
        }

        $circleId   = (int) ($this->request->getGet('circle_id')   ?? 0) ?: null;
        $movementId = (int) ($this->request->getGet('movement_id') ?? 0) ?: null;

        if ($circleId !== null) {
            $rows = $this->sessions->getSessionsForCircle($circleId);
        } elseif ($movementId !== null) {
            $rows = $this->sessions->getSessionsForMovement($movementId);
        } elseif ($status === 'ended') {
            $rows = $this->sessions->getEndedPublicSessions(null);
        } elseif ($status === 'scheduled') {
            $rows = $this->sessions->getScheduledPublicSessions(null);
        } else {
            $rows = $this->sessions->getActiveSessions();
        }
        return $this->success(array_map(fn($r) => LiveSessionModel::format($r), $rows));
    }

    // ── POST /v1/live/start ───────────────────────────────────────────────────

    public function start(): ResponseInterface
    {
        if (! $this->checkLiveRateLimit()) {
            return $this->error('Rate limit: max 5 live streams per minute.', 429);
        }

        $this->ensureLiveTables();

        $userId = $this->authUserId();
        $input  = $this->inputJson();

        $rules = [
            'title'    => 'required|max_length[255]',
            'category' => 'required|max_length[100]',
        ];
        if (! $this->validateData($input, $rules)) {
            return $this->validationError($this->validator->getErrors());
        }

        $visibility = $input['visibility'] ?? 'public';
        if (!in_array($visibility, ['public', 'circle_only', 'movement_followers'])) {
            $visibility = 'public';
        }

        // Validate circle membership when circle_only
        $circleId = !empty($input['circle_id']) ? (int) $input['circle_id'] : null;
        if ($visibility === 'circle_only' && $circleId) {
            $db     = db_connect();
            $member = $db->table('circle_members')
                ->where('circle_id', $circleId)->where('user_id', $userId)->where('status', 'approved')
                ->get()->getRowArray();
            if (!$member) {
                return $this->error('You must be an approved member to go live in this circle', 403);
            }
        }

        $db   = db_connect();
        $user = $db->table('users')->where('id', $userId)->get()->getRowArray();

        $validTrustLevels = ['institution_verified','curator_reviewed','community_submitted','approved_live_host','needs_reconfirmation'];
        $hostTrustLevel   = $user['trust_level'] ?? 'community_submitted';
        if (!in_array($hostTrustLevel, $validTrustLevels, true)) {
            $hostTrustLevel = 'community_submitted';
        }

        // Unique room name (reusing agora_channel column)
        $roomName = 'dim_' . bin2hex(random_bytes(8));

        // Generate host LiveKit token (canPublish = true)
        $token = $this->generateLiveKitToken($roomName, 'host_' . $userId, true);

        $sessionId = $this->sessions->insert([
            'host_id'           => $userId,
            'title'             => trim($input['title']),
            'category'          => trim($input['category']),
            'description'       => $input['description'] ?? null,
            'linked_listing_id' => $input['linked_listing_id'] ?? null,
            'circle_id'         => $circleId,
            'movement_id'       => !empty($input['movement_id'])  ? (int) $input['movement_id']  : null,
            'action_id'         => !empty($input['action_id'])    ? (int) $input['action_id']    : null,
            'visibility'        => $visibility,
            'agora_channel'     => $roomName,
            'agora_token'       => $token,
            'viewer_count'      => 0,
            'status'            => 'active',
            'started_at'        => date('Y-m-d H:i:s'),
        ]);

        $this->notifyFollowers($userId, (string) $sessionId, trim($input['title']));

        return $this->success([
            'id'               => (string) $sessionId,
            'host_id'          => (string) $userId,
            'host_name'        => $user['name']       ?? '',
            'host_avatar_url'  => $user['avatar_url'] ?? null,
            'host_trust_level' => $hostTrustLevel,
            'title'            => trim($input['title']),
            'category'         => trim($input['category']),
            'description'      => $input['description'] ?? null,
            'linked_listing_id' => $input['linked_listing_id'] ?? null,
            'circle_id'        => $circleId ? (string) $circleId : null,
            'movement_id'      => !empty($input['movement_id']) ? (string) $input['movement_id'] : null,
            'action_id'        => !empty($input['action_id'])   ? (string) $input['action_id']  : null,
            'visibility'       => $visibility,
            'room_name'        => $roomName,
            'token'            => $token,
            'server_url'       => 'wss://dim-z07mwg4s.livekit.cloud',
            'viewer_count'     => 0,
            'status'           => 'active',
            'started_at'       => date('Y-m-d H:i:s'),
        ], 'Live session started', 201);
    }

    // ── POST /v1/live/schedule ────────────────────────────────────────────────

    public function schedule(): ResponseInterface
    {
        $this->ensureLiveTables();

        $userId = $this->authUserId();
        $input  = $this->inputJson();

        $rules = [
            'title'        => 'required|max_length[255]',
            'category'     => 'required|max_length[100]',
            'scheduled_at' => 'required',
        ];
        if (! $this->validateData($input, $rules)) {
            return $this->validationError($this->validator->getErrors());
        }

        $scheduledAt = strtotime($input['scheduled_at']);
        if ($scheduledAt === false || $scheduledAt <= time()) {
            return $this->validationError(['scheduled_at' => 'scheduled_at must be a future date/time']);
        }

        $visibility = $input['visibility'] ?? 'public';
        if (!in_array($visibility, ['public', 'circle_only', 'movement_followers'])) {
            $visibility = 'public';
        }

        $db = db_connect();

        // Prevent duplicate: same user already has a scheduled session with this exact title
        $duplicate = $db->table('live_sessions')
            ->where('host_id', $userId)
            ->where('status', 'scheduled')
            ->where('LOWER(title)', strtolower(trim($input['title'])))
            ->countAllResults();
        if ($duplicate > 0) {
            return $this->validationError(['title' => 'You already have a scheduled session with this title.']);
        }

        $sessionId = $this->sessions->insert([
            'host_id'           => $userId,
            'title'             => trim($input['title']),
            'category'          => trim($input['category']),
            'description'       => $input['description'] ?? null,
            'linked_listing_id' => $input['linked_listing_id'] ?? null,
            'circle_id'         => !empty($input['circle_id'])    ? (int) $input['circle_id']    : null,
            'movement_id'       => !empty($input['movement_id'])  ? (int) $input['movement_id']  : null,
            'action_id'         => !empty($input['action_id'])    ? (int) $input['action_id']    : null,
            'visibility'        => $visibility,
            'agora_channel'     => '',
            'agora_token'       => '',
            'viewer_count'      => 0,
            'status'            => 'scheduled',
            'scheduled_at'      => date('Y-m-d H:i:s', $scheduledAt),
        ]);

        $session = $this->sessions->getWithHost((int) $sessionId);
        return $this->success(LiveSessionModel::format($session), 'Live session scheduled', 201);
    }

    // ── POST /v1/live/:id/start ───────────────────────────────────────────────

    public function startScheduled($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = db_connect();

        $session = $this->sessions->find((int) $id);
        if (! $session) {
            return $this->error('Live session not found.', 404);
        }
        if ((int) $session['host_id'] !== (int) $userId) {
            return $this->error('Only the host can start this session.', 403);
        }
        if ($session['status'] !== 'scheduled') {
            return $this->error('Session is not in a scheduled state.', 422);
        }

        $user     = $db->table('users')->where('id', $userId)->get()->getRowArray();
        $roomName = 'dim_' . bin2hex(random_bytes(8));
        $token    = $this->generateLiveKitToken($roomName, 'host_' . $userId, true);

        $validTrustLevels = ['institution_verified','curator_reviewed','community_submitted','approved_live_host','needs_reconfirmation'];
        $hostTrustLevel   = $user['trust_level'] ?? 'community_submitted';
        if (!in_array($hostTrustLevel, $validTrustLevels, true)) {
            $hostTrustLevel = 'community_submitted';
        }

        $this->sessions->update((int) $id, [
            'agora_channel' => $roomName,
            'agora_token'   => $token,
            'status'        => 'active',
            'started_at'    => date('Y-m-d H:i:s'),
        ]);

        $this->notifyFollowers($userId, (string) $id, $session['title']);
        $this->notifyReminders((int) $id, $session['title']);

        return $this->success([
            'id'                => (string) $id,
            'host_id'           => (string) $userId,
            'host_name'         => $user['name']       ?? '',
            'host_avatar_url'   => $user['avatar_url'] ?? null,
            'host_trust_level'  => $hostTrustLevel,
            'title'             => $session['title'],
            'category'          => $session['category'],
            'description'       => $session['description'] ?? null,
            'linked_listing_id' => $session['linked_listing_id'] ?? null,
            'circle_id'         => $session['circle_id'] ?? null,
            'movement_id'       => $session['movement_id'] ?? null,
            'action_id'         => $session['action_id'] ?? null,
            'visibility'        => $session['visibility'],
            'room_name'         => $roomName,
            'token'             => $token,
            'server_url'        => 'wss://dim-z07mwg4s.livekit.cloud',
            'viewer_count'      => 0,
            'status'            => 'active',
            'started_at'        => date('Y-m-d H:i:s'),
        ], 'Live session started');
    }

    // ── GET /v1/live/:id ──────────────────────────────────────────────────────

    public function show($id = null): ResponseInterface
    {
        $userId  = $this->authUserId();
        $session = $this->sessions->getWithHost((int) $id);
        if ($session === null) {
            return $this->error('Live session not found.', 404);
        }

        // Return the stored host token only to the host (prevents token leakage to viewers)
        $token = ((int) $session['host_id'] === $userId)
            ? ($session['agora_token'] ?? null)
            : null;

        return $this->success(LiveSessionModel::format($session, $token));
    }

    // ── PUT /v1/live/:id ──────────────────────────────────────────────────────

    public function update($id = null): ResponseInterface
    {
        $userId  = $this->authUserId();
        $session = $this->sessions->find((int) $id);

        if ($session === null) {
            return $this->error('Live session not found.', 404);
        }
        if ((int) $session['host_id'] !== $userId) {
            return $this->error('Only the host can update this session.', 403);
        }

        $input = $this->inputJson();
        $rules = [
            'title'    => 'permit_empty|max_length[255]',
            'category' => 'permit_empty|max_length[100]',
        ];
        if (! $this->validateData($input, $rules)) {
            return $this->validationError($this->validator->getErrors());
        }

        $update = array_filter([
            'title'             => isset($input['title'])    ? trim($input['title'])    : null,
            'category'          => isset($input['category']) ? trim($input['category']) : null,
            'linked_listing_id' => $input['linked_listing_id'] ?? null,
        ], fn($v) => $v !== null);

        if (! empty($update)) {
            $this->sessions->update((int) $id, $update);
        }

        return $this->success(LiveSessionModel::format($this->sessions->getWithHost((int) $id)));
    }

    // ── POST /v1/live/:id/end ─────────────────────────────────────────────────

    public function end($id = null): ResponseInterface
    {
        $userId  = $this->authUserId();
        $session = $this->sessions->find((int) $id);

        if ($session === null) {
            return $this->error('Live session not found.', 404);
        }
        if ((int) $session['host_id'] !== $userId) {
            return $this->error('Only the host can end this session.', 403);
        }
        if ($session['status'] === 'ended') {
            return $this->error('Session already ended.', 409);
        }

        $this->sessions->delete((int) $id);

        return $this->success(null, 'Live session ended');
    }

    // ── POST /v1/live/:id/join ────────────────────────────────────────────────

    public function join($id = null): ResponseInterface
    {
        $this->ensureLiveTables();
        $userId  = $this->authUserId();
        $session = $this->sessions->getWithHost((int) $id);

        if ($session === null) {
            return $this->error('Live session not found.', 404);
        }
        if ($session['status'] !== 'active') {
            return $this->error('This live session has ended.', 410);
        }

        // Enforce visibility rules
        $visibility = $session['visibility'] ?? 'public';
        if ($visibility === 'circle_only' && !empty($session['circle_id'])) {
            $db     = db_connect();
            $member = $db->table('circle_members')
                ->where('circle_id', $session['circle_id'])
                ->where('user_id', $userId)
                ->where('status', 'approved')
                ->get()->getRowArray();
            if (!$member) {
                return $this->error('This live session is for circle members only', 403);
            }
        } elseif ($visibility === 'movement_followers' && !empty($session['movement_id'])) {
            $db          = db_connect();
            $isFollowing = $db->table('movement_followers')
                ->where('movement_id', $session['movement_id'])
                ->where('user_id', $userId)
                ->countAllResults();
            if (!$isFollowing) {
                return $this->error('This live session is for movement followers only', 403);
            }
        }

        // Generate participant LiveKit token (canPublish = true — everyone can share camera/mic)
        $token = $this->generateLiveKitToken($session['agora_channel'], 'viewer_' . $userId, true);

        // Increment viewer count
        db_connect()->table('live_sessions')
            ->where('id', $id)
            ->set('viewer_count', 'viewer_count + 1', false)
            ->update();

        return $this->success([
            'id'               => (string) $session['id'],
            'host_id'          => (string) $session['host_id'],
            'host_name'        => $session['host_name']        ?? '',
            'host_avatar_url'  => $session['host_avatar']      ?? null,
            'host_trust_level' => $session['host_trust_level'] ?? 'community_submitted',
            'title'            => $session['title'],
            'category'         => $session['category'],
            'description'      => $session['description'] ?? null,
            'linked_listing_id' => $session['linked_listing_id'] ? (string) $session['linked_listing_id'] : null,
            'circle_id'        => $session['circle_id']   ? (int) $session['circle_id']   : null,
            'movement_id'      => $session['movement_id'] ? (int) $session['movement_id'] : null,
            'action_id'        => $session['action_id']   ? (int) $session['action_id']   : null,
            'visibility'       => $session['visibility'] ?? 'public',
            'room_name'        => $session['agora_channel'],
            'token'            => $token,
            'server_url'       => 'wss://dim-z07mwg4s.livekit.cloud',
            'viewer_count'     => (int) $session['viewer_count'],
            'status'           => $session['status'],
            'started_at'       => $session['started_at'],
        ]);
    }

    // ── POST /v1/live/:id/cohost ──────────────────────────────────────────────

    public function addCohost($id = null): ResponseInterface
    {
        // Ensure live_cohosts table exists (idempotent)
        try {
            db_connect()->query("
                CREATE TABLE IF NOT EXISTS `live_cohosts` (
                    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `session_id` INT UNSIGNED NOT NULL,
                    `user_id`    INT UNSIGNED NOT NULL,
                    `joined_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_live_cohosts` (`session_id`, `user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) {}

        $userId  = $this->authUserId();
        $session = $this->sessions->find((int) $id);

        if ($session === null) {
            return $this->error('Live session not found.', 404);
        }
        if ((int) $session['host_id'] !== $userId) {
            return $this->error('Only the host can invite co-hosts.', 403);
        }

        $input        = $this->inputJson();
        $cohostUserId = (int) ($input['user_id'] ?? 0);

        if ($cohostUserId === 0) {
            return $this->error('user_id is required.', 422);
        }

        if ($this->sessions->cohostCount((int) $id) >= 9) {
            return $this->error('Maximum 9 co-hosts reached.', 422);
        }

        $db     = db_connect();
        $exists = $db->table('live_cohosts')
            ->where('session_id', $id)
            ->where('user_id', $cohostUserId)
            ->get()->getRowArray();

        if ($exists) {
            return $this->error('User is already a co-host.', 409);
        }

        // Generate co-host token with publish permission
        $cohostToken = $this->generateLiveKitToken(
            $session['agora_channel'],
            'cohost_' . $cohostUserId,
            true
        );

        $db->table('live_cohosts')->insert([
            'session_id' => (int) $id,
            'user_id'    => $cohostUserId,
            'joined_at'  => date('Y-m-d H:i:s'),
        ]);

        $this->notifyCohost($cohostUserId, $session);

        return $this->success([
            'token'      => $cohostToken,
            'server_url' => 'wss://dim-z07mwg4s.livekit.cloud',
            'room_name'  => $session['agora_channel'],
        ], 'Co-host invited');
    }

    // ── DELETE /v1/live/:id/cohost ────────────────────────────────────────────

    public function removeCohost($id = null): ResponseInterface
    {
        $userId  = $this->authUserId();
        $session = $this->sessions->find((int) $id);

        if ($session === null) {
            return $this->error('Live session not found.', 404);
        }
        if ((int) $session['host_id'] !== $userId) {
            return $this->error('Only the host can remove co-hosts.', 403);
        }

        $input        = $this->inputJson();
        $cohostUserId = (int) ($input['user_id'] ?? 0);

        db_connect()->table('live_cohosts')
            ->where('session_id', $id)
            ->where('user_id', $cohostUserId)
            ->delete();

        return $this->success(null, 'Co-host removed');
    }

    // ── POST /v1/live/:id/kick-participant ────────────────────────────────────

    public function kickParticipant($id = null): ResponseInterface
    {
        $userId  = $this->authUserId();
        $session = $this->sessions->find((int) $id);

        if ($session === null) {
            return $this->error('Live session not found.', 404);
        }

        // Only host or co-host can kick
        $isHost    = (int) $session['host_id'] === $userId;
        $isCohost  = false;
        if (! $isHost) {
            try {
                $isCohost = db_connect()->table('live_cohosts')
                    ->where('session_id', (int) $id)
                    ->where('user_id', $userId)
                    ->countAllResults() > 0;
            } catch (\Throwable $e) {}
        }
        if (! $isHost && ! $isCohost) {
            return $this->error('Only the host or co-hosts can remove participants.', 403);
        }

        $input    = $this->inputJson();
        $identity = trim($input['identity'] ?? '');
        if ($identity === '') {
            return $this->error('identity is required.', 422);
        }

        // Call LiveKit server API to remove the participant
        $apiKey    = 'APIRV4kSEhDLFHV';
        $apiSecret = 'QP3IfYyWvPjfAn7IYdeyHwAzBYOyGs2U6VL0dgZlwfvC';
        $serverUrl = 'https://dim-z07mwg4s.livekit.cloud';
        $roomName  = $session['agora_channel'];

        $now   = time();
        $adminToken = $this->generateLiveKitToken($roomName, 'admin_' . $userId, true);

        $body = json_encode(['room' => $roomName, 'identity' => $identity]);

        $ch = curl_init($serverUrl . '/twirp/livekit.RoomService/RemoveParticipant');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $adminToken,
            ],
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            log_message('error', "[kick] LiveKit API error {$httpCode}: {$resp}");
            return $this->error('Could not remove participant.', 500);
        }

        return $this->success(null, 'Participant removed');
    }

    // ── GET /v1/live/:id/comments ─────────────────────────────────────────────

    public function getComments($id = null): ResponseInterface
    {
        $db = db_connect();

        $session = $this->sessions->find((int) $id);
        if ($session === null) {
            return $this->error('Live session not found.', 404);
        }

        $comments = $db->table('live_comments lc')
            ->select('lc.id, lc.body, lc.created_at, u.id AS user_id, u.name AS user_name, u.avatar_url')
            ->join('users u', 'u.id = lc.user_id')
            ->where('lc.session_id', (int) $id)
            ->orderBy('lc.created_at', 'ASC')
            ->limit(200)
            ->get()->getResultArray();

        $formatted = array_map(fn($c) => [
            'id'         => (string) $c['id'],
            'user_id'    => (string) $c['user_id'],
            'user_name'  => $c['user_name'],
            'avatar_url' => $c['avatar_url'] ?? null,
            'body'       => $c['body'],
            'is_pinned'  => false,
            'created_at' => $c['created_at'],
        ], $comments);

        return $this->success($formatted);
    }

    // ── POST /v1/live/:id/comment ─────────────────────────────────────────────

    public function comment($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $input  = $this->inputJson();

        if (! $this->validateData($input, ['body' => 'required|max_length[200]'])) {
            return $this->validationError($this->validator->getErrors());
        }

        $session = $this->sessions->find((int) $id);
        if ($session === null || $session['status'] !== 'active') {
            return $this->error('Session not found or has ended.', 404);
        }

        $db = db_connect();
        $db->table('live_comments')->insert([
            'session_id' => (int) $id,
            'user_id'    => $userId,
            'body'       => trim($input['body']),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $insertId = $db->insertID();
        $comment = $db->table('live_comments lc')
            ->select('lc.id, lc.body, lc.created_at, u.id AS user_id, u.name AS user_name, u.avatar_url')
            ->join('users u', 'u.id = lc.user_id')
            ->where('lc.id', $insertId)
            ->get()->getRowArray();

        return $this->success([
            'id'         => (string) $comment['id'],
            'user_id'    => (string) $comment['user_id'],
            'user_name'  => $comment['user_name'],
            'avatar_url' => $comment['avatar_url'] ?? null,
            'body'       => $comment['body'],
            'is_pinned'  => false,
            'created_at' => $comment['created_at'],
        ], 'Comment added', 201);
    }

    // ── POST /v1/live/:id/react ───────────────────────────────────────────────

    public function react($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $input  = $this->inputJson();

        if (! $this->validateData($input, ['emoji' => 'required|max_length[10]'])) {
            return $this->validationError($this->validator->getErrors());
        }

        db_connect()->table('live_reactions')->insert([
            'session_id' => (int) $id,
            'user_id'    => $userId,
            'emoji'      => $input['emoji'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->success(null, 'Reaction sent');
    }

    // ── POST /v1/live/:id/report ──────────────────────────────────────────────

    public function reportSession($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $input  = $this->inputJson();

        db_connect()->table('moderation_queue')->insert([
            'reference_type' => 'live_session',
            'reference_id'   => (int) $id,
            'reported_by'    => $userId,
            'reason'         => $input['reason'] ?? null,
            'status'         => 'pending',
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        return $this->success(null, 'Report submitted');
    }

    // ── POST /v1/live/:id/remind ──────────────────────────────────────────────

    public function toggleRemind($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = db_connect();

        try {
            $db->query("
                CREATE TABLE IF NOT EXISTS `live_reminders` (
                    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `session_id` BIGINT UNSIGNED NOT NULL,
                    `user_id`    INT UNSIGNED NOT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_reminder` (`session_id`, `user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) {}

        $session = $this->sessions->find((int) $id);
        if (! $session) {
            return $this->error('Live session not found.', 404);
        }
        if ($session['status'] !== 'scheduled') {
            return $this->error('Reminders can only be set for scheduled sessions.', 422);
        }

        $exists = $db->table('live_reminders')
            ->where('session_id', (int) $id)
            ->where('user_id', $userId)
            ->countAllResults();

        if ($exists) {
            $db->table('live_reminders')
                ->where('session_id', (int) $id)
                ->where('user_id', $userId)
                ->delete();
            return $this->success(['reminded' => false]);
        }

        $db->table('live_reminders')->insert([
            'session_id' => (int) $id,
            'user_id'    => $userId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->success(['reminded' => true]);
    }

    // ── GET /v1/live/:id/replay ───────────────────────────────────────────────

    public function replay($id = null): ResponseInterface
    {
        $session = $this->sessions->getWithHost((int) $id);

        if ($session === null) {
            return $this->error('Live session not found.', 404);
        }
        if ($session['status'] !== 'ended') {
            return $this->error('Replay is only available for ended sessions.', 422);
        }
        if (empty($session['replay_url'])) {
            return $this->error('Replay is not yet available for this session.', 404);
        }

        return $this->success([
            'replay_url' => $session['replay_url'],
            'session'    => LiveSessionModel::format($session),
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function ensureLiveTables(): void
    {
        $db = db_connect();

        // Create live_sessions table if missing
        try {
            $db->query("
                CREATE TABLE IF NOT EXISTS `live_sessions` (
                    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `host_id`           INT UNSIGNED    NOT NULL,
                    `title`             VARCHAR(255)    NOT NULL,
                    `category`          VARCHAR(100)    NOT NULL,
                    `description`       TEXT            DEFAULT NULL,
                    `linked_listing_id` BIGINT UNSIGNED DEFAULT NULL,
                    `circle_id`         BIGINT UNSIGNED DEFAULT NULL,
                    `movement_id`       BIGINT UNSIGNED DEFAULT NULL,
                    `action_id`         BIGINT UNSIGNED DEFAULT NULL,
                    `visibility`        VARCHAR(40)     NOT NULL DEFAULT 'public',
                    `agora_channel`     VARCHAR(120)    NOT NULL DEFAULT '',
                    `agora_token`       TEXT            DEFAULT NULL,
                    `viewer_count`      INT UNSIGNED    NOT NULL DEFAULT 0,
                    `status`            ENUM('pending','active','ended','scheduled') NOT NULL DEFAULT 'pending',
                    `scheduled_at`      DATETIME        DEFAULT NULL,
                    `started_at`        DATETIME        DEFAULT NULL,
                    `ended_at`          DATETIME        DEFAULT NULL,
                    `replay_url`        VARCHAR(500)    DEFAULT NULL,
                    `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_ls_host`   (`host_id`),
                    KEY `idx_ls_status` (`status`, `viewer_count`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\Throwable $e) {}

        // Self-heal missing columns
        $alterCols = [
            "ALTER TABLE `live_sessions` ADD COLUMN `description`       TEXT            DEFAULT NULL",
            "ALTER TABLE `live_sessions` ADD COLUMN `linked_listing_id` BIGINT UNSIGNED DEFAULT NULL",
            "ALTER TABLE `live_sessions` ADD COLUMN `circle_id`         BIGINT UNSIGNED DEFAULT NULL",
            "ALTER TABLE `live_sessions` ADD COLUMN `movement_id`       BIGINT UNSIGNED DEFAULT NULL",
            "ALTER TABLE `live_sessions` ADD COLUMN `action_id`         BIGINT UNSIGNED DEFAULT NULL",
            "ALTER TABLE `live_sessions` ADD COLUMN `visibility`        VARCHAR(40)     NOT NULL DEFAULT 'public'",
            "ALTER TABLE `live_sessions` ADD COLUMN `scheduled_at`      DATETIME        DEFAULT NULL",
            "ALTER TABLE `live_sessions` ADD COLUMN `ended_at`          DATETIME        DEFAULT NULL",
            "ALTER TABLE `live_sessions` ADD COLUMN `replay_url`        VARCHAR(500)    DEFAULT NULL",
            "ALTER TABLE `live_sessions` MODIFY COLUMN `status` ENUM('pending','active','ended','scheduled') NOT NULL DEFAULT 'pending'",
            "ALTER TABLE `live_sessions` MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'pending'",
        ];
        foreach ($alterCols as $sql) {
            try { $db->query($sql); } catch (\Throwable $e) {}
        }

        // live_comments table
        try {
            $db->query("
                CREATE TABLE IF NOT EXISTS `live_comments` (
                    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `session_id` BIGINT UNSIGNED NOT NULL,
                    `user_id`    INT UNSIGNED    NOT NULL,
                    `body`       VARCHAR(200)    NOT NULL,
                    `is_pinned`  TINYINT(1)      NOT NULL DEFAULT 0,
                    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_lc_session` (`session_id`, `created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) {}
        try { $db->query("ALTER TABLE `live_comments` ADD COLUMN `is_pinned` TINYINT(1) NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}

        // live_reactions table
        try {
            $db->query("
                CREATE TABLE IF NOT EXISTS `live_reactions` (
                    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `session_id` BIGINT UNSIGNED NOT NULL,
                    `user_id`    INT UNSIGNED    NOT NULL,
                    `emoji`      VARCHAR(10)     NOT NULL,
                    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) {}
    }

    private function generateLiveKitToken(string $roomName, string $identity, bool $canPublish): string
    {
        $apiKey    = 'APIRV4kSEhDLFHV';
        $apiSecret = 'QP3IfYyWvPjfAn7IYdeyHwAzBYOyGs2U6VL0dgZlwfvC';
        $now       = time();

        $header  = $this->base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->base64url(json_encode([
            'iss'   => $apiKey,
            'sub'   => $identity,
            'jti'   => $identity . '_' . $now,
            'exp'   => $now + 21600,
            'nbf'   => $now,
            'video' => [
                'room'           => $roomName,
                'roomJoin'       => true,
                'canPublish'     => $canPublish,
                'canSubscribe'   => true,
                'canPublishData' => true,
            ],
        ]));
        $sig = $this->base64url(hash_hmac('sha256', "$header.$payload", $apiSecret, true));
        return "$header.$payload.$sig";
    }

    private function notifyFollowers(int $hostId, string $sessionId, string $title): void
    {
        $serverKey = env('FIREBASE_SERVER_KEY', '');
        if (empty($serverKey)) return;

        $db = db_connect();

        // Get host name
        $host = $db->table('users')->select('name')->where('id', $hostId)->get()->getRowArray();
        $hostName = $host['name'] ?? 'Someone';

        // Get all followers' FCM tokens
        $followers = $db->table('user_follows uf')
            ->select('ft.token')
            ->join('fcm_tokens ft', 'ft.user_id = uf.follower_id')
            ->join('(SELECT user_id, MAX(updated_at) AS max_ua FROM fcm_tokens GROUP BY user_id) latest',
                   'latest.user_id = ft.user_id AND latest.max_ua = ft.updated_at')
            ->where('uf.following_id', $hostId)
            ->get()->getResultArray();

        if (empty($followers)) return;

        $tokens = array_column($followers, 'token');

        // FCM multicast (max 500 per request)
        foreach (array_chunk($tokens, 500) as $chunk) {
            $payload = json_encode([
                'registration_ids' => $chunk,
                'notification' => [
                    'title' => "{$hostName} is now LIVE 🔴",
                    'body'  => $title,
                ],
                'data' => [
                    'type'       => 'live_starting',
                    'session_id' => $sessionId,
                ],
            ]);

            $ch = curl_init('https://fcm.googleapis.com/fcm/send');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    "Authorization: key={$serverKey}",
                ],
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function checkLiveRateLimit(): bool
    {
        $userId   = $this->authUserId();
        $cacheKey = 'live_start_' . $userId;
        $cache    = \Config\Services::cache();
        $hits     = (int) ($cache->get($cacheKey) ?? 0);

        if ($hits >= 5) {
            return false;
        }

        $cache->save($cacheKey, $hits + 1, 60);
        return true;
    }

    // ── POST /v1/live/:id/cohost-join ─────────────────────────────────────────

    public function cohostJoin($id = null): ResponseInterface
    {
        $userId  = $this->authUserId();
        $session = $this->sessions->getWithHost((int) $id);

        if ($session === null) {
            return $this->error('Live session not found.', 404);
        }
        if ($session['status'] !== 'active') {
            return $this->error('This live session has ended.', 410);
        }

        $db = db_connect();
        $isCohost = $db->table('live_cohosts')
            ->where('session_id', (int) $id)
            ->where('user_id', $userId)
            ->countAllResults() > 0;

        if (! $isCohost) {
            return $this->error('You have not been invited as a co-host.', 403);
        }

        $token = $this->generateLiveKitToken(
            $session['agora_channel'],
            'cohost_' . $userId,
            true
        );

        return $this->success([
            'id'               => (string) $session['id'],
            'host_id'          => (string) $session['host_id'],
            'host_name'        => $session['host_name']        ?? '',
            'host_avatar_url'  => $session['host_avatar']      ?? null,
            'host_trust_level' => $session['host_trust_level'] ?? 'community_submitted',
            'title'            => $session['title'],
            'category'         => $session['category'],
            'linked_listing_id' => $session['linked_listing_id'] ? (string) $session['linked_listing_id'] : null,
            'room_name'        => $session['agora_channel'],
            'token'            => $token,
            'server_url'       => 'wss://dim-z07mwg4s.livekit.cloud',
            'viewer_count'     => (int) $session['viewer_count'],
            'status'           => $session['status'],
            'started_at'       => $session['started_at'],
        ]);
    }

    private function notifyReminders(int $sessionId, string $title): void
    {
        $db = db_connect();
        try {
            $reminders = $db->table('live_reminders lr')
                ->select('ft.token')
                ->join('fcm_tokens ft', 'ft.user_id = lr.user_id')
                ->join('(SELECT user_id, MAX(updated_at) AS max_ua FROM fcm_tokens GROUP BY user_id) latest',
                       'latest.user_id = ft.user_id AND latest.max_ua = ft.updated_at')
                ->where('lr.session_id', $sessionId)
                ->get()->getResultArray();

            if (empty($reminders)) return;

            $tokens     = array_column($reminders, 'token');
            $serverKey  = env('FIREBASE_SERVER_KEY', '');
            if (empty($serverKey)) return;

            foreach (array_chunk($tokens, 500) as $chunk) {
                $payload = json_encode([
                    'registration_ids' => $chunk,
                    'notification' => [
                        'title' => 'Live session starting now 🔴',
                        'body'  => $title,
                    ],
                    'data' => [
                        'type'       => 'live_starting',
                        'session_id' => (string) $sessionId,
                    ],
                ]);
                $ch = curl_init('https://fcm.googleapis.com/fcm/send');
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 5,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        "Authorization: key={$serverKey}",
                    ],
                ]);
                curl_exec($ch);
                curl_close($ch);
            }

            // Clean up reminders for this session
            $db->table('live_reminders')->where('session_id', $sessionId)->delete();
        } catch (\Throwable $e) {}
    }

    private function notifyCohost(int $userId, array $session): void
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        // Save in-app notification so it appears in the notifications list
        $db->table('notifications')->insert([
            'user_id'        => $userId,
            'type'           => 'cohost_invite',
            'title'          => "You've been invited to co-host!",
            'body'           => "Join \"{$session['title']}\" now",
            'reference_id'   => $session['id'],
            'reference_type' => 'live',
            'is_read'        => 0,
            'created_at'     => $now,
        ]);

        $fcmRow = $db->table('fcm_tokens')
            ->where('user_id', $userId)
            ->orderBy('updated_at', 'DESC')
            ->limit(1)->get()->getRowArray();

        if ($fcmRow === null) return;

        $serverKey = env('FIREBASE_SERVER_KEY', '');
        if (empty($serverKey)) return;

        $payload = json_encode([
            'to'           => $fcmRow['token'],
            'notification' => [
                'title' => 'You\'ve been invited to co-host!',
                'body'  => "Join \"{$session['title']}\" now",
            ],
            'data' => [
                'type'       => 'cohost_invite',
                'session_id' => (string) $session['id'],
            ],
        ]);

        $ch = curl_init('https://fcm.googleapis.com/fcm/send');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: key={$serverKey}",
            ],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
