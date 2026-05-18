<?php

namespace App\Controllers\Api\Circles;

use App\Controllers\Api\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

class DiscussionsController extends BaseApiController
{
    // GET /circles/:id/discussions
    public function indexForCircle($circleId): ResponseInterface
    {
        return $this->listDiscussions('circle_id', (int) $circleId);
    }

    // GET /movements/:id/discussions
    public function indexForMovement($movementId): ResponseInterface
    {
        return $this->listDiscussions('movement_id', (int) $movementId);
    }

    // POST /circles/:id/discussions
    public function createForCircle($circleId): ResponseInterface
    {
        $uid = $this->authUserId();
        $db  = db_connect();

        $circle = $db->table('circles')->where('id', (int) $circleId)->get()->getRowArray();
        if (!$circle) {
            return $this->error('Circle not found', 404);
        }

        $member = $db->table('circle_members')
            ->where('circle_id', (int) $circleId)
            ->where('user_id', $uid)
            ->where('status', 'approved')
            ->get()->getRowArray();

        if (!$member && $circle['visibility'] !== 'public') {
            return $this->error('You must be an approved member to start a discussion', 403);
        }

        $isAdmin = $member && $member['role'] === 'admin';
        return $this->createDiscussion($uid, $db, ['circle_id' => (int) $circleId], $isAdmin);
    }

