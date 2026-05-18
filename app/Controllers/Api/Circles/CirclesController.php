<?php

namespace App\Controllers\Api\Circles;

use App\Controllers\Api\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

class CirclesController extends BaseApiController
{
    private function db() { return db_connect(); }

    // ── GET /v1/circles ───────────────────────────────────────────────────────

    public function index(): ResponseInterface
    {
        $userId     = $this->authUserId();
        $q          = trim((string) ($this->request->getGet('q') ?? ''));
        $catId      = $this->request->getGet('category_id');
        $location   = $this->request->getGet('location');
        $visibility = $this->request->getGet('visibility');
        $sort       = $this->request->getGet('sort') ?? 'newest'; // newest|most_members|recently_active
        $tab        = $this->request->getGet('tab') ?? 'all'; // all|joined
        $page       = max(1, (int) ($this->request->getGet('page') ?? 1));
        $limit      = 20;
        $offset     = ($page - 1) * $limit;
        $db         = $this->db();

        $uid = (int) $userId; // explicit int cast — safe for SQL interpolation
        $query = $db->table('circles c')
            ->select("c.*, cat.name AS category_name,
                (SELECT cm2.role FROM circle_members cm2 WHERE cm2.circle_id = c.id AND cm2.user_id = {$uid} AND cm2.status = 'approved' LIMIT 1) AS my_role,
                (SELECT cm3.status FROM circle_members cm3 WHERE cm3.circle_id = c.id AND cm3.user_id = {$uid} LIMIT 1) AS membership_status", false)
            ->join('categories cat', 'cat.id = c.category_id', 'left')
            ->where('c.status', 'active');

        if ($q !== '') {
            $query->groupStart()->like('c.name', $q)->orLike('c.description', $q)->orLike('c.location', $q)->groupEnd();
        }
        if ($catId)      $query->where('c.category_id', (int) $catId);
        if ($location)   $query->like('c.location', $location);
        if ($visibility) $query->where('c.visibility', $visibility);

        if ($tab === 'joined') {
            $query->join('circle_members cm_j', "cm_j.circle_id = c.id AND cm_j.user_id = {$uid} AND cm_j.status = 'approved'");
        }

        if ($sort === 'most_members') {
            $query->orderBy('c.member_count', 'DESC');
        } elseif ($sort === 'recently_active') {
            $query->orderBy('c.updated_at', 'DESC');
        } else {
            $query->orderBy('c.created_at', 'DESC');
        }

        $total    = (int) (clone $query)->countAllResults(false);
        $rows     = $query->limit($limit, $offset)->get()->getResultArray();
        $lastPage = (int) ceil($total / $limit);

        return $this->success(
            array_map([$this, 'formatCircle'], $rows),
            'OK', 200,
            ['current_page' => $page, 'per_page' => $limit, 'total' => $total, 'last_page' => $lastPage]
        );
    }

    // ── GET /v1/circles/:id ───────────────────────────────────────────────────

    public function show($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = $this->db();

        $circle = $db->table('circles c')
            ->select("c.*, cat.name AS category_name,
                (SELECT cm2.role FROM circle_members cm2 WHERE cm2.circle_id = c.id AND cm2.user_id = {$userId} AND cm2.status = 'approved' LIMIT 1) AS my_role,
                (SELECT cm3.status FROM circle_members cm3 WHERE cm3.circle_id = c.id AND cm3.user_id = {$userId} LIMIT 1) AS membership_status,
                (SELECT COUNT(*) FROM discussions WHERE circle_id = c.id) AS discussion_count,
                (SELECT COUNT(*) FROM community_actions WHERE circle_id = c.id AND status = 'active') AS action_count,
                (SELECT COUNT(*) FROM posts WHERE circle_id = c.id) AS post_count,
                (SELECT COUNT(*) FROM live_sessions WHERE circle_id = c.id AND status IN ('pending','active')) AS live_count", false)
            ->join('categories cat', 'cat.id = c.category_id', 'left')
            ->where('c.id', (int) $id)
            ->get()->getRowArray();

        if (! $circle) return $this->error('Circle not found.', 404);

        $data = $this->formatCircle($circle);

        // Non-members of private/invite_only circles see basic info with locked flag
        if ($circle['visibility'] !== 'public' && ! $circle['my_role']) {
            $data['is_locked'] = true;
            return $this->success($data);
        }

        $data['is_locked'] = false;
        return $this->success($data);
    }

    // ── POST /v1/circles ──────────────────────────────────────────────────────

    public function create(): ResponseInterface
    {
        $userId = $this->authUserId();
        $input  = $this->inputJson();

        if (! $this->validateData($input, [
            'name'        => 'required|max_length[255]',
            'description' => 'required',
            'category_id' => 'required|integer',
            'visibility'  => 'permit_empty|in_list[public,private,invite_only]',
            'circle_type' => 'permit_empty|in_list[interest_based,location_based,profession_based,identity_based,chapter_based]',
        ])) {
            return $this->validationError($this->validator->getErrors());
        }

        $db   = $this->db();
        $name = trim($input['name']);
        $slug = $this->makeSlug($name, $db);
        $now  = date('Y-m-d H:i:s');

        $db->table('circles')->insert([
            'name'          => $name,
            'slug'          => $slug,
            'description'   => $input['description'],
            'banner_url'    => $input['banner_url'] ?? null,
            'logo_url'      => $input['logo_url'] ?? null,
            'category_id'   => (int) $input['category_id'],
            'location'      => $input['location'] ?? null,
            'visibility'    => $input['visibility'] ?? 'public',
            'circle_type'   => $input['circle_type'] ?? 'interest_based',
            'who_can_post'  => $input['who_can_post'] ?? 'all_members',
            'who_can_discuss' => $input['who_can_discuss'] ?? 'all_members',
            'status'        => 'active',
            'member_count'  => 1,
            'created_by'    => $userId,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        $circleId = (int) $db->insertID();

        $db->table('circle_members')->insert([
            'circle_id'  => $circleId,
            'user_id'    => $userId,
            'role'       => 'admin',
            'status'     => 'approved',
            'joined_at'  => $now,
            'created_at' => $now,
        ]);

        // Link movements if provided
        if (! empty($input['linked_movement_ids']) && is_array($input['linked_movement_ids'])) {
            foreach ($input['linked_movement_ids'] as $movementId) {
                $db->table('circle_movements')->insert([
                    'circle_id'   => $circleId,
                    'movement_id' => (int) $movementId,
                    'linked_by'   => $userId,
                    'linked_at'   => $now,
                ]);
            }
        }

        $circle = $this->fetchCircle($circleId, $userId, $db);
        return $this->success($this->formatCircle($circle), 'Circle created', 201);
    }

    // ── PATCH /v1/circles/:id ─────────────────────────────────────────────────

    public function update($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = $this->db();

        if ($err = $this->guardAdmin((int) $id, $userId, $db)) return $err;

        $input   = $this->inputJson();
        $allowed = ['name', 'description', 'banner_url', 'logo_url', 'category_id', 'location', 'visibility'];
        $data    = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $input)) $data[$f] = $input[$f];
        }
        if (! empty($data)) {
            $data['updated_at'] = date('Y-m-d H:i:s');
            $db->table('circles')->where('id', (int) $id)->update($data);
        }

        $circle = $this->fetchCircle((int) $id, $userId, $db);
        return $this->success($this->formatCircle($circle));
    }

    // ── POST /v1/circles/:id/join ─────────────────────────────────────────────

    public function join($id = null): ResponseInterface
    {
        $userId   = $this->authUserId();
        $db       = $this->db();
        $circleId = (int) $id;

        $circle = $db->table('circles')->where('id', $circleId)->where('status', 'active')->get()->getRowArray();
        if (! $circle) return $this->error('Circle not found.', 404);

        $existing = $db->table('circle_members')
            ->where('circle_id', $circleId)->where('user_id', $userId)->get()->getRowArray();

        if ($existing) {
            if ($existing['status'] === 'banned')   return $this->error('You have been banned from this circle.', 403);
            if ($existing['status'] === 'approved') return $this->error('You are already a member.', 409);
            return $this->success(['membership_status' => $existing['status']], 'Join request already pending');
        }

        $status = $circle['visibility'] === 'public' ? 'approved' : 'pending';
        $now    = date('Y-m-d H:i:s');

        $db->table('circle_members')->insert([
            'circle_id'  => $circleId,
            'user_id'    => $userId,
            'role'       => 'member',
            'status'     => $status,
            'joined_at'  => $status === 'approved' ? $now : null,
            'created_at' => $now,
        ]);

        if ($status === 'approved') {
            $db->table('circles')->where('id', $circleId)->set('member_count', 'member_count + 1', false)->update();
        }

        $msg = $status === 'approved' ? 'Joined circle' : 'Join request sent';
        return $this->success(['membership_status' => $status], $msg, 201);
    }

    // ── POST /v1/circles/:id/leave ────────────────────────────────────────────

    public function leave($id = null): ResponseInterface
    {
        $userId   = $this->authUserId();
        $db       = $this->db();
        $circleId = (int) $id;

        $member = $db->table('circle_members')
            ->where('circle_id', $circleId)->where('user_id', $userId)->get()->getRowArray();
        if (! $member) return $this->error('You are not a member of this circle.', 404);

        if ($member['role'] === 'admin') {
            $otherAdmins = $db->table('circle_members')
                ->where('circle_id', $circleId)->where('user_id !=', $userId)
                ->where('role', 'admin')->where('status', 'approved')->countAllResults();
            if ($otherAdmins === 0) {
                return $this->error('Transfer admin role to another member before leaving.', 422);
            }
        }

        $db->table('circle_members')->where('circle_id', $circleId)->where('user_id', $userId)->delete();

        if ($member['status'] === 'approved') {
            $db->table('circles')->where('id', $circleId)
               ->set('member_count', 'GREATEST(member_count - 1, 0)', false)->update();
        }

        return $this->success(null, 'Left circle');
    }

    // ── GET /v1/circles/:id/members ───────────────────────────────────────────

    public function members($id = null): ResponseInterface
    {
        $userId   = $this->authUserId();
        $db       = $this->db();
        $circleId = (int) $id;

        if ($err = $this->guardAccess($circleId, $userId, $db)) return $err;

        $statusFilter = $this->request->getGet('status') ?? 'approved';

        $rows = $db->table('circle_members cm')
            ->select('u.id, u.name, u.avatar_url, cm.role, cm.status, cm.joined_at')
            ->join('users u', 'u.id = cm.user_id')
            ->where('cm.circle_id', $circleId)
            ->where('cm.status', $statusFilter)
            ->orderBy('FIELD(cm.role, "admin","moderator","member")', '', false)
            ->orderBy('cm.joined_at', 'ASC')
            ->limit(200)
            ->get()->getResultArray();

        return $this->success(array_map(static fn($r) => [
            'id'         => (string) $r['id'],
            'name'       => $r['name'],
            'avatar_url' => $r['avatar_url'] ?? null,
            'role'       => $r['role'],
            'status'     => $r['status'],
            'joined_at'  => $r['joined_at'],
        ], $rows));
    }

    // ── PATCH /v1/circles/:id/members/:userId ─────────────────────────────────

    public function updateMember($circleId = null, $targetUserId = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = $this->db();
        $cid    = (int) $circleId;
        $tid    = (int) $targetUserId;

        if ($err = $this->guardAdmin($cid, $userId, $db)) return $err;

        $input  = $this->inputJson();
        $update = [];

        if (isset($input['role']) && in_array($input['role'], ['member', 'moderator', 'admin'], true)) {
            $update['role'] = $input['role'];
        }
        if (isset($input['status']) && in_array($input['status'], ['pending', 'approved', 'banned'], true)) {
            $update['status'] = $input['status'];
            if ($input['status'] === 'approved') {
                $update['joined_at'] = date('Y-m-d H:i:s');
            }
        }
        if (empty($update)) return $this->error('No valid fields provided.', 422);

        $old = $db->table('circle_members')->where('circle_id', $cid)->where('user_id', $tid)->get()->getRowArray();
        if (! $old) return $this->error('Member not found.', 404);

        $db->table('circle_members')->where('circle_id', $cid)->where('user_id', $tid)->update($update);

        if (isset($input['status'])) {
            $wasApproved = ($old['status'] ?? '') === 'approved';
            $nowApproved = $input['status'] === 'approved';
            if (! $wasApproved && $nowApproved) {
                $db->table('circles')->where('id', $cid)->set('member_count', 'member_count + 1', false)->update();
            } elseif ($wasApproved && ! $nowApproved) {
                $db->table('circles')->where('id', $cid)->set('member_count', 'GREATEST(member_count - 1, 0)', false)->update();
            }
        }

        return $this->success(null, 'Member updated');
    }

    // ── PUT /v1/circles/:id/members/:userId/role ──────────────────────────────

    public function updateMemberRole($circleId = null, $targetUserId = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = $this->db();
        $cid    = (int) $circleId;
        $tid    = (int) $targetUserId;

        if ($err = $this->guardAdmin($cid, $userId, $db)) return $err;

        $input = $this->inputJson();
        if (! isset($input['role']) || ! in_array($input['role'], ['member', 'moderator', 'admin'], true)) {
            return $this->error('Invalid role. Must be member, moderator, or admin.', 422);
        }

        $member = $db->table('circle_members')
            ->where('circle_id', $cid)->where('user_id', $tid)->where('status', 'approved')
            ->get()->getRowArray();
        if (! $member) return $this->error('Approved member not found.', 404);

        $db->table('circle_members')->where('circle_id', $cid)->where('user_id', $tid)
           ->update(['role' => $input['role']]);

        return $this->success(null, 'Role updated');
    }

    // ── DELETE /v1/circles/:id/members/:userId ────────────────────────────────

    public function removeMember($circleId = null, $targetUserId = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = $this->db();
        $cid    = (int) $circleId;
        $tid    = (int) $targetUserId;

        if ($err = $this->guardAdmin($cid, $userId, $db)) return $err;

        if ($tid === $userId) return $this->error('Use the leave endpoint to remove yourself.', 422);

        $member = $db->table('circle_members')
            ->where('circle_id', $cid)->where('user_id', $tid)->get()->getRowArray();
        if (! $member) return $this->error('Member not found.', 404);

        $db->table('circle_members')->where('circle_id', $cid)->where('user_id', $tid)->delete();

        if (($member['status'] ?? '') === 'approved') {
            $db->table('circles')->where('id', $cid)
               ->set('member_count', 'GREATEST(member_count - 1, 0)', false)->update();
        }

        return $this->success(null, 'Member removed');
    }

    // ── DELETE /v1/circles/:id ────────────────────────────────────────────────

    public function delete($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = $this->db();
        $cid    = (int) $id;

        if ($err = $this->guardAdmin($cid, $userId, $db)) return $err;

        $db->table('circle_members')->where('circle_id', $cid)->delete();
        $db->table('circles')->where('id', $cid)->update([
            'status'     => 'deleted',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->success(null, 'Circle deleted');
    }

    // ── GET /v1/circles/:id/movements ─────────────────────────────────────────

    public function listMovements($id = null): ResponseInterface
    {
        $userId   = $this->authUserId();
        $db       = $this->db();
        $circleId = (int) $id;

        if ($err = $this->guardAccess($circleId, $userId, $db)) return $err;

        $rows = $db->table('circle_movements cm')
            ->select("m.*, u.name AS organizer_name,
                (SELECT 1 FROM movement_followers mf WHERE mf.movement_id = m.id AND mf.user_id = {$userId}) AS is_following", false)
            ->join('movements m', 'm.id = cm.movement_id')
            ->join('users u', 'u.id = m.organizer_id', 'left')
            ->where('cm.circle_id', $circleId)
            ->orderBy('cm.linked_at', 'DESC')
            ->get()->getResultArray();

        return $this->success(array_map([$this, 'formatMovementSummary'], $rows));
    }

    // ── POST /v1/circles/:id/movements/:movementId ────────────────────────────

    public function linkMovement($circleId = null, $movementId = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = $this->db();
        $cid    = (int) $circleId;
        $mid    = (int) $movementId;

        if ($err = $this->guardAdmin($cid, $userId, $db)) return $err;

        $movement = $db->table('movements')->where('id', $mid)->where('status', 'active')->get()->getRowArray();
        if (! $movement) return $this->error('Movement not found.', 404);

        $exists = $db->table('circle_movements')
            ->where('circle_id', $cid)->where('movement_id', $mid)->countAllResults();
        if ($exists) return $this->error('Movement already linked to this circle.', 409);

        $db->table('circle_movements')->insert([
            'circle_id'   => $cid,
            'movement_id' => $mid,
            'linked_by'   => $userId,
            'linked_at'   => date('Y-m-d H:i:s'),
        ]);

        return $this->success(null, 'Movement linked', 201);
    }

    // ── DELETE /v1/circles/:id/movements/:movementId ──────────────────────────

    public function unlinkMovement($circleId = null, $movementId = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = $this->db();
        $cid    = (int) $circleId;
        $mid    = (int) $movementId;

        if ($err = $this->guardAdmin($cid, $userId, $db)) return $err;

        $db->table('circle_movements')->where('circle_id', $cid)->where('movement_id', $mid)->delete();
        return $this->success(null, 'Movement unlinked');
    }

    // ── Guards ────────────────────────────────────────────────────────────────

    private function guardAccess(int $circleId, int $userId, $db): ?ResponseInterface
    {
        $circle = $db->table('circles')->select('visibility, status')->where('id', $circleId)->get()->getRowArray();
        if (! $circle) return $this->error('Circle not found.', 404);
        if ($circle['status'] !== 'active') return $this->error('Circle not available.', 404);
        if ($circle['visibility'] === 'public') return null;

        $isMember = $db->table('circle_members')
            ->where('circle_id', $circleId)->where('user_id', $userId)->where('status', 'approved')
            ->countAllResults();
        if (! $isMember) return $this->error('You must be a circle member.', 403);
        return null;
    }

    private function guardAdmin(int $circleId, int $userId, $db): ?ResponseInterface
    {
        $isAdmin = $db->table('circle_members')
            ->where('circle_id', $circleId)->where('user_id', $userId)
            ->where('role', 'admin')->where('status', 'approved')->countAllResults();
        if (! $isAdmin) return $this->error('Circle admin access required.', 403);
        return null;
    }

    // ── Formatters / helpers ──────────────────────────────────────────────────

    private function fetchCircle(int $circleId, int $userId, $db): array
    {
        return $db->table('circles c')
            ->select("c.*, cat.name AS category_name,
                (SELECT cm2.role FROM circle_members cm2 WHERE cm2.circle_id = c.id AND cm2.user_id = {$userId} AND cm2.status = 'approved' LIMIT 1) AS my_role,
                (SELECT cm3.status FROM circle_members cm3 WHERE cm3.circle_id = c.id AND cm3.user_id = {$userId} LIMIT 1) AS membership_status", false)
            ->join('categories cat', 'cat.id = c.category_id', 'left')
            ->where('c.id', $circleId)
            ->get()->getRowArray();
    }

    public function formatCircle(array $row): array
    {
        return [
            'id'               => (string) $row['id'],
            'name'             => $row['name'],
            'slug'             => $row['slug'],
            'description'      => $row['description'] ?? null,
            'banner_url'       => $row['banner_url'] ?? null,
            'logo_url'         => $row['logo_url'] ?? null,
            'category_id'      => $row['category_id'] ? (string) $row['category_id'] : null,
            'category_name'    => $row['category_name'] ?? null,
            'location'         => $row['location'] ?? null,
            'visibility'       => $row['visibility'],
            'status'           => $row['status'],
            'member_count'     => (int) ($row['member_count'] ?? 0),
            'discussion_count' => (int) ($row['discussion_count'] ?? 0),
            'action_count'     => (int) ($row['action_count'] ?? 0),
            'post_count'       => (int) ($row['post_count'] ?? 0),
            'live_count'       => (int) ($row['live_count'] ?? 0),
            'is_member'        => ! empty($row['my_role']) || ($row['membership_status'] ?? '') === 'pending',
            'membership_status' => $row['membership_status'] ?? null,
            'my_role'          => $row['my_role'] ?? null,
            'circle_type'      => $row['circle_type'] ?? null,
            'who_can_post'     => $row['who_can_post'] ?? 'all_members',
            'who_can_discuss'  => $row['who_can_discuss'] ?? 'all_members',
            'created_at'       => $row['created_at'],
        ];
    }

    private function formatMovementSummary(array $row): array
    {
        return [
            'id'             => (string) $row['id'],
            'title'          => $row['title'],
            'slug'           => $row['slug'],
            'description'    => $row['description'] ?? null,
            'cover_url'      => $row['cover_url'] ?? null,
            'organizer_name' => $row['organizer_name'] ?? null,
            'follower_count' => (int) ($row['follower_count'] ?? 0),
            'status'         => $row['status'],
            'is_following'   => (bool) ($row['is_following'] ?? false),
        ];
    }

    public static function makeSlug(string $name, $db): string
    {
        $slug   = trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name)), '-');
        $exists = $db->table('circles')->where('slug', $slug)->countAllResults();
        return $exists > 0 ? $slug . '-' . time() : $slug;
    }

    // POST /circles/:id/members  (admin-only direct invite)
    public function inviteMember($id = null): ResponseInterface
    {
        $userId   = $this->authUserId();
        $db       = $this->db();
        $circleId = (int) $id;
        if ($err = $this->guardAdmin($circleId, $userId, $db)) return $err;
        $input        = $this->inputJson();
        $targetUserId = (int) ($input['user_id'] ?? 0);
        if (!$targetUserId) {
            return $this->validationError(['user_id' => 'user_id is required']);
        }
        $user = $db->table('users')->where('id', $targetUserId)->get()->getRowArray();
        if (!$user) return $this->error('User not found', 404);
        $existing = $db->table('circle_members')
            ->where('circle_id', $circleId)->where('user_id', $targetUserId)
            ->get()->getRowArray();
        if ($existing) {
            if ($existing['status'] === 'approved') return $this->error('User is already a member', 400);
            $db->table('circle_members')
                ->where('circle_id', $circleId)->where('user_id', $targetUserId)
                ->update(['status' => 'approved', 'joined_at' => date('Y-m-d H:i:s')]);
        } else {
            $now = date('Y-m-d H:i:s');
            $db->table('circle_members')->insert([
                'circle_id'  => $circleId,
                'user_id'    => $targetUserId,
                'role'       => 'member',
                'status'     => 'approved',
                'joined_at'  => $now,
                'created_at' => $now,
            ]);
            $db->query('UPDATE circles SET member_count = member_count + 1 WHERE id = ?', [$circleId]);
        }
        return $this->success([
            'invited'  => true,
            'user'     => [
                'id'         => (string) $targetUserId,
                'name'       => $user['name'],
                'avatar_url' => $user['avatar_url'] ?? null,
            ],
        ], 'User added to circle', 201);
    }
}
