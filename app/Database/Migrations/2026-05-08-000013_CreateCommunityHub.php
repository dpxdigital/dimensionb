<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 1 — Community Hub schema
 *
 * New tables (creation order respects FK dependencies):
 *   circles, circle_members, movements, movement_followers,
 *   circle_movements, discussions, discussion_comments,
 *   community_actions, action_participants, post_reactions,
 *   activity_metrics, reports
 *
 * Altered tables:
 *   posts        — add circle_id, post_type, reaction_count
 *   live_sessions — add description, circle_id, movement_id, action_id,
 *                   scheduled_at, visibility
 *
 * Decisions recorded:
 *  - Circles are separate from conversations (circles = community hubs)
 *  - Table named community_actions to avoid ambiguity
 *  - live_cohosts kept as-is; live_sessions.host_id remains canonical host
 *  - notifications.reference_id/reference_type kept (not renamed)
 */
class CreateCommunityHub extends Migration
{
    // ── Tables created by this migration (drop order = reverse) ───────────────
    private array $newTables = [
        'reports',
        'activity_metrics',
        'action_participants',
        'community_actions',
        'discussion_comments',
        'discussions',
        'circle_movements',
        'movement_followers',
        'post_reactions',
        'movements',
        'circle_members',
        'circles',
    ];

