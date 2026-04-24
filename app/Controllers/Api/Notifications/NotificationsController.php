<?php

namespace App\Controllers\Api\Notifications;

use App\Controllers\Api\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

class NotificationsController extends BaseApiController
{
    private const PER_PAGE = 20;

    // ── GET /v1/notifications ─────────────────────────────────────────────────

    public function index(): ResponseInterface
    {
        $userId = $this->authUserId();
        $page   = max(1, (int) ($this->request->getGet('page') ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $db    = db_connect();
        $total = $db->table('notifications')->where('user_id', $userId)->countAllResults();
        $rows  = $db->table('notifications')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->limit(self::PER_PAGE, $offset)
            ->get()->getResultArray();

        $unreadCount = $db->table('notifications')
            ->where('user_id', $userId)
            ->where('is_read', 0)
            ->countAllResults();

        return $this->success(
            array_map([self::class, 'formatRow'], $rows),
            'OK',
            200,
            [
                'current_page' => $page,
                'per_page'     => self::PER_PAGE,
                'total'        => $total,
                'last_page'    => (int) ceil($total / self::PER_PAGE),
                'unread_count' => $unreadCount,
            ]
        );
    }

    // ── PUT /v1/notifications/:id/read ────────────────────────────────────────

    public function markRead($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = db_connect();

        $row = $db->table('notifications')
            ->where('id', (int) $id)
            ->where('user_id', $userId)
            ->get()->getRowArray();

        if ($row === null) {
            return $this->error('Notification not found.', 404);
        }

        $db->table('notifications')
            ->where('id', (int) $id)
            ->update(['is_read' => 1]);

        return $this->success(null, 'Marked as read');
    }

    // ── PUT /v1/notifications/read-all ────────────────────────────────────────

    public function markAllRead(): ResponseInterface
    {
        $userId = $this->authUserId();

        db_connect()->table('notifications')
            ->where('user_id', $userId)
            ->where('is_read', 0)
            ->update(['is_read' => 1]);

        return $this->success(null, 'All notifications marked as read');
    }

    // ── Formatter ─────────────────────────────────────────────────────────────

    public static function formatRow(array $row): array
    {
        return [
            'id'            => (int)  $row['id'],
            'type'          =>        $row['type'],
            'title'         =>        $row['title'],
            'body'          =>        $row['body'],
            'referenceId'   => isset($row['reference_id']) ? (int) $row['reference_id'] : null,
            'referenceType' =>        $row['reference_type'] ?? null,
            'isRead'        => (bool) $row['is_read'],
            'createdAt'     =>        $row['created_at'],
        ];
    }
}
