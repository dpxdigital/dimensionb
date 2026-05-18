<?php

namespace App\Controllers\Api\Circles;

use App\Controllers\Api\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

class CirclePostsController extends BaseApiController
{
    // GET /circles/:id/posts
    public function index($circleId = null): ResponseInterface
    {
        $db     = db_connect();
        $this->ensurePostReactionsTable($db);
        $this->ensurePostsColumns($db);
        $uid    = $this->authUserId();
        $page   = max(1, (int) ($this->request->getGet('page') ?? 1));
        $limit  = min(50, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $offset = ($page - 1) * $limit;

        $circle = $db->table('circles')->where('id', (int) $circleId)->get()->getRowArray();
        if (!$circle) {
            return $this->error('Circle not found', 404);
        }

        if ($guard = $this->guardAccess((int) $circleId, $uid, $db)) {
            return $guard;
        }

        $isAdmin = $this->isAdmin((int) $circleId, $uid, $db);

        $builder = $db->table('posts p')
            ->select('p.*, u.name AS author_name, u.avatar_url AS author_avatar, u.trust_level AS author_trust_level')
            ->join('users u', 'u.id = p.user_id', 'left')
            ->where('p.circle_id', (int) $circleId)
            ->where('p.is_deleted', 0);

        // Members only see approved posts; admins see both
        if (!$isAdmin) {
            $builder->where('p.status', 'approved');
        }

        $total = (clone $builder)->countAllResults(false);

        $rows = $builder->orderBy('p.created_at', 'DESC')
            ->limit($limit, $offset)
            ->get()->getResultArray();

        $postIds    = array_column($rows, 'id');
        $reactedSet = [];
        if ($uid && $postIds) {
            $reactedSet = array_column(
                $db->table('post_reactions')
                   ->select('post_id')
                   ->whereIn('post_id', $postIds)
                   ->where('user_id', $uid)
                   ->get()->getResultArray(),
                'post_id'
            );
        }

        $data = array_map(fn($r) => $this->formatPost($r, $uid, $reactedSet), $rows);

        return $this->success($data, 'OK', 200, [
            'current_page' => $page,
            'per_page'     => $limit,
            'total'        => $total,
            'last_page'    => (int) ceil($total / $limit),
        ]);
    }

    // GET /circles/:id/posts/pending  (admin or moderator)
    public function pending($circleId = null): ResponseInterface
    {
        $db  = db_connect();
        $this->ensurePostsColumns($db);
        $uid = $this->authUserId();

        if (!$this->isAdminOrMod((int) $circleId, $uid, $db)) {
            return $this->error('Moderator access required', 403);
        }

        $rows = $db->table('posts p')
            ->select('p.*, u.name AS author_name, u.avatar_url AS author_avatar, u.trust_level AS author_trust_level')
            ->join('users u', 'u.id = p.user_id', 'left')
            ->where('p.circle_id', (int) $circleId)
            ->where('p.is_deleted', 0)
            ->where('p.status', 'pending')
            ->orderBy('p.created_at', 'ASC')
            ->get()->getResultArray();

        return $this->success(
            array_map(fn($r) => $this->formatPost($r, $uid, []), $rows)
        );
    }

    // POST /circles/:id/posts
    public function create($circleId = null): ResponseInterface
    {
        $uid = $this->authUserId();
        $db  = db_connect();
        $this->ensurePostsColumns($db);

        $circle = $db->table('circles')->where('id', (int) $circleId)->get()->getRowArray();
        if (!$circle) {
            return $this->error('Circle not found', 404);
        }

        if ($guard = $this->guardMember((int) $circleId, $uid, $db)) {
            return $guard;
        }

        $input   = $this->inputJson();
        $content = trim($input['content'] ?? '');

        if (!$content && empty($input['image_url']) && empty($input['video_url'])) {
            return $this->validationError(['content' => 'Post content is required']);
        }

        $postType = 'text';
        if (!empty($input['video_url'])) {
            $postType = 'video';
        } elseif (!empty($input['image_url'])) {
            $postType = 'image';
        }

        // Admin posts are immediately approved; member posts go pending
        $isAdmin = $this->isAdmin((int) $circleId, $uid, $db);
        $status  = $isAdmin ? 'approved' : 'pending';

        // Only admins can link an action to a post
        $actionId = null;
        if ($isAdmin && !empty($input['action_id'])) {
            $actionId = (int) $input['action_id'];
        }

        $insertData = [
            'user_id'        => $uid,
            'circle_id'      => (int) $circleId,
            'content'        => $content ?: null,
            'image_url'      => $input['image_url'] ?? null,
            'video_url'      => $input['video_url'] ?? null,
            'post_type'      => $postType,
            'reaction_count' => 0,
            'comment_count'  => 0,
            'is_deleted'     => 0,
            'status'         => $status,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ];
        if ($actionId !== null) {
            $insertData['action_id'] = $actionId;
        }

        $db->table('posts')->insert($insertData);

        $postId = $db->insertID();

        $post = $db->table('posts p')
            ->select('p.*, u.name AS author_name, u.avatar_url AS author_avatar, u.trust_level AS author_trust_level')
            ->join('users u', 'u.id = p.user_id', 'left')
            ->where('p.id', $postId)
            ->get()->getRowArray();

        $message = $isAdmin ? 'Post created' : 'Post submitted for review';
        return $this->success($this->formatPost($post, $uid, []), $message, 201);
    }

    // POST /posts/:id/approve  (admin only)
    public function approve($postId): ResponseInterface
    {
        $uid = $this->authUserId();
        $db  = db_connect();

        $post = $db->table('posts')->where('id', (int) $postId)->where('is_deleted', 0)->get()->getRowArray();
        if (!$post) return $this->error('Post not found', 404);

        if (!$this->isAdminOrMod((int) $post['circle_id'], $uid, $db)) {
            return $this->error('Moderator access required', 403);
        }

        $db->table('posts')->where('id', (int) $postId)->update([
            'status'     => 'approved',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->success(['approved' => true], 'Post approved');
    }

    // POST /posts/:id/reject  (admin only)
    public function reject($postId): ResponseInterface
    {
        $uid = $this->authUserId();
        $db  = db_connect();

        $post = $db->table('posts')->where('id', (int) $postId)->where('is_deleted', 0)->get()->getRowArray();
        if (!$post) return $this->error('Post not found', 404);

        if (!$this->isAdminOrMod((int) $post['circle_id'], $uid, $db)) {
            return $this->error('Moderator access required', 403);
        }

        $db->table('posts')->where('id', (int) $postId)->update([
            'is_deleted' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->success(['rejected' => true], 'Post rejected');
    }

    // DELETE /posts/:id
    public function delete($postId = null): ResponseInterface
    {
        $uid = $this->authUserId();
        $db  = db_connect();

        $post = $db->table('posts')->where('id', (int) $postId)->where('is_deleted', 0)->get()->getRowArray();
        if (!$post) {
            return $this->error('Post not found', 404);
        }

        $isAuthor   = (int) $post['user_id'] === $uid;
        $isCircleMod = $post['circle_id'] && $this->isAdminOrMod((int) $post['circle_id'], $uid, $db);

        if (!$isAuthor && !$isCircleMod) {
            return $this->error('You do not have permission to delete this post', 403);
        }

        $db->table('posts')->where('id', (int) $postId)->update([
            'is_deleted' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->success(null, 'Post deleted');
    }

    // POST /posts/:id/react
    public function react($postId): ResponseInterface
    {
        $uid   = $this->authUserId();
        $db    = db_connect();
        $this->ensurePostReactionsTable($db);
        $input = $this->inputJson();

        $post = $db->table('posts')->where('id', (int) $postId)->where('is_deleted', 0)->get()->getRowArray();
        if (!$post) return $this->error('Post not found', 404);

        if ($post['circle_id']) {
            if ($guard = $this->guardAccess((int) $post['circle_id'], $uid, $db)) return $guard;
        }

        $reactionType = $input['reaction_type'] ?? 'like';
        $exists = $db->table('post_reactions')
            ->where('post_id', (int) $postId)->where('user_id', $uid)->countAllResults();

        if (!$exists) {
            $db->table('post_reactions')->insert([
                'post_id'       => (int) $postId,
                'user_id'       => $uid,
                'reaction_type' => $reactionType,
            ]);
            $db->query("UPDATE posts SET reaction_count = reaction_count + 1 WHERE id = ?", [(int) $postId]);
        } else {
            $db->table('post_reactions')
                ->where('post_id', (int) $postId)->where('user_id', $uid)
                ->update(['reaction_type' => $reactionType]);
        }

        $count = (int) $db->table('posts')->select('reaction_count')->where('id', (int) $postId)->get()->getRowArray()['reaction_count'];
        return $this->success(['reaction_count' => $count, 'reacted' => true]);
    }

    // DELETE /posts/:id/react
    public function unreact($postId): ResponseInterface
    {
        $uid = $this->authUserId();
        $db  = db_connect();
        $this->ensurePostReactionsTable($db);

        $post = $db->table('posts')->where('id', (int) $postId)->where('is_deleted', 0)->get()->getRowArray();
        if (!$post) return $this->error('Post not found', 404);

        $deleted = $db->table('post_reactions')
            ->where('post_id', (int) $postId)->where('user_id', $uid)->delete();

        if ($deleted) {
            $db->query("UPDATE posts SET reaction_count = GREATEST(reaction_count - 1, 0) WHERE id = ?", [(int) $postId]);
        }

        $count = (int) $db->table('posts')->select('reaction_count')->where('id', (int) $postId)->get()->getRowArray()['reaction_count'];
        return $this->success(['reaction_count' => $count, 'reacted' => false]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function ensurePostsColumns($db): void
    {
        $cols = [
            "ALTER TABLE `posts` ADD COLUMN `image_url` VARCHAR(500) DEFAULT NULL",
            "ALTER TABLE `posts` ADD COLUMN `video_url` VARCHAR(500) DEFAULT NULL",
            "ALTER TABLE `posts` ADD COLUMN `post_type` VARCHAR(20) NOT NULL DEFAULT 'text'",
            "ALTER TABLE `posts` ADD COLUMN `action_id` BIGINT UNSIGNED DEFAULT NULL",
            "ALTER TABLE `posts` ADD COLUMN `comment_count` INT UNSIGNED NOT NULL DEFAULT 0",
            "ALTER TABLE `posts` ADD COLUMN `reaction_count` INT UNSIGNED NOT NULL DEFAULT 0",
            "ALTER TABLE `posts` ADD COLUMN `content` TEXT DEFAULT NULL",
            "ALTER TABLE `posts` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'approved'",
            "ALTER TABLE `posts` ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE `posts` ADD COLUMN `updated_at` DATETIME DEFAULT NULL",
        ];
        foreach ($cols as $sql) {
            try { $db->query($sql); } catch (\Throwable $e) {}
        }
    }

    private function ensurePostReactionsTable($db): void
    {
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `post_reactions` (
                `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `post_id`       BIGINT UNSIGNED NOT NULL,
                `user_id`       INT UNSIGNED    NOT NULL,
                `reaction_type` VARCHAR(20)     NOT NULL DEFAULT 'like',
                `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unique_reaction` (`post_id`, `user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {}
    }

    private function isAdmin(int $cid, int $uid, $db): bool
    {
        if (!$uid) return false;
        return (bool) $db->table('circle_members')
            ->where('circle_id', $cid)->where('user_id', $uid)
            ->where('status', 'approved')->where('role', 'admin')
            ->countAllResults();
    }

    private function isAdminOrMod(int $cid, int $uid, $db): bool
    {
        if (!$uid) return false;
        return (bool) $db->table('circle_members')
            ->where('circle_id', $cid)->where('user_id', $uid)
            ->where('status', 'approved')
            ->whereIn('role', ['admin', 'moderator'])
            ->countAllResults();
    }

    private function guardAccess(int $cid, int $uid, $db): ?ResponseInterface
    {
        $circle = $db->table('circles')->where('id', $cid)->get()->getRowArray();
        if (!$circle) return $this->error('Circle not found', 404);
        if ($circle['visibility'] === 'public') return null;
        if (!$uid) return $this->error('Authentication required', 401);
        $member = $db->table('circle_members')
            ->where('circle_id', $cid)->where('user_id', $uid)->where('status', 'approved')
            ->get()->getRowArray();
        if (!$member) return $this->error('You must be a member to view this content', 403);
        return null;
    }

    private function guardMember(int $cid, int $uid, $db): ?ResponseInterface
    {
        if (!$uid) return $this->error('Authentication required', 401);
        $member = $db->table('circle_members')
            ->where('circle_id', $cid)->where('user_id', $uid)->where('status', 'approved')
            ->get()->getRowArray();
        if (!$member) return $this->error('You must be an approved member to post', 403);
        return null;
    }

    private function formatPost(array $row, int $uid, array $reactedSet): array
    {
        $linkedAction = null;
        if (!empty($row['action_id'])) {
            $db = db_connect();
            $action = $db->table('community_actions')
                ->where('id', (int) $row['action_id'])
                ->get()->getRowArray();
            if ($action) {
                $linkedAction = [
                    'id'          => (string) $action['id'],
                    'title'       => $action['title'],
                    'action_type' => $action['action_type'],
                    'cta_label'   => $action['cta_label'] ?? null,
                    'cta_url'     => $action['cta_url'] ?? null,
                    'description' => $action['description'] ?? null,
                ];
            }
        }

        return [
            'id'             => (int) $row['id'],
            'circle_id'      => $row['circle_id'] ? (int) $row['circle_id'] : null,
            'content'        => $row['content'] ?? $row['body'] ?? null,
            'image_url'      => $row['image_url'] ?? null,
            'video_url'      => $row['video_url'] ?? null,
            'post_type'      => $row['post_type'] ?? 'text',
            'reaction_count' => (int) ($row['reaction_count'] ?? 0),
            'comment_count'  => (int) ($row['comment_count'] ?? 0),
            'is_reacted'     => in_array((int) $row['id'], array_map('intval', $reactedSet)),
            'status'         => $row['status'] ?? 'approved',
            'linked_action'  => $linkedAction,
            'author'         => [
                'id'          => (int) $row['user_id'],
                'name'        => $row['author_name'],
                'avatar_url'  => $row['author_avatar'],
                'trust_level' => $row['author_trust_level'] ?? null,
            ],
            'created_at' => $row['created_at'],
        ];
    }
}
