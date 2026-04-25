<?php

namespace App\Models;

use CodeIgniter\Model;

class MessageModel extends Model
{
    protected $table         = 'messages';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'conversation_id', 'sender_id', 'type', 'body',
        'file_url', 'file_name', 'file_size', 'file_mime',
        'listing_id', 'is_deleted', 'deleted_for_all',
    ];
    protected $useTimestamps = true;

    private const BLOCKED_EXTENSIONS = ['exe', 'apk', 'sh', 'bat', 'cmd', 'ps1', 'vbs', 'jar'];
    private const BLOCKED_MIMES      = [
        'application/x-msdownload',
        'application/x-executable',
        'application/vnd.android.package-archive',
        'application/x-sh',
        'application/x-bat',
    ];

    /**
     * Cursor-based pagination, newest first.
     * Returns [$rows, $hasMore].
     */
    public function getForConversation(int $conversationId, ?int $cursor = null, int $limit = 20): array
    {
        $q = $this->db->table('messages m')
            ->select("
                m.id, m.conversation_id, m.sender_id, m.type, m.body,
                m.file_url, m.file_name, m.file_size, m.listing_id,
                m.is_deleted, m.deleted_for_all, m.created_at,
                u.id AS sender_user_id, u.name AS sender_name, u.avatar_url AS sender_avatar
            ", false)
            ->join('users u', 'u.id = m.sender_id', 'left')
            ->where('m.conversation_id', $conversationId);

        if ($cursor !== null) {
            $q->where('m.id <', $cursor);
        }

        $rows = $q->orderBy('m.id', 'DESC')
                  ->limit($limit + 1)
                  ->get()->getResultArray();

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        return [$rows, $hasMore];
    }

    /**
     * Returns true if the user is the sender and the message is within 60 min.
     */
    public function canDeleteForAll(int $messageId, int $userId): bool
    {
        $row = $this->find($messageId);
        if ($row === null) {
            return false;
        }
        if ((int) $row['sender_id'] !== $userId) {
            return false;
        }

        return (time() - strtotime($row['created_at'])) <= 3600;
    }

    /**
     * Returns true if the file name or MIME type is blocked.
     */
    public static function isBlockedFile(string $fileName, ?string $mime = null): bool
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
            return true;
        }
        if ($mime !== null && in_array($mime, self::BLOCKED_MIMES, true)) {
            return true;
        }

        return false;
    }

    /**
     * Returns all reactions for a message grouped by emoji.
     */
    public function getReactions(int $messageId): array
    {
        $rows = $this->db->table('message_reactions')
            ->select('emoji, COUNT(*) AS count, GROUP_CONCAT(user_id) AS user_ids')
            ->where('message_id', $messageId)
            ->groupBy('emoji')
            ->get()->getResultArray();

        return array_map(static fn($r) => [
            'emoji'   => $r['emoji'],
            'count'   => (int) $r['count'],
            'userIds' => array_map('intval', explode(',', $r['user_ids'])),
        ], $rows);
    }

    /**
     * Formats a message row for the API response.
     */
    public static function formatRow(array $row, ?array $listingPreview = null): array
    {
        $isDeleted = (bool) $row['is_deleted'];
        return [
            'id'              => (string) $row['id'],
            'conversation_id' => (string) $row['conversation_id'],
            'sender_id'       => (string) $row['sender_id'],
            'sender_name'     =>          $row['sender_name']   ?? null,
            'sender_avatar'   =>          $row['sender_avatar'] ?? null,
            'type'            =>          $row['type'],
            'body'            => $isDeleted ? 'This message was deleted' : ($row['body'] ?? null),
            'file_url'        => $isDeleted ? null : ($row['file_url'] ?? null),
            'file_name'       => $isDeleted ? null : ($row['file_name'] ?? null),
            'file_size'       => isset($row['file_size']) ? (int) $row['file_size'] : null,
            'listing_id'      => isset($row['listing_id']) ? (string) $row['listing_id'] : null,
            'is_deleted'      => $isDeleted,
            'deleted_for_all' => (bool) $row['deleted_for_all'],
            'reactions'       => [],
            'created_at'      =>        $row['created_at'],
        ];
    }
}
