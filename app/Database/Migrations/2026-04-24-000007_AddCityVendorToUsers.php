<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCityVendorToUsers extends Migration
{
    public function up(): void
    {
        $existing = $this->db->getFieldNames('users');
        $fields = [];
        if (!in_array('city', $existing)) {
            $fields['city'] = ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'location'];
        }
        if (!in_array('is_vendor', $existing)) {
            $fields['is_vendor'] = ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'city'];
        }
        if (!empty($fields)) {
            $this->forge->addColumn('users', $fields);
        }
    }

    public function down(): void
    {
        $this->forge->dropColumn('users', ['city', 'is_vendor']);
    }
}
