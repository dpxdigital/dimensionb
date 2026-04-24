<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddNotificationPrefsToUsers extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('users', [
            'pref_event_reminders' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
                'after'      => 'is_active',
            ],
            'pref_live_alerts' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
                'after'      => 'pref_event_reminders',
            ],
            'pref_new_matches' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
                'after'      => 'pref_live_alerts',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('users', ['pref_event_reminders', 'pref_live_alerts', 'pref_new_matches']);
    }
}
