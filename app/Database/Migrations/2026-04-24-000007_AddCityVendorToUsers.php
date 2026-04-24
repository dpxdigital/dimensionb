<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCityVendorToUsers extends Migration
{
    public function up(): void
    {
        // Add city and is_vendor columns if not already present
        $fields = [
            'city'      => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'location'],
            'is_vendor' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'city'],
        ];
        $this->forge->addColumn('users', $fields);
    }

    public function down(): void
    {
        $this->forge->dropColumn('users', ['city', 'is_vendor']);
    }
}
