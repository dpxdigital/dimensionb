<?php

namespace App\Libraries;

class FCMNotificationService
{
    private string $projectId;
    private static string $cachedToken  = '';
    private static int    $tokenExpiry  = 0;

    public function __construct()
    {
        $this->projectId = env('FIREBASE_PROJECT_ID', '');
    }

    // ── Public send helpers ───────────────────────────────────────────────────

    public function sendToUser(int $userId, string $title, string $body, array $data = []): void
    {
        $tokens = $this->getTokensForUser($userId);
        if (! empty($tokens)) $this->dispatch($tokens, $title, $body, $data);
    }

    public function sendToMultiple(array $userIds, string $title, string $body, array $data = []): void
    {
        if (empty($userIds)) return;
        $tokens = $this->getTokensForUsers($userIds);
        foreach (array_chunk($tokens, 500) as $chunk) {
            $this->dispatch($chunk, $title, $body, $data);
        }
    }

    // ── Typed notification helpers ────────────────────────────────────────────

    public function notifyEventReminder(int $userId, int $listingId, string $listingTitle): void
    {
        $this->sendToUser($userId, 'Event Reminder', "\"$listingTitle\" is coming up soon!", [
            'type'       => 'event_reminder',
            'listing_id' => (string) $listingId,
            'deep_link'  => "/listing/$listingId",
        ]);
    }

    public function notifyLiveStarting(int $userId, int $sessionId, string $hostName): void
    {
        $this->sendToUser($userId, 'Live Now!', "$hostName just went live", [
            'type'       => 'live_starting',
            'session_id' => (string) $sessionId,
            'deep_link'  => "/live/watch/$sessionId",
        ]);
    }

    public function notifyNewMatch(int $userId, int $listingId, string $listingTitle): void
    {
        $this->sendToUser($userId, 'New Match', "\"$listingTitle\" matches your interests", [
            'type'       => 'new_match',
            'listing_id' => (string) $listingId,
            'deep_link'  => "/listing/$listingId",
        ]);
    }

    public function notifySubmissionStatus(int $userId, int $submissionId, string $status): void
    {
        $isApproved = $status === 'approved';
        $this->sendToUser($userId,
            $isApproved ? 'Submission Approved!' : 'Submission Update',
            $isApproved ? 'Your submission has been approved and is now live.' : 'Your submission needs review.',
            [
                'type'          => "submission_$status",
                'submission_id' => (string) $submissionId,
                'deep_link'     => '/activity/submissions',
            ]
        );
    }

    public function notifyNewMessage(int $userId, int $conversationId, string $senderName): void
    {
        $this->sendToUser($userId, $senderName, "{$senderName} sent you a message", [
            'type'            => 'new_message',
            'conversation_id' => (string) $conversationId,
            'sender_name'     => $senderName,
            'deep_link'       => "/chat/$conversationId",
        ]);
    }

    public function notifyNewGroupMessage(int $userId, int $groupId, string $groupName, string $senderName = ''): void
    {
        $this->sendToUser($userId, $groupName, $senderName ? "$senderName sent a message" : 'New message in group', [
            'type'        => 'new_group_message',
            'group_id'    => (string) $groupId,
            'sender_name' => $senderName,
            'deep_link'   => "/chat/group/$groupId",
        ]);
    }

    public function notifyConnectionRequest(int $userId, string $requesterName): void
    {
        $this->sendToUser($userId, 'Connection Request', "$requesterName wants to connect", [
            'type'        => 'connection_request',
            'sender_name' => $requesterName,
            'deep_link'   => '/chat/requests',
        ]);
    }

    public function notifyConnectionAccepted(int $userId, int $conversationId, string $acceptorName): void
    {
        $this->sendToUser($userId, 'Connection Accepted', "$acceptorName accepted your request", [
            'type'            => 'connection_accepted',
            'conversation_id' => (string) $conversationId,
            'sender_name'     => $acceptorName,
            'deep_link'       => "/chat/$conversationId",
        ]);
    }

    public function notifyAddedToGroup(int $userId, int $groupId, string $groupName): void
    {
        $this->sendToUser($userId, 'Added to Group', "You've been added to \"$groupName\"", [
            'type'      => 'added_to_group',
            'group_id'  => (string) $groupId,
            'deep_link' => "/chat/group/$groupId",
        ]);
    }

