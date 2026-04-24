<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateChaptersCensusMarketplace extends Migration
{
    public function up(): void
    {
        // ── Chapters ──────────────────────────────────────────────────────────
        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'name'         => ['type' => 'VARCHAR', 'constraint' => 255],
            'slug'         => ['type' => 'VARCHAR', 'constraint' => 255],
            'description'  => ['type' => 'TEXT', 'null' => true],
            'city'         => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'state'        => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'country'      => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => 'US'],
            'category'     => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'image_url'    => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'member_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'event_count'  => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'post_count'   => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'is_active'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
            'created_by'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey(['city', 'state']);
        $this->forge->createTable('chapters', true);

        // ── Chapter members ───────────────────────────────────────────────────
        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'chapter_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'user_id'    => ['type' => 'BIGINT', 'unsigned' => true],
            'role'       => ['type' => 'ENUM', 'constraint' => ['member','admin','moderator'], 'default' => 'member'],
            'joined_at'  => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['chapter_id', 'user_id']);
        $this->forge->addKey('user_id');
        $this->forge->createTable('chapter_members', true);

        // ── Black Census ──────────────────────────────────────────────────────
        $this->forge->addField([
            'id'             => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'        => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'first_name'     => ['type' => 'VARCHAR', 'constraint' => 100],
            'last_name'      => ['type' => 'VARCHAR', 'constraint' => 100],
            'email'          => ['type' => 'VARCHAR', 'constraint' => 255],
            'phone'          => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'date_of_birth'  => ['type' => 'DATE', 'null' => true],
            'gender'         => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'city'           => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'state'          => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'zip'            => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'chapter_id'     => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'interests'      => ['type' => 'JSON', 'null' => true],
            'sms_updates'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'email_updates'  => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('email');
        $this->forge->addKey('user_id');
        $this->forge->createTable('census_records', true);

        // ── Vendors ───────────────────────────────────────────────────────────
        $this->forge->addField([
            'id'            => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'       => ['type' => 'BIGINT', 'unsigned' => true],
            'name'          => ['type' => 'VARCHAR', 'constraint' => 255],
            'slug'          => ['type' => 'VARCHAR', 'constraint' => 255],
            'description'   => ['type' => 'TEXT', 'null' => true],
            'category'      => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'banner_url'    => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'logo_url'      => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'contact_email' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'contact_phone' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'rating'        => ['type' => 'DECIMAL', 'constraint' => '3,2', 'default' => 0],
            'is_active'     => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey('user_id');
        $this->forge->createTable('vendors', true);

        // ── Products ──────────────────────────────────────────────────────────
        $this->forge->addField([
            'id'             => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'vendor_id'      => ['type' => 'BIGINT', 'unsigned' => true],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 255],
            'description'    => ['type' => 'TEXT', 'null' => true],
            'price'          => ['type' => 'DECIMAL', 'constraint' => '10,2'],
            'images'         => ['type' => 'JSON', 'null' => true],
            'category'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'stock_quantity' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'is_available'   => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('vendor_id');
        $this->forge->addKey('category');
        $this->forge->createTable('products', true);

        // ── Cart items ────────────────────────────────────────────────────────
        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'BIGINT', 'unsigned' => true],
            'product_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'quantity'   => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['user_id', 'product_id']);
        $this->forge->createTable('cart_items', true);

        // ── Orders ────────────────────────────────────────────────────────────
        $this->forge->addField([
            'id'             => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'buyer_id'       => ['type' => 'BIGINT', 'unsigned' => true],
            'vendor_id'      => ['type' => 'BIGINT', 'unsigned' => true],
            'status'         => ['type' => 'ENUM', 'constraint' => ['pending','confirmed','shipped','delivered','cancelled'], 'default' => 'pending'],
            'total_amount'   => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0],
            'delivery_note'  => ['type' => 'TEXT', 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('buyer_id');
        $this->forge->addKey('vendor_id');
        $this->forge->createTable('orders', true);

        // ── Order items ───────────────────────────────────────────────────────
        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'order_id'   => ['type' => 'BIGINT', 'unsigned' => true],
            'product_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'quantity'   => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
            'unit_price' => ['type' => 'DECIMAL', 'constraint' => '10,2'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('order_id');
        $this->forge->createTable('order_items', true);

        // ── Add cover_url to submissions if missing ───────────────────────────
        if (! $this->db->fieldExists('cover_url', 'submissions')) {
            $this->forge->addColumn('submissions', [
                'cover_url' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'after' => 'source_url'],
            ]);
        }

        // ── Add is_vendor to users ────────────────────────────────────────────
        if (! $this->db->fieldExists('is_vendor', 'users')) {
            $this->forge->addColumn('users', [
                'is_vendor' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0, 'after' => 'is_active'],
            ]);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('order_items', true);
        $this->forge->dropTable('orders', true);
        $this->forge->dropTable('cart_items', true);
        $this->forge->dropTable('products', true);
        $this->forge->dropTable('vendors', true);
        $this->forge->dropTable('census_records', true);
        $this->forge->dropTable('chapter_members', true);
        $this->forge->dropTable('chapters', true);
    }
}
