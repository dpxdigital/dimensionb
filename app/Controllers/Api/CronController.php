<?php

namespace App\Controllers\Api;

use App\Models\RssFeedModel;
use App\Services\ListingFeedService;
use App\Services\RssFeedService;
use CodeIgniter\Controller;

/**
 * Token-protected cron endpoints.
 *
 * Configure in cPanel → Cron Jobs:
 *   Every hour:  0 * * * *  curl -s "https://api.dimensions.global/v1/cron/rss-feeds?token=YOUR_RSS_CRON_TOKEN" > /dev/null 2>&1
 *   (The endpoint self-throttles: only fetches feeds not fetched in the last 20 hours.)
 *
 *   Listing feeds:  * /30 * * * *  curl -s "https://api.dimensions.global/v1/cron/listing-feeds?token=YOUR_TOKEN" > /dev/null 2>&1
 */
class CronController extends Controller
{
    private const RSS_INTERVAL_HOURS = 20;

    /**
     * Auto-fetch RSS feeds that haven't been fetched in the last 20 hours.
     * Safe to call frequently (e.g. every hour) — will no-op if nothing is due.
     *
     * GET /v1/cron/rss-feeds?token=YOUR_RSS_CRON_TOKEN
     */
    public function rssFeedsAutoFetch()
    {
        $token    = (string) ($this->request->getGet('token') ?? '');
        $expected = (string) env('RSS_CRON_TOKEN', '');

        if ($expected === '' || $token !== $expected) {
            return $this->response->setStatusCode(403)->setBody('Forbidden');
        }

        $db = db_connect();

        // Only fetch feeds that are active AND (never fetched OR last fetch > 20 hours ago)
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::RSS_INTERVAL_HOURS . ' hours'));
        $feeds  = $db->table('rss_feeds')
            ->where('is_active', 1)
            ->groupStart()
                ->where('last_fetched_at IS NULL')
                ->orWhere('last_fetched_at <', $cutoff)
            ->groupEnd()
            ->get()
            ->getResultArray();

        if (empty($feeds)) {
            return $this->response->setJSON([
                'status'  => 'ok',
                'message' => 'No feeds due for fetch (all fetched within ' . self::RSS_INTERVAL_HOURS . ' hours).',
                'fetched' => 0,
            ]);
        }

        $service      = new RssFeedService();
        $now          = date('Y-m-d H:i:s');
        $totalInserted = 0;
        $totalSkipped  = 0;
        $results       = [];

        foreach ($feeds as $feed) {
            $result = $service->fetchAndStore((int) $feed['id']);

            $db->table('rss_feeds')->where('id', (int) $feed['id'])->update([
                'last_fetched_at' => $now,
            ]);

            $results[] = [
                'id'       => (int) $feed['id'],
                'name'     => $feed['name'],
                'inserted' => $result['inserted'] ?? 0,
                'skipped'  => $result['skipped']  ?? 0,
                'error'    => $result['error']     ?? null,
            ];

            $totalInserted += (int) ($result['inserted'] ?? 0);
            $totalSkipped  += (int) ($result['skipped']  ?? 0);
        }

        return $this->response->setJSON([
            'status'          => 'ok',
            'feeds_processed' => count($feeds),
            'items_inserted'  => $totalInserted,
            'items_skipped'   => $totalSkipped,
            'next_run_after'  => date('Y-m-d H:i:s', strtotime('+' . self::RSS_INTERVAL_HOURS . ' hours', strtotime($now))),
            'results'         => $results,
        ]);
    }

    public function listingFeeds()
    {
        $token    = (string) ($this->request->getGet('token') ?? '');
        $expected = (string) env('LISTING_FEED_CRON_TOKEN', '');

        if ($expected === '' || $token !== $expected) {
            return $this->response->setStatusCode(403)->setBody('Forbidden');
        }

        $db      = db_connect();
        $feeds   = $db->table('listing_rss_feeds')->where('is_active', 1)->get()->getResultArray();
        $service = new ListingFeedService();
        $now     = date('Y-m-d H:i:s');
        $results = [];

        foreach ($feeds as $feed) {
            $result = $service->importFeed($feed);
            $count  = (int) ($result['count'] ?? 0);

            $db->table('listing_rss_feeds')->where('id', (int) $feed['id'])->update([
                'last_fetched_at' => $now,
                'item_count'      => ((int) $feed['item_count']) + $count,
            ]);

            $results[] = [
                'id'    => (int) $feed['id'],
                'name'  => $feed['name'],
                'count' => $count,
                'error' => $result['error'] ?? null,
            ];
        }

        return $this->response->setJSON([
            'status'          => 'success',
            'feeds_processed' => count($feeds),
            'items_imported'  => array_sum(array_column($results, 'count')),
            'results'         => $results,
        ]);
    }
}
