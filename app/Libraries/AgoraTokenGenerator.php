<?php

namespace App\Libraries;

/**
 * Agora AccessToken2 generator — HS256/HMAC, no external SDK required.
 *
 * Implements the Agora token spec:
 * https://docs.agora.io/en/video-calling/token-server-side/authentication-workflow
 *
 * NEVER expose AGORA_APP_CERTIFICATE in any API response.
 * Return only agora_token (time-limited) and AGORA_APP_ID to the client.
 *
 * Role constants:
 *   ROLE_PUBLISHER  = 1  (host / co-host)
 *   ROLE_SUBSCRIBER = 2  (viewer / audience)
 */
class AgoraTokenGenerator
{
    public const ROLE_PUBLISHER  = 1;
    public const ROLE_SUBSCRIBER = 2;

    private const VERSION       = '007';
    private const EXPIRY_SECONDS = 3600; // 1 hour

    private string $appId;
    private string $appCertificate;

    public function __construct()
    {
        $appId          = env('AGORA_APP_ID');
        $appCertificate = env('AGORA_APP_CERTIFICATE');

        if (empty($appId) || empty($appCertificate)) {
            throw new \RuntimeException('AGORA_APP_ID and AGORA_APP_CERTIFICATE must be set in .env');
        }

        $this->appId          = $appId;
        $this->appCertificate = $appCertificate;
    }

    /**
     * Generate a host (publisher) token.
     */
    public function generateHostToken(string $channelName, int $userId, int $expireSeconds = self::EXPIRY_SECONDS): string
    {
        return $this->buildToken($channelName, (string) $userId, self::ROLE_PUBLISHER, $expireSeconds);
    }

    /**
     * Generate a viewer (subscriber) token.
     */
    public function generateViewerToken(string $channelName, int $userId, int $expireSeconds = self::EXPIRY_SECONDS): string
    {
        return $this->buildToken($channelName, (string) $userId, self::ROLE_SUBSCRIBER, $expireSeconds);
    }

    /**
     * Returns only the App ID (safe for client responses).
     * Never call env('AGORA_APP_CERTIFICATE') from a controller.
     */
    public function getAppId(): string
    {
        return $this->appId;
    }

    // ── Token construction (Agora AccessToken2 format) ────────────────────────

    private function buildToken(string $channel, string $uid, int $role, int $ttl): string
    {
        $expireTs  = time() + $ttl;
        $issueTs   = time();
        $salt      = random_int(1, 0x7FFFFFFF);
        $nonce     = $this->packUint32($salt) . $this->packUint32($issueTs) . $this->packUint32($expireTs);

        // Privilege map: join-channel = 1, publish-audio = 2, publish-video = 3
        $privileges = [
            1 => $expireTs, // join channel
            2 => $role === self::ROLE_PUBLISHER ? $expireTs : 0, // publish audio
            3 => $role === self::ROLE_PUBLISHER ? $expireTs : 0, // publish video
            4 => $role === self::ROLE_PUBLISHER ? $expireTs : 0, // publish data stream
            5 => $expireTs, // subscribe audio
            6 => $expireTs, // subscribe video
            7 => $expireTs, // subscribe data stream
        ];

        $privilegeMsg = '';
        foreach ($privileges as $key => $value) {
            $privilegeMsg .= $this->packUint16($key) . $this->packUint32($value);
        }

        $message = $this->packUint32($salt)
            . $this->packUint32($issueTs)
            . $this->packUint32($expireTs)
            . $this->packString($channel)
            . $this->packString($uid)
            . pack('v', count($privileges))
            . $privilegeMsg;

        $signing = hash_hmac('sha256', $message, $this->appCertificate, true);

        $content = $nonce . $this->packString($channel) . $this->packString($uid)
            . pack('v', count($privileges)) . $privilegeMsg;

        $token = self::VERSION . $this->appId . base64_encode($signing . $content);

        return $token;
    }

    private function packUint16(int $v): string
    {
        return pack('v', $v); // little-endian uint16
    }

    private function packUint32(int $v): string
    {
        return pack('V', $v); // little-endian uint32
    }

    private function packString(string $s): string
    {
        return pack('v', strlen($s)) . $s; // uint16 length prefix + content
    }
}
