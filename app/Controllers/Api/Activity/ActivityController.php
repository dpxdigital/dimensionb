<?php

namespace App\Controllers\Api\Activity;

use App\Controllers\Api\BaseApiController;
use App\Models\ListingModel;
use CodeIgniter\HTTP\ResponseInterface;

class ActivityController extends BaseApiController
{
    private const PER_PAGE = 20;

    // ── GET /v1/activity?tab=saved|rsvped|applied|watched ─────────────────────

    public function index(): ResponseInterface
    {
        $userId = $this->authUserId();
        $tab    = $this->request->getGet('tab') ?? 'saved';
        $page   = max(1, (int) ($this->request->getGet('page') ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $validTabs = ['saved', 'rsvped', 'applied', 'watched'];
        if (! in_array($tab, $validTabs, true)) {
            return $this->error("Invalid tab. Must be one of: " . implode(', ', $validTabs), 422);
        }

        [$items, $total] = match ($tab) {
            'saved'   => $this->getSaved($userId, $offset),
            'rsvped'  => $this->getRsvped($userId, $offset),
            'applied' => $this->getApplied($userId, $offset),
            'watched' => $this->getWatched($userId, $offset),
        };

        return $this->success(
            $items,
            'OK',
            200,
            [
                'current_page' => $page,
                'per_page'     => self::PER_PAGE,
                'total'        => $total,
                'last_page'    => (int) ceil($total / self::PER_PAGE),
            ]
        );
    }

    // ── Private tab helpers ───────────────────────────────────────────────────

    private function getSaved(int $userId, int $offset): array
    {
        $db    = db_connect();
        $total = $db->table('listing_saves')
            ->where('user_id', $userId)->countAllResults();

        $rows = (new ListingModel())
            ->withMeta($userId)
            ->join('listing_saves ls_act', 'ls_act.listing_id = listings.id')
            ->where('ls_act.user_id', $userId)
            ->orderBy('ls_act.created_at', 'DESC')
            ->findAll(self::PER_PAGE, $offset);

        return [
            array_map(fn($r) => $this->wrapActivity($r, 'saved'), $rows),
            $total,
        ];
    }

    private function getRsvped(int $userId, int $offset): array
    {
        $db    = db_connect();
        $total = $db->table('listing_rsvps')
            ->where('user_id', $userId)->countAllResults();

        $rows = (new ListingModel())
            ->withMeta($userId)
            ->join('listing_rsvps lr_act', 'lr_act.listing_id = listings.id')
            ->where('lr_act.user_id', $userId)
            ->orderBy('lr_act.created_at', 'DESC')
            ->findAll(self::PER_PAGE, $offset);

        return [
            array_map(fn($r) => $this->wrapActivity($r, 'rsvped'), $rows),
            $total,
        ];
    }

    private function getApplied(int $userId, int $offset): array
    {
        $db    = db_connect();
        $total = $db->table('activity_log')
            ->where('user_id', $userId)
            ->where('action_type', 'applied')
            ->countAllResults();

        $rows = (new ListingModel())
            ->withMeta($userId)
            ->join('activity_log al_act', 'al_act.listing_id = listings.id')
            ->where('al_act.user_id', $userId)
            ->where('al_act.action_type', 'applied')
            ->orderBy('al_act.created_at', 'DESC')
            ->findAll(self::PER_PAGE, $offset);

        return [
            array_map(fn($r) => $this->wrapActivity($r, 'applied'), $rows),
            $total,
        ];
    }

    private function wrapActivity(array $row, string $actionType): array
    {
        $listing = ListingModel::format($row);
        return [
            'id'                 => (string) $row['id'],
            'listing_id'         => (string) $row['id'],
            'listing_title'      => $listing['title'] ?? '',
            'listing_image_url'  => $listing['image_url'] ?? null,
            'listing_category'   => $listing['category'] ?? '',
            'action_type'        => $actionType,
            'created_at'         => $row['created_at'] ?? date('Y-m-d H:i:s'),
        ];
    }

    private function getWatched(int $userId, int $offset): array
    {
        $db    = db_connect();
        $total = $db->table('activity_log')
            ->where('user_id', $userId)
            ->where('action_type', 'watched')
            ->countAllResults();

        $rows = $db->table('activity_log al')
            ->select('al.id, al.action_type, al.created_at, ls.id AS session_id, ls.title, ls.category, ls.status, ls.replay_url, ls.viewer_count, ls.started_at,
                      u.id AS host_id, u.name AS host_name, u.avatar_url AS host_avatar')
            ->join('live_sessions ls', 'ls.id = al.listing_id')
            ->join('users u', 'u.id = ls.host_id')
            ->where('al.user_id', $userId)
            ->where('al.action_type', 'watched')
            ->orderBy('al.created_at', 'DESC')
            ->limit(self::PER_PAGE, $offset)
            ->get()->getResultArray();

        $formatted = array_map(static fn($r) => [
            'id'          => (int) $r['id'],
            'actionType'  => $r['action_type'],
            'createdAt'   => $r['created_at'],
            'session'     => [
                'id'          => (int) $r['session_id'],
                'title'       => $r['title'],
                'category'    => $r['category'],
                'status'      => $r['status'],
                'replayUrl'   => $r['replay_url'],
                'viewerCount' => (int) $r['viewer_count'],
                'startedAt'   => $r['started_at'],
                'host'        => [
                    'id'        => (int) $r['host_id'],
                    'name'      => $r['host_name'],
                    'avatarUrl' => $r['host_avatar'],
                ],
            ],
        ], $rows);

        return [$formatted, $total];
    }
}
