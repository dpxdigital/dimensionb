<?php

namespace App\Controllers\Api\Posts;

use App\Controllers\Api\BaseApiController;
use App\Libraries\S3Uploader;
use CodeIgniter\HTTP\ResponseInterface;

class PostsController extends BaseApiController
{
    // ── POST /v1/posts ────────────────────────────────────────────────────────

    public function create(): ResponseInterface
    {
        $userId    = $this->authUserId();
        $body      = trim((string) ($this->request->getPost('body') ?? ''));
        $files     = $this->request->getFiles('media');
        $mediaUrls = [];

        $mediaFiles = $files['media'] ?? $files['media[]'] ?? [];
        if (empty($body) && empty($mediaFiles)) {
            return $this->error('Post must have text or at least one media file.', 422);
        }

        if (strlen($body) > 2200) {
            return $this->error('Post body must be 2200 characters or fewer.', 422);
        }

        // Support both 'media' and 'media[]' field names
        $mediaFiles = $files['media'] ?? $files['media[]'] ?? [];

        if (! empty($mediaFiles)) {
            $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov'];
            $videoExts   = ['mp4', 'mov'];
            $tmpDir      = WRITEPATH . 'uploads/tmp/';
            if (! is_dir($tmpDir)) mkdir($tmpDir, 0755, true);
            $s3 = new S3Uploader();

            foreach ((array) $mediaFiles as $file) {
                if (! $file->isValid()) continue;

                $ext = strtolower($file->getClientExtension() ?: $file->guessExtension() ?: '');
                if (! in_array($ext, $allowedExts, true)) {
                    return $this->error('Unsupported file type: ' . $ext, 422);
                }
                if ($file->getSize() > 50 * 1024 * 1024) {
                    return $this->error('Each file must be under 50 MB.', 422);
                }

                $filename = 'post_' . $userId . '_' . time() . '_' . uniqid() . '.' . $ext;
                $file->move($tmpDir, $filename);
                $tmpPath  = $tmpDir . $filename;
                $mimeType = in_array($ext, $videoExts, true) ? "video/{$ext}" : "image/{$ext}";

                try {
                    $url = $s3->uploadOrLocal($tmpPath, "uploads/posts/{$filename}", $mimeType, 'posts');
                    $mediaUrls[] = [
                        'url'  => $url,
                        'type' => in_array($ext, $videoExts, true) ? 'video' : 'image',
                    ];
                } catch (\Throwable $e) {
                    return $this->error('Media upload failed: ' . $e->getMessage(), 500);
                }
            }
        }

        $db = db_connect();
        $db->table('posts')->insert([
            'user_id'    => $userId,
            'body'       => $body ?: null,
            'media'      => ! empty($mediaUrls) ? json_encode($mediaUrls) : null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $postId = $db->insertID();

        $post = $db->table('posts p')
            ->select('p.*, u.name AS user_name, u.avatar_url')
            ->join('users u', 'u.id = p.user_id')
            ->where('p.id', $postId)
            ->get()->getRowArray();

        return $this->success($this->formatPost($post), 'Post created', 201);
    }

    // ── GET /v1/posts ─────────────────────────────────────────────────────────

    public function index(): ResponseInterface
    {
        $userId = $this->authUserId();
        $cursor = (int) ($this->request->getGet('cursor') ?? 0);
        $q      = trim((string) ($this->request->getGet('q') ?? ''));
        $limit  = 20;
        $db     = db_connect();

        $query = $db->table('posts p')
            ->select('p.*, u.name AS user_name, u.avatar_url')
            ->join('users u', 'u.id = p.user_id')
            ->orderBy('p.id', 'DESC')
            ->limit($limit + 1);

        if ($cursor > 0) $query->where('p.id <', $cursor);
        if ($q !== '') $query->like('p.body', $q);

        $rows    = $query->get()->getResultArray();
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        $nextCursor = $hasMore && ! empty($rows) ? end($rows)['id'] : null;

        // Get followed user IDs for is_following flag
        $followedIds = [];
        try {
            $follows = $db->table('follows')
                ->select('following_id')
                ->where('follower_id', $userId)
                ->get()->getResultArray();
            $followedIds = array_map('intval', array_column($follows, 'following_id'));
        } catch (\Throwable $_) {}

        $formatted = array_map(function ($row) use ($followedIds) {
            $post = $this->formatPost($row);
            $post['is_following'] = in_array((int) $row['user_id'], $followedIds, true);
            return $post;
        }, $rows);

        return $this->success($formatted, 'OK', 200, ['next_cursor' => $nextCursor]);
    }

    // ── GET /v1/posts/:id/comments ────────────────────────────────────────────

    public function comments($id = null): ResponseInterface
    {
        $db   = db_connect();
        $rows = $db->table('post_comments pc')
            ->select('pc.*, u.name AS user_name, u.avatar_url')
            ->join('users u', 'u.id = pc.user_id')
            ->where('pc.post_id', (int) $id)
            ->orderBy('pc.created_at', 'DESC')
            ->limit(50)
            ->get()->getResultArray();

        return $this->success(array_map(static fn($r) => [
            'id'         => (string) $r['id'],
            'post_id'    => (string) $r['post_id'],
            'user_id'    => (string) $r['user_id'],
            'user_name'  => $r['user_name'] ?? '',
            'avatar_url' => $r['avatar_url'] ?? null,
            'body'       => $r['body'],
            'created_at' => $r['created_at'],
        ], $rows));
    }

    // ── POST /v1/posts/:id/comments ───────────────────────────────────────────

    public function addComment($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $input  = $this->inputJson();
        $body   = trim((string) ($input['body'] ?? ''));

        if ($body === '') return $this->error('Comment body is required.', 422);

        $db  = db_connect();
        $now = date('Y-m-d H:i:s');
        $db->table('post_comments')->insert([
            'post_id'    => (int) $id,
            'user_id'    => $userId,
            'body'       => $body,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $commentId = $db->insertID();

        $user = $db->table('users')->select('name, avatar_url')->where('id', $userId)->get()->getRowArray();

        return $this->success([
            'id'         => (string) $commentId,
            'post_id'    => (string) $id,
            'user_id'    => (string) $userId,
            'user_name'  => $user['name'] ?? '',
            'avatar_url' => $user['avatar_url'] ?? null,
            'body'       => $body,
            'created_at' => $now,
        ], 'Comment added', 201);
    }

    private function formatPost(array $row): array
    {
        return [
            'id'         => (string) $row['id'],
            'user_id'    => (string) $row['user_id'],
            'user_name'  => $row['user_name'] ?? '',
            'avatar_url' => $row['avatar_url'] ?? null,
            'body'       => $row['body'] ?? null,
            'media'      => isset($row['media']) ? json_decode($row['media'], true) : [],
            'created_at' => $row['created_at'],
        ];
    }
}
