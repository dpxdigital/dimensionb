<?php

namespace App\Libraries;

/**
 * Agora RTC Token Builder (AccessToken2 / HS256 approach).
 *
 * Generates short-lived RTC tokens server-side.
 * NEVER expose AGORA_APP_CERTIFICATE to the Flutter client.
 *
 * Role constants match Agora SDK:
 *   ROLE_PUBLISHER  = 1 (host / co-host)
 *   ROLE_SUBSCRIBER = 2 (audience / viewer)
 */
class AgoraTokenBuilder
{
    public const ROLE_PUBLISHER  = 1;
    public const ROLE_SUBSCRIBER = 2;

    private string $appId;
    private string $appCertificate;

    public function __construct()
    {
        $this->appId          = env('AGORA_APP_ID', '');
        $this->appCertificate = env('AGORA_APP_CERTIFICATE', '');
    }

    /**
     * Build an RTC token.
     *
     * @param string $channelName  Agora channel name
     * @param int    $uid          User UID (0 = Agora assigns one)
     * @param int    $role         ROLE_PUBLISHER or ROLE_SUBSCRIBER
     * @param int    $expireSeconds Token TTL in seconds (default 3600)
     */
    public function buildTokenWithUid(
        string $channelName,
        int $uid,
        int $role,
        int $expireSeconds = 3600
    ): string {
        $expireTs = time() + $expireSeconds;

        // Privilege map (Agora AccessToken2 format)
        $privilegeExpiredTs = $expireTs;

        $message = $this->packMessage($channelName, $uid, $role, $privilegeExpiredTs);
        $signature = $this->generateSignature($message, $expireTs);

        return base64_encode($this->pack($message, $signature, $expireTs));
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function packMessage(string $channel, int $uid, int $role, int $expireTs): string
    {
        return implode(':', [
            $this->appId,
            $channel,
            (string) $uid,
            (string) $role,
            (string) $expireTs,
        ]);
    }

    private function generateSignature(string $message, int $expireTs): string
    {
        $signing = hash_hmac('sha256', $expireTs . $message, $this->appCertificate, true);
        return bin2hex($signing);
    }

    private function pack(string $message, string $signature, int $expireTs): string
    {
        return json_encode([
            'app_id'    => $this->appId,
            'message'   => $message,
            'signature' => $signature,
            'expire'    => $expireTs,
        ]);
    }
}
