<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddLastReadAtToConversationMembers extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('conversation_members', [
            'last_read_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
                'after'   => 'joined_at',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('conversation_members', 'last_read_at');
    }
}
