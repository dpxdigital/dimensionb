<?php

namespace App\Controllers\Api\Search;

use App\Controllers\Api\BaseApiController;
use App\Models\CategoryModel;
use App\Models\ListingModel;
use CodeIgniter\HTTP\ResponseInterface;

class SearchController extends BaseApiController
{
    private const PER_PAGE = 20;

    // ── GET /v1/search ────────────────────────────────────────────────────────

    public function index(): ResponseInterface
    {
        $userId   = $this->authUserId();
        $q        = trim($this->request->getGet('q') ?? '');
        $category = $this->request->getGet('category');
        $trust    = $this->request->getGet('trust');
        $date     = $this->request->getGet('date');   // today|week|month
        $lat      = $this->request->getGet('lat');
        $lng      = $this->request->getGet('lng');
        $radius   = (float) ($this->request->getGet('radius') ?? 25);
        $page     = max(1, (int) ($this->request->getGet('page') ?? 1));
        $offset   = ($page - 1) * self::PER_PAGE;

        $model = new ListingModel();
        $model->withMeta($userId)->active();

        // ── Text search (LIKE) ────────────────────────────────────────────────
        if ($q !== '') {
            $model->groupStart()
                  ->like('listings.title', $q)
                  ->orLike('listings.org_name', $q)
                  ->orLike('listings.description', $q)
                  ->groupEnd();
        }

        // ── Category filter ───────────────────────────────────────────────────
        // Use the alias 'c' already joined by withMeta()
        if ($category !== null) {
            $model->where('c.slug', $category);
        }

        // ── Trust level filter ────────────────────────────────────────────────
        if ($trust !== null) {
            $validTrust = ['institution_verified', 'curator_reviewed', 'community_submitted', 'approved_live_host', 'needs_reconfirmation'];
            if (in_array($trust, $validTrust, true)) {
                $model->where('listings.trust_level', $trust);
            }
        }

        // ── Date filter ───────────────────────────────────────────────────────
        match ($date) {
            'today' => $model->where('DATE(listings.date)', date('Y-m-d')),
            'week'  => $model->where('listings.date >=', date('Y-m-d 00:00:00'))
                             ->where('listings.date <=', date('Y-m-d 23:59:59', strtotime('+6 days'))),
            'month' => $model->where('YEAR(listings.date)', date('Y'), false)
                             ->where('MONTH(listings.date)', date('n'), false),
            default => null,
        };

        // ── Geo filter ────────────────────────────────────────────────────────
        if ($lat !== null && $lng !== null) {
            $model->nearby((float) $lat, (float) $lng, $radius);
        }

        // ── Ordering ──────────────────────────────────────────────────────────
        $model->orderBy('listings.created_at', 'DESC');

        // ── Count total (clone DB state before pagination) ────────────────────
        // CI4 doesn't have an easy countAll after complex joins, so we use a subquery trick
        $allRows   = $model->findAll(); // this is fine for search — results are bounded by filters
        $total     = count($allRows);
        $items     = array_slice($allRows, $offset, self::PER_PAGE);
        $lastPage  = (int) ceil($total / self::PER_PAGE);

        return $this->success(
            array_map([ListingModel::class, 'format'], $items),
            'OK',
            200,
            [
                'current_page' => $page,
                'per_page'     => self::PER_PAGE,
                'total'        => $total,
                'last_page'    => $lastPage,
            ]
        );
    }

    // ── GET /v1/search/filters ────────────────────────────────────────────────

    public function filters(): ResponseInterface
    {
        $categories = (new CategoryModel())->allOrdered();

        $trustLevels = [
            ['value' => 'institution_verified',  'label' => 'Institution Verified'],
            ['value' => 'curator_reviewed',       'label' => 'Curator Reviewed'],
            ['value' => 'community_submitted',    'label' => 'Community Submitted'],
            ['value' => 'approved_live_host',     'label' => 'Approved Live Host'],
            ['value' => 'needs_reconfirmation',   'label' => 'Needs Reconfirmation'],
        ];

        $dateOptions = [
            ['value' => 'today', 'label' => 'Today'],
            ['value' => 'week',  'label' => 'This Week'],
            ['value' => 'month', 'label' => 'This Month'],
            ['value' => null,    'label' => 'All'],
        ];

        return $this->success([
            'categories'   => $categories,
            'trust_levels' => $trustLevels,
            'date_options' => $dateOptions,
        ]);
    }

}
