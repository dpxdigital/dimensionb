<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAppContent extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'content_key' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'title'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'content'     => ['type' => 'MEDIUMTEXT', 'null' => false],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('content_key');
        $this->forge->createTable('app_content', true);

        // Seed default content
        $now = date('Y-m-d H:i:s');
        $this->db->table('app_content')->insertBatch([
            [
                'content_key' => 'privacy-policy',
                'title'       => 'Privacy Policy',
                'content'     => "## Privacy Policy\n\nLast updated by the Dimensions team.\n\n### Information We Collect\nWe collect information you provide when you create an account, post content, or interact with the platform.\n\n### How We Use Your Information\n- To provide and improve the Dimensions platform\n- To send notifications about content relevant to your interests\n- To ensure safety and prevent abuse\n\n### Data Sharing\nWe do not sell your personal data. We share information only with service providers who help us operate the platform.\n\n### Your Rights\nYou may request access to or deletion of your personal data by contacting support.\n\n### Contact\nFor privacy inquiries, contact us through the app.",
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'content_key' => 'terms',
                'title'       => 'Terms and Conditions',
                'content'     => "## Terms and Conditions\n\nBy using Dimensions you agree to these terms.\n\n### Use of the Platform\n- You must be 13 years or older to use Dimensions\n- You are responsible for your account and activity\n- You may not post illegal, harmful, or misleading content\n\n### Content Ownership\nYou retain ownership of content you post. By posting, you grant Dimensions a licence to display it on the platform.\n\n### Prohibited Conduct\n- Harassment or threatening behaviour\n- Spam or automated abuse\n- Impersonation of others\n\n### Termination\nWe reserve the right to suspend accounts that violate these terms.\n\n### Changes\nWe may update these terms. Continued use after changes constitutes acceptance.\n\n### Contact\nFor questions, contact support through the app.",
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('app_content', true);
    }
}
