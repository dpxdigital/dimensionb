<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * RSS Feed Aggregator schema
 *
 * New tables:
 *   rss_feeds    — registered feed sources (name, XML URL, active flag)
 *   rss_articles — fetched articles, deduped by (feed_id, guid)
 */
class CreateRssFeedsTables extends Migration
{
    public function up(): void
    {
        // ── 1. rss_feeds ──────────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `rss_feeds` (
                `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
                `name`            VARCHAR(100)    NOT NULL,
                `url`             TEXT            NOT NULL,
                `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
                `last_fetched_at` DATETIME        DEFAULT NULL,
                `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_rss_feeds_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── 2. rss_articles ───────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `rss_articles` (
                `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
                `feed_id`      INT UNSIGNED    NOT NULL,
                `guid`         VARCHAR(512)    NOT NULL,
                `title`        VARCHAR(512)    NOT NULL,
                `description`  TEXT            DEFAULT NULL,
                `content`      LONGTEXT        DEFAULT NULL,
                `url`          TEXT            NOT NULL,
                `image_url`    TEXT            DEFAULT NULL,
                `published_at` DATETIME        DEFAULT NULL,
                `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_rss_article_guid` (`feed_id`, `guid`(191)),
                KEY `idx_rss_articles_feed_pub` (`feed_id`, `published_at` DESC),
                CONSTRAINT `fk_rss_articles_feed`
                    FOREIGN KEY (`feed_id`) REFERENCES `rss_feeds` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `rss_articles`");
        $this->db->query("DROP TABLE IF EXISTS `rss_feeds`");
    }
}
