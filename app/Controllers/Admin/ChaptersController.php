<?php

namespace App\Controllers\Admin;

use App\Libraries\S3Uploader;

class ChaptersController extends BaseAdminController
{
    private const PER_PAGE = 25;

    public function index()
    {
        $db     = db_connect();
        $q      = trim($this->request->getGet('q') ?? '');
        $page   = max(1, (int) ($this->request->getGet('page') ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $query = $db->table('chapters c')
            ->select('c.*, u.name AS creator_name')
            ->join('users u', 'u.id = c.created_by', 'left');

        if ($q !== '') {
            $query->groupStart()->like('c.name', $q)->orLike('c.city', $q)->orLike('c.category', $q)->groupEnd();
        }

        $countQ = $db->table('chapters');
        if ($q !== '') {
            $countQ->groupStart()->like('name', $q)->orLike('city', $q)->orLike('category', $q)->groupEnd();
        }
        $total = $countQ->countAllResults();

        $chapters = $query->orderBy('c.created_at', 'DESC')->limit(self::PER_PAGE, $offset)->get()->getResultArray();
        $lastPage = (int) ceil($total / self::PER_PAGE);

        return $this->renderView('admin/chapters/index', compact('chapters', 'total', 'page', 'lastPage', 'q'));
    }

    public function show($id)
    {
        $db      = db_connect();
        $chapter = $db->table('chapters c')
            ->select('c.*, u.name AS creator_name, u.email AS creator_email')
            ->join('users u', 'u.id = c.created_by', 'left')
            ->where('c.id', (int) $id)
            ->get()->getRowArray();

        if (! $chapter) {
            return redirect()->to(site_url('manager/chapters'))->with('error', 'Chapter not found.');
        }

        $members = $db->table('chapter_members cm')
            ->select('u.id, u.name, u.email, u.avatar_url, cm.role, cm.joined_at')
            ->join('users u', 'u.id = cm.user_id')
            ->where('cm.chapter_id', (int) $id)
            ->orderBy('cm.joined_at', 'DESC')
            ->limit(50)
            ->get()->getResultArray();

        return $this->renderView('admin/chapters/show', compact('chapter', 'members'));
    }

    public function create()
    {
        return $this->renderView('admin/chapters/create');
    }

    public function store()
    {
        $input = $this->request->getPost();

        if (empty(trim($input['name'] ?? ''))) {
            return redirect()->back()->withInput()->with('error', 'Chapter name is required.');
        }

        $db   = db_connect();
        $name = trim($input['name']);
        $slug = $this->makeSlug($name, $db);

        $imageUrl  = null;
        $imageFile = $this->request->getFile('image');
        if ($imageFile && $imageFile->isValid() && ! $imageFile->hasMoved()) {
            $ext      = strtolower($imageFile->getExtension());
            $fn       = 'chapter_' . time() . '.' . $ext;
            $imageFile->move(WRITEPATH . 'uploads/', $fn);
            $s3       = new S3Uploader();
            $imageUrl = $s3->uploadOrLocal(WRITEPATH . 'uploads/' . $fn, "uploads/chapters/{$fn}", "image/{$ext}", 'chapters');
        }

        $db->table('chapters')->insert([
            'name'        => $name,
            'slug'        => $slug,
            'description' => $input['description'] ?? null,
            'city'        => $input['city'] ?? null,
            'state'       => $input['state'] ?? null,
            'country'     => $input['country'] ?? 'US',
            'category'    => $input['category'] ?? null,
            'image_url'   => $imageUrl,
            'created_by'  => session('admin_id'),
            'is_active'   => 1,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        $chapterId = $db->insertID();

        $this->audit('chapter_created', 'chapter', (int) $chapterId, $name);

        return redirect()->to(site_url("manager/chapters/{$chapterId}"))->with('success', 'Chapter created.');
    }

    public function update($id)
    {
        $db      = db_connect();
        $chapter = $db->table('chapters')->where('id', (int) $id)->get()->getRowArray();
        if (! $chapter) {
            return $this->jsonResponse(['error' => 'Not found.'], 404);
        }

        $input = $this->request->getPost();
        $name  = trim($input['name'] ?? $chapter['name']);

        $db->table('chapters')->where('id', (int) $id)->update([
            'name'        => $name,
            'description' => $input['description'] ?? $chapter['description'],
            'city'        => $input['city'] ?? $chapter['city'],
            'state'       => $input['state'] ?? $chapter['state'],
            'country'     => $input['country'] ?? $chapter['country'],
            'category'    => $input['category'] ?? $chapter['category'],
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        $this->audit('chapter_updated', 'chapter', (int) $id, $name);

        return redirect()->to(site_url("manager/chapters/{$id}"))->with('success', 'Chapter updated.');
    }

    public function uploadCover($id)
    {
        $db      = db_connect();
        $chapter = $db->table('chapters')->where('id', (int) $id)->get()->getRowArray();
        if (! $chapter) {
            return $this->jsonResponse(['error' => 'Chapter not found.'], 404);
        }

        $imageFile = $this->request->getFile('image');
        if (! $imageFile || ! $imageFile->isValid() || $imageFile->hasMoved()) {
            return $this->jsonResponse(['error' => 'No valid image provided.'], 422);
        }

        $ext     = strtolower($imageFile->getExtension());
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        if (! in_array($ext, $allowed, true) || $imageFile->getSize() > 5 * 1024 * 1024) {
            return $this->jsonResponse(['error' => 'Invalid image. Use JPG, PNG, or WebP under 5 MB.'], 422);
        }

        $fn = 'chapter_cover_' . $id . '_' . time() . '.' . $ext;
        $imageFile->move(WRITEPATH . 'uploads/', $fn);
        $s3       = new S3Uploader();
        $imageUrl = $s3->uploadOrLocal(WRITEPATH . 'uploads/' . $fn, "uploads/chapters/{$fn}", "image/{$ext}", 'chapters');

        $db->table('chapters')->where('id', (int) $id)->update([
            'image_url'  => $imageUrl,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->audit('chapter_cover_updated', 'chapter', (int) $id, $chapter['name']);

        return $this->jsonResponse(['image_url' => $imageUrl]);
    }

    public function toggleStatus($id)
    {
        $db      = db_connect();
        $chapter = $db->table('chapters')->select('id, name, is_active')->where('id', (int) $id)->get()->getRowArray();
        if (! $chapter) {
            return $this->jsonResponse(['error' => 'Not found.'], 404);
        }

        $newStatus = $chapter['is_active'] ? 0 : 1;
        $db->table('chapters')->where('id', (int) $id)->update(['is_active' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')]);

        $this->audit($newStatus ? 'chapter_activated' : 'chapter_deactivated', 'chapter', (int) $id, $chapter['name']);

        return $this->jsonResponse(['status' => $newStatus ? 'active' : 'inactive']);
    }

    public function delete($id)
    {
        $db      = db_connect();
        $chapter = $db->table('chapters')->select('id, name')->where('id', (int) $id)->get()->getRowArray();
        if (! $chapter) {
            return $this->jsonResponse(['error' => 'Not found.'], 404);
        }

        $db->table('chapters')->where('id', (int) $id)->update(['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')]);

        $this->audit('chapter_deleted', 'chapter', (int) $id, $chapter['name']);

        return $this->jsonResponse(['ok' => true]);
    }

    private function makeSlug(string $name, $db): string
    {
        $slug   = trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name)), '-');
        $exists = $db->table('chapters')->where('slug', $slug)->countAllResults();
        return $exists > 0 ? $slug . '-' . time() : $slug;
    }
}
