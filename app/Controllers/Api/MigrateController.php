<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;

// One-shot migration runner — DELETE THIS FILE after use
class MigrateController extends BaseApiController
{
    public function run(): ResponseInterface
    {
        $db = db_connect();
        $results = [];
        try {
            $db->query("
                ALTER TABLE `circles`
                ADD COLUMN IF NOT EXISTS `circle_type`     VARCHAR(50) NOT NULL DEFAULT 'interest_based' AFTER `visibility`,
                ADD COLUMN IF NOT EXISTS `who_can_post`    VARCHAR(50) NOT NULL DEFAULT 'all_members'    AFTER `circle_type`,
                ADD COLUMN IF NOT EXISTS `who_can_discuss` VARCHAR(50) NOT NULL DEFAULT 'all_members'    AFTER `who_can_post`
            ");
            $results[] = 'Added circle_type, who_can_post, who_can_discuss columns';
        } catch (\Throwable $e) {
            $results[] = 'columns: ' . $e->getMessage();
        }
        try {
            $db->query("
                ALTER TABLE `circles`
                MODIFY COLUMN `visibility` ENUM('public','private','invite','invite_only') NOT NULL DEFAULT 'public',
                MODIFY COLUMN `status`     ENUM('active','pending','suspended','deleted')  NOT NULL DEFAULT 'active'
            ");
            $results[] = 'Updated visibility and status enums';
        } catch (\Throwable $e) {
            $results[] = 'enums: ' . $e->getMessage();
        }
        return $this->success($results, 'Done');
    }
}
