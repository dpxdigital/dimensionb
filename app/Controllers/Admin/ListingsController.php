<?php

namespace App\Controllers\Admin;

use App\Libraries\S3Uploader;
use App\Services\ListingFeedService;

class ListingsController extends BaseAdminController
{
    private const PER_PAGE = 25;

    // ── The 7 post types required by product spec ─────────────────────────────

    private const REQUIRED_CATEGORIES = [
        ['name' => 'Events',            'slug' => 'events',            'icon_name' => 'event_outlined',          'sort_order' => 20],
        ['name' => 'Grants',            'slug' => 'grants',            'icon_name' => 'card_giftcard_outlined',  'sort_order' => 21],
        ['name' => 'Youth Programs',    'slug' => 'youth-programs',    'icon_name' => 'child_care_outlined',     'sort_order' => 22],
        ['name' => 'Trainings',         'slug' => 'trainings',         'icon_name' => 'fitness_center_outlined', 'sort_order' => 23],
        ['name' => 'Community Actions', 'slug' => 'community-actions', 'icon_name' => 'handshake_outlined',      'sort_order' => 24],
        ['name' => 'Resources',         'slug' => 'resources',         'icon_name' => 'library_books_outlined',  'sort_order' => 25],
        ['name' => 'Scholarships',      'slug' => 'scholarships',      'icon_name' => 'school_outlined',         'sort_order' => 26],
    ];

    // ── Ensure the 7 required categories always exist ─────────────────────────

    private function ensureCategories(): void
    {
        $db = db_connect();
        foreach (self::REQUIRED_CATEGORIES as $cat) {
            $exists = $db->table('categories')->where('slug', $cat['slug'])->countAllResults();
            if (! $exists) {
                $db->table('categories')->insert($cat);
            }
        }
    }

    // ── GET /manager/listings ─────────────────────────────────────────────────

