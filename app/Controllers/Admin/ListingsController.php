<?php

namespace App\Controllers\Admin;

use App\Libraries\S3Uploader;

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

        return $this->renderView('admin/listings/index', compact(
            'listings', 'total', 'page', 'lastPage', 'search',
            'categories', 'category', 'trustLevel', 'status'
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
}
