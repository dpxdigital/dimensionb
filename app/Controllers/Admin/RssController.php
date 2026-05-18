<?php

namespace App\Controllers\Admin;

use App\Models\RssFeedModel;
use App\Services\RssFeedService;

class RssController extends BaseAdminController
{
    // GET /manager/rss
    public function index()
    {
        $db    = db_connect();
        $feeds = $db->query("
            SELECT f.*, COUNT(a.id) AS article_count
            FROM rss_feeds f
            LEFT JOIN rss_articles a ON a.feed_id = f.id
            GROUP BY f.id
            ORDER BY f.id ASC
        ")->getResultArray();

        return $this->renderView('admin/rss/index', [
            'pageTitle' => 'RSS Feeds',
            'feeds'     => $feeds,
        ]);
    }

    // POST /manager/rss/store
    public function store()
    {
        $name = trim($this->request->getPost('name') ?? '');
        $url  = trim($this->request->getPost('url')  ?? '');

        if ($name === '' || $url === '') {
            session()->setFlashdata('error', 'Name and URL are required.');
            return redirect()->to(site_url('manager/rss'));
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            session()->setFlashdata('error', 'Please enter a valid URL.');
            return redirect()->to(site_url('manager/rss'));
        }

        $model = new RssFeedModel();
        $model->insert(['name' => $name, 'url' => $url, 'is_active' => 1]);

        $this->audit('rss_feed_added', 'rss_feeds', (int) $model->getInsertID(), "Added: $name");
        session()->setFlashdata('success', "Feed \"$name\" added.");
        return redirect()->to(site_url('manager/rss'));
    }

    // POST /manager/rss/(:num)/toggle
    public function toggle(int $id)
    {
        $model = new RssFeedModel();
        $feed  = $model->find($id);
        if (! $feed) {
            session()->setFlashdata('error', 'Feed not found.');
            return redirect()->to(site_url('manager/rss'));
        }

        $newVal = $feed['is_active'] ? 0 : 1;
        $model->update($id, ['is_active' => $newVal]);

        $label = $newVal ? 'activated' : 'deactivated';
        $this->audit('rss_feed_toggled', 'rss_feeds', $id, ucfirst($label) . ': ' . $feed['name']);
        session()->setFlashdata('success', "Feed \"{$feed['name']}\" {$label}.");
        return redirect()->to(site_url('manager/rss'));
    }

    // POST /manager/rss/(:num)/delete
    public function delete(int $id)
    {
        $model = new RssFeedModel();
        $feed  = $model->find($id);
        if (! $feed) {
            session()->setFlashdata('error', 'Feed not found.');
            return redirect()->to(site_url('manager/rss'));
        }

        $model->delete($id);
        $this->audit('rss_feed_deleted', 'rss_feeds', $id, 'Deleted: ' . $feed['name']);
        session()->setFlashdata('success', "Feed \"{$feed['name']}\" and its articles deleted.");
        return redirect()->to(site_url('manager/rss'));
    }

    // POST /manager/rss/fetch-all
    public function fetchAll()
    {
        $model   = new RssFeedModel();
        $service = new RssFeedService();
        $feeds   = $model->getActiveFeeds();

        $inserted = 0;
        $errors   = 0;
        foreach ($feeds as $feed) {
            $result = $service->fetchAndStore((int) $feed['id']);
            if ($result['error'] !== null) {
                $errors++;
            } else {
                $inserted += $result['inserted'];
            }
        }

        $msg = "Fetch complete: {$inserted} new article(s) stored across " . count($feeds) . " feed(s).";
        if ($errors > 0) $msg .= " {$errors} feed(s) had errors.";
        $this->audit('rss_fetch_all', 'rss_feeds', null, $msg);
        session()->setFlashdata('success', $msg);
        return redirect()->to(site_url('manager/rss'));
    }

    // POST /manager/rss/(:num)/fetch
    public function fetchNow(int $id)
    {
        $model = new RssFeedModel();
        $feed  = $model->find($id);
        if (! $feed) {
            session()->setFlashdata('error', 'Feed not found.');
            return redirect()->to(site_url('manager/rss'));
        }

        $service = new RssFeedService();
        $result  = $service->fetchAndStore($id);

        if ($result['error'] !== null) {
            session()->setFlashdata('error', 'Fetch failed: ' . $result['error']);
            return redirect()->to(site_url('manager/rss'));
        }

        $this->audit('rss_feed_fetched', 'rss_feeds', $id,
            "Fetched {$result['inserted']} new article(s) from {$feed['name']}");
        session()->setFlashdata('success',
            "Fetched \"{$feed['name']}\": {$result['inserted']} new, {$result['skipped']} already stored.");
        return redirect()->to(site_url('manager/rss'));
    }
}
