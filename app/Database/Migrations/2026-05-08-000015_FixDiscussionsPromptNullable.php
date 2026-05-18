<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class FixDiscussionsPromptNullable extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE discussions MODIFY COLUMN prompt TEXT DEFAULT NULL');
    }

    public function down(): void
    {
        $this->db->query("UPDATE discussions SET prompt = '' WHERE prompt IS NULL");
        $this->db->query("ALTER TABLE discussions MODIFY COLUMN prompt TEXT NOT NULL DEFAULT ''");
    }
}
