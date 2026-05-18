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
        'host_id', 'title', 'category', 'description', 'linked_listing_id',
        'circle_id', 'movement_id', 'action_id', 'visibility',
        'agora_channel', 'agora_token', 'viewer_count',
        'status', 'scheduled_at', 'started_at', 'ended_at', 'replay_url',
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
    public function getActiveSessions(?int $circleId = null, ?int $movementId = null): array
    {
        $builder = $this->select("
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
            ->where('live_sessions.status', 'active');
        if ($circleId !== null)   $builder = $builder->where('live_sessions.circle_id', $circleId);
        if ($movementId !== null) $builder = $builder->where('live_sessions.movement_id', $movementId);
        return $builder->orderBy('live_sessions.viewer_count', 'DESC')->findAll();
    }

    /** Returns active (and recently ended) sessions scoped to a circle:
     *  - sessions directly attached to the circle
     *  - sessions attached to movements linked to that circle
     */
    public function getSessionsForCircle(int $circleId): array
    {
        $db      = \Config\Database::connect();
        $builder = $db->table('live_sessions ls');
        return $builder
            ->select("
                ls.*,
                u.name        AS host_name,
                u.avatar_url  AS host_avatar,
                u.trust_level AS host_trust_level,
                u.trust_label AS host_trust_label,
                l.title       AS linked_listing_title,
                l.cover_url   AS linked_listing_cover
            ")
            ->join('users u', 'u.id = ls.host_id')
            ->join('listings l', 'l.id = ls.linked_listing_id', 'left')
            ->whereIn('ls.status', ['active', 'scheduled', 'ended'])
            ->groupStart()
                ->where('ls.circle_id', $circleId)
                ->orWhere("ls.movement_id IN (SELECT movement_id FROM circle_movements WHERE circle_id = {$circleId} AND movement_id IS NOT NULL)")
            ->groupEnd()
            ->orderBy('FIELD(ls.status, "active", "scheduled", "ended")', 'ASC', false)
            ->orderBy('ls.viewer_count', 'DESC')
            ->orderBy('ls.scheduled_at', 'ASC')
            ->orderBy('ls.created_at', 'DESC')
            ->limit(50)
            ->get()
            ->getResultArray();
    }

    public function getSessionsForMovement(int $movementId): array
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
            ->whereIn('live_sessions.status', ['active', 'scheduled'])
            ->where('live_sessions.movement_id', $movementId)
            ->orderBy('FIELD(live_sessions.status, "active", "scheduled")', 'ASC', false)
            ->orderBy('live_sessions.viewer_count', 'DESC')
            ->orderBy('live_sessions.scheduled_at', 'ASC')
            ->orderBy('live_sessions.created_at', 'DESC')
            ->limit(50)
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

    public function getScheduledPublicSessions(?int $movementId = null): array
    {
        $builder = $this->select("
                live_sessions.*,
                u.name        AS host_name,
                u.avatar_url  AS host_avatar,
                u.trust_level AS host_trust_level,
                u.trust_label AS host_trust_label
            ")
            ->join('users u', 'u.id = live_sessions.host_id')
            ->where('live_sessions.status', 'scheduled')
            ->where('live_sessions.scheduled_at >=', date('Y-m-d H:i:s'));
        if ($movementId !== null) $builder = $builder->where('live_sessions.movement_id', $movementId);
        return $builder->orderBy('live_sessions.scheduled_at', 'ASC')->findAll(50);
    }

    public function getEndedPublicSessions(?int $movementId = null): array
    {
        $builder = $this->select("
                live_sessions.*,
                u.name        AS host_name,
                u.avatar_url  AS host_avatar,
                u.trust_level AS host_trust_level,
                u.trust_label AS host_trust_label
            ")
            ->join('users u', 'u.id = live_sessions.host_id')
            ->where('live_sessions.status', 'ended')
            ->where('live_sessions.visibility', 'public');
        if ($movementId !== null) $builder = $builder->where('live_sessions.movement_id', $movementId);
        return $builder->orderBy('live_sessions.ended_at', 'DESC')->findAll(20);
    }

    public function cohostCount(int $sessionId): int
    {
        return (int) db_connect()
            ->table('live_cohosts')
            ->where('session_id', $sessionId)
            ->countAllResults();
    }

    // ── Formatting ────────────────────────────────────────────────────────────

    public static function format(?array $row, ?string $token = null): array
    {
        if ($row === null) {
            return [
                'id' => '', 'host_id' => '', 'host_name' => '', 'host_avatar_url' => null,
                'host_trust_level' => 'community_submitted', 'title' => '', 'category' => '',
                'description' => null, 'linked_listing_id' => null, 'circle_id' => null,
                'movement_id' => null, 'action_id' => null, 'visibility' => 'public',
                'room_name' => '', 'viewer_count' => 0, 'status' => 'pending',
                'scheduled_at' => null, 'started_at' => date('Y-m-d H:i:s'),
                'ended_at' => null, 'replay_url' => null,
            ];
        }

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
            'title'                 => $row['title']       ?? '',
            'category'              => $row['category']    ?? '',
            'description'           => $row['description'] ?? null,
            'linked_listing_id'     => ! empty($row['linked_listing_id']) ? (string) $row['linked_listing_id'] : null,
            'circle_id'             => ! empty($row['circle_id'])   ? (string) $row['circle_id']   : null,
            'movement_id'           => ! empty($row['movement_id']) ? (string) $row['movement_id'] : null,
            'action_id'             => ! empty($row['action_id'])   ? (string) $row['action_id']   : null,
            'visibility'            => $row['visibility']  ?? 'public',
            'room_name'             => $row['agora_channel'] ?? '',
            'viewer_count'          => (int) ($row['viewer_count'] ?? 0),
            'status'                => $row['status']      ?? 'active',
            'scheduled_at'          => $row['scheduled_at'] ?? null,
            'started_at'            => $row['started_at']  ?? $row['created_at'] ?? date('Y-m-d H:i:s'),
            'ended_at'              => $row['ended_at']    ?? null,
            'replay_url'            => $row['replay_url']  ?? null,
        ];

        if ($token !== null) {
            $out['token']      = $token;
            $out['server_url'] = 'wss://dim-z07mwg4s.livekit.cloud';
        }

        return $out;
    }
}
