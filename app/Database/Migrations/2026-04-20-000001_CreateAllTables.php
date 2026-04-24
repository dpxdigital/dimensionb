<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAllTables extends Migration
{
    public function up(): void
    {
        // ── 1. users ─────────────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `users` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`          VARCHAR(120) NOT NULL,
                `email`         VARCHAR(191) NOT NULL,
                `phone`         VARCHAR(30)  DEFAULT NULL,
                `password_hash` VARCHAR(255) NOT NULL,
                `avatar_url`    VARCHAR(500) DEFAULT NULL,
                `cover_url`     VARCHAR(500) DEFAULT NULL,
                `bio`           TEXT         DEFAULT NULL,
                `location`      VARCHAR(255) DEFAULT NULL,
                `lat`           DECIMAL(10,7) DEFAULT NULL,
                `lng`           DECIMAL(10,7) DEFAULT NULL,
                `trust_level`   ENUM('institution_verified','curator_reviewed','community_submitted','approved_live_host','needs_reconfirmation') DEFAULT 'community_submitted',
                `trust_label`   VARCHAR(100) DEFAULT NULL,
                `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
                `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_users_email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 2. user_interests ─────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `user_interests` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`    INT UNSIGNED NOT NULL,
                `interest`   VARCHAR(100) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_user_interest` (`user_id`, `interest`),
                KEY `idx_user_interests_user` (`user_id`),
                CONSTRAINT `fk_ui_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 3. categories ─────────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `categories` (
                `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`      VARCHAR(100) NOT NULL,
                `slug`      VARCHAR(100) NOT NULL,
                `icon_name` VARCHAR(100) DEFAULT NULL,
                `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_categories_slug` (`slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 4. organizations ──────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `organizations` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`        VARCHAR(200) NOT NULL,
                `description` TEXT         DEFAULT NULL,
                `logo_url`    VARCHAR(500) DEFAULT NULL,
                `website`     VARCHAR(500) DEFAULT NULL,
                `trust_level` ENUM('institution_verified','curator_reviewed','community_submitted','approved_live_host','needs_reconfirmation') DEFAULT 'community_submitted',
                `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 5. trust_labels ───────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `trust_labels` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `label`       VARCHAR(100) NOT NULL,
                `trust_level` ENUM('institution_verified','curator_reviewed','community_submitted','approved_live_host','needs_reconfirmation') NOT NULL,
                `color_hex`   CHAR(7)      DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_trust_labels_label` (`label`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 6. listings ───────────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `listings` (
                `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `title`        VARCHAR(255) NOT NULL,
                `description`  TEXT         NOT NULL,
                `category_id`  INT UNSIGNED DEFAULT NULL,
                `org_name`     VARCHAR(200) DEFAULT NULL,
                `org_id`       INT UNSIGNED DEFAULT NULL,
                `trust_level`  ENUM('institution_verified','curator_reviewed','community_submitted','approved_live_host','needs_reconfirmation') NOT NULL DEFAULT 'community_submitted',
                `trust_label`  VARCHAR(100) DEFAULT NULL,
                `location`     VARCHAR(255) DEFAULT NULL,
                `lat`          DECIMAL(10,7) DEFAULT NULL,
                `lng`          DECIMAL(10,7) DEFAULT NULL,
                `date`         DATETIME     DEFAULT NULL,
                `deadline`     DATETIME     DEFAULT NULL,
                `action_type`  ENUM('rsvp','save','apply','external') NOT NULL DEFAULT 'save',
                `external_url` VARCHAR(500) DEFAULT NULL,
                `is_live`      TINYINT(1) NOT NULL DEFAULT 0,
                `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
                `like_count`   INT UNSIGNED NOT NULL DEFAULT 0,
                `comment_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `rsvp_count`   INT UNSIGNED NOT NULL DEFAULT 0,
                `submitted_by` INT UNSIGNED DEFAULT NULL,
                `status`       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                `cover_url`    VARCHAR(500) DEFAULT NULL,
                `tags`         JSON         DEFAULT NULL,
                `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_listings_category_trust_date` (`category_id`, `trust_level`, `date`, `is_active`),
                KEY `idx_listings_status_active` (`status`, `is_active`),
                KEY `idx_listings_submitted_by` (`submitted_by`),
                FULLTEXT KEY `ft_listings_title_desc` (`title`, `description`),
                CONSTRAINT `fk_listings_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_listings_org` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_listings_user` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Spatial index requires separate statement (after table creation)
        $this->db->query("ALTER TABLE `listings` ADD SPATIAL INDEX `idx_listings_location` ((POINT(`lat`, `lng`)))") ;
        // Note: spatial on DECIMAL columns isn't supported directly; use a generated POINT column
        // We add a generated point column for spatial queries
        $this->db->query("ALTER TABLE `listings` ADD COLUMN `geo_point` POINT GENERATED ALWAYS AS (ST_SRID(POINT(`lng`, `lat`), 4326)) STORED") ;
        $this->db->query("ALTER TABLE `listings` DROP INDEX `idx_listings_location`");
        $this->db->query("ALTER TABLE `listings` ADD SPATIAL INDEX `idx_listings_geo` (`geo_point`)");

        // ── 7. listing_likes ──────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `listing_likes` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `listing_id` INT UNSIGNED NOT NULL,
                `user_id`    INT UNSIGNED NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_listing_likes` (`listing_id`, `user_id`),
                KEY `idx_listing_likes_user` (`user_id`),
                CONSTRAINT `fk_ll_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_ll_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 8. listing_saves ──────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `listing_saves` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `listing_id` INT UNSIGNED NOT NULL,
                `user_id`    INT UNSIGNED NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_listing_saves` (`listing_id`, `user_id`),
                KEY `idx_listing_saves_user` (`user_id`),
                CONSTRAINT `fk_ls_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_ls_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 9. listing_rsvps ──────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `listing_rsvps` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `listing_id` INT UNSIGNED NOT NULL,
                `user_id`    INT UNSIGNED NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_listing_rsvps` (`listing_id`, `user_id`),
                KEY `idx_listing_rsvps_user` (`user_id`),
                CONSTRAINT `fk_lr_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_lr_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 10. listing_applications ──────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `listing_applications` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `listing_id` INT UNSIGNED NOT NULL,
                `user_id`    INT UNSIGNED NOT NULL,
                `note`       TEXT         DEFAULT NULL,
                `status`     ENUM('submitted','reviewed','accepted','rejected') NOT NULL DEFAULT 'submitted',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_listing_applications` (`listing_id`, `user_id`),
                KEY `idx_la_user` (`user_id`),
                CONSTRAINT `fk_la_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_la_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 11. listing_comments ──────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `listing_comments` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `listing_id` INT UNSIGNED NOT NULL,
                `user_id`    INT UNSIGNED NOT NULL,
                `body`       TEXT         NOT NULL,
                `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_lc_listing` (`listing_id`, `created_at`),
                KEY `idx_lc_user`    (`user_id`),
                CONSTRAINT `fk_lc_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_lc_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 12. live_sessions ─────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `live_sessions` (
                `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `host_id`           INT UNSIGNED NOT NULL,
                `title`             VARCHAR(255) NOT NULL,
                `category`          VARCHAR(100) DEFAULT NULL,
                `linked_listing_id` INT UNSIGNED DEFAULT NULL,
                `agora_channel`     VARCHAR(255) NOT NULL,
                `agora_token`       TEXT         DEFAULT NULL,
                `viewer_count`      INT UNSIGNED NOT NULL DEFAULT 0,
                `status`            ENUM('pending','active','ended') NOT NULL DEFAULT 'pending',
                `replay_url`        VARCHAR(500) DEFAULT NULL,
                `started_at`        DATETIME     DEFAULT NULL,
                `ended_at`          DATETIME     DEFAULT NULL,
                `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_live_status_started` (`status`, `started_at`),
                KEY `idx_live_host` (`host_id`),
                CONSTRAINT `fk_live_host`    FOREIGN KEY (`host_id`)           REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_live_listing` FOREIGN KEY (`linked_listing_id`) REFERENCES `listings` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 13. live_comments ─────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `live_comments` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `session_id` INT UNSIGNED NOT NULL,
                `user_id`    INT UNSIGNED NOT NULL,
                `body`       VARCHAR(500) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_livec_session` (`session_id`, `created_at`),
                CONSTRAINT `fk_livec_session` FOREIGN KEY (`session_id`) REFERENCES `live_sessions` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_livec_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 14. live_reactions ────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `live_reactions` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `session_id` INT UNSIGNED NOT NULL,
                `user_id`    INT UNSIGNED NOT NULL,
                `emoji`      VARCHAR(10)  NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_liver_session` (`session_id`),
                CONSTRAINT `fk_liver_session` FOREIGN KEY (`session_id`) REFERENCES `live_sessions` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_liver_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 15. live_cohosts ──────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `live_cohosts` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `session_id` INT UNSIGNED NOT NULL,
                `user_id`    INT UNSIGNED NOT NULL,
                `joined_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_live_cohosts` (`session_id`, `user_id`),
                CONSTRAINT `fk_lch_session` FOREIGN KEY (`session_id`) REFERENCES `live_sessions` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_lch_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 16. submissions ───────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `submissions` (
                `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `type`         ENUM('event','cause','program','scholarship','other') NOT NULL DEFAULT 'event',
                `title`        VARCHAR(255) NOT NULL,
                `org_name`     VARCHAR(200) DEFAULT NULL,
                `description`  TEXT         NOT NULL,
                `date`         DATETIME     DEFAULT NULL,
                `location`     VARCHAR(255) DEFAULT NULL,
                `source_url`   VARCHAR(500) DEFAULT NULL,
                `status`       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                `trust_label`  VARCHAR(100) DEFAULT NULL,
                `submitted_by` INT UNSIGNED NOT NULL,
                `listing_id`   INT UNSIGNED DEFAULT NULL,
                `reviewer_note` TEXT DEFAULT NULL,
                `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_submissions_user`   (`submitted_by`),
                KEY `idx_submissions_status` (`status`),
                CONSTRAINT `fk_sub_user`    FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_sub_listing` FOREIGN KEY (`listing_id`)   REFERENCES `listings` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 17. moderation_queue ──────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `moderation_queue` (
                `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `reference_type` ENUM('listing','submission','comment','live_session','user') NOT NULL,
                `reference_id`   INT UNSIGNED NOT NULL,
                `reported_by`    INT UNSIGNED DEFAULT NULL,
                `reason`         VARCHAR(500) DEFAULT NULL,
                `status`         ENUM('pending','reviewed','dismissed') NOT NULL DEFAULT 'pending',
                `reviewer_id`    INT UNSIGNED DEFAULT NULL,
                `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_modq_status` (`status`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 18. notifications ─────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `notifications` (
                `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`        INT UNSIGNED NOT NULL,
                `type`           VARCHAR(60)  NOT NULL,
                `title`          VARCHAR(255) NOT NULL,
                `body`           TEXT         NOT NULL,
                `reference_id`   INT UNSIGNED DEFAULT NULL,
                `reference_type` VARCHAR(60)  DEFAULT NULL,
                `is_read`        TINYINT(1) NOT NULL DEFAULT 0,
                `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_notif_user_read_date` (`user_id`, `is_read`, `created_at`),
                CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 19. activity_log ──────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `activity_log` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`     INT UNSIGNED NOT NULL,
                `listing_id`  INT UNSIGNED NOT NULL,
                `action_type` ENUM('save','rsvp','share','apply','like') NOT NULL,
                `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_activity_user_date` (`user_id`, `created_at`),
                KEY `idx_activity_listing`   (`listing_id`),
                CONSTRAINT `fk_act_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_act_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 20. refresh_tokens ────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `refresh_tokens` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`    INT UNSIGNED NOT NULL,
                `token_hash` VARCHAR(255) NOT NULL,
                `expires_at` DATETIME     NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `revoked_at` DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_refresh_token` (`token_hash`),
                KEY `idx_rt_user` (`user_id`),
                CONSTRAINT `fk_rt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 21. fcm_tokens ────────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `fcm_tokens` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`    INT UNSIGNED NOT NULL,
                `token`      VARCHAR(500) NOT NULL,
                `platform`   ENUM('android','ios') DEFAULT NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_fcm_token` (`token`(191)),
                KEY `idx_fcm_user` (`user_id`),
                CONSTRAINT `fk_fcm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 22. followup_prompts ──────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `followup_prompts` (
                `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`        INT UNSIGNED NOT NULL,
                `listing_id`     INT UNSIGNED NOT NULL,
                `prompt_text`    TEXT         NOT NULL,
                `action_type`    VARCHAR(60)  NOT NULL,
                `is_dismissed`   TINYINT(1) NOT NULL DEFAULT 0,
                `scheduled_for`  DATETIME     DEFAULT NULL,
                `sent_at`        DATETIME     DEFAULT NULL,
                `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_fp_user`      (`user_id`),
                KEY `idx_fp_scheduled` (`scheduled_for`, `is_dismissed`),
                CONSTRAINT `fk_fp_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_fp_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 23. connections ───────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `connections` (
                `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `requester_id` INT UNSIGNED NOT NULL,
                `receiver_id`  INT UNSIGNED NOT NULL,
                `status`       ENUM('pending','accepted','declined','blocked') NOT NULL DEFAULT 'pending',
                `context_type` VARCHAR(60)  DEFAULT NULL,
                `context_id`   INT UNSIGNED DEFAULT NULL,
                `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_connections_pair` (`requester_id`, `receiver_id`),
                KEY `idx_conn_receiver_status` (`receiver_id`, `status`),
                KEY `idx_conn_requester`       (`requester_id`),
                CONSTRAINT `fk_conn_requester` FOREIGN KEY (`requester_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_conn_receiver`  FOREIGN KEY (`receiver_id`)  REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 24. conversations ─────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `conversations` (
                `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `type`            ENUM('direct','group') NOT NULL DEFAULT 'direct',
                `created_by`      INT UNSIGNED NOT NULL,
                `name`            VARCHAR(200) DEFAULT NULL,
                `avatar_url`      VARCHAR(500) DEFAULT NULL,
                `last_message_at` DATETIME     DEFAULT NULL,
                `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_conv_last_msg` (`last_message_at` DESC),
                CONSTRAINT `fk_conv_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 25. conversation_members ──────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `conversation_members` (
                `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `conversation_id` INT UNSIGNED NOT NULL,
                `user_id`         INT UNSIGNED NOT NULL,
                `is_admin`        TINYINT(1) NOT NULL DEFAULT 0,
                `is_muted`        TINYINT(1) NOT NULL DEFAULT 0,
                `joined_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_conv_member` (`conversation_id`, `user_id`),
                KEY `idx_cm_user` (`user_id`),
                CONSTRAINT `fk_cm_conv` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_cm_user` FOREIGN KEY (`user_id`)         REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 26. messages ──────────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `messages` (
                `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `conversation_id` INT UNSIGNED NOT NULL,
                `sender_id`       INT UNSIGNED NOT NULL,
                `type`            ENUM('text','image','file','listing_share','system') NOT NULL DEFAULT 'text',
                `body`            TEXT         DEFAULT NULL,
                `file_url`        VARCHAR(500) DEFAULT NULL,
                `file_name`       VARCHAR(255) DEFAULT NULL,
                `file_size`       INT UNSIGNED DEFAULT NULL,
                `file_mime`       VARCHAR(127) DEFAULT NULL,
                `listing_id`      INT UNSIGNED DEFAULT NULL,
                `is_deleted`      TINYINT(1) NOT NULL DEFAULT 0,
                `deleted_for_all` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_msg_conv_date` (`conversation_id`, `created_at` DESC),
                KEY `idx_msg_sender`    (`sender_id`),
                CONSTRAINT `fk_msg_conv`    FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_msg_sender`  FOREIGN KEY (`sender_id`)       REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_msg_listing` FOREIGN KEY (`listing_id`)      REFERENCES `listings` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 27. message_reactions ─────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `message_reactions` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `message_id` INT UNSIGNED NOT NULL,
                `user_id`    INT UNSIGNED NOT NULL,
                `emoji`      VARCHAR(10)  NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_msg_reaction` (`message_id`, `user_id`, `emoji`),
                KEY `idx_mr_message` (`message_id`),
                CONSTRAINT `fk_mr_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_mr_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 28. message_read_receipts ─────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `message_read_receipts` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `message_id` INT UNSIGNED NOT NULL,
                `user_id`    INT UNSIGNED NOT NULL,
                `read_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_read_receipt` (`message_id`, `user_id`),
                KEY `idx_rr_user`    (`user_id`),
                KEY `idx_rr_message` (`message_id`),
                CONSTRAINT `fk_rr_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_rr_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        // Drop in reverse order to respect foreign key constraints
        $tables = [
            'message_read_receipts',
            'message_reactions',
            'messages',
            'conversation_members',
            'conversations',
            'connections',
            'followup_prompts',
            'fcm_tokens',
            'refresh_tokens',
            'activity_log',
            'notifications',
            'moderation_queue',
            'submissions',
            'live_cohosts',
            'live_reactions',
            'live_comments',
            'live_sessions',
            'listing_comments',
            'listing_applications',
            'listing_rsvps',
            'listing_saves',
            'listing_likes',
            'listings',
            'trust_labels',
            'organizations',
            'categories',
            'user_interests',
            'users',
        ];

        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $this->forge->dropTable($table, true);
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
