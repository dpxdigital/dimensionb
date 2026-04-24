<?php

namespace App\Libraries;

class FCMNotificationService
{
    private string $serverKey;
    private string $fcmUrl = 'https://fcm.googleapis.com/fcm/send';

    public function __construct()
    {
        $this->serverKey = env('FIREBASE_SERVER_KEY', '');
    }

    /**
     * Send a push notification to a single user (all their devices).
     */
    public function sendToUser(int $userId, string $title, string $body, array $data = []): void
    {
        $tokens = $this->getTokensForUser($userId);
        if (empty($tokens)) {
            return;
        }
        $this->dispatch($tokens, $title, $body, $data);
    }

    /**
     * Send a push notification to multiple users.
     */
    public function sendToMultiple(array $userIds, string $title, string $body, array $data = []): void
    {
        if (empty($userIds)) {
            return;
        }
        $tokens = $this->getTokensForUsers($userIds);
        if (empty($tokens)) {
            return;
        }
        // FCM allows up to 1000 registration IDs per request
        foreach (array_chunk($tokens, 1000) as $chunk) {
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
        $title = $isApproved ? 'Submission Approved!' : 'Submission Update';
        $body  = $isApproved
            ? 'Your submission has been approved and is now live.'
            : 'Your submission needs review. Tap to see details.';

        $this->sendToUser($userId, $title, $body, [
            'type'          => "submission_$status",
            'submission_id' => (string) $submissionId,
            'deep_link'     => '/activity/submissions',
        ]);
    }

    public function notifyNewMessage(int $userId, int $conversationId, string $senderName): void
    {
        $this->sendToUser($userId, 'New Message', "Message from $senderName", [
            'type'            => 'new_message',
            'conversation_id' => (string) $conversationId,
            'deep_link'       => "/chat/$conversationId",
        ]);
    }

    public function notifyNewGroupMessage(int $userId, int $groupId, string $groupName): void
    {
        $this->sendToUser($userId, $groupName, 'New message in group', [
            'type'      => 'new_group_message',
            'group_id'  => (string) $groupId,
            'deep_link' => "/chat/group/$groupId",
        ]);
    }

    public function notifyConnectionRequest(int $userId, string $requesterName): void
    {
        $this->sendToUser($userId, 'Connection Request', "$requesterName wants to connect", [
            'type'      => 'connection_request',
            'deep_link' => '/chat/requests',
        ]);
    }

    public function notifyConnectionAccepted(int $userId, int $conversationId, string $acceptorName): void
    {
        $this->sendToUser($userId, 'Connection Accepted', "$acceptorName accepted your request", [
            'type'            => 'connection_accepted',
            'conversation_id' => (string) $conversationId,
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

    // ── Private helpers ───────────────────────────────────────────────────────

    private function getTokensForUser(int $userId): array
    {
        $rows = db_connect()
            ->table('fcm_tokens')
            ->select('token')
            ->where('user_id', $userId)
            ->get()->getResultArray();

        return array_column($rows, 'token');
    }

    private function getTokensForUsers(array $userIds): array
    {
        $rows = db_connect()
            ->table('fcm_tokens')
            ->select('token')
            ->whereIn('user_id', $userIds)
            ->get()->getResultArray();

        return array_column($rows, 'token');
    }

    private function dispatch(array $tokens, string $title, string $body, array $data): void
    {
        if (empty($this->serverKey) || empty($tokens)) {
            return;
        }

        $payload = json_encode([
            'registration_ids' => $tokens,
            'notification' => [
                'title' => $title,
                'body'  => $body,
                'sound' => 'default',
            ],
            'data' => $data,
        ]);

        $ch = curl_init($this->fcmUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: key={$this->serverKey}",
            ],
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        // Clean up stale tokens (invalid_registration / not_registered)
        if ($response !== false) {
            $this->cleanupStaleTokens($tokens, $response);
        }
    }

    private function cleanupStaleTokens(array $tokens, string $response): void
    {
        $decoded = json_decode($response, true);
        if (! isset($decoded['results'])) {
            return;
        }

        $stale = [];
        foreach ($decoded['results'] as $i => $result) {
            if (isset($result['error']) &&
                in_array($result['error'], ['InvalidRegistration', 'NotRegistered'], true)) {
                $stale[] = $tokens[$i];
            }
        }

        if (! empty($stale)) {
            db_connect()->table('fcm_tokens')->whereIn('token', $stale)->delete();
        }
    }
}
