<?php

namespace App\Models;

use CodeIgniter\Model;

class LiveSessionModel extends Model
{
    protected $table         = 'live_sessions';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'host_id', 'title', 'category', 'linked_listing_id',
        'agora_channel', 'agora_token', 'viewer_count',
        'status', 'started_at', 'ended_at', 'replay_url',
    ];

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function active(): static
    {
        return $this->where('status', 'active');
    }

    // ── Queries ───────────────────────────────────────────────────────────────

    /**
     * Returns active sessions with host info, ordered by viewer count.
     */
    public function getActiveSessions(): array
    {
        return $this->select("
                live_sessions.*,
                u.name        AS host_name,
                u.avatar_url  AS host_avatar,
                u.trust_level AS host_trust_level,
                u.trust_label AS host_trust_label,
                l.title       AS linked_listing_title,
                l.cover_url   AS linked_listing_cover
            ")
            ->join('users u', 'u.id = live_sessions.host_id')
            ->join('listings l', 'l.id = live_sessions.linked_listing_id', 'left')
            ->where('live_sessions.status', 'active')
            ->orderBy('live_sessions.viewer_count', 'DESC')
            ->findAll();
    }

    public function getWithHost(int $sessionId): ?array
    {
        return $this->select("
                live_sessions.*,
                u.name        AS host_name,
                u.avatar_url  AS host_avatar,
                u.trust_level AS host_trust_level,
                u.trust_label AS host_trust_label
            ")
            ->join('users u', 'u.id = live_sessions.host_id')
            ->find($sessionId);
    }

    public function cohostCount(int $sessionId): int
    {
        return (int) db_connect()
            ->table('live_cohosts')
            ->where('session_id', $sessionId)
            ->countAllResults();
    }

    // ── Formatting ────────────────────────────────────────────────────────────

    public static function format(array $row, ?string $token = null): array
    {
        $validTrustLevels = [
            'institution_verified', 'curator_reviewed', 'community_submitted',
            'approved_live_host', 'needs_reconfirmation',
        ];
        $trustLevel = $row['host_trust_level'] ?? 'community_submitted';
        if (! in_array($trustLevel, $validTrustLevels, true)) {
            $trustLevel = 'community_submitted';
        }

        $out = [
            'id'                    => (string) $row['id'],
            'host_id'               => (string) $row['host_id'],
            'host_name'             => $row['host_name']   ?? 'Unknown',
            'host_avatar_url'       => $row['host_avatar'] ?? null,
            'host_trust_level'      => $trustLevel,
            'title'                 => $row['title']    ?? '',
            'category'              => $row['category'] ?? '',
            'linked_listing_id'     => ! empty($row['linked_listing_id']) ? (string) $row['linked_listing_id'] : null,
            'room_name'             => $row['agora_channel'] ?? '',
            'viewer_count'          => (int) ($row['viewer_count'] ?? 0),
            'status'                => $row['status']    ?? 'active',
            'started_at'            => $row['started_at'] ?? $row['created_at'] ?? date('Y-m-d H:i:s'),
            'ended_at'              => $row['ended_at']   ?? null,
            'replay_url'            => $row['replay_url'] ?? null,
        ];

        if ($token !== null) {
            $out['token']      = $token;
            $out['server_url'] = 'wss://dim-z07mwg4s.livekit.cloud';
        }

        return $out;
    }
}
