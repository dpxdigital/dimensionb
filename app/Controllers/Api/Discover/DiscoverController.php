<?php

namespace App\Controllers\Api\Discover;

use App\Controllers\Api\BaseApiController;
use App\Models\ActivityLogModel;
use App\Models\ListingModel;
use CodeIgniter\HTTP\ResponseInterface;

class DiscoverController extends BaseApiController
{
    private const PER_PAGE      = 20;
    private const DEFAULT_RADIUS = 25.0; // km

    // ── GET /v1/discover ──────────────────────────────────────────────────────

    public function index(): ResponseInterface
    {
        $userId   = $this->authUserId();
        $lat      = $this->request->getGet('lat');
        $lng      = $this->request->getGet('lng');
        $radius   = (float) ($this->request->getGet('radius') ?? self::DEFAULT_RADIUS);
        $category = $this->request->getGet('category');
        $lastId   = (int) ($this->request->getGet('last_id') ?? 0) ?: null;

        $model = new ListingModel();

        // Build base query: approved, active, with per-user meta
        $model->withMeta($userId)->active();

        // Exclude listings the user has already interacted with
        $this->excludeSeen($model, $userId);

        // Geo filter when coordinates are provided
        if ($lat !== null && $lng !== null) {
            $model->nearby((float) $lat, (float) $lng, $radius);
        }

        // Category filter
        if ($category !== null) {
            $model->join('categories dc', 'dc.id = listings.category_id', 'left')
                  ->where('dc.slug', $category);
        }

        // Cursor pagination with slight randomisation within each page
        if ($lastId !== null) {
            $model->where('listings.id <', $lastId);
        }

        // RAND() seed based on user + day keeps order stable for same session
        $seed = crc32($userId . date('Y-m-d'));
        $rows = $model->orderBy("RAND({$seed})", '', false)
                      ->findAll(self::PER_PAGE + 1);

        $hasMore = count($rows) > self::PER_PAGE;
        $items   = array_slice($rows, 0, self::PER_PAGE);

        return $this->success(
            array_map([ListingModel::class, 'format'], $items),
            'OK',
            200,
            ['has_more' => $hasMore, 'per_page' => self::PER_PAGE]
        );
    }

    // ── POST /v1/discover/:id/pass ────────────────────────────────────────────

    public function pass($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        (new ActivityLogModel())->log($userId, (int) $id, 'pass');
        return $this->success(null, 'Passed');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Exclude listings already seen (saved, RSVPed, applied, passed, shared).
     */
    private function excludeSeen(ListingModel $model, int $userId): void
    {
        $seenIds = db_connect()
            ->table('activity_log')
            ->select('listing_id')
            ->where('user_id', $userId)
            ->get()->getResultArray();

        $ids = array_column($seenIds, 'listing_id');

        if (! empty($ids)) {
            $model->whereNotIn('listings.id', $ids);
        }

        // Also exclude saves and RSVPs even if not in activity_log yet
        $savedIds = db_connect()
            ->table('listing_saves')
            ->select('listing_id')
            ->where('user_id', $userId)
            ->get()->getResultArray();

        $rsvpIds = db_connect()
            ->table('listing_rsvps')
            ->select('listing_id')
            ->where('user_id', $userId)
            ->get()->getResultArray();

        $excludeIds = array_unique(array_merge(
            array_column($savedIds, 'listing_id'),
            array_column($rsvpIds,  'listing_id'),
        ));

        if (! empty($excludeIds)) {
            $model->whereNotIn('listings.id', $excludeIds);
        }
    }
}
