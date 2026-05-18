<?php

namespace App\Controllers\Api\Movements;

use App\Controllers\Api\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

class MovementsController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $db     = db_connect();
        $uid    = $this->authUserId();
        $page   = max(1, (int) ($this->request->getGet('page') ?? 1));
        $limit  = min(50, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $offset = ($page - 1) * $limit;

        $category = $this->request->getGet('category');
        $search   = $this->request->getGet('q');
        $tab      = $this->request->getGet('tab') ?? 'all'; // all | following
        $circleId = (int) ($this->request->getGet('circle_id') ?? 0);

        $builder = $db->table('movements m')
            ->select('m.*, c.name AS category_name')
            ->join('categories c', 'c.id = m.category_id', 'left')
            ->where('m.status', 'active');

        if ($circleId > 0) {
            $builder->join('circle_movements cirm', "cirm.movement_id = m.id AND cirm.circle_id = {$circleId}", 'inner');
        }

        if ($tab === 'following' && $uid) {
            $builder->join('movement_followers mf', "mf.movement_id = m.id AND mf.user_id = {$uid}", 'inner');
        }

        if ($category) {
            $builder->where('c.slug', $category);
        }

        if ($search) {
            $safe = $db->escapeString($search);
            $builder->groupStart()
                ->like('m.title', $safe)
                ->orLike('m.description', $safe)
                ->groupEnd();
        }

        $total   = $builder->countAllResults(false);
        $rows    = $builder->orderBy('m.follower_count', 'DESC')
                           ->limit($limit, $offset)
                           ->get()->getResultArray();

        $movementIds = array_column($rows, 'id');
        $followingSet = [];
        if ($uid && $movementIds) {
            $followingSet = array_column(
                $db->table('movement_followers')
                   ->whereIn('movement_id', $movementIds)
                   ->where('user_id', $uid)
                   ->get()->getResultArray(),
                'movement_id'
            );
        }

        $data = array_map(fn($r) => $this->formatMovement($r, $uid, $followingSet), $rows);

        return $this->success($data, 'OK', 200, [
            'current_page' => $page,
            'per_page'     => $limit,
            'total'        => $total,
            'last_page'    => (int) ceil($total / $limit),
        ]);
    }

    public function show($id = null): ResponseInterface
    {
        $db  = db_connect();
        $uid = $this->authUserId();

        $row = $db->table('movements m')
            ->select('m.*, c.name AS category_name, u.name AS organizer_name, u.avatar_url AS organizer_avatar')
            ->join('categories c', 'c.id = m.category_id', 'left')
            ->join('users u', 'u.id = m.organizer_id', 'left')
            ->where('m.id', (int) $id)
            ->get()->getRowArray();

        if (!$row) {
            return $this->error('Movement not found', 404);
        }

        $circleCount     = (int) $db->table('circle_movements')->where('movement_id', $id)->countAllResults();
        $discussionCount = (int) $db->table('discussions')->where('movement_id', $id)->where('status', 'open')->countAllResults();
        $actionCount     = (int) $db->table('community_actions')->where('movement_id', $id)->where('status', 'active')->countAllResults();

        $isFollowing = false;
        if ($uid) {
            $isFollowing = (bool) $db->table('movement_followers')
                ->where('movement_id', $id)->where('user_id', $uid)
                ->countAllResults();
        }

        $movement = $this->formatMovement($row, $uid, $isFollowing ? [(int)$id] : []);
        $movement['circle_count']     = $circleCount;
        $movement['discussion_count'] = $discussionCount;
        $movement['action_count']     = $actionCount;
        $movement['organizer'] = [
            'id'         => (int) $row['organizer_id'],
            'name'       => $row['organizer_name'],
            'avatar_url' => $row['organizer_avatar'],
        ];

        return $this->success($movement);
    }

    public function create(): ResponseInterface
    {
        $uid   = $this->authUserId();
        $input = $this->inputJson();

        $title = trim($input['title'] ?? '');
        if (!$title) {
            return $this->validationError(['title' => 'Title is required']);
        }

        $db   = db_connect();
        $slug = self::makeSlug($title, $db);

        $db->table('movements')->insert([
            'title'       => $title,
            'slug'        => $slug,
            'description' => $input['description'] ?? null,
            'category_id' => !empty($input['category_id']) ? (int) $input['category_id'] : null,
            'organizer_id'=> $uid,
            'cover_url'   => $input['cover_url'] ?? null,
            'status'      => 'active',
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        $movementId = $db->insertID();

        // Creator auto-follows
        $db->table('movement_followers')->insert([
            'movement_id' => $movementId,
            'user_id'     => $uid,
        ]);
        $db->table('movements')->where('id', $movementId)->update(['follower_count' => 1]);

        $movement = $db->table('movements m')
            ->select('m.*, c.name AS category_name')
            ->join('categories c', 'c.id = m.category_id', 'left')
            ->where('m.id', $movementId)
            ->get()->getRowArray();

        return $this->success($this->formatMovement($movement, $uid, [(int)$movementId]), 'Movement created', 201);
    }

    public function update($id = null): ResponseInterface
    {
        $uid = $this->authUserId();
        $db  = db_connect();

        $movement = $db->table('movements')->where('id', (int) $id)->get()->getRowArray();
        if (!$movement) {
            return $this->error('Movement not found', 404);
        }
        if ((int) $movement['organizer_id'] !== $uid) {
            return $this->error('Only the organizer can edit this movement', 403);
        }

        $input  = $this->inputJson();
        $fields = [];

        if (isset($input['title']) && trim($input['title']) !== '') {
            $fields['title'] = trim($input['title']);
            if ($fields['title'] !== $movement['title']) {
                $fields['slug'] = self::makeSlug($fields['title'], $db);
            }
        }
        foreach (['description', 'cover_url'] as $f) {
            if (array_key_exists($f, $input)) {
                $fields[$f] = $input[$f];
            }
        }
        if (!empty($input['category_id'])) {
            $fields['category_id'] = (int) $input['category_id'];
        }
        if (!empty($input['status']) && in_array($input['status'], ['active', 'archived'])) {
            $fields['status'] = $input['status'];
        }

        if ($fields) {
            $fields['updated_at'] = date('Y-m-d H:i:s');
            $db->table('movements')->where('id', (int) $id)->update($fields);
        }

        $updated = $db->table('movements m')
            ->select('m.*, c.name AS category_name')
            ->join('categories c', 'c.id = m.category_id', 'left')
            ->where('m.id', (int) $id)
            ->get()->getRowArray();

        $isFollowing = (bool) $db->table('movement_followers')
            ->where('movement_id', $id)->where('user_id', $uid)->countAllResults();

        return $this->success($this->formatMovement($updated, $uid, $isFollowing ? [(int)$id] : []));
    }

    public function follow($id): ResponseInterface
    {
        $uid = $this->authUserId();
        $db  = db_connect();

        $exists = $db->table('movement_followers')
            ->where('movement_id', (int)$id)->where('user_id', $uid)->countAllResults();

        if (!$exists) {
            $row = $db->table('movements')->where('id', (int) $id)->where('status', 'active')->get()->getRowArray();
            if (!$row) return $this->error('Movement not found', 404);
            $db->table('movement_followers')->insert(['movement_id' => (int)$id, 'user_id' => $uid]);
            $db->query("UPDATE movements SET follower_count = follower_count + 1 WHERE id = ?", [(int)$id]);
        }

        $row = $db->table('movements m')
            ->select('m.*, c.name AS category_name')
            ->join('categories c', 'c.id = m.category_id', 'left')
            ->where('m.id', (int) $id)
            ->get()->getRowArray();

        if (!$row) return $this->error('Movement not found', 404);

        return $this->success($this->formatMovement($row, $uid, [(int)$id]));
    }

    public function unfollow($id): ResponseInterface
    {
        $uid = $this->authUserId();
        $db  = db_connect();

        $movement = $db->table('movements')->where('id', (int) $id)->get()->getRowArray();
        if (!$movement) {
            return $this->error('Movement not found', 404);
        }
        if ((int) $movement['organizer_id'] === $uid) {
            return $this->error('Organizer cannot unfollow their own movement', 400);
        }

        $deleted = $db->table('movement_followers')
            ->where('movement_id', (int)$id)->where('user_id', $uid)->delete();

        if ($deleted) {
            $db->query("UPDATE movements SET follower_count = GREATEST(follower_count - 1, 0) WHERE id = ?", [(int)$id]);
        }

        $row = $db->table('movements m')
            ->select('m.*, c.name AS category_name')
            ->join('categories c', 'c.id = m.category_id', 'left')
            ->where('m.id', (int) $id)
            ->get()->getRowArray();

        return $this->success($this->formatMovement($row, $uid, []));
    }

    // ── Formatters ────────────────────────────────────────────────────────────

    private function formatMovement(array $row, int $uid, array $followingSet): array
    {
        $isCircleAdmin = false;
        if ($uid) {
            $db = db_connect();
            $isCircleAdmin = (bool) $db->table('circle_movements cm')
                ->join('circle_members cmem', 'cmem.circle_id = cm.circle_id')
                ->where('cm.movement_id', (int) $row['id'])
                ->where('cmem.user_id', $uid)
                ->where('cmem.role', 'admin')
                ->where('cmem.status', 'approved')
                ->countAllResults();
        }
        return [
            'id'              => (int) $row['id'],
            'title'           => $row['title'],
            'slug'            => $row['slug'],
            'description'     => $row['description'],
            'category_id'     => $row['category_id'] ? (int) $row['category_id'] : null,
            'category_name'   => $row['category_name'] ?? null,
            'cover_url'       => $row['cover_url'],
            'follower_count'  => (int) $row['follower_count'],
            'status'          => $row['status'],
            'is_following'    => in_array((int) $row['id'], array_map('intval', $followingSet)),
            'is_circle_admin' => $isCircleAdmin,
            'created_at'      => $row['created_at'],
        ];
    }

    public static function makeSlug(string $name, $db): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($name)));
        $base = trim($base, '-');
        $slug = $base;
        $i    = 1;
        while ($db->table('movements')->where('slug', $slug)->countAllResults()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
