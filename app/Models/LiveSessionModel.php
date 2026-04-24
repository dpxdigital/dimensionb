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

    public static function format(array $row, bool $includeToken = false): array
    {
        $out = [
            'id'                   => (int) $row['id'],
            'hostId'               => (int) $row['host_id'],
            'hostName'             => $row['host_name']        ?? null,
            'hostAvatar'           => $row['host_avatar']      ?? null,
            'hostTrustLevel'       => $row['host_trust_level'] ?? null,
            'title'                => $row['title'],
            'category'             => $row['category'],
            'linkedListingId'      => $row['linked_listing_id'] ? (int) $row['linked_listing_id'] : null,
            'linkedListingTitle'   => $row['linked_listing_title'] ?? null,
            'agoraChannel'         => $row['agora_channel'],
            'viewerCount'          => (int) $row['viewer_count'],
            'status'               => $row['status'],
            'startedAt'            => $row['started_at'],
            'endedAt'              => $row['ended_at']   ?? null,
            'replayUrl'            => $row['replay_url'] ?? null,
        ];

        if ($includeToken) {
            $out['agoraToken'] = $row['agora_token'];
        }

        return $out;
    }
}
