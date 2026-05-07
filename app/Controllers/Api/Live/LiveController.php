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
        $rows = $this->sessions->getActiveSessions();
        return $this->success(array_map(fn($r) => LiveSessionModel::format($r), $rows));
    }

    // ── POST /v1/live/start ───────────────────────────────────────────────────

    public function start(): ResponseInterface
    {
        if (! $this->checkLiveRateLimit()) {
            return $this->error('Rate limit: max 5 live streams per minute.', 429);
        }

        $userId = $this->authUserId();
        $input  = $this->inputJson();

        $rules = [
            'title'    => 'required|max_length[255]',
            'category' => 'required|max_length[100]',
        ];
        if (! $this->validateData($input, $rules)) {
            return $this->validationError($this->validator->getErrors());
        }

        $db   = db_connect();
        $user = $db->table('users')->where('id', $userId)->get()->getRowArray();

        // Unique room name (reusing agora_channel column)
        $roomName = 'dim_' . bin2hex(random_bytes(8));

        // Generate host LiveKit token (canPublish = true)
        $token = $this->generateLiveKitToken($roomName, 'host_' . $userId, true);

        $sessionId = $this->sessions->insert([
            'host_id'       => $userId,
            'title'         => trim($input['title']),
            'category'      => trim($input['category']),
            'linked_listing_id' => $input['linked_listing_id'] ?? null,
            'agora_channel' => $roomName,
            'agora_token'   => $token,
            'viewer_count'  => 0,
            'status'        => 'active',
            'started_at'    => date('Y-m-d H:i:s'),
        ]);

        $this->notifyFollowers($userId, (string) $sessionId, trim($input['title']));

        return $this->success([
            'id'               => (string) $sessionId,
            'host_id'          => (string) $userId,
            'host_name'        => $user['name']        ?? '',
            'host_avatar_url'  => $user['avatar_url']  ?? null,
            'host_trust_level' => $user['trust_level'] ?? 'community_submitted',
            'title'            => trim($input['title']),
            'category'         => trim($input['category']),
            'linked_listing_id' => $input['linked_listing_id'] ?? null,
            'room_name'        => $roomName,
            'token'            => $token,
            'server_url'       => 'wss://dim-z07mwg4s.livekit.cloud',
            'viewer_count'     => 0,
            'status'           => 'active',
            'started_at'       => date('Y-m-d H:i:s'),
        ], 'Live session started', 201);
    }

    // ── GET /v1/live/:id ──────────────────────────────────────────────────────

    public function show($id = null): ResponseInterface
    {
        $session = $this->sessions->getWithHost((int) $id);
        if ($session === null) {
            return $this->error('Live session not found.', 404);
        }

        return $this->success(LiveSessionModel::format($session));
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

        $input = $this->inputJson();
        $this->sessions->update((int) $id, [
            'status'     => 'ended',
            'ended_at'   => date('Y-m-d H:i:s'),
            'replay_url' => $input['replay_url'] ?? null,
        ]);

        return $this->success(null, 'Live session ended');
    }

    // ── POST /v1/live/:id/join ────────────────────────────────────────────────

    public function join($id = null): ResponseInterface
    {
        $userId  = $this->authUserId();
        $session = $this->sessions->getWithHost((int) $id);

        if ($session === null) {
            return $this->error('Live session not found.', 404);
        }
        if ($session['status'] !== 'active') {
            return $this->error('This live session has ended.', 410);
        }

        // Generate viewer LiveKit token (canPublish = false)
        $token = $this->generateLiveKitToken($session['agora_channel'], 'viewer_' . $userId, false);

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
            'linked_listing_id' => $session['linked_listing_id'] ? (string) $session['linked_listing_id'] : null,
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
