<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateChapterMessages extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('chapter_messages')) return;

        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'chapter_id'  => ['type' => 'BIGINT', 'unsigned' => true],
            'user_id'     => ['type' => 'BIGINT', 'unsigned' => true],
            'body'        => ['type' => 'TEXT', 'null' => true],
            'media_url'   => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
            'media_type'  => ['type' => 'ENUM', 'constraint' => ['image', 'video'], 'null' => true],
            'reply_to_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'is_deleted'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['chapter_id', 'created_at']);
        $this->forge->addKey('user_id');
        $this->forge->createTable('chapter_messages');

        // Reactions
        if (! $this->db->tableExists('chapter_message_reactions')) {
            $this->forge->addField([
                'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'message_id' => ['type' => 'BIGINT', 'unsigned' => true],
                'user_id'    => ['type' => 'BIGINT', 'unsigned' => true],
                'emoji'      => ['type' => 'VARCHAR', 'constraint' => 10],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey(['message_id', 'user_id', 'emoji']);
            $this->forge->createTable('chapter_message_reactions');
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('chapter_message_reactions', true);
        $this->forge->dropTable('chapter_messages', true);
    }
}