    // POST /discussions/:id/approve  (admin only)
    public function approve($id): ResponseInterface
    {
        $uid = $this->authUserId();
        $db  = db_connect();

        $discussion = $this->fetchDiscussion((int) $id, $db);
        if (!$discussion) return $this->error('Discussion not found', 404);

        if ($discussion['circle_id'] && !$this->isCircleAdmin((int) $discussion['circle_id'], $uid, $db)) {
            return $this->error('Admin access required', 403);
        }

        $db->table('discussions')->where('id', (int) $id)->update([
            'status'     => 'open',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->success(['approved' => true], 'Discussion approved');
    }

    // POST /discussions/:id/reject  (admin only)
    public function reject($id): ResponseInterface
    {
        $uid = $this->authUserId();
        $db  = db_connect();

        $discussion = $this->fetchDiscussion((int) $id, $db);
        if (!$discussion) return $this->error('Discussion not found', 404);

        if ($discussion['circle_id'] && !$this->isCircleAdmin((int) $discussion['circle_id'], $uid, $db)) {
            return $this->error('Admin access required', 403);
        }

        $db->table('discussions')->where('id', (int) $id)->update([
            'status'     => 'closed',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->success(['rejected' => true], 'Discussion rejected');
    }

    // GET /circles/:id/discussions/pending  (admin only)
    public function pendingForCircle($circleId): ResponseInterface
    {
        $uid = $this->authUserId();
        $db  = db_connect();

        if (!$this->isCircleAdmin((int) $circleId, $uid, $db)) {
            return $this->error('Admin access required', 403);
        }

        $rows = $db->table('discussions d')
            ->select('d.*, u.name AS author_name, u.avatar_url AS author_avatar')
            ->join('users u', 'u.id = d.author_id', 'left')
            ->where('d.circle_id', (int) $circleId)
            ->where('d.status', 'pending')
            ->orderBy('d.created_at', 'ASC')
            ->get()->getResultArray();

        return $this->success(array_map([$this, 'formatDiscussion'], $rows));
    }

    // POST /movements/:id/discussions
    public function createForMovement($movementId): ResponseInterface
    {
        $uid = $this->authUserId();
        $db  = db_connect();

        $movement = $db->table('movements')->where('id', (int) $movementId)->where('status', 'active')->get()->getRowArray();
        if (!$movement) {
            return $this->error('Movement not found', 404);
        }

        $isFollowing = $db->table('movement_followers')
            ->where('movement_id', (int) $movementId)->where('user_id', $uid)->countAllResults();

        if (!$isFollowing) {
            return $this->error('You must follow this movement to start a discussion', 403);
        }

        return $this->createDiscussion($uid, $db, ['movement_id' => (int) $movementId]);
    }

    // GET /discussions/:id
    public function show($id = null): ResponseInterface
    {
        $uid = $this->authUserId();
        $db  = db_connect();

        $discussion = $this->fetchDiscussion((int) $id, $db);
        if (!$discussion) {
            return $this->error('Discussion not found', 404);
        }

        if ($discussion['circle_id']) {
            if ($guard = $this->guardCircleAccess((int) $discussion['circle_id'], $uid, $db)) {
                return $guard;
            }
        }

        return $this->success($this->formatDiscussion($discussion));
    }

    // PATCH /discussions/:id
    public function update($id = null): ResponseInterface
    {
        $uid = $this->authUserId();
        $db  = db_connect();

        $discussion = $db->table('discussions')->where('id', (int) $id)->get()->getRowArray();
        if (!$discussion) {
            return $this->error('Discussion not found', 404);
        }
        if ((int) $discussion['author_id'] !== $uid) {
            return $this->error('Only the author can edit this discussion', 403);
        }

        $input  = $this->inputJson();
        $fields = [];

        if (!empty($input['title'])) {
            $fields['title'] = trim($input['title']);
        }
        if (array_key_exists('prompt', $input)) {
            $fields['prompt'] = $input['prompt'];
        }
        if (!empty($input['status']) && in_array($input['status'], ['open', 'closed'])) {
            $fields['status'] = $input['status'];
        }

        if ($fields) {
            $fields['updated_at'] = date('Y-m-d H:i:s');
            $db->table('discussions')->where('id', (int) $id)->update($fields);
        }

        return $this->success($this->formatDiscussion($this->fetchDiscussion((int) $id, $db)));
    }

    // GET /discussions/:id/comments
    public function comments($id): ResponseInterface
    {
        $uid   = $this->authUserId();
        $db    = db_connect();
        $page  = max(1, (int) ($this->request->getGet('page') ?? 1));
        $limit = min(50, max(1, (int) ($this->request->getGet('per_page') ?? 30)));
        $offset = ($page - 1) * $limit;

        $discussion = $db->table('discussions')->where('id', (int) $id)->get()->getRowArray();
        if (!$discussion) {
            return $this->error('Discussion not found', 404);
        }

        if ($discussion['circle_id']) {
            if ($guard = $this->guardCircleAccess((int) $discussion['circle_id'], $uid, $db)) {
                return $guard;
            }
        }

        $total = $db->table('discussion_comments')
            ->where('discussion_id', (int) $id)
            ->where('parent_id IS NULL', null, false)
            ->countAllResults();

        $rows = $db->table('discussion_comments dc')
            ->select('dc.*, u.name AS author_name, u.avatar_url AS author_avatar')
            ->join('users u', 'u.id = dc.author_id', 'left')
            ->where('dc.discussion_id', (int) $id)
            ->where('dc.parent_id IS NULL', null, false)
            ->orderBy('dc.created_at', 'ASC')
            ->limit($limit, $offset)
            ->get()->getResultArray();

        // Load first-level replies for each root comment
        $rootIds = array_column($rows, 'id');
        $replies = [];
        if ($rootIds) {
            $replyRows = $db->table('discussion_comments dc')
                ->select('dc.*, u.name AS author_name, u.avatar_url AS author_avatar')
                ->join('users u', 'u.id = dc.author_id', 'left')
                ->whereIn('dc.parent_id', $rootIds)
                ->orderBy('dc.created_at', 'ASC')
                ->get()->getResultArray();
            foreach ($replyRows as $r) {
                $replies[(int) $r['parent_id']][] = $this->formatComment($r);
            }
        }

        $data = array_map(function ($r) use ($replies) {
            $c = $this->formatComment($r);
            $c['replies'] = $replies[(int) $r['id']] ?? [];
            return $c;
        }, $rows);

        return $this->success($data, 'OK', 200, [
            'current_page' => $page,
            'per_page'     => $limit,
            'total'        => $total,
            'last_page'    => (int) ceil($total / $limit),
        ]);
    }

    // POST /discussions/:id/comments
    public function addComment($id): ResponseInterface
    {
        $uid   = $this->authUserId();
        $db    = db_connect();
        $input = $this->inputJson();

        $discussion = $db->table('discussions')->where('id', (int) $id)->get()->getRowArray();
        if (!$discussion) {
            return $this->error('Discussion not found', 404);
        }
        if ($discussion['status'] === 'closed') {
            return $this->error('This discussion is closed', 400);
        }

        if ($discussion['circle_id']) {
            $member = $db->table('circle_members')
                ->where('circle_id', $discussion['circle_id'])
                ->where('user_id', $uid)
                ->where('status', 'approved')
                ->get()->getRowArray();

            $circle = $db->table('circles')->where('id', $discussion['circle_id'])->get()->getRowArray();
            if (!$member && $circle['visibility'] !== 'public') {
                return $this->error('You must be a member to comment', 403);
            }
        }

        $content = trim($input['content'] ?? '');
        if (!$content) {
            return $this->validationError(['content' => 'Comment content is required']);
        }

        $parentId = !empty($input['parent_id']) ? (int) $input['parent_id'] : null;
        if ($parentId) {
            $parent = $db->table('discussion_comments')
                ->where('id', $parentId)->where('discussion_id', (int) $id)->get()->getRowArray();
            if (!$parent) {
                return $this->error('Parent comment not found', 404);
            }
            if ($parent['parent_id'] !== null) {
                return $this->error('Replies can only be one level deep', 400);
            }
        }

        $db->table('discussion_comments')->insert([
            'discussion_id' => (int) $id,
            'author_id'     => $uid,
            'content'       => $content,
            'parent_id'     => $parentId,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        $commentId = $db->insertID();
        $db->query("UPDATE discussions SET reply_count = reply_count + 1 WHERE id = ?", [(int) $id]);

        $comment = $db->table('discussion_comments dc')
            ->select('dc.*, u.name AS author_name, u.avatar_url AS author_avatar')
            ->join('users u', 'u.id = dc.author_id', 'left')
            ->where('dc.id', $commentId)
            ->get()->getRowArray();

        $c = $this->formatComment($comment);
        $c['replies'] = [];
        return $this->success($c, 'Comment added', 201);
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    private function listDiscussions(string $column, int $entityId): ResponseInterface
    {
        $db   = db_connect();
        $uid  = $this->authUserId();

        // Self-heal: ensure discussions table and correct schema exist
        try {
            $db->query("
                CREATE TABLE IF NOT EXISTS `discussions` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `circle_id` BIGINT UNSIGNED DEFAULT NULL,
                    `movement_id` BIGINT UNSIGNED DEFAULT NULL,
                    `author_id` INT UNSIGNED NOT NULL,
                    `title` VARCHAR(255) NOT NULL,
                    `prompt` TEXT DEFAULT NULL,
                    `status` ENUM('open','closed','pending') NOT NULL DEFAULT 'open',
                    `reply_count` INT UNSIGNED NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_disc_circle` (`circle_id`, `status`),
                    KEY `idx_disc_movement` (`movement_id`),
                    KEY `idx_disc_author` (`author_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\Throwable $e) {}
        try { $db->query("ALTER TABLE `discussions` MODIFY COLUMN `prompt` TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
        try { $db->query("ALTER TABLE `discussions` MODIFY COLUMN `status` ENUM('open','closed','pending') NOT NULL DEFAULT 'open'"); } catch (\Throwable $e) {}
        try { $db->query("ALTER TABLE `discussions` ADD COLUMN `movement_id` BIGINT UNSIGNED DEFAULT NULL"); } catch (\Throwable $e) {}

        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $limit  = min(50, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $offset = ($page - 1) * $limit;
        $status = $this->request->getGet('status') ?? 'open';

        $builder = $db->table('discussions d')
            ->select('d.*, u.name AS author_name, u.avatar_url AS author_avatar')
            ->join('users u', 'u.id = d.author_id', 'left')
            ->where("d.{$column}", $entityId);

        if ($status !== 'all') {
            $builder->where('d.status', $status);
        } else {
            // 'all' is only allowed for admins; non-admins never see pending
            $isAdmin = false;
            if ($column === 'circle_id') {
                $isAdmin = $this->isCircleAdmin($entityId, $uid, $db);
            } elseif ($column === 'movement_id') {
                // Check if user is admin of any circle linked to this movement
                $linked = $db->table('circle_movements')
                    ->where('movement_id', $entityId)
                    ->get()->getResultArray();
                foreach ($linked as $lc) {
                    if ($this->isCircleAdmin((int) $lc['circle_id'], $uid, $db)) {
                        $isAdmin = true;
                        break;
                    }
                }
            }
            if (!$isAdmin) {
                $builder->whereNotIn('d.status', ['pending']);
            }
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('d.created_at', 'DESC')
                         ->limit($limit, $offset)
                         ->get()->getResultArray();

        return $this->success(array_map([$this, 'formatDiscussion'], $rows), 'OK', 200, [
            'current_page' => $page,
            'per_page'     => $limit,
            'total'        => $total,
            'last_page'    => (int) ceil($total / $limit),
        ]);
    }

    private function createDiscussion(int $uid, $db, array $context, bool $isAdmin = true): ResponseInterface
    {
        // Self-heal: ensure prompt is nullable and status allows pending
        try { $db->query("ALTER TABLE `discussions` MODIFY COLUMN `prompt` TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
        try { $db->query("ALTER TABLE `discussions` MODIFY COLUMN `status` ENUM('open','closed','pending') NOT NULL DEFAULT 'open'"); } catch (\Throwable $e) {}

        $input = $this->inputJson();
        $title = trim($input['title'] ?? '');

        if (!$title) {
            return $this->validationError(['title' => 'Title is required']);
        }

        // Admin discussions go live immediately; member-created ones need approval
        $status = $isAdmin ? 'open' : 'pending';

        $insert = array_merge($context, [
            'author_id'   => $uid,
            'title'       => $title,
            'prompt'      => $input['prompt'] ?? '',
            'status'      => $status,
            'reply_count' => 0,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        // Allow linking to a movement when creating from a circle
        if (!empty($input['movement_id']) && !isset($insert['movement_id'])) {
            $insert['movement_id'] = (int) $input['movement_id'];
        }

        $db->table('discussions')->insert($insert);
        $discussionId = $db->insertID();

        $message = $isAdmin ? 'Discussion created' : 'Discussion submitted for review';
        return $this->success($this->formatDiscussion($this->fetchDiscussion($discussionId, $db)), $message, 201);
    }

    private function isCircleAdmin(int $cid, int $uid, $db): bool
    {
        if (!$uid) return false;
        return (bool) $db->table('circle_members')
            ->where('circle_id', $cid)->where('user_id', $uid)
            ->where('status', 'approved')->where('role', 'admin')
            ->countAllResults();
    }

    private function fetchDiscussion(int $id, $db): ?array
    {
        return $db->table('discussions d')
            ->select('d.*, u.name AS author_name, u.avatar_url AS author_avatar')
            ->join('users u', 'u.id = d.author_id', 'left')
            ->where('d.id', $id)
            ->get()->getRowArray() ?: null;
    }

    private function guardCircleAccess(int $cid, int $uid, $db): ?ResponseInterface
    {
        $circle = $db->table('circles')->where('id', $cid)->get()->getRowArray();
        if (!$circle) {
            return $this->error('Circle not found', 404);
        }
        if ($circle['visibility'] === 'public') {
            return null;
        }
        if (!$uid) {
            return $this->error('Authentication required', 401);
        }
        $member = $db->table('circle_members')
            ->where('circle_id', $cid)->where('user_id', $uid)->where('status', 'approved')
            ->get()->getRowArray();
        if (!$member) {
            return $this->error('You must be a member to view this discussion', 403);
        }
        return null;
    }

    // ── Formatters ────────────────────────────────────────────────────────────

    private function formatDiscussion(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'circle_id'   => $row['circle_id'] ? (int) $row['circle_id'] : null,
            'movement_id' => $row['movement_id'] ? (int) $row['movement_id'] : null,
            'title'       => $row['title'],
            'prompt'      => $row['prompt'],
            'status'      => $row['status'],
            'reply_count' => (int) $row['reply_count'],
            'author' => [
                'id'         => (int) $row['author_id'],
                'name'       => $row['author_name'],
                'avatar_url' => $row['author_avatar'],
            ],
            'created_at' => $row['created_at'],
        ];
    }

    private function formatComment(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'discussion_id' => (int) $row['discussion_id'],
            'parent_id'     => $row['parent_id'] ? (int) $row['parent_id'] : null,
            'content'       => $row['content'],
            'author' => [
                'id'         => (int) $row['author_id'],
                'name'       => $row['author_name'],
                'avatar_url' => $row['author_avatar'],
            ],
            'created_at' => $row['created_at'],
        ];
    }
}
