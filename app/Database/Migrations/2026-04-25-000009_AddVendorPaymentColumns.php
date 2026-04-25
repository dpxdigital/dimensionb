<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddVendorPaymentColumns extends Migration
{
    public function up(): void
    {
        $fields = [
            'stripe_enabled'          => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0,    'after' => 'is_active'],
            'paypal_enabled'          => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0,    'after' => 'stripe_enabled'],
            'flutterwave_enabled'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0,    'after' => 'paypal_enabled'],
            'stripe_publishable_key'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true,  'after' => 'flutterwave_enabled'],
            'stripe_secret_key'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true,  'after' => 'stripe_publishable_key'],
            'paypal_client_id'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true,  'after' => 'stripe_secret_key'],
            'paypal_client_secret'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true,  'after' => 'paypal_client_id'],
            'flutterwave_public_key'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true,  'after' => 'paypal_client_secret'],
            'flutterwave_secret_key'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true,  'after' => 'flutterwave_public_key'],
            'free_shipping'           => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0,    'after' => 'flutterwave_secret_key'],
            'shipping_rate'           => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0.00, 'after' => 'free_shipping'],
            'delivery_time'           => ['type' => 'VARCHAR', 'constraint' => 50, 'default' => '3-5 business days', 'after' => 'shipping_rate'],
            'is_activated'            => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0,    'after' => 'delivery_time'],
        ];

        foreach ($fields as $col => $def) {
            if (! $this->db->fieldExists($col, 'vendors')) {
                $this->forge->addColumn('vendors', [$col => $def]);
            }
        }
    }

    public function down(): void
    {
        $cols = [
            'stripe_enabled', 'paypal_enabled', 'flutterwave_enabled',
            'stripe_publishable_key', 'stripe_secret_key',
            'paypal_client_id', 'paypal_client_secret',
            'flutterwave_public_key', 'flutterwave_secret_key',
            'free_shipping', 'shipping_rate', 'delivery_time', 'is_activated',
        ];
        foreach ($cols as $col) {
            if ($this->db->fieldExists($col, 'vendors')) {
                $this->forge->dropColumn('vendors', $col);
            }
        }
    }
}