    public function up(): void
    {
        // ── 1. circles ────────────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `circles` (
                `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`         VARCHAR(255)    NOT NULL,
                `slug`         VARCHAR(255)    NOT NULL,
                `description`  TEXT            DEFAULT NULL,
                `banner_url`   VARCHAR(500)    DEFAULT NULL,
                `logo_url`     VARCHAR(500)    DEFAULT NULL,
                `category_id`  INT UNSIGNED    DEFAULT NULL,
                `location`     VARCHAR(255)    DEFAULT NULL,
                `visibility`   ENUM('public','private','invite') NOT NULL DEFAULT 'public',
                `status`       ENUM('active','pending','suspended') NOT NULL DEFAULT 'active',
                `member_count` INT UNSIGNED    NOT NULL DEFAULT 0,
                `created_by`   INT UNSIGNED    NOT NULL,
                `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_circles_slug` (`slug`),
                KEY `idx_circles_status`   (`status`),
                KEY `idx_circles_category` (`category_id`),
                KEY `idx_circles_creator`  (`created_by`),
                CONSTRAINT `fk_circles_creator`  FOREIGN KEY (`created_by`)  REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_circles_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 2. circle_members ─────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `circle_members` (
                `id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `circle_id` BIGINT UNSIGNED NOT NULL,
                `user_id`   INT UNSIGNED    NOT NULL,
                `role`      ENUM('member','moderator','admin') NOT NULL DEFAULT 'member',
                `status`    ENUM('pending','approved','banned') NOT NULL DEFAULT 'approved',
                `joined_at` DATETIME        DEFAULT NULL,
                `created_at` DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_circle_member` (`circle_id`, `user_id`),
                KEY `idx_cm_user`   (`user_id`),
                KEY `idx_cm_status` (`status`),
                CONSTRAINT `fk_cm_circle` FOREIGN KEY (`circle_id`) REFERENCES `circles` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_cm_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 3. movements ──────────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `movements` (
                `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `title`          VARCHAR(255)    NOT NULL,
                `slug`           VARCHAR(255)    NOT NULL,
                `description`    TEXT            DEFAULT NULL,
                `category_id`    INT UNSIGNED    DEFAULT NULL,
                `organizer_id`   INT UNSIGNED    NOT NULL,
                `cover_url`      VARCHAR(500)    DEFAULT NULL,
                `follower_count` INT UNSIGNED    NOT NULL DEFAULT 0,
                `status`         ENUM('active','archived') NOT NULL DEFAULT 'active',
                `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_movements_slug` (`slug`),
                KEY `idx_movements_organizer` (`organizer_id`),
                KEY `idx_movements_category`  (`category_id`),
                KEY `idx_movements_status`    (`status`),
                CONSTRAINT `fk_mov_organizer` FOREIGN KEY (`organizer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_mov_category`  FOREIGN KEY (`category_id`)  REFERENCES `categories` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 4. movement_followers ─────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `movement_followers` (
                `movement_id` BIGINT UNSIGNED NOT NULL,
                `user_id`     INT UNSIGNED    NOT NULL,
                `followed_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`movement_id`, `user_id`),
                KEY `idx_mf_user` (`user_id`),
                CONSTRAINT `fk_mf_movement` FOREIGN KEY (`movement_id`) REFERENCES `movements` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_mf_user`     FOREIGN KEY (`user_id`)     REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 5. circle_movements ───────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `circle_movements` (
                `circle_id`   BIGINT UNSIGNED NOT NULL,
                `movement_id` BIGINT UNSIGNED NOT NULL,
                `linked_by`   INT UNSIGNED    NOT NULL,
                `linked_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`circle_id`, `movement_id`),
                KEY `idx_cirm_movement` (`movement_id`),
                CONSTRAINT `fk_cirm_circle`   FOREIGN KEY (`circle_id`)   REFERENCES `circles` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_cirm_movement` FOREIGN KEY (`movement_id`) REFERENCES `movements` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 6. post_reactions (depends on posts) ──────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `post_reactions` (
                `post_id`       BIGINT UNSIGNED NOT NULL,
                `user_id`       INT UNSIGNED    NOT NULL,
                `reaction_type` VARCHAR(20)     NOT NULL DEFAULT 'like',
                `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`post_id`, `user_id`),
                KEY `idx_pr_user` (`user_id`),
                CONSTRAINT `fk_pr_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 7. discussions ────────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `discussions` (
                `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `circle_id`   BIGINT UNSIGNED DEFAULT NULL,
                `movement_id` BIGINT UNSIGNED DEFAULT NULL,
                `author_id`   INT UNSIGNED    NOT NULL,
                `title`       VARCHAR(255)    NOT NULL,
                `prompt`      TEXT            NOT NULL,
                `status`      ENUM('open','closed') NOT NULL DEFAULT 'open',
                `reply_count` INT UNSIGNED    NOT NULL DEFAULT 0,
                `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_disc_circle`   (`circle_id`, `status`),
                KEY `idx_disc_movement` (`movement_id`),
                KEY `idx_disc_author`   (`author_id`),
                CONSTRAINT `fk_disc_circle`   FOREIGN KEY (`circle_id`)   REFERENCES `circles` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_disc_movement` FOREIGN KEY (`movement_id`) REFERENCES `movements` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_disc_author`   FOREIGN KEY (`author_id`)   REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 8. discussion_comments ────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `discussion_comments` (
                `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `discussion_id` BIGINT UNSIGNED NOT NULL,
                `author_id`     INT UNSIGNED    NOT NULL,
                `content`       TEXT            NOT NULL,
                `parent_id`     BIGINT UNSIGNED DEFAULT NULL,
                `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_dc_discussion` (`discussion_id`, `created_at`),
                KEY `idx_dc_parent`     (`parent_id`),
                CONSTRAINT `fk_dc_discussion` FOREIGN KEY (`discussion_id`) REFERENCES `discussions` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_dc_author`     FOREIGN KEY (`author_id`)     REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_dc_parent`     FOREIGN KEY (`parent_id`)     REFERENCES `discussion_comments` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 9. community_actions ──────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `community_actions` (
                `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `title`             VARCHAR(255)    NOT NULL,
                `description`       TEXT            DEFAULT NULL,
                `action_type`       ENUM('register','attend','volunteer','apply','share','survey','discuss','join_live') NOT NULL,
                `circle_id`         BIGINT UNSIGNED DEFAULT NULL,
                `movement_id`       BIGINT UNSIGNED DEFAULT NULL,
                `discussion_id`     BIGINT UNSIGNED DEFAULT NULL,
                `cta_label`         VARCHAR(100)    NOT NULL DEFAULT 'Take Action',
                `cta_url`           VARCHAR(500)    DEFAULT NULL,
                `deadline`          DATETIME        DEFAULT NULL,
                `participant_goal`  INT UNSIGNED    DEFAULT NULL,
                `interested_count`  INT UNSIGNED    NOT NULL DEFAULT 0,
                `completed_count`   INT UNSIGNED    NOT NULL DEFAULT 0,
                `status`            ENUM('active','closed') NOT NULL DEFAULT 'active',
                `created_by`        INT UNSIGNED    NOT NULL,
                `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_ca_circle`     (`circle_id`, `status`),
                KEY `idx_ca_movement`   (`movement_id`),
                KEY `idx_ca_discussion` (`discussion_id`),
                KEY `idx_ca_status`     (`status`, `deadline`),
                CONSTRAINT `fk_ca_circle`     FOREIGN KEY (`circle_id`)     REFERENCES `circles` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_ca_movement`   FOREIGN KEY (`movement_id`)   REFERENCES `movements` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_ca_discussion` FOREIGN KEY (`discussion_id`) REFERENCES `discussions` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_ca_creator`    FOREIGN KEY (`created_by`)    REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 10. action_participants ───────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `action_participants` (
                `action_id`          BIGINT UNSIGNED NOT NULL,
                `user_id`            INT UNSIGNED    NOT NULL,
                `participation_type` ENUM('interested','completed') NOT NULL DEFAULT 'interested',
                `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`action_id`, `user_id`),
                KEY `idx_ap_user` (`user_id`),
                CONSTRAINT `fk_ap_action` FOREIGN KEY (`action_id`) REFERENCES `community_actions` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_ap_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 11. activity_metrics ──────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `activity_metrics` (
                `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `entity_type` VARCHAR(50)     NOT NULL,
                `entity_id`   BIGINT UNSIGNED NOT NULL,
                `metric_type` VARCHAR(50)     NOT NULL,
                `value`       INT             NOT NULL DEFAULT 0,
                `recorded_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_am_entity`   (`entity_type`, `entity_id`, `metric_type`),
                KEY `idx_am_recorded` (`recorded_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 12. reports ───────────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `reports` (
                `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `reporter_id` INT UNSIGNED    NOT NULL,
                `entity_type` VARCHAR(50)     NOT NULL,
                `entity_id`   BIGINT UNSIGNED NOT NULL,
                `reason`      TEXT            NOT NULL,
                `status`      ENUM('pending','reviewed','resolved','dismissed') NOT NULL DEFAULT 'pending',
                `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_reports_entity`   (`entity_type`, `entity_id`),
                KEY `idx_reports_status`   (`status`),
                KEY `idx_reports_reporter` (`reporter_id`),
                CONSTRAINT `fk_reports_reporter` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 13. ALTER posts — add circle context ──────────────────────────────
        try {
            $this->db->query("ALTER TABLE `posts` ADD COLUMN `circle_id` BIGINT UNSIGNED DEFAULT NULL AFTER `user_id`");
        } catch (\Throwable $e) { /* column already exists */ }

        try {
            $this->db->query("ALTER TABLE `posts` ADD COLUMN `post_type` ENUM('text','image','video') NOT NULL DEFAULT 'text' AFTER `media`");
        } catch (\Throwable $e) { /* column already exists */ }

        try {
            $this->db->query("ALTER TABLE `posts` ADD COLUMN `reaction_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `like_count`");
        } catch (\Throwable $e) { /* column already exists */ }

        try {
            $this->db->query("ALTER TABLE `posts` ADD KEY `idx_posts_circle` (`circle_id`)");
        } catch (\Throwable $e) { /* index already exists */ }

        try {
            $this->db->query("ALTER TABLE `posts` ADD CONSTRAINT `fk_posts_circle` FOREIGN KEY (`circle_id`) REFERENCES `circles` (`id`) ON DELETE CASCADE");
        } catch (\Throwable $e) { /* constraint already exists */ }

        // ── 14. ALTER live_sessions — add community context ───────────────────
        try {
            $this->db->query("ALTER TABLE `live_sessions` ADD COLUMN `description` TEXT DEFAULT NULL AFTER `title`");
        } catch (\Throwable $e) {}

        try {
            $this->db->query("ALTER TABLE `live_sessions` ADD COLUMN `circle_id` BIGINT UNSIGNED DEFAULT NULL");
        } catch (\Throwable $e) {}

        try {
            $this->db->query("ALTER TABLE `live_sessions` ADD COLUMN `movement_id` BIGINT UNSIGNED DEFAULT NULL");
        } catch (\Throwable $e) {}

        try {
            $this->db->query("ALTER TABLE `live_sessions` ADD COLUMN `action_id` BIGINT UNSIGNED DEFAULT NULL");
        } catch (\Throwable $e) {}

        try {
            $this->db->query("ALTER TABLE `live_sessions` ADD COLUMN `scheduled_at` DATETIME DEFAULT NULL");
        } catch (\Throwable $e) {}

        try {
            $this->db->query("ALTER TABLE `live_sessions` ADD COLUMN `visibility` ENUM('public','circle_only','movement_followers') NOT NULL DEFAULT 'public'");
        } catch (\Throwable $e) {}

        try {
            $this->db->query("ALTER TABLE `live_sessions` ADD KEY `idx_ls_circle` (`circle_id`)");
        } catch (\Throwable $e) {}

        try {
            $this->db->query("ALTER TABLE `live_sessions` ADD KEY `idx_ls_movement` (`movement_id`)");
        } catch (\Throwable $e) {}

        try {
            $this->db->query("ALTER TABLE `live_sessions` ADD CONSTRAINT `fk_ls_circle`   FOREIGN KEY (`circle_id`)   REFERENCES `circles` (`id`) ON DELETE SET NULL");
        } catch (\Throwable $e) {}

        try {
            $this->db->query("ALTER TABLE `live_sessions` ADD CONSTRAINT `fk_ls_movement` FOREIGN KEY (`movement_id`) REFERENCES `movements` (`id`) ON DELETE SET NULL");
        } catch (\Throwable $e) {}

        try {
            $this->db->query("ALTER TABLE `live_sessions` ADD CONSTRAINT `fk_ls_action`   FOREIGN KEY (`action_id`)   REFERENCES `community_actions` (`id`) ON DELETE SET NULL");
        } catch (\Throwable $e) {}
    }

