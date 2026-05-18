<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddReplyToIdToMessages extends Migration
{
    public function up(): void
    {
        $cols = array_column($this->db->getFieldData($this->db->getPrefix() . 'messages'), 'name');
        if (! in_array('reply_to_id', $cols, true)) {
            $this->db->query("ALTER TABLE `messages` ADD COLUMN `reply_to_id` INT UNSIGNED DEFAULT NULL AFTER `listing_id`");
        }
    }

    public function down(): void
    {
        $this->db->query("ALTER TABLE `messages` DROP COLUMN `reply_to_id`");
    }
}
