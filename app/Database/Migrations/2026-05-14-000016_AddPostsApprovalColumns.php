<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class AddPostsApprovalColumns extends Migration
{
    public function up(): void
    {
        // Add columns only if they don't already exist (safe to re-run on production)
        $db     = $this->db;
        $prefix = $db->getPrefix();
        $cols   = array_column($db->getFieldData($prefix . 'posts'), 'name');

        $alters = [];
        if (! in_array('content',   $cols, true)) $alters[] = 'ADD COLUMN content TEXT NULL AFTER user_id';
        if (! in_array('image_url', $cols, true)) $alters[] = 'ADD COLUMN image_url VARCHAR(500) NULL';
        if (! in_array('video_url', $cols, true)) $alters[] = 'ADD COLUMN video_url VARCHAR(500) NULL';
        if (! in_array('is_deleted',$cols, true)) $alters[] = 'ADD COLUMN is_deleted TINYINT(1) UNSIGNED NOT NULL DEFAULT 0';
        if (! in_array('status',    $cols, true)) $alters[] = "ADD COLUMN status ENUM('approved','pending') NOT NULL DEFAULT 'approved'";

        if (! empty($alters)) {
            $db->query('ALTER TABLE posts ' . implode(', ', $alters));
        }

        // Populate content from legacy body column
        $db->query('UPDATE posts SET content = body WHERE content IS NULL AND body IS NOT NULL');

        // Add 'pending' to discussions.status enum
        $db->query("ALTER TABLE discussions MODIFY COLUMN status ENUM('open','closed','pending') NOT NULL DEFAULT 'open'");
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
