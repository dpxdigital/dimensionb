<?php

namespace App\Controllers\Api\Live;

use App\Controllers\Api\BaseApiController;
use App\Libraries\AgoraTokenGenerator;
use App\Models\ActivityLogModel;
use App\Models\LiveSessionModel;
use CodeIgniter\HTTP\ResponseInterface;

class LiveController extends BaseApiController
{
    private LiveSessionModel    $sessions;
    private AgoraTokenGenerator $agora;

    public function __construct()
    {
        $this->sessions = new LiveSessionModel();
        // Agora may not be configured in dev — catch to prevent boot crash
        try {
            $this->agora = new AgoraTokenGenerator();
        } catch (\RuntimeException) {
            $this->agora = null;
        }
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

        // Generate a unique channel name
        $channel = 'dim_' . bin2hex(random_bytes(8));

        // Generate host Agora token
        $agoraToken = null;
        $appId      = env('AGORA_APP_ID', '');

        if ($this->agora !== null) {
            $agoraToken = $this->agora->generateHostToken($channel, $userId);
            $appId      = $this->agora->getAppId();
        }

        $sessionId = $this->sessions->insert([
            'host_id'       => $userId,
            'title'         => trim($input['title']),
            'category'      => trim($input['category']),
            'linked_listing_id' => $input['linked_listing_id'] ?? null,
            'agora_channel' => $channel,
            'agora_token'   => $agoraToken,
            'viewer_count'  => 0,
            'status'        => 'active',
            'started_at'    => date('Y-m-d H:i:s'),
        ]);

        (new ActivityLogModel())->log($userId, 0, 'went_live');

        // NEVER include AGORA_APP_CERTIFICATE in response
        return $this->success([
            'session_id'    => (int) $sessionId,
            'agora_channel' => $channel,
            'agora_token'   => $agoraToken,
            'app_id'        => $appId,
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
        $session = $this->sessions->find((int) $id);

        if ($session === null) {
            return $this->error('Live session not found.', 404);
        }
        if ($session['status'] !== 'active') {
            return $this->error('This live session has ended.', 410);
        }

        // Generate viewer token
        $agoraToken = null;
        $appId      = env('AGORA_APP_ID', '');

        if ($this->agora !== null) {
            $agoraToken = $this->agora->generateViewerToken($session['agora_channel'], $userId);
            $appId      = $this->agora->getAppId();
        }

        // Increment viewer count
        db_connect()->table('live_sessions')
            ->where('id', $id)
            ->set('viewer_count', 'viewer_count + 1', false)
            ->update();

        (new ActivityLogModel())->log($userId, 0, 'watched');

        return $this->success([
            'agora_channel' => $session['agora_channel'],
            'agora_token'   => $agoraToken,
            'app_id'        => $appId,
        ]);
    }

    // ── POST /v1/live/:id/cohost ──────────────────────────────────────────────

    public function addCohost($id = null): ResponseInterface
    {
        $userId  = $this->authUserId();
        $session = $this->sessions->find((int) $id);

        if ($session === null) {
            return $this->error('Live session not found.', 404);
        }
        if ((int) $session['host_id'] !== $userId) {
            return $this->error('Only the host can invite co-hosts.', 403);
        }

        $input       = $this->inputJson();
        $cohostUserId = (int) ($input['user_id'] ?? 0);

        if ($cohostUserId === 0) {
            return $this->error('user_id is required.', 422);
        }

        // Max 9 co-hosts (10 including host)
        if ($this->sessions->cohostCount((int) $id) >= 9) {
            return $this->error('Maximum 9 co-hosts reached.', 422);
        }

        $db = db_connect();

        // Check not already a co-host
        $exists = $db->table('live_cohosts')
            ->where('session_id', $id)
            ->where('user_id', $cohostUserId)
            ->get()->getRowArray();

        if ($exists) {
            return $this->error('User is already a co-host.', 409);
        }

        $db->table('live_cohosts')->insert([
            'session_id' => (int) $id,
            'user_id'    => $cohostUserId,
            'joined_at'  => date('Y-m-d H:i:s'),
        ]);

        // FCM push notification to the invited user (fire and forget)
        $this->notifyCohost($cohostUserId, $session);

        return $this->success(null, 'Co-host invited');
    }

    // ── DELETE /v1/live/:id/cohost (remove co-host) ────────────────────────────

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

        $comment = $db->table('live_comments lc')
            ->select('lc.id, lc.body, lc.created_at, u.id AS user_id, u.name AS user_name, u.avatar_url')
            ->join('users u', 'u.id = lc.user_id')
            ->where('lc.id', $db->insertID())
            ->get()->getRowArray();

        return $this->success(['comment' => $comment], 'Comment added', 201);
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

    private function notifyCohost(int $userId, array $session): void
    {
        // Fire-and-forget FCM push — best effort
        $fcmRow = db_connect()->table('fcm_tokens')
            ->where('user_id', $userId)
            ->orderBy('updated_at', 'DESC')
            ->limit(1)->get()->getRowArray();

        if ($fcmRow === null) {
            return;
        }

        $serverKey = env('FIREBASE_SERVER_KEY', '');
        if (empty($serverKey)) {
            return;
        }

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

        // Non-blocking cURL
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
