<?php

namespace App\Controllers\Api\CommunityActions;

use App\Controllers\Api\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

class CommunityActionsController extends BaseApiController
{
    private const VALID_TYPES = ['register', 'attend', 'volunteer', 'apply', 'share', 'survey', 'discuss', 'join_live'];

    public function index(): ResponseInterface
    {
        $db   = db_connect();
        $uid  = $this->authUserId();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $limit  = min(50, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $offset = ($page - 1) * $limit;

        $circleId   = $this->request->getGet('circle_id');
        $movementId = $this->request->getGet('movement_id');
        $type       = $this->request->getGet('type');
        $status     = $this->request->getGet('status') ?? 'active';

        $builder = $db->table('community_actions ca')
            ->select('ca.*, u.name AS creator_name')
            ->join('users u', 'u.id = ca.created_by', 'left');

        if ($status !== 'all') {
            $builder->where('ca.status', $status);
        }
        if ($circleId) {
            $builder->where('ca.circle_id', (int) $circleId);
        }
        if ($movementId) {
            $builder->where('ca.movement_id', (int) $movementId);
        }
        if ($type && in_array($type, self::VALID_TYPES)) {
            $builder->where('ca.action_type', $type);
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('ca.created_at', 'DESC')
                         ->limit($limit, $offset)
                         ->get()->getResultArray();

        $actionIds = array_column($rows, 'id');
        $participatingSet = [];
        if ($uid && $actionIds) {
            $participatingSet = array_column(
                $db->table('action_participants')
                   ->select('action_id, participation_type')
                   ->whereIn('action_id', $actionIds)
                   ->where('user_id', $uid)
                   ->get()->getResultArray(),
                null,
                'action_id'
            );
        }

        $data = array_map(fn($r) => $this->formatAction($r, $participatingSet), $rows);

        return $this->success($data, 'OK', 200, [
            'current_page' => $page,
            'per_page'     => $limit,
            'total'        => $total,
            'last_page'    => (int) ceil($total / $limit),
        ]);
    }

    public function show($id = null): ResponseInterface
    {
        $uid = $this->authUserId();
        $db  = db_connect();

        $action = $this->fetchAction((int) $id, $db);
        if (!$action) {
            return $this->error('Action not found', 404);
        }

        $participation = $uid ? $db->table('action_participants')
            ->where('action_id', (int) $id)->where('user_id', $uid)
            ->get()->getRowArray() : null;

        $formatted = $this->formatAction($action, $participation ? [(int)$id => $participation] : []);
        return $this->success($formatted);
    }

    public function create(): ResponseInterface
    {
        $uid   = $this->authUserId();
        $db    = db_connect();
        $input = $this->inputJson();

        $title = trim($input['title'] ?? '');
        if (!$title) {
            return $this->validationError(['title' => 'Title is required']);
        }
        if (!isset($input['action_type']) || !in_array($input['action_type'], self::VALID_TYPES)) {
            return $this->validationError(['action_type' => 'Valid action_type is required']);
        }

        // Verify circle/movement membership if provided
        if (!empty($input['circle_id'])) {
            $member = $db->table('circle_members')
                ->where('circle_id', (int) $input['circle_id'])
                ->where('user_id', $uid)
                ->where('status', 'approved')
                ->whereIn('role', ['admin', 'moderator'])
                ->get()->getRowArray();
            if (!$member) {
                return $this->error('Only circle admins/moderators can create actions', 403);
            }
        }

        $db->table('community_actions')->insert([
            'title'              => $title,
            'description'        => $input['description'] ?? null,
            'action_type'        => $input['action_type'],
            'circle_id'          => !empty($input['circle_id'])     ? (int) $input['circle_id']     : null,
            'movement_id'        => !empty($input['movement_id'])    ? (int) $input['movement_id']   : null,
            'discussion_id'      => !empty($input['discussion_id'])  ? (int) $input['discussion_id'] : null,
            'cta_label'          => $input['cta_label'] ?? 'Take Action',
            'cta_url'            => $input['cta_url'] ?? null,
            'deadline'           => !empty($input['deadline']) ? $input['deadline'] : null,
            'participant_goal'   => !empty($input['participant_goal']) ? (int) $input['participant_goal'] : null,
            'interested_count'   => 0,
            'completed_count'    => 0,
            'status'             => 'active',
            'created_by'         => $uid,
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        $actionId = $db->insertID();
        return $this->success($this->formatAction($this->fetchAction($actionId, $db), []), 'Action created', 201);
    }

    public function update($id = null): ResponseInterface
    {
        $uid = $this->authUserId();
        $db  = db_connect();

        $action = $db->table('community_actions')->where('id', (int) $id)->get()->getRowArray();
        if (!$action) {
            return $this->error('Action not found', 404);
        }
        if ((int) $action['created_by'] !== $uid) {
            return $this->error('Only the creator can edit this action', 403);
        }

        $input  = $this->inputJson();
        $fields = [];

        if (!empty($input['title'])) {
            $fields['title'] = trim($input['title']);
        }
        foreach (['description', 'cta_label', 'cta_url', 'deadline'] as $f) {
            if (array_key_exists($f, $input)) {
                $fields[$f] = $input[$f];
            }
        }
        if (!empty($input['participant_goal'])) {
            $fields['participant_goal'] = (int) $input['participant_goal'];
        }
        if (!empty($input['status']) && in_array($input['status'], ['active', 'closed'])) {
            $fields['status'] = $input['status'];
        }

        if ($fields) {
            $fields['updated_at'] = date('Y-m-d H:i:s');
            $db->table('community_actions')->where('id', (int) $id)->update($fields);
        }

        return $this->success($this->formatAction($this->fetchAction((int) $id, $db), []));
    }

    public function participate($id): ResponseInterface
    {
        $uid   = $this->authUserId();
        $db    = db_connect();
        $input = $this->inputJson();

        $action = $db->table('community_actions')->where('id', (int) $id)->where('status', 'active')->get()->getRowArray();
        if (!$action) {
            return $this->error('Action not found or closed', 404);
        }

        $pType = $input['participation_type'] ?? 'interested';
        if (!in_array($pType, ['interested', 'completed'])) {
            return $this->validationError(['participation_type' => 'Must be interested or completed']);
        }

        $existing = $db->table('action_participants')
            ->where('action_id', (int) $id)->where('user_id', $uid)->get()->getRowArray();

        if (!$existing) {
            $db->table('action_participants')->insert([
                'action_id'          => (int) $id,
                'user_id'            => $uid,
                'participation_type' => $pType,
            ]);
            $column = $pType === 'completed' ? 'completed_count' : 'interested_count';
            $db->query("UPDATE community_actions SET {$column} = {$column} + 1 WHERE id = ?", [(int) $id]);
        } elseif ($existing['participation_type'] !== $pType) {
            // Upgrade from interested → completed
            $db->table('action_participants')
                ->where('action_id', (int) $id)->where('user_id', $uid)
                ->update(['participation_type' => $pType]);

            if ($pType === 'completed' && $existing['participation_type'] === 'interested') {
                $db->query("UPDATE community_actions SET
                    interested_count = GREATEST(interested_count - 1, 0),
                    completed_count  = completed_count + 1
                    WHERE id = ?", [(int) $id]);
            }
        }

        $updated = $db->table('community_actions')->where('id', (int) $id)->get()->getRowArray();
        return $this->success([
            'participation_type' => $pType,
            'interested_count'   => (int) $updated['interested_count'],
            'completed_count'    => (int) $updated['completed_count'],
        ]);
    }

    public function unparticipate($id): ResponseInterface
    {
        $uid = $this->authUserId();
        $db  = db_connect();

        $existing = $db->table('action_participants')
            ->where('action_id', (int) $id)->where('user_id', $uid)->get()->getRowArray();

        if ($existing) {
            $db->table('action_participants')
                ->where('action_id', (int) $id)->where('user_id', $uid)->delete();

            $column = $existing['participation_type'] === 'completed' ? 'completed_count' : 'interested_count';
            $db->query("UPDATE community_actions SET {$column} = GREATEST({$column} - 1, 0) WHERE id = ?", [(int) $id]);
        }

        return $this->success(['participating' => false]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function fetchAction(int $id, $db): ?array
    {
        return $db->table('community_actions ca')
            ->select('ca.*, u.name AS creator_name')
            ->join('users u', 'u.id = ca.created_by', 'left')
            ->where('ca.id', $id)
            ->get()->getRowArray() ?: null;
    }

    protected function formatAction(array $row, array $participatingSet): array
    {
        $myParticipation = $participatingSet[(int) $row['id']] ?? null;
        return [
            'id'               => (int) $row['id'],
            'title'            => $row['title'],
            'description'      => $row['description'],
            'action_type'      => $row['action_type'],
            'circle_id'        => $row['circle_id']     ? (int) $row['circle_id']     : null,
            'movement_id'      => $row['movement_id']   ? (int) $row['movement_id']   : null,
            'discussion_id'    => $row['discussion_id'] ? (int) $row['discussion_id'] : null,
            'cta_label'        => $row['cta_label'],
            'cta_url'          => $row['cta_url'],
            'deadline'         => $row['deadline'],
            'participant_goal' => $row['participant_goal'] ? (int) $row['participant_goal'] : null,
            'interested_count' => (int) $row['interested_count'],
            'completed_count'  => (int) $row['completed_count'],
            'status'           => $row['status'],
            'my_participation' => $myParticipation ? $myParticipation['participation_type'] : null,
            'creator' => [
                'id'   => (int) $row['created_by'],
                'name' => $row['creator_name'],
            ],
            'created_at' => $row['created_at'],
        ];
    }
}
