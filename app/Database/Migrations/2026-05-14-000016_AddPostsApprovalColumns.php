<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class AddPostsApprovalColumns extends Migration
{
    public function up(): void
    {
        // Add new columns to posts table (real columns are body/media; adding content/image_url aliases)
        $this->db->query("ALTER TABLE posts
            ADD COLUMN content TEXT NULL AFTER user_id,
            ADD COLUMN image_url VARCHAR(500) NULL,
            ADD COLUMN video_url VARCHAR(500) NULL,
            ADD COLUMN is_deleted TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            ADD COLUMN status ENUM('approved','pending') NOT NULL DEFAULT 'approved'
        ");
        // Populate content from legacy body column
        $this->db->query("UPDATE posts SET content = body WHERE content IS NULL AND body IS NOT NULL");

        // Add 'pending' to discussions.status enum
        $this->db->query("ALTER TABLE discussions MODIFY COLUMN status ENUM('open','closed','pending') NOT NULL DEFAULT 'open'");
    }

    public function down(): void
    {
        $this->db->query("ALTER TABLE posts
            DROP COLUMN content,
            DROP COLUMN image_url,
            DROP COLUMN video_url,
            DROP COLUMN is_deleted,
            DROP COLUMN status
        ");
        $this->db->query("ALTER TABLE discussions MODIFY COLUMN status ENUM('open','closed') NOT NULL DEFAULT 'open'");
    }
}
