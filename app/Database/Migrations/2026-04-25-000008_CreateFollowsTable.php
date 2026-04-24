<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateFollowsTable extends Migration
{
    public function up(): void
    {
        // Add follow count columns to users if missing
        $existing = $this->db->getFieldNames('users');
        $cols = [];
        if (!in_array('followers_count', $existing)) {
            $cols[] = 'ADD COLUMN `followers_count` INT UNSIGNED NOT NULL DEFAULT 0';
        }
        if (!in_array('following_count', $existing)) {
            $cols[] = 'ADD COLUMN `following_count` INT UNSIGNED NOT NULL DEFAULT 0';
        }
        if (!empty($cols)) {
            $this->db->query('ALTER TABLE `users` ' . implode(', ', $cols));
        }

        $this->db->query('
            CREATE TABLE IF NOT EXISTS `user_follows` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `follower_id` INT UNSIGNED NOT NULL,
                `following_id` INT UNSIGNED NOT NULL,
                `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_follow` (`follower_id`, `following_id`),
                KEY `idx_following_id` (`following_id`),
                KEY `idx_follower_id` (`follower_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `user_follows`');
    }
}
