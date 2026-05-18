<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateListingRssFeedsTable extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `listing_rss_feeds` (
                `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name`            VARCHAR(200) NOT NULL,
                `url`             TEXT NOT NULL,
                `category_id`     INT UNSIGNED NOT NULL,
                `trust_level`     VARCHAR(50)  NOT NULL DEFAULT 'community_submitted',
                `import_status`   VARCHAR(20)  NOT NULL DEFAULT 'pending',
                `is_active`       TINYINT(1)   NOT NULL DEFAULT 1,
                `last_fetched_at` DATETIME     DEFAULT NULL,
                `item_count`      INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `listing_rss_feeds`");
    }
}