    // ── Private: OAuth2 access token (FCM v1 API) ─────────────────────────────

    private function getAccessToken(): string
    {
        if (self::$cachedToken !== '' && time() < self::$tokenExpiry) {
            return self::$cachedToken;
        }

        $raw = env('FIREBASE_SERVICE_ACCOUNT_JSON', '');
        // CI4 DotEnv cannot handle \n inside single-quoted values, so the outer
        // single quotes may be left intact. Strip them before decoding.
        if ($raw !== '' && $raw[0] === "'" && $raw[-1] === "'") {
            $raw = substr($raw, 1, -1);
        }
        $sa  = $raw ? json_decode($raw, true) : null;

        if (empty($sa['private_key']) || empty($sa['client_email'])) {
            log_message('error', '[FCM] FIREBASE_SERVICE_ACCOUNT_JSON not configured or invalid.');
            return '';
        }

        $now    = time();
        $header = $this->b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->b64u(json_encode([
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $input = "{$header}.{$claims}";
        if (! openssl_sign($input, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
            log_message('error', '[FCM] Failed to sign JWT — check private key.');
            return '';
        }
        $jwt = "{$input}." . $this->b64u($sig);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
        ]);
        $res  = json_decode(curl_exec($ch), true);
        curl_close($ch);

        $token = $res['access_token'] ?? '';
        if ($token === '') {
            log_message('error', '[FCM] Failed to get access token: ' . json_encode($res));
            return '';
        }

        self::$cachedToken = $token;
        self::$tokenExpiry = $now + 3500;
        return $token;
    }

    // ── Private: dispatch messages ────────────────────────────────────────────

    private function dispatch(array $tokens, string $title, string $body, array $data): void
    {
        if (empty($this->projectId) || empty($tokens)) return;

        $accessToken = $this->getAccessToken();
        if ($accessToken === '') return;

        $url     = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";
        $headers = [
            'Content-Type: application/json',
            "Authorization: Bearer {$accessToken}",
        ];

        // FCM v1 requires all data values to be strings
        $strData    = array_map('strval', $data);
        $channelId  = $this->channelId($data['type'] ?? '');

        foreach ($tokens as $token) {
            $payload = json_encode([
                'message' => [
                    'token'        => $token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data'         => $strData,
                    'android'      => [
                        'priority'     => 'high',
                        'notification' => [
                            'sound'      => 'notification_sound',
                            'channel_id' => $channelId,
                            'icon'       => 'ic_notification',
                            'color'      => '#D94032',
                        ],
                    ],
                    'apns' => [
                        'headers' => ['apns-priority' => '10'],
                        'payload' => ['aps' => ['sound' => 'default', 'badge' => 1]],
                    ],
                ],
            ]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_HTTPHEADER     => $headers,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) continue;

            // Remove stale/unregistered tokens
            if ($httpCode === 404) {
                $errCode = json_decode($response, true)['error']['details'][0]['errorCode'] ?? '';
                if (in_array($errCode, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
                    db_connect()->table('fcm_tokens')->where('token', $token)->delete();
                }
            } elseif ($httpCode === 401) {
                // Access token expired or invalid — clear cached token so next call re-fetches
                self::$cachedToken = '';
                self::$tokenExpiry = 0;
                log_message('error', '[FCM] 401 Unauthorized — service account may be misconfigured.');
            } else {
                log_message('error', "[FCM] Unexpected HTTP $httpCode: $response");
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function channelId(string $type): string
    {
        $chat = ['new_message', 'new_group_message', 'connection_request', 'connection_accepted', 'added_to_group'];
        $live = ['live_starting'];
        if (in_array($type, $chat, true)) return 'dimensions_messages';
        if (in_array($type, $live, true)) return 'dimensions_live';
        return 'dimensions_default';
    }

    private function b64u(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function getTokensForUser(int $userId): array
    {
        return array_column(
            db_connect()->table('fcm_tokens')->select('token')->where('user_id', $userId)->get()->getResultArray(),
            'token'
        );
    }

    private function getTokensForUsers(array $userIds): array
    {
        return array_column(
            db_connect()->table('fcm_tokens')->select('token')->whereIn('user_id', $userIds)->get()->getResultArray(),
            'token'
        );
    }
}