    public function down(): void
    {
        // Remove FKs and columns from altered tables first
        $alterDowns = [
            "ALTER TABLE `live_sessions` DROP FOREIGN KEY IF EXISTS `fk_ls_action`",
            "ALTER TABLE `live_sessions` DROP FOREIGN KEY IF EXISTS `fk_ls_movement`",
            "ALTER TABLE `live_sessions` DROP FOREIGN KEY IF EXISTS `fk_ls_circle`",
            "ALTER TABLE `live_sessions` DROP KEY IF EXISTS `idx_ls_movement`",
            "ALTER TABLE `live_sessions` DROP KEY IF EXISTS `idx_ls_circle`",
            "ALTER TABLE `live_sessions` DROP COLUMN IF EXISTS `visibility`",
            "ALTER TABLE `live_sessions` DROP COLUMN IF EXISTS `scheduled_at`",
            "ALTER TABLE `live_sessions` DROP COLUMN IF EXISTS `action_id`",
            "ALTER TABLE `live_sessions` DROP COLUMN IF EXISTS `movement_id`",
            "ALTER TABLE `live_sessions` DROP COLUMN IF EXISTS `circle_id`",
            "ALTER TABLE `live_sessions` DROP COLUMN IF EXISTS `description`",
            "ALTER TABLE `posts` DROP FOREIGN KEY IF EXISTS `fk_posts_circle`",
            "ALTER TABLE `posts` DROP KEY IF EXISTS `idx_posts_circle`",
            "ALTER TABLE `posts` DROP COLUMN IF EXISTS `reaction_count`",
            "ALTER TABLE `posts` DROP COLUMN IF EXISTS `post_type`",
            "ALTER TABLE `posts` DROP COLUMN IF EXISTS `circle_id`",
        ];

        foreach ($alterDowns as $sql) {
            try { $this->db->query($sql); } catch (\Throwable $e) {}
        }

        // Drop new tables in reverse dependency order
        foreach ($this->newTables as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
