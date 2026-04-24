<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class LiveSessionSeeder extends Seeder
{
    public function run(): void
    {
        if ($this->db->table('live_sessions')->countAllResults() > 0) {
            return;
        }

        // Requires at least one user in the users table
        $user = $this->db->table('users')->limit(1)->get()->getRowArray();
        if ($user === null) {
            echo "  LiveSessionSeeder: no users found — skipping.\n";
            return;
        }

        $this->db->table('live_sessions')->insert([
            'host_id'       => $user['id'],
            'title'         => 'Tech Talk: Building Apps for Africa',
            'category'      => 'Technology',
            'agora_channel' => 'dim_demo_' . bin2hex(random_bytes(4)),
            'agora_token'   => 'DEMO_TOKEN_REPLACE_WITH_REAL',
            'viewer_count'  => 14,
            'status'        => 'active',
            'started_at'    => date('Y-m-d H:i:s', strtotime('-30 minutes')),
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        echo "  LiveSessionSeeder: inserted 1 sample active session.\n";
    }
}
