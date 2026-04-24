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
        $tab     = $this->request->getGet('tab') ?? 'default';
        $lastId  = (int) ($this->request->getGet('last_id') ?? 0) ?: null;
        $lat     = $this->request->getGet('lat');
        $lng     = $this->request->getGet('lng');

        $model = new ListingModel();

        switch ($tab) {
            case 'following':
                $model->withMeta($userId)->active()->following($userId);
                break;

            case 'nearby':
                if ($lat === null || $lng === null) {
                    return $this->error('lat and lng are required for the nearby tab.', 422);
                }
                $model->withMeta($userId)->active()->nearby((float) $lat, (float) $lng);
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
