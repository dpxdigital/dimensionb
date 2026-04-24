<?php

namespace App\Controllers\Api\Chapters;

use App\Controllers\Api\BaseApiController;
use App\Libraries\S3Uploader;
use CodeIgniter\HTTP\ResponseInterface;

class ChaptersController extends BaseApiController
{
    private function db() { return db_connect(); }

    // ── GET /v1/chapters ──────────────────────────────────────────────────────

    public function index(): ResponseInterface
    {
        $userId = $this->authUserId();
        $q      = trim((string) ($this->request->getGet('q') ?? ''));
        $city   = $this->request->getGet('city');
        $tab    = $this->request->getGet('tab') ?? 'suggested'; // suggested|nearby|trending|joined
        $limit  = 20;

        $db = $this->db();
        $query = $db->table('chapters c')
            ->select('c.*, cm_me.id IS NOT NULL AS is_joined')
            ->join("chapter_members cm_me", "cm_me.chapter_id = c.id AND cm_me.user_id = {$userId}", 'left')
            ->where('c.is_active', 1)
            ->limit($limit);

        if (! empty($q)) {
            $query->groupStart()->like('c.name', $q)->orLike('c.city', $q)->groupEnd();
        }
        if ($city) {
            $query->where('c.city', $city);
        }

        if ($tab === 'joined') {
            $query->join('chapter_members cm_j', "cm_j.chapter_id = c.id AND cm_j.user_id = {$userId}")
                  ->orderBy('cm_j.joined_at', 'DESC');
        } elseif ($tab === 'trending') {
            $query->orderBy('c.member_count', 'DESC');
        } else {
            $query->orderBy('c.id', 'DESC');
        }

        $rows = $query->get()->getResultArray();

        return $this->success(array_map([$this, 'formatChapter'], $rows));
    }

    // ── GET /v1/chapters/:id ──────────────────────────────────────────────────

    public function show($id = null): ResponseInterface
    {
        $userId  = $this->authUserId();
        $db      = $this->db();

        $chapter = $db->table('chapters c')
            ->select('c.*, (SELECT COUNT(*) FROM chapter_members WHERE chapter_id = c.id AND user_id = ?) AS is_joined', false)
            ->where('c.id', (int) $id)
            ->where('c.is_active', 1)
            ->get(1, 0, [$userId])->getRowArray();

        if (! $chapter) {
            return $this->error('Chapter not found.', 404);
        }

        $members = $db->table('chapter_members cm')
            ->select('u.id, u.name, u.avatar_url, cm.role, cm.joined_at')
            ->join('users u', 'u.id = cm.user_id')
            ->where('cm.chapter_id', (int) $id)
            ->limit(50)
            ->get()->getResultArray();

        return $this->success([
            'chapter' => $this->formatChapter($chapter),
            'members' => $members,
        ]);
    }

    // ── POST /v1/chapters/:id/join ────────────────────────────────────────────

