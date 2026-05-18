<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCircleTypeColumns extends Migration
{
    public function up(): void
    {
        // Add circle_type, who_can_post, who_can_discuss if not already present
        $this->db->query("
            ALTER TABLE `circles`
            ADD COLUMN IF NOT EXISTS `circle_type`    VARCHAR(50) NOT NULL DEFAULT 'interest_based' AFTER `visibility`,
            ADD COLUMN IF NOT EXISTS `who_can_post`   VARCHAR(50) NOT NULL DEFAULT 'all_members'    AFTER `circle_type`,
            ADD COLUMN IF NOT EXISTS `who_can_discuss` VARCHAR(50) NOT NULL DEFAULT 'all_members'   AFTER `who_can_post`
        ");

        // Extend visibility ENUM to include invite_only and status ENUM to include deleted
        $this->db->query("
            ALTER TABLE `circles`
            MODIFY COLUMN `visibility` ENUM('public','private','invite','invite_only') NOT NULL DEFAULT 'public',
            MODIFY COLUMN `status`     ENUM('active','pending','suspended','deleted')  NOT NULL DEFAULT 'active'
        ");
    }

    public function down(): void
    {
        $this->db->query("ALTER TABLE `circles` DROP COLUMN IF EXISTS `circle_type`");
        $this->db->query("ALTER TABLE `circles` DROP COLUMN IF EXISTS `who_can_post`");
        $this->db->query("ALTER TABLE `circles` DROP COLUMN IF EXISTS `who_can_discuss`");
    }
}
