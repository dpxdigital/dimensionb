<?php

namespace App\Models;

use CodeIgniter\Model;

class ConversationModel extends Model
{
    protected $table         = 'conversations';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'type', 'created_by', 'name', 'avatar_url', 'last_message_at',
    ];
    protected $useTimestamps = true;

    /**
     * Returns the paginated inbox for a user, sorted by last_message_at DESC.
     * Each row includes: last message preview + unread count.
     */
    public function getInboxForUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        $db = $this->db;

        $rows = $db->table('conversations c')
            ->select("
                c.id, c.type, c.name, c.avatar_url, c.last_message_at, c.created_at,
                cm.is_muted,
                (
                    SELECT COUNT(*)
                    FROM messages m2
                    LEFT JOIN message_read_receipts rr
                           ON rr.message_id = m2.id AND rr.user_id = {$userId}
                    WHERE m2.conversation_id = c.id
                      AND rr.id IS NULL
                      AND m2.sender_id != {$userId}
                      AND m2.is_deleted = 0
                ) AS unread_count,
                last_msg.id    AS last_msg_id,
                last_msg.type  AS last_msg_type,
                last_msg.body  AS last_msg_body,
                last_msg.file_name AS last_msg_file_name,
                last_msg.created_at AS last_msg_created_at,
                last_msg.is_deleted AS last_msg_is_deleted,
                last_sender.id        AS last_sender_id,
                last_sender.name      AS last_sender_name,
                last_sender.avatar_url AS last_sender_avatar
            ", false)
            ->join('conversation_members cm', "cm.conversation_id = c.id AND cm.user_id = {$userId}", 'inner')
            ->join(
                "(SELECT m.id, m.conversation_id, m.type, m.body, m.file_name,
                         m.sender_id, m.created_at, m.is_deleted
                  FROM messages m
                  INNER JOIN (
                      SELECT conversation_id, MAX(id) AS max_id
                      FROM messages
                      GROUP BY conversation_id
                  ) latest ON latest.conversation_id = m.conversation_id AND latest.max_id = m.id
                 ) AS last_msg",
                'last_msg.conversation_id = c.id',
                'left'
            )
            ->join('users last_sender', 'last_sender.id = last_msg.sender_id', 'left')
            ->orderBy('c.last_message_at', 'DESC')
            ->limit($limit, $offset)
            ->get()->getResultArray();

        return $rows;
    }

    /**
     * Returns the total inbox count for pagination.
     */
    public function countInboxForUser(int $userId): int
    {
        return (int) $this->db->table('conversations c')
            ->join('conversation_members cm', "cm.conversation_id = c.id AND cm.user_id = {$userId}", 'inner')
            ->countAllResults();
    }

    /**
     * Returns all members of a conversation with their user details.
     */
    public function getMembersOf(int $conversationId): array
    {
        return $this->db->table('conversation_members cm')
            ->select('u.id, u.name, u.avatar_url, u.location, cm.is_admin, cm.is_muted, cm.joined_at')
            ->join('users u', 'u.id = cm.user_id', 'inner')
            ->where('cm.conversation_id', $conversationId)
            ->get()->getResultArray();
    }

    /**
     * Returns true if $userId is a member of $conversationId.
     */
    public function isMember(int $conversationId, int $userId): bool
    {
        return $this->db->table('conversation_members')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->countAllResults() > 0;
    }

    /**
     * Returns true if $userId is an admin of $conversationId.
     */
    public function isAdmin(int $conversationId, int $userId): bool
    {
        return $this->db->table('conversation_members')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->where('is_admin', 1)
            ->countAllResults() > 0;
    }

    /**
     * Returns the existing direct conversation between two users, or null.
     */
    public function findDirectBetween(int $userAId, int $userBId): ?array
    {
        $row = $this->db->table('conversations c')
            ->select('c.*')
            ->join('conversation_members cm1', "cm1.conversation_id = c.id AND cm1.user_id = {$userAId}", 'inner')
            ->join('conversation_members cm2', "cm2.conversation_id = c.id AND cm2.user_id = {$userBId}", 'inner')
            ->where('c.type', 'direct')
            ->limit(1)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /**
     * Formats a conversation row for API response.
     */
    public static function formatRow(array $row): array
    {
        $lastMessage = null;
        if (! empty($row['last_msg_id'])) {
            $body = (bool) ($row['last_msg_is_deleted'] ?? false)
                ? 'This message was deleted'
                : ($row['last_msg_body'] ?? $row['last_msg_file_name'] ?? '');

            $lastMessage = [
                'id'        => (int) $row['last_msg_id'],
                'type'      => $row['last_msg_type'],
                'body'      => $body,
                'createdAt' => $row['last_msg_created_at'],
                'sender'    => [
                    'id'        => (int) ($row['last_sender_id'] ?? 0),
                    'name'      => $row['last_sender_name'] ?? null,
                    'avatarUrl' => $row['last_sender_avatar'] ?? null,
                ],
            ];
        }

        return [
            'id'            => (int) $row['id'],
            'type'          =>       $row['type'],
            'name'          =>       $row['name'] ?? null,
            'avatarUrl'     =>       $row['avatar_url'] ?? null,
            'lastMessageAt' =>       $row['last_message_at'] ?? null,
            'isMuted'       => (bool) ($row['is_muted'] ?? false),
            'unreadCount'   => (int)  ($row['unread_count'] ?? 0),
            'lastMessage'   =>        $lastMessage,
            'createdAt'     =>        $row['created_at'],
        ];
    }
}
