<?php

namespace App\Controllers\Api\Rss;

use App\Controllers\Api\BaseApiController;
use App\Models\RssArticleModel;
use App\Models\RssFeedModel;
use App\Services\RssFeedService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * RSS Feed Aggregator — public + admin endpoints.
 *
 * Public (no auth required):
 *   GET  /v1/rss/articles          — paginated article list (optional ?feed_id filter)
 *   GET  /v1/rss/feeds             — list active feeds
 *
 * Admin (JWT + is_admin = 1 on users table):
 *   POST   /v1/rss/admin/feeds             — add a new feed
 *   PUT    /v1/rss/admin/feeds/{id}        — update feed
 *   DELETE /v1/rss/admin/feeds/{id}        — delete feed + articles
 *   POST   /v1/rss/admin/feeds/{id}/fetch  — trigger immediate fetch
 */
class RssController extends BaseApiController
{
    // ── Public endpoints ──────────────────────────────────────────────────────

    /**
     * GET /v1/rss/articles
     *
     * Query params:
     *   page     (default 1)
     *   per_page (default 20, max 100)
     *   feed_id  (optional integer filter)
     */
    public function articles(): ResponseInterface
    {
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $feedId  = $this->request->getGet('feed_id');
        $feedId  = ($feedId !== null && $feedId !== '') ? (int) $feedId : null;

        $model  = new RssArticleModel();
        $result = $model->getPaginated($page, $perPage, $feedId);

        $total    = $result['total'];
        $lastPage = (int) ceil($total / $perPage);
        $lastPage = max(1, $lastPage);

        $data = array_map([RssArticleModel::class, 'formatRow'], $result['items']);

        return $this->success($data, 'OK', 200, [
            'current_page' => $page,
            'per_page'     => $perPage,
            'total'        => $total,
            'last_page'    => $lastPage,
        ]);
    }

    /**
     * GET /v1/rss/feeds
     *
     * Lists all active RSS feed sources (id, name, last_fetched_at).
     */
    public function feeds(): ResponseInterface
    {
        $model = new RssFeedModel();
        $feeds = $model->getActiveFeeds();

        $data = array_map([RssFeedModel::class, 'formatRow'], $feeds);

        return $this->success($data);
    }

    // ── Admin endpoints ───────────────────────────────────────────────────────

    /**
     * POST /v1/rss/admin/feeds
     *
     * Body: { "name": "...", "url": "..." }
     * Requires: JWT auth + is_admin = 1
     */
    public function addFeed(): ResponseInterface
    {
        $adminCheck = $this->requireAdmin();
        if ($adminCheck !== null) return $adminCheck;

        $input = $this->inputJson();
        $name  = trim((string) ($input['name'] ?? ''));
        $url   = trim((string) ($input['url']  ?? ''));

        if ($name === '') return $this->error('name is required.', 422);
        if ($url  === '') return $this->error('url is required.',  422);
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->error('url must be a valid URL.', 422);
        }

        $model  = new RssFeedModel();
        $feedId = $model->insert(['name' => $name, 'url' => $url, 'is_active' => 1]);

        $feed = $model->find($feedId);

        return $this->success($feed, 'Feed added.', 201);
    }

    /**
     * PUT /v1/rss/admin/feeds/{id}
     *
     * Body: { "name"?: "...", "url"?: "...", "is_active"?: 0|1 }
     * Requires: JWT auth + is_admin = 1
     */
    public function updateFeed(int $id): ResponseInterface
    {
        $adminCheck = $this->requireAdmin();
        if ($adminCheck !== null) return $adminCheck;

        $model = new RssFeedModel();
        $feed  = $model->find($id);
        if (! $feed) {
            return $this->error('Feed not found.', 404);
        }

        $input = $this->inputJson();
        $data  = [];

        if (isset($input['name'])) {
            $name = trim((string) $input['name']);
            if ($name === '') return $this->error('name cannot be empty.', 422);
            $data['name'] = $name;
        }

        if (isset($input['url'])) {
            $url = trim((string) $input['url']);
            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                return $this->error('url must be a valid URL.', 422);
            }
            $data['url'] = $url;
        }

        if (isset($input['is_active'])) {
            $data['is_active'] = (int) (bool) $input['is_active'];
        }

        if (empty($data)) {
            return $this->error('No fields to update.', 422);
        }

        $model->update($id, $data);

        return $this->success($model->find($id), 'Feed updated.');
    }

    /**
     * DELETE /v1/rss/admin/feeds/{id}
     *
     * Deletes the feed and all its articles (cascades via FK).
     * Requires: JWT auth + is_admin = 1
     */
    public function deleteFeed(int $id): ResponseInterface
    {
        $adminCheck = $this->requireAdmin();
        if ($adminCheck !== null) return $adminCheck;

        $model = new RssFeedModel();
        $feed  = $model->find($id);
        if (! $feed) {
            return $this->error('Feed not found.', 404);
        }

        $model->delete($id);

        return $this->success(null, 'Feed and its articles deleted.');
    }

    /**
     * POST /v1/rss/admin/feeds/{id}/fetch
     *
     * Triggers an immediate fetch for a single feed.
     * Requires: JWT auth + is_admin = 1
     */
    public function fetchFeed(int $id): ResponseInterface
    {
        $adminCheck = $this->requireAdmin();
        if ($adminCheck !== null) return $adminCheck;

        $feedModel = new RssFeedModel();
        $feed      = $feedModel->find($id);
        if (! $feed) {
            return $this->error('Feed not found.', 404);
        }

        $service = new RssFeedService();
        $result  = $service->fetchAndStore($id);

        if ($result['error'] !== null) {
            return $this->error('Fetch failed: ' . $result['error'], 500);
        }

        return $this->success([
            'inserted' => $result['inserted'],
            'skipped'  => $result['skipped'],
        ], "Fetch complete. {$result['inserted']} new article(s) stored.");
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Checks that the authenticated user has is_admin = 1 on the users table.
     * Returns a 403 response if not, or null if the check passes.
     */
    private function requireAdmin(): ?ResponseInterface
    {
        $userId = $this->authUserId();
        if ($userId <= 0) {
            return $this->error('Unauthenticated.', 401);
        }

        $user = db_connect()
            ->table('users')
            ->select('is_admin')
            ->where('id', $userId)
            ->get()
            ->getRowArray();

        if (! $user || empty($user['is_admin'])) {
            return $this->error('Admin access required.', 403);
        }

        return null;
    }
}
