<?php

namespace App\Controllers\Admin;

class ListingsController extends BaseAdminController
{
    private const PER_PAGE = 25;

    public function index()
    {
        $db         = db_connect();
        $search     = trim($this->request->getGet('q') ?? '');
        $category   = $this->request->getGet('category') ?? '';
        $trustLevel = $this->request->getGet('trust') ?? '';
        $status     = $this->request->getGet('status') ?? '';
        $page       = max(1, (int) ($this->request->getGet('page') ?? 1));
        $offset     = ($page - 1) * self::PER_PAGE;

        $query = $db->table('listings l')
            ->select('l.id, l.title, l.trust_level, l.trust_label, l.status, l.date, l.created_at,
                      c.name AS category_name, o.name AS org_name, u.name AS created_by_name')
            ->join('categories c', 'c.id = l.category_id', 'left')
            ->join('organisations o', 'o.id = l.org_id', 'left')
            ->join('users u', 'u.id = l.created_by', 'left');

        $countQuery = $db->table('listings l')
            ->join('categories c', 'c.id = l.category_id', 'left')
            ->join('organisations o', 'o.id = l.org_id', 'left');

        if ($search !== '') {
            $query->groupStart()->like('l.title', $search)->orLike('o.name', $search)->groupEnd();
            $countQuery->groupStart()->like('l.title', $search)->orLike('o.name', $search)->groupEnd();
        }
        if ($category !== '') { $query->where('c.slug', $category); $countQuery->where('c.slug', $category); }
        if ($trustLevel !== '') { $query->where('l.trust_level', $trustLevel); $countQuery->where('l.trust_level', $trustLevel); }
        if ($status !== '') { $query->where('l.status', $status); $countQuery->where('l.status', $status); }

        $total     = $countQuery->countAllResults();
        $listings  = $query->orderBy('l.created_at', 'DESC')->limit(self::PER_PAGE, $offset)->get()->getResultArray();
        $categories = $db->table('categories')->where('is_active', 1)->orderBy('name')->get()->getResultArray();
        $lastPage  = (int) ceil($total / self::PER_PAGE);

        return $this->renderView('admin/listings/index', compact(
            'listings', 'total', 'page', 'lastPage', 'search',
            'categories', 'category', 'trustLevel', 'status'
        ));
    }

    public function show($id)
    {
        $db      = db_connect();
        $listing = $db->table('listings l')
            ->select('l.*, c.name AS category_name, o.name AS org_name, u.name AS created_by_name')
            ->join('categories c', 'c.id = l.category_id', 'left')
            ->join('organisations o', 'o.id = l.org_id', 'left')
            ->join('users u', 'u.id = l.created_by', 'left')
            ->where('l.id', (int) $id)
            ->get()->getRowArray();

        if (! $listing) {
            return redirect()->to('/manager/listings')->with('error', 'Listing not found.');
        }

        $saveCount = $db->table('listing_saves')->where('listing_id', $id)->countAllResults();
        $rsvpCount = $db->table('listing_rsvps')->where('listing_id', $id)->countAllResults();

        return $this->renderView('admin/listings/show', compact('listing', 'saveCount', 'rsvpCount'));
    }

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

    public function toggleStatus($id)
    {
        $db      = db_connect();
        $listing = $db->table('listings')->select('id, title, status')->where('id', (int) $id)->get()->getRowArray();

        if (! $listing) {
            return $this->jsonResponse(['error' => 'Listing not found.'], 404);
        }

        $newStatus = $listing['status'] === 'active' ? 'inactive' : 'active';
        $db->table('listings')->where('id', (int) $id)->update(['status' => $newStatus]);
        $this->audit("listing_{$newStatus}", 'listing', (int) $id, $listing['title']);

        return $this->jsonResponse(['status' => $newStatus]);
    }

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
