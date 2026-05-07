<?php

namespace App\Controllers\Api\Feed;

use App\Controllers\Api\BaseApiController;
use App\Models\ListingModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

class FeedController extends BaseApiController
{
    private const PER_PAGE = 20;

    public function index(): ResponseInterface
    {
        $userId  = $this->authUserId();
        $tab    = $this->request->getGet('tab') ?? 'default';
        $lastId = (int) ($this->request->getGet('last_id') ?? 0) ?: null;
        $lat    = $this->request->getGet('lat');
        $lng    = $this->request->getGet('lng');

        // Accept 'near_me' as an alias for 'nearby'
        if ($tab === 'near_me') $tab = 'nearby';

        $model = new ListingModel();

        switch ($tab) {
            case 'following':
                $model->withMeta($userId)->active()->following($userId);
                break;

            case 'nearby':
                if ($lat !== null && $lng !== null) {
                    // Sort by proximity when location is available
                    $model->withMeta($userId)->active()->nearby((float) $lat, (float) $lng);
                } else {
                    // No location provided — show all active listings (newest first)
                    $model->withMeta($userId)->active();
                }
                break;

            default: // personalised
                $interests = $this->getUserInterestSlugs($userId);
                $model->withMeta($userId)->active()->personalised($interests);
                break;
        }

        $rows = $model->cursor($lastId, self::PER_PAGE);

        $hasMore   = count($rows) > self::PER_PAGE;
        $items     = array_slice($rows, 0, self::PER_PAGE);
        $nextCursor = $hasMore ? end($items)['id'] : null;

        return $this->success(
            array_map([ListingModel::class, 'format'], $items),
            'OK',
            200,
            [
                'per_page'    => self::PER_PAGE,
                'has_more'    => $hasMore,
                'next_cursor' => $nextCursor,
            ]
        );
    }

    private function getUserInterestSlugs(int $userId): array
    {
        // Fetch the user's interests and map to category slugs
        $rows = db_connect()
            ->table('user_interests ui')
            ->select('c.slug')
            ->join('categories c', 'LOWER(c.name) = LOWER(ui.interest)', 'inner')
            ->where('ui.user_id', $userId)
            ->get()
            ->getResultArray();

        return array_column($rows, 'slug');
    }
}
