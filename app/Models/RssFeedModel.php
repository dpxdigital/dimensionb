<?php

namespace App\Models;

use CodeIgniter\Model;

class RssFeedModel extends Model
{
    protected $table         = 'rss_feeds';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'name',
        'url',
        'is_active',
        'last_fetched_at',
    ];
    protected $useTimestamps = true;

    /**
     * Returns all active feeds.
     */
    public function getActiveFeeds(): array
    {
        return $this->where('is_active', 1)->findAll();
    }

    /**
     * Formats a feed row for the public API response.
     */
    public static function formatRow(array $row): array
    {
        return [
            'id'              => (int)    $row['id'],
            'name'            =>          $row['name'],
            'last_fetched_at' =>          $row['last_fetched_at'] ?? null,
        ];
    }
}
