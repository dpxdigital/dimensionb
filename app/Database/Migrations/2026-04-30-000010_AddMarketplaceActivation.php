<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddMarketplaceActivation extends Migration
{
    public function up(): void
    {
        // ── platform_settings (admin-configurable key/value store) ────────────
        if (! $this->db->tableExists('platform_settings')) {
            $this->forge->addField([
                'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'key'        => ['type' => 'VARCHAR', 'constraint' => 100, 'unique' => true],
                'value'      => ['type' => 'TEXT', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->createTable('platform_settings');

            // Seed defaults
            db_connect()->table('platform_settings')->insertBatch([
                ['key' => 'activation_fee_amount',   'value' => '9.99',   'updated_at' => date('Y-m-d H:i:s')],
                ['key' => 'activation_fee_currency', 'value' => 'usd',    'updated_at' => date('Y-m-d H:i:s')],
                ['key' => 'platform_stripe_key',     'value' => '',       'updated_at' => date('Y-m-d H:i:s')],
                ['key' => 'platform_stripe_secret',  'value' => '',       'updated_at' => date('Y-m-d H:i:s')],
            ]);
        }

        // ── Add activation columns to vendors ─────────────────────────────────
        $newCols = [
            'is_approved'               => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'is_active'],
            'activation_fee_paid'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'is_approved'],
            'activation_fee_amount'     => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true, 'after' => 'activation_fee_paid'],
            'activation_payment_intent' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'activation_fee_amount'],
            'activation_session_id'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'activation_payment_intent'],
            'rejection_reason'          => ['type' => 'TEXT', 'null' => true, 'after' => 'activation_session_id'],
        ];

        foreach ($newCols as $col => $def) {
            if (! $this->db->fieldExists($col, 'vendors')) {
                $this->forge->addColumn('vendors', [$col => $def]);
            }
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('platform_settings', true);
        $cols = ['is_approved', 'activation_fee_paid', 'activation_fee_amount',
                 'activation_payment_intent', 'activation_session_id', 'rejection_reason'];
        foreach ($cols as $col) {
            if ($this->db->fieldExists($col, 'vendors')) {
                $this->forge->dropColumn('vendors', $col);
            }
        }
    }
}