    public function index()
    {
        $this->ensureCategories();

        $db         = db_connect();
        $search     = trim($this->request->getGet('q') ?? '');
        $category   = $this->request->getGet('category') ?? '';
        $trustLevel = $this->request->getGet('trust') ?? '';
        $status     = $this->request->getGet('status') ?? '';
        $page       = max(1, (int) ($this->request->getGet('page') ?? 1));
        $offset     = ($page - 1) * self::PER_PAGE;

        $query = $db->table('listings l')
            ->select('l.id, l.title, l.trust_level, l.trust_label, l.status, l.is_active, l.date, l.created_at,
                      c.name AS category_name, o.name AS org_name, u.name AS created_by_name')
            ->join('categories c', 'c.id = l.category_id', 'left')
            ->join('organizations o', 'o.id = l.org_id', 'left')
            ->join('users u', 'u.id = l.submitted_by', 'left');

        $countQuery = $db->table('listings l')
            ->join('categories c', 'c.id = l.category_id', 'left')
            ->join('organizations o', 'o.id = l.org_id', 'left');

        if ($search !== '') {
            $query->groupStart()->like('l.title', $search)->orLike('o.name', $search)->groupEnd();
            $countQuery->groupStart()->like('l.title', $search)->orLike('o.name', $search)->groupEnd();
        }
        if ($category !== '') { $query->where('c.slug', $category); $countQuery->where('c.slug', $category); }
        if ($trustLevel !== '') { $query->where('l.trust_level', $trustLevel); $countQuery->where('l.trust_level', $trustLevel); }
        if ($status !== '') { $query->where('l.status', $status); $countQuery->where('l.status', $status); }

        $total      = $countQuery->countAllResults();
        $listings   = $query->orderBy('l.created_at', 'DESC')->limit(self::PER_PAGE, $offset)->get()->getResultArray();
        $categories = $db->table('categories')->orderBy('sort_order')->orderBy('name')->get()->getResultArray();
        $lastPage   = (int) ceil($total / self::PER_PAGE);

        $feeds    = $db->table('listing_rss_feeds lf')
            ->select('lf.*, c.name AS category_name')
            ->join('categories c', 'c.id = lf.category_id', 'left')
            ->orderBy('lf.created_at', 'DESC')
            ->get()->getResultArray();
        $cronToken = env('LISTING_FEED_CRON_TOKEN', '');

        return $this->renderView('admin/listings/index', compact(
            'listings', 'total', 'page', 'lastPage', 'search',
            'categories', 'category', 'trustLevel', 'status',
            'feeds', 'cronToken'
        ));
    }

    // ── GET /manager/listings/create ──────────────────────────────────────────

    public function create()
    {
        $this->ensureCategories();
        $db         = db_connect();
        $categories = $db->table('categories')->orderBy('sort_order')->orderBy('name')->get()->getResultArray();

        return $this->renderView('admin/listings/create', [
            'categories' => $categories,
            'errors'     => session()->getFlashdata('errors') ?? [],
            'old'        => session()->getFlashdata('old') ?? [],
        ]);
    }

    // ── POST /manager/listings/create ─────────────────────────────────────────

    public function store()
    {
        $rules = [
            'title'       => 'required|max_length[255]',
            'category_id' => 'required|is_natural_no_zero',
            'description' => 'required',
            'action_type' => 'required|in_list[rsvp,save,apply,external]',
        ];

        if (! $this->validate($rules)) {
            session()->setFlashdata('errors', $this->validator->getErrors());
            session()->setFlashdata('old', $this->request->getPost());
            return redirect()->back();
        }

        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $coverUrl = null;
        $file     = $this->request->getFile('cover_image');
        if ($file && $file->isValid() && ! $file->hasMoved()) {
            $ext      = strtolower($file->getExtension());
            $allowed  = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            if (in_array($ext, $allowed, true) && $file->getSize() <= 5 * 1024 * 1024) {
                $filename = 'listing_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $tmpPath  = WRITEPATH . 'uploads/' . $filename;
                $mimeType = $file->getMimeType() ?? 'image/jpeg';
                if (! is_dir(WRITEPATH . 'uploads/')) {
                    mkdir(WRITEPATH . 'uploads/', 0755, true);
                }
                $file->move(WRITEPATH . 'uploads/', $filename);
                try {
                    $s3       = new S3Uploader();
                    $coverUrl = $s3->uploadOrLocal($tmpPath, "uploads/listings/{$filename}", $mimeType, 'listings');
                } catch (\Throwable $e) {
                    log_message('error', '[store] cover upload failed: ' . $e->getMessage());
                    $coverUrl = null;
                }
            }
        }

        $p        = $this->request->getPost();
        $date     = ! empty($p['date'])     ? $p['date']     : null;
        $deadline = ! empty($p['deadline']) ? $p['deadline'] : null;

        $db->table('listings')->insert([
            'title'        => trim($p['title']),
            'description'  => trim($p['description']),
            'category_id'  => (int) $p['category_id'],
            'org_name'     => trim($p['org_name'] ?? ''),
            'location'     => trim($p['location'] ?? ''),
            'date'         => $date,
            'deadline'     => $deadline,
            'action_type'  => $p['action_type'],
            'external_url' => trim($p['external_url'] ?? ''),
            'trust_level'  => $p['trust_level'] ?? 'institution_verified',
            'trust_label'  => trim($p['trust_label'] ?? ''),
            'cover_url'    => $coverUrl,
            'status'       => 'approved',
            'is_active'    => 1,
            'submitted_by' => null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $id = (int) $db->insertID();
        $this->audit('listing_created', 'listing', $id, trim($p['title']));

        return redirect()->to("/manager/listings/{$id}")->with('success', 'Listing created and published.');
    }

    // ── POST /manager/listings/import-rss ────────────────────────────────────

    public function importRss()
    {
        $url        = trim($this->request->getPost('rss_url') ?? '');
        $categoryId = (int) $this->request->getPost('category_id');
        $trustLevel = $this->request->getPost('trust_level') ?? 'community_submitted';
        $status     = $this->request->getPost('import_status') ?? 'pending';

        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return redirect()->to('/manager/listings')->with('error', 'Invalid RSS URL.');
        }
        if (! $categoryId) {
            return redirect()->to('/manager/listings')->with('error', 'Please select a category.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Dimensions-Bot/1.0 (+https://dimensions.app)',
        ]);
        $xml     = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($xml === false || $curlErr || $httpCode < 200 || $httpCode >= 300) {
            return redirect()->to('/manager/listings')
                ->with('error', 'Could not fetch RSS (HTTP ' . $httpCode . '): ' . $curlErr);
        }

        // Strip BOM and leading whitespace before XML declaration
        $xml = ltrim($xml, "\xEF\xBB\xBF \t\n\r");

        libxml_use_internal_errors(true);
        $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();

        if ($sx === false) {
            return redirect()->to('/manager/listings')->with('error', 'Invalid RSS/XML format — not a valid feed.');
        }

        $db       = db_connect();
        $now      = date('Y-m-d H:i:s');
        $count    = 0;
        $rootName = strtolower($sx->getName());
        $isAtom   = ($rootName === 'feed');
        $channel  = ! $isAtom ? ($sx->channel ?? null) : null;
        $items    = $isAtom
            ? ($sx->entry ?? [])
            : ($channel !== null ? ($channel->item ?? []) : ($sx->item ?? []));
        $isActive = ($status === 'approved') ? 1 : 0;
        $label    = ucwords(str_replace('_', ' ', $trustLevel));

        foreach ($items as $item) {
            $title = mb_substr(trim((string) ($item->title ?? '')), 0, 255);
            if ($title === '') continue;

            if ($isAtom) {
                $link = '';
                foreach ($item->link as $l) {
                    $rel = (string) ($l['rel'] ?? 'alternate');
                    if ($rel === 'alternate' || $rel === '') { $link = (string) $l['href']; break; }
                    if ($link === '') $link = (string) $l['href'];
                }
                $rawDesc = (string) ($item->summary ?? $item->content ?? '');
            } else {
                $link    = trim((string) ($item->link ?? ''));
                $rawDesc = (string) ($item->description ?? '');
            }

            $desc = mb_substr(trim(strip_tags($rawDesc)), 0, 500);
            if ($desc === '') $desc = $title; // description is NOT NULL

            // Skip duplicate external URLs
            if ($link !== '' && $db->table('listings')->where('external_url', $link)->countAllResults()) {
                continue;
            }

            // Image extraction: media:thumbnail → enclosure → first <img>
            $imageUrl = null;
            $media    = $item->children('media', true);
            if (! isset($media->thumbnail) && ! isset($media->content)) {
                $media = $item->children('http://search.yahoo.com/mrss/');
            }
            if (isset($media->thumbnail)) {
                $imageUrl = (string) $media->thumbnail['url'];
            } elseif (isset($media->content)) {
                $mType = (string) $media->content['type'];
                if (! str_starts_with($mType, 'video/')) {
                    $imageUrl = (string) $media->content['url'];
                }
            }
            if ($imageUrl === null && isset($item->enclosure)) {
                if (str_starts_with((string) $item->enclosure['type'], 'image/')) {
                    $imageUrl = (string) $item->enclosure['url'];
                }
            }
            if ($imageUrl === null) {
                if (preg_match('/<img[^>]+src=["\']([^"\']+)["\'][^>]*/is', $rawDesc, $m)) {
                    $imageUrl = $m[1];
                }
            }
            if ($imageUrl !== null && ! filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                $imageUrl = null;
            }

            $db->table('listings')->insert([
                'title'        => $title,
                'description'  => $desc,
                'category_id'  => $categoryId,
                'org_name'     => '',
                'location'     => '',
                'date'         => null,
                'deadline'     => null,
                'action_type'  => 'external',
                'external_url' => $link,
                'trust_level'  => $trustLevel,
                'trust_label'  => $label,
                'cover_url'    => $imageUrl,
                'status'       => $status,
                'is_active'    => $isActive,
                'submitted_by' => null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
            $count++;
        }

        $this->audit('listings_rss_import', 'system', 0, "Imported {$count} from {$url}");

        return redirect()->to('/manager/listings')
            ->with('success', "Imported {$count} listing(s) from RSS feed.");
    }

    // ── POST /manager/listings/feeds/store ───────────────────────────────────

    public function storeFeed()
    {
        $name       = trim($this->request->getPost('name') ?? '');
        $url        = trim($this->request->getPost('url')  ?? '');
        $categoryId = (int) $this->request->getPost('category_id');
        $trustLevel = $this->request->getPost('trust_level')   ?? 'community_submitted';
        $status     = $this->request->getPost('import_status') ?? 'pending';

        if ($name === '' || ! filter_var($url, FILTER_VALIDATE_URL) || ! $categoryId) {
            return redirect()->to('/manager/listings')->with('error', 'Name, valid URL and category are required.');
        }

        db_connect()->table('listing_rss_feeds')->insert([
            'name'          => mb_substr($name, 0, 200),
            'url'           => $url,
            'category_id'   => $categoryId,
            'trust_level'   => $trustLevel,
            'import_status' => $status,
            'is_active'     => 1,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        $this->audit('listing_feed_added', 'system', 0, $name);
        return redirect()->to('/manager/listings')->with('success', "Feed \"{$name}\" added.");
    }

    // ── POST /manager/listings/feeds/:id/delete ───────────────────────────────

    public function deleteFeed(int $id)
    {
        $db   = db_connect();
        $feed = $db->table('listing_rss_feeds')->where('id', $id)->get()->getRowArray();
        if (! $feed) return redirect()->to('/manager/listings')->with('error', 'Feed not found.');

        $db->table('listing_rss_feeds')->where('id', $id)->delete();
        $this->audit('listing_feed_deleted', 'system', 0, $feed['name']);
        return redirect()->to('/manager/listings')->with('success', "Feed \"{$feed['name']}\" deleted.");
    }

    // ── POST /manager/listings/feeds/:id/toggle ───────────────────────────────

    public function toggleFeed(int $id)
    {
        $db   = db_connect();
        $feed = $db->table('listing_rss_feeds')->where('id', $id)->get()->getRowArray();
        if (! $feed) return $this->jsonResponse(['success' => false, 'error' => 'Not found'], 404);

        $newVal = $feed['is_active'] ? 0 : 1;
        $db->table('listing_rss_feeds')->where('id', $id)->update(['is_active' => $newVal]);
        return $this->jsonResponse(['success' => true, 'is_active' => $newVal]);
    }

    // ── POST /manager/listings/feeds/:id/fetch ────────────────────────────────

    public function fetchFeed(int $id)
    {
        $db   = db_connect();
        $feed = $db->table('listing_rss_feeds')->where('id', $id)->get()->getRowArray();
        if (! $feed) return redirect()->to('/manager/listings')->with('error', 'Feed not found.');

        $result = (new ListingFeedService())->importFeed($feed);
        $count  = (int) ($result['count'] ?? 0);
        $now    = date('Y-m-d H:i:s');

        $db->table('listing_rss_feeds')->where('id', $id)->update([
            'last_fetched_at' => $now,
            'item_count'      => ((int) $feed['item_count']) + $count,
        ]);

        $this->audit('listing_feed_fetched', 'system', $id, "Imported {$count} from {$feed['name']}");

        if ($result['error']) {
            return redirect()->to('/manager/listings')->with('error', "Fetch error: {$result['error']}");
        }
        return redirect()->to('/manager/listings')->with('success', "Fetched {$count} new listing(s) from \"{$feed['name']}\".");
    }

    // ── POST /manager/listings/feeds/fetch-all ────────────────────────────────

    public function fetchAllFeeds()
    {
        $db      = db_connect();
        $feeds   = $db->table('listing_rss_feeds')->where('is_active', 1)->get()->getResultArray();
        $service = new ListingFeedService();
        $total   = 0;
        $now     = date('Y-m-d H:i:s');

        foreach ($feeds as $feed) {
            $result = $service->importFeed($feed);
            $count  = (int) ($result['count'] ?? 0);
            $total += $count;
            $db->table('listing_rss_feeds')->where('id', (int) $feed['id'])->update([
                'last_fetched_at' => $now,
                'item_count'      => ((int) $feed['item_count']) + $count,
            ]);
        }

        $this->audit('listing_feeds_fetch_all', 'system', 0, "Imported {$total} from " . count($feeds) . " feeds");
        return redirect()->to('/manager/listings')
            ->with('success', "Fetched all feeds — {$total} new listing(s) imported.");
    }

    // ── GET /manager/listings/:id ─────────────────────────────────────────────

    public function show($id)
    {
        $db      = db_connect();
        $listing = $db->table('listings l')
            ->select('l.*, c.name AS category_name, o.name AS org_name, u.name AS created_by_name')
            ->join('categories c', 'c.id = l.category_id', 'left')
            ->join('organizations o', 'o.id = l.org_id', 'left')
            ->join('users u', 'u.id = l.submitted_by', 'left')
            ->where('l.id', (int) $id)
            ->get()->getRowArray();

        if (! $listing) {
            return redirect()->to('/manager/listings')->with('error', 'Listing not found.');
        }

        $saveCount = $db->table('listing_saves')->where('listing_id', $id)->countAllResults();
        $rsvpCount = $db->table('listing_rsvps')->where('listing_id', $id)->countAllResults();

        return $this->renderView('admin/listings/show', compact('listing', 'saveCount', 'rsvpCount'));
    }

    // ── GET /manager/listings/:id/edit ────────────────────────────────────────

    public function edit($id)
    {
        $this->ensureCategories();
        $db      = db_connect();
        $listing = $db->table('listings')->where('id', (int) $id)->get()->getRowArray();

        if (! $listing) {
            return redirect()->to('/manager/listings')->with('error', 'Listing not found.');
        }

        $categories = $db->table('categories')->orderBy('sort_order')->orderBy('name')->get()->getResultArray();

        return $this->renderView('admin/listings/edit', [
            'listing'    => $listing,
            'categories' => $categories,
            'errors'     => session()->getFlashdata('errors') ?? [],
        ]);
    }

    // ── POST /manager/listings/:id/edit ───────────────────────────────────────

    public function update($id)
    {
        $db      = db_connect();
        $listing = $db->table('listings')->where('id', (int) $id)->get()->getRowArray();

        if (! $listing) {
            return redirect()->to('/manager/listings')->with('error', 'Listing not found.');
        }

        $rules = [
            'title'       => 'required|max_length[255]',
            'category_id' => 'required|is_natural_no_zero',
            'description' => 'required',
            'action_type' => 'required|in_list[rsvp,save,apply,external]',
        ];

        if (! $this->validate($rules)) {
            session()->setFlashdata('errors', $this->validator->getErrors());
            return redirect()->back();
        }

        $coverUrl = $listing['cover_url'];
        $file     = $this->request->getFile('cover_image');
        if ($file && $file->isValid() && ! $file->hasMoved()) {
            $ext     = strtolower($file->getExtension());
            $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            if (in_array($ext, $allowed, true) && $file->getSize() <= 5 * 1024 * 1024) {
                $filename = 'listing_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $tmpPath  = WRITEPATH . 'uploads/' . $filename;
                $mimeType = $file->getMimeType() ?? 'image/jpeg';
                if (! is_dir(WRITEPATH . 'uploads/')) {
                    mkdir(WRITEPATH . 'uploads/', 0755, true);
                }
                $file->move(WRITEPATH . 'uploads/', $filename);
                try {
                    $s3       = new S3Uploader();
                    $coverUrl = $s3->uploadOrLocal($tmpPath, "uploads/listings/{$filename}", $mimeType, 'listings');
                } catch (\Throwable $e) {
                    log_message('error', '[update] cover upload failed: ' . $e->getMessage());
                }
            }
        }

        $p        = $this->request->getPost();
        $date     = ! empty($p['date'])     ? $p['date']     : null;
        $deadline = ! empty($p['deadline']) ? $p['deadline'] : null;

        $db->table('listings')->where('id', (int) $id)->update([
            'title'        => trim($p['title']),
            'description'  => trim($p['description']),
            'category_id'  => (int) $p['category_id'],
            'org_name'     => trim($p['org_name'] ?? ''),
            'location'     => trim($p['location'] ?? ''),
            'date'         => $date,
            'deadline'     => $deadline,
            'action_type'  => $p['action_type'],
            'external_url' => trim($p['external_url'] ?? ''),
            'trust_level'  => $p['trust_level'] ?? 'institution_verified',
            'trust_label'  => trim($p['trust_label'] ?? ''),
            'cover_url'    => $coverUrl,
            'status'       => $p['status'] ?? 'approved',
            'is_active'    => isset($p['is_active']) ? 1 : 0,
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $this->audit('listing_updated', 'listing', (int) $id, trim($p['title']));

        return redirect()->to("/manager/listings/{$id}")->with('success', 'Listing updated.');
    }

    // ── POST /manager/listings/:id/trust ─────────────────────────────────────

    public function updateTrust($id)
    {
        $trustLevel = $this->request->getPost('trust_level');
        $trustLabel = trim($this->request->getPost('trust_label') ?? '');

        $validLevels = ['institution_verified', 'curator_reviewed', 'community_submitted', 'approved_live_host', 'needs_reconfirmation'];
        if (! in_array($trustLevel, $validLevels, true)) {
            return $this->jsonResponse(['error' => 'Invalid trust level.'], 422);
        }

        db_connect()->table('listings')->where('id', (int) $id)->update([
            'trust_level' => $trustLevel,
            'trust_label' => $trustLabel ?: null,
        ]);

        $this->audit('listing_trust_updated', 'listing', (int) $id, "Trust: {$trustLevel}");

        return $this->jsonResponse(['success' => true, 'trust_level' => $trustLevel, 'trust_label' => $trustLabel]);
    }

    // ── POST /manager/listings/:id/toggle-status ─────────────────────────────

    public function toggleStatus($id)
    {
        $db      = db_connect();
        $listing = $db->table('listings')->select('id, title, status')->where('id', (int) $id)->get()->getRowArray();

        if (! $listing) {
            return $this->jsonResponse(['error' => 'Listing not found.'], 404);
        }

        $newStatus = $listing['status'] === 'approved' ? 'rejected' : 'approved';
        $db->table('listings')->where('id', (int) $id)->update(['status' => $newStatus]);
        $this->audit("listing_{$newStatus}", 'listing', (int) $id, $listing['title']);

        return $this->jsonResponse(['status' => $newStatus, 'success' => true]);
    }

    // ── POST /manager/listings/:id/delete ────────────────────────────────────

    public function delete($id)
    {
        $listing = db_connect()->table('listings')->select('id, title')->where('id', (int) $id)->get()->getRowArray();

        if (! $listing) {
            return redirect()->to('/manager/listings')->with('error', 'Listing not found.');
        }

        db_connect()->table('listings')->where('id', (int) $id)->delete();
        $this->audit('listing_deleted', 'listing', (int) $id, $listing['title']);

        return redirect()->to('/manager/listings')->with('success', "Listing deleted.");
    }

    // ── POST /manager/listings/bulk-delete ────────────────────────────────────

    public function bulkDelete()
    {
        $raw = trim($this->request->getPost('ids') ?? '');
        if ($raw === '') {
            return redirect()->to('/manager/listings')->with('error', 'No listings selected.');
        }

        $ids = array_filter(array_map('intval', explode(',', $raw)));
        if (empty($ids)) {
            return redirect()->to('/manager/listings')->with('error', 'Invalid selection.');
        }

        $db      = db_connect();
        $deleted = 0;
        foreach ($ids as $id) {
            $listing = $db->table('listings')->select('id, title')->where('id', $id)->get()->getRowArray();
            if (! $listing) continue;
            $db->table('listings')->where('id', $id)->delete();
            $this->audit('listing_deleted', 'listing', $id, $listing['title']);
            $deleted++;
        }

        return redirect()->to('/manager/listings')->with('success', "{$deleted} listing(s) deleted.");
    }
}
