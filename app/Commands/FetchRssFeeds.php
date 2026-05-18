<?php

namespace App\Commands;

use App\Models\RssFeedModel;
use App\Services\RssFeedService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Fetches all active RSS feeds and stores new articles.
 *
 * Usage:
 *   php spark feeds:fetch
 *
 * Recommended cron schedule: every 15–30 minutes
 *   * /15 * * * *  cd /var/www/html && php spark feeds:fetch >> /var/log/rss-fetch.log 2>&1
 */
class FetchRssFeeds extends BaseCommand
{
    protected $group       = 'Dimensions';
    protected $name        = 'feeds:fetch';
    protected $description = 'Fetch all active RSS feeds and store new articles.';

    public function run(array $params): void
    {
        $feedModel = new RssFeedModel();
        $service   = new RssFeedService();

        $feeds = $feedModel->getActiveFeeds();

        if (empty($feeds)) {
            CLI::write('No active RSS feeds configured.', 'yellow');
            return;
        }

        CLI::write('Starting RSS fetch for ' . count($feeds) . ' feed(s)...', 'green');
        CLI::newLine();

        $totalInserted = 0;
        $totalSkipped  = 0;
        $totalErrors   = 0;

        foreach ($feeds as $feed) {
            $label = "[#{$feed['id']}] {$feed['name']}";
            CLI::write("  Fetching {$label} ...", 'white');

            $result = $service->fetchAndStore((int) $feed['id']);

            if ($result['error'] !== null) {
                CLI::write("    ERROR: {$result['error']}", 'red');
                $totalErrors++;
                continue;
            }

            CLI::write(
                "    OK — inserted: {$result['inserted']}, skipped (already stored): {$result['skipped']}",
                'green'
            );

            $totalInserted += $result['inserted'];
            $totalSkipped  += $result['skipped'];
        }

        CLI::newLine();
        CLI::write("Done. Total inserted: {$totalInserted}, skipped: {$totalSkipped}, errors: {$totalErrors}.", 'green');
    }
}