    public function join($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = $this->db();

        $chapter = $db->table('chapters')->where('id', (int) $id)->where('is_active', 1)->get()->getRowArray();
        if (! $chapter) return $this->error('Chapter not found.', 404);

        $existing = $db->table('chapter_members')
            ->where('chapter_id', (int) $id)->where('user_id', $userId)->get()->getRowArray();

        if ($existing) {
            // Leave
            $db->table('chapter_members')->where('chapter_id', (int) $id)->where('user_id', $userId)->delete();
            $db->table('chapters')->where('id', (int) $id)->set('member_count', 'GREATEST(member_count - 1, 0)', false)->update();
            return $this->success(['is_joined' => false], 'Left chapter');
        }

        $db->table('chapter_members')->insert([
            'chapter_id' => (int) $id,
            'user_id'    => $userId,
            'role'       => 'member',
            'joined_at'  => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $db->table('chapters')->where('id', (int) $id)->set('member_count', 'member_count + 1', false)->update();

        return $this->success(['is_joined' => true], 'Joined chapter');
    }

    // ── GET /v1/chapters/:id/feed ─────────────────────────────────────────────

    public function feed($id = null): ResponseInterface
    {
        $cursor = (int) ($this->request->getGet('cursor') ?? 0);
        $limit  = 20;
        $db     = $this->db();

        $query = $db->table('posts p')
            ->select('p.*, u.name AS user_name, u.avatar_url')
            ->join('users u', 'u.id = p.user_id')
            ->where('p.chapter_id', (int) $id)
            ->orderBy('p.id', 'DESC')
            ->limit($limit + 1);

        if ($cursor > 0) $query->where('p.id <', $cursor);

        $rows    = $query->get()->getResultArray();
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);

        return $this->success(
            array_map(static fn($r) => [
                'id'         => (string) $r['id'],
                'user_name'  => $r['user_name'],
                'avatar_url' => $r['avatar_url'] ?? null,
                'body'       => $r['body'] ?? null,
                'media'      => isset($r['media']) ? json_decode($r['media'], true) : [],
                'created_at' => $r['created_at'],
            ], $rows),
            'OK', 200, ['next_cursor' => $hasMore && ! empty($rows) ? end($rows)['id'] : null]
        );
    }

    // ── POST /v1/chapters (admin/moderator only) ──────────────────────────────

    public function create(): ResponseInterface
    {
        $userId = $this->authUserId();
        $isMultipart = str_contains($this->request->getHeaderLine('Content-Type'), 'multipart');
        $input = $isMultipart ? $this->request->getPost() : $this->inputJson();

        if (! $this->validateData($input, [
            'name' => 'required|max_length[255]',
            'city' => 'permit_empty|max_length[100]',
        ])) {
            return $this->validationError($this->validator->getErrors());
        }

        $imageUrl = null;
        $imageFile = $this->request->getFile('image');
        if ($imageFile && $imageFile->isValid()) {
            $ext = strtolower($imageFile->getExtension());
            $fn  = 'chapter_' . time() . '.' . $ext;
            $imageFile->move(WRITEPATH . 'uploads/', $fn);
            $s3 = new S3Uploader();
            $imageUrl = $s3->uploadOrLocal(WRITEPATH . 'uploads/' . $fn, "uploads/chapters/{$fn}", "image/{$ext}", 'chapters');
        }

        $db   = $this->db();
        $slug = $this->makeSlug($input['name']);
        $db->table('chapters')->insert([
            'name'        => trim($input['name']),
            'slug'        => $slug,
            'description' => $input['description'] ?? null,
            'city'        => $input['city'] ?? null,
            'state'       => $input['state'] ?? null,
            'country'     => $input['country'] ?? 'US',
            'category'    => $input['category'] ?? null,
            'image_url'   => $imageUrl,
            'created_by'  => $userId,
            'is_active'   => 1,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        $chapterId = $db->insertID();

        $db->table('chapter_members')->insert([
            'chapter_id' => $chapterId,
            'user_id'    => $userId,
            'role'       => 'admin',
            'joined_at'  => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $db->table('chapters')->where('id', $chapterId)->set('member_count', 1)->update();

        $chapter = $db->table('chapters')->where('id', $chapterId)->get()->getRowArray();
        $chapter['is_joined'] = 1;

        return $this->success($this->formatChapter($chapter), 'Chapter created', 201);
    }

    private function formatChapter(array $row): array
    {
        return [
            'id'           => (string) $row['id'],
            'name'         => $row['name'],
            'slug'         => $row['slug'],
            'description'  => $row['description'] ?? null,
            'city'         => $row['city'] ?? null,
            'state'        => $row['state'] ?? null,
            'country'      => $row['country'] ?? 'US',
            'category'     => $row['category'] ?? null,
            'image_url'    => $row['image_url'] ?? null,
            'member_count' => (int) ($row['member_count'] ?? 0),
            'event_count'  => (int) ($row['event_count'] ?? 0),
            'post_count'   => (int) ($row['post_count'] ?? 0),
            'is_joined'    => (bool) ($row['is_joined'] ?? false),
        ];
    }

    private function makeSlug(string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        $slug = trim($slug, '-');
        $exists = db_connect()->table('chapters')->where('slug', $slug)->countAllResults();
        return $exists > 0 ? $slug . '-' . time() : $slug;
    }
}
