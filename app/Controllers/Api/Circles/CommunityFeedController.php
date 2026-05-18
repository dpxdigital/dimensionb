<?php

namespace App\Controllers\Api\Circles;

use App\Controllers\Api\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

class CommunityFeedController extends BaseApiController
{
    // GET /v1/community/feed
    // Returns a reverse-chronological mix of circle posts, discussions, and actions
    // from circles the user is a member of and movements they follow.

    public function index(): ResponseInterface
    {
        $userId  = $this->authUserId();
        $db      = db_connect();

        // Self-heal: ensure posts table has all required columns
        foreach ([
            "ALTER TABLE `posts` ADD COLUMN `image_url` VARCHAR(500) DEFAULT NULL",
            "ALTER TABLE `posts` ADD COLUMN `video_url` VARCHAR(500) DEFAULT NULL",
            "ALTER TABLE `posts` ADD COLUMN `post_type` VARCHAR(20) NOT NULL DEFAULT 'text'",
            "ALTER TABLE `posts` ADD COLUMN `action_id` BIGINT UNSIGNED DEFAULT NULL",
            "ALTER TABLE `posts` ADD COLUMN `comment_count` INT UNSIGNED NOT NULL DEFAULT 0",
            "ALTER TABLE `posts` ADD COLUMN `reaction_count` INT UNSIGNED NOT NULL DEFAULT 0",
        ] as $sql) { try { $db->query($sql); } catch (\Throwable $e) {} }

        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(50, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $offset  = ($page - 1) * $perPage;

        // Circles the user belongs to (approved)
        $circleIds = array_column(
            $db->table('circle_members')
               ->select('circle_id')
               ->where('user_id', $userId)
               ->where('status', 'approved')
               ->get()->getResultArray(),
            'circle_id'
        );

        // Movements the user follows
        $movementIds = array_column(
            $db->table('movement_followers')
               ->select('movement_id')
               ->where('user_id', $userId)
               ->get()->getResultArray(),
            'movement_id'
        );

        $items = [];

        // Posts the current user has reacted to (for is_liked)
        $likedPostIds = [];
        try {
            $likedPostIds = array_column(
                $db->table('post_reactions')->select('post_id')->where('user_id', $userId)->get()->getResultArray(),
                'post_id'
            );
        } catch (\Throwable $e) {}

        // ── Posts from joined circles only ────────────────────────────────────
        if ($circleIds) {
            $posts = $db->table('posts p')
                ->select("'circle_post' AS type,
                    p.id,
                    p.content AS body,
                    NULL AS title,
                    p.image_url,
                    p.video_url,
                    p.post_type,
                    p.action_id,
                    p.reaction_count AS like_count,
                    p.comment_count,
                    NULL AS participant_count,
                    NULL AS action_type_label,
                    p.circle_id,
                    NULL AS movement_id,
                    p.created_at,
                    u.id AS author_id, u.name AS author_name, u.avatar_url AS author_avatar,
                    c.name AS circle_name, c.logo_url AS circle_avatar_url,
                    NULL AS movement_title,
                    NULL AS action_title", false)
                ->join('users u', 'u.id = p.user_id', 'left')
                ->join('circles c', 'c.id = p.circle_id', 'left')
                ->whereIn('p.circle_id', $circleIds)
                ->where('p.is_deleted', 0)
                ->where('p.status', 'approved')
                ->get()->getResultArray();

            // Enrich each post with is_liked, comment_count, and linked action
            foreach ($posts as &$post) {
                $post['is_liked'] = in_array((int) $post['id'], array_map('intval', $likedPostIds));
                if (!empty($post['action_id'])) {
                    try {
                        $action = $db->table('community_actions')
                            ->where('id', (int) $post['action_id'])
                            ->get()->getRowArray();
                        $post['linked_action'] = $action ? [
                            'id'          => (string) $action['id'],
                            'title'       => $action['title'],
                            'action_type' => $action['action_type'] ?? null,
                            'cta_label'   => $action['cta_label'] ?? null,
                            'cta_url'     => $action['cta_url'] ?? null,
                            'description' => $action['description'] ?? null,
                        ] : null;
                        $post['action_title'] = $action['title'] ?? null;
                    } catch (\Throwable $e) {
                        $post['linked_action'] = null;
                        $post['action_title'] = null;
                    }
                }
            }
            unset($post);

            $items = array_merge($items, $posts);
        }

        // Sort all items by created_at DESC
        usort($items, static fn($a, $b) => strtotime((string)($b['created_at'] ?? '')) <=> strtotime((string)($a['created_at'] ?? '')));

        $total      = count($items);
        $page_items = array_slice($items, $offset, $perPage);

        $formatted = array_map(static fn($r) => [
            'type'              => $r['type'],
            'id'                => (string) $r['id'],
            'body'              => $r['body'] ?? null,
            'content'           => $r['body'] ?? null,
            'title'             => $r['title'] ?? null,
            'image_url'         => $r['image_url'] ?? null,
            'video_url'         => $r['video_url'] ?? null,
            'post_type'         => $r['post_type'] ?? 'text',
            'like_count'        => isset($r['like_count']) ? (int) $r['like_count'] : 0,
            'comment_count'     => isset($r['comment_count']) ? (int) $r['comment_count'] : 0,
            'is_liked'          => !empty($r['is_liked']),
            'participant_count' => isset($r['participant_count']) ? (int) $r['participant_count'] : null,
            'action_type'       => $r['action_type_label'] ?? null,
            'action_title'      => $r['action_title'] ?? null,
            'linked_action'     => $r['linked_action'] ?? null,
            'circle_id'         => $r['circle_id'] ? (string) $r['circle_id'] : null,
            'circle_name'       => $r['circle_name'] ?? null,
            'circle_avatar_url' => $r['circle_avatar_url'] ?? null,
            'movement_id'       => $r['movement_id'] ? (string) $r['movement_id'] : null,
            'movement_title'    => $r['movement_title'] ?? null,
            'author_name'       => $r['author_name'] ?? '',
            'author_avatar_url' => $r['author_avatar'] ?? null,
            'author_avatar'     => $r['author_avatar'] ?? null,
            'author' => [
                'id'         => (string) ($r['author_id'] ?? ''),
                'name'       => $r['author_name'] ?? '',
                'avatar_url' => $r['author_avatar'] ?? null,
            ],
            'created_at' => $r['created_at'],
        ], $page_items);

        return $this->success($formatted, 'OK', 200, [
            'current_page' => $page,
            'per_page'     => $perPage,
            'total'        => $total,
            'last_page'    => max(1, (int) ceil($total / $perPage)),
        ]);
    }
}
