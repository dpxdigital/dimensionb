<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddVideoUrlToRssArticles extends Migration
{
    public function up(): void
    {
        $this->db->query("
            ALTER TABLE `rss_articles`
            ADD COLUMN `video_url` TEXT DEFAULT NULL AFTER `image_url`
        ");
    }

    public function down(): void
    {
        $this->db->query("ALTER TABLE `rss_articles` DROP COLUMN `video_url`");
    }
}
