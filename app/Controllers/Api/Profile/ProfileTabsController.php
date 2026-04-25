<?php

namespace App\Controllers\Api\Profile;

use App\Controllers\Api\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

class ProfileTabsController extends BaseApiController
{
    // GET /v1/profile/posts
    public function posts(): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = db_connect();

        $rows = $db->table('posts p')
            ->select('p.id, p.body, p.media, p.created_at, u.name AS user_name, u.avatar_url')
            ->join('users u', 'u.id = p.user_id')
            ->where('p.user_id', $userId)
            ->orderBy('p.created_at', 'DESC')
            ->limit(50)
            ->get()->getResultArray();

        $data = array_map(function ($row) {
            return [
                'id'         => (string) $row['id'],
                'body'       => $row['body'],
                'media'      => $row['media'] ? json_decode($row['media'], true) : [],
                'user_name'  => $row['user_name'],
                'avatar_url' => $row['avatar_url'],
                'created_at' => $row['created_at'],
            ];
        }, $rows);

        return $this->success($data);
    }

    // GET /v1/profile/saved
    public function saved(): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = db_connect();

        $rows = $db->table('listing_saves ls')
            ->select('l.id, l.title, l.org_name, c.slug AS category, l.cover_url AS image_url, l.date, l.trust_level, l.trust_label')
            ->join('listings l', 'l.id = ls.listing_id')
            ->join('categories c', 'c.id = l.category_id', 'left')
            ->where('ls.user_id', $userId)
            ->orderBy('ls.created_at', 'DESC')
            ->limit(50)
            ->get()->getResultArray();

        $data = array_map(function ($row) {
            return [
                'id'          => (string) $row['id'],
                'title'       => $row['title'],
                'org_name'    => $row['org_name'],
                'category'    => $row['category'],
                'image_url'   => $row['image_url'] ?? null,
                'date'        => $row['date'] ?? null,
                'trust_level' => $row['trust_level'],
                'trust_label' => $row['trust_label'],
            ];
        }, $rows);

        return $this->success($data);
    }

    // GET /v1/profile/chapters
    public function chapters(): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = db_connect();

        $rows = $db->table('chapter_members cm')
            ->select('c.id, c.name, c.city, c.state, c.image_url, c.member_count')
            ->join('chapters c', 'c.id = cm.chapter_id')
            ->where('cm.user_id', $userId)
            ->orderBy('cm.joined_at', 'DESC')
            ->get()->getResultArray();

        $data = array_map(function ($row) {
            return [
                'id'           => (string) $row['id'],
                'name'         => $row['name'],
                'city'         => $row['city'] ?? null,
                'state'        => $row['state'] ?? null,
                'image_url'    => $row['image_url'] ?? null,
                'member_count' => (int) ($row['member_count'] ?? 0),
            ];
        }, $rows);

        return $this->success($data);
    }
}
