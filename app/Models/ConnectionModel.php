<?php

namespace App\Models;

use CodeIgniter\Model;

class ConnectionModel extends Model
{
    protected $table         = 'connections';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'requester_id', 'receiver_id', 'status', 'context_type', 'context_id',
    ];
    protected $useTimestamps = true;

    /**
     * Returns the current relationship status between two users from the
     * perspective of $viewerUserId.
     *
     * Returns: none | pending_sent | pending_received | connected | blocked
     */
    public function getStatus(int $viewerUserId, int $targetUserId): string
    {
        $row = $this->db->table('connections')
            ->where(
                "(requester_id = {$viewerUserId} AND receiver_id = {$targetUserId})
                 OR (requester_id = {$targetUserId} AND receiver_id = {$viewerUserId})",
                null, false
            )
            ->orderBy('created_at', 'DESC')
            ->limit(1)
            ->get()->getRowArray();

        if ($row === null) {
            return 'none';
        }

        switch ($row['status']) {
            case 'accepted':
                return 'connected';
            case 'blocked':
                return 'blocked';
            case 'pending':
                return (int) $row['requester_id'] === $viewerUserId
                    ? 'pending_sent'
                    : 'pending_received';
            default:
                return 'none';
        }
    }

    /**
     * All accepted connections for a user (returns array of other user rows).
     */
    public function getAccepted(int $userId): array
    {
        return $this->db->table('connections c')
            ->select('u.id, u.name, u.avatar_url, u.location, c.created_at AS connected_at')
            ->join('users u', "u.id = IF(c.requester_id = {$userId}, c.receiver_id, c.requester_id)", 'inner')
            ->where('c.status', 'accepted')
            ->groupStart()
                ->where('c.requester_id', $userId)
                ->orWhere('c.receiver_id', $userId)
            ->groupEnd()
            ->get()->getResultArray();
    }

    /**
     * All incoming pending requests for a user.
     */
    public function getPending(int $userId): array
    {
        return $this->db->table('connections c')
            ->select('c.id, c.context_type, c.context_id, c.created_at,
                      u.id AS requester_id, u.name AS requester_name,
                      u.avatar_url AS requester_avatar, u.location AS requester_location')
            ->join('users u', 'u.id = c.requester_id', 'inner')
            ->where('c.receiver_id', $userId)
            ->where('c.status', 'pending')
            ->orderBy('c.created_at', 'DESC')
            ->get()->getResultArray();
    }

    /**
     * Returns false if a connection request cannot be sent.
     */
    public function canSendRequest(int $requesterId, int $receiverId): bool
    {
        $row = $this->db->table('connections')
            ->where(
                "(requester_id = {$requesterId} AND receiver_id = {$receiverId})
                 OR (requester_id = {$receiverId} AND receiver_id = {$requesterId})",
                null, false
            )
            ->orderBy('created_at', 'DESC')
            ->limit(1)
            ->get()->getRowArray();

        if ($row === null) {
            return true;
        }

        if (in_array($row['status'], ['accepted', 'pending', 'blocked'], true)) {
            return false;
        }

        // Declined within last 30 days — cannot re-send
        if ($row['status'] === 'declined') {
            $declinedAt = strtotime($row['updated_at']);
            if (time() - $declinedAt < 30 * 86400) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns true if an accepted connection exists between the two users.
     */
    public function isConnected(int $userAId, int $userBId): bool
    {
        return $this->db->table('connections')
            ->where('status', 'accepted')
            ->groupStart()
                ->where('requester_id', $userAId)->where('receiver_id', $userBId)
            ->groupEnd()
            ->orGroupStart()
                ->where('requester_id', $userBId)->where('receiver_id', $userAId)
            ->groupEnd()
            ->countAllResults() > 0;
    }

    /**
     * Formats a connection row for the API response.
     */
    public static function formatRow(array $row): array
    {
        return [
            'id'              => (int) $row['id'],
            'requesterId'     => (int) $row['requester_id'],
            'receiverId'      => (int) $row['receiver_id'],
            'status'          =>       $row['status'],
            'contextType'     =>       $row['context_type'] ?? null,
            'contextId'       => isset($row['context_id']) ? (int) $row['context_id'] : null,
            'createdAt'       =>       $row['created_at'],
        ];
    }
}
