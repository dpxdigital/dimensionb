<?php

namespace App\Models;

use CodeIgniter\Model;

class RssArticleModel extends Model
{
    protected $table         = 'rss_articles';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'feed_id',
        'guid',
        'title',
        'description',
        'content',
        'url',
        'image_url',
        'video_url',
        'published_at',
        'created_at',
    ];

    public function existsForFeed(int $feedId, string $guid): bool
    {
        return $this->where('feed_id', $feedId)
                    ->where('guid', $guid)
                    ->countAllResults() > 0;
    }

    public function getPaginated(int $page, int $perPage, ?int $feedId = null): array
    {
        $db     = $this->db;
        $offset = ($page - 1) * $perPage;

        $builder = $db->table('rss_articles a')
            ->select('a.*, f.name AS feed_name')
            ->join('rss_feeds f', 'f.id = a.feed_id', 'left')
            ->orderBy('a.published_at', 'DESC')
            ->orderBy('a.id', 'DESC');

        if ($feedId !== null) {
            $builder->where('a.feed_id', $feedId);
        }

        $total = (clone $builder)->countAllResults(false);
        $rows  = $builder->limit($perPage, $offset)->get()->getResultArray();

        return ['items' => $rows, 'total' => $total];
    }

    public static function formatRow(array $row): array
    {
        return [
            'id'           => (int)   $row['id'],
            'feed_id'      => (int)   $row['feed_id'],
            'feed_name'    =>         $row['feed_name'] ?? null,
            'title'        =>         $row['title'],
            'description'  =>         $row['description'] ?? null,
            'content'      =>         $row['content'] ?? null,
            'url'          =>         $row['url'],
            'image_url'    =>         $row['image_url'] ?? null,
            'video_url'    =>         $row['video_url'] ?? null,
            'published_at' =>         $row['published_at'] ?? null,
            'created_at'   =>         $row['created_at'],
        ];
    }
}
