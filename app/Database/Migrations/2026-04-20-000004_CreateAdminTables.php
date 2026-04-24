<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAdminTables extends Migration
{
    public function up(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

        // ── admin_users ────────────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `admin_users` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`       VARCHAR(150) NOT NULL,
                `email`      VARCHAR(255) NOT NULL,
                `password`   VARCHAR(255) NOT NULL,
                `role`       ENUM('super_admin','moderator') NOT NULL DEFAULT 'moderator',
                `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
                `last_login` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_admin_email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── admin_audit_log ────────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `admin_audit_log` (
                `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `admin_id`       INT UNSIGNED NOT NULL,
                `action`         VARCHAR(100) NOT NULL,
                `reference_type` VARCHAR(60)  DEFAULT NULL,
                `reference_id`   INT UNSIGNED DEFAULT NULL,
                `detail`         TEXT         DEFAULT NULL,
                `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_aal_admin`   (`admin_id`),
                KEY `idx_aal_action`  (`action`),
                CONSTRAINT `fk_aal_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ── notification_broadcasts ────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `notification_broadcasts` (
                `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `admin_id`       INT UNSIGNED NOT NULL,
                `title`          VARCHAR(255) NOT NULL,
                `body`           TEXT NOT NULL,
                `target_type`    ENUM('all','user','category') NOT NULL DEFAULT 'all',
                `target_value`   VARCHAR(255) DEFAULT NULL,
                `deep_link`      VARCHAR(500) DEFAULT NULL,
                `delivery_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                CONSTRAINT `fk_nb_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');

        // Seed default super admin (password: Admin@2025)
        $hash = password_hash('Admin@2025', PASSWORD_BCRYPT, ['cost' => 12]);
        $this->db->query("
            INSERT IGNORE INTO `admin_users` (`name`, `email`, `password`, `role`)
            VALUES ('Super Admin', 'admin@dimensions.global', '$hash', 'super_admin')
        ");
    }

    public function down(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['notification_broadcasts', 'admin_audit_log', 'admin_users'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
