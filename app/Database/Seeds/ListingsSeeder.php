<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class ListingsSeeder extends Seeder
{
    public function run(): void
    {
        if ($this->db->table('listings')->countAllResults() > 0) {
            return;
        }

        // Fetch category IDs by slug
        $cat = fn(string $slug): ?int => ($this->db->table('categories')
            ->select('id')->where('slug', $slug)->get()->getRowArray()['id'] ?? null);

        $listings = [
            [
                'title'       => 'Youth Tech Bootcamp 2026',
                'description' => 'A 3-day intensive coding bootcamp for youths aged 15–25. Learn web development, AI basics, and build a real project. Scholarships available.',
                'category_id' => $cat('technology'),
                'org_name'    => 'TechForward Africa',
                'trust_level' => 'institution_verified',
                'trust_label' => 'Institution Verified',
                'location'    => 'Lagos, Nigeria',
                'lat'         => 6.5244,
                'lng'         => 3.3792,
                'date'        => '2026-05-10 09:00:00',
                'deadline'    => '2026-05-01 23:59:59',
                'action_type' => 'rsvp',
                'is_live'     => 0,
                'is_active'   => 1,
                'status'      => 'approved',
                'like_count'  => 42,
                'comment_count' => 8,
            ],
            [
                'title'       => 'Community Health Fair — Free Checkups',
                'description' => 'Free blood pressure, diabetes, and vision screening for all residents. Sponsored by Westside Clinic. Bring your family.',
                'category_id' => $cat('health'),
                'org_name'    => 'Westside Community Clinic',
                'trust_level' => 'curator_reviewed',
                'trust_label' => 'Curator Reviewed',
                'location'    => 'Abuja, Nigeria',
                'lat'         => 9.0765,
                'lng'         => 7.3986,
                'date'        => '2026-05-15 08:00:00',
                'deadline'    => null,
                'action_type' => 'rsvp',
                'is_live'     => 0,
                'is_active'   => 1,
                'status'      => 'approved',
                'like_count'  => 19,
                'comment_count' => 3,
            ],
            [
                'title'       => 'STEM Scholarship — University of Ibadan',
                'description' => 'Full scholarship covering tuition and accommodation for undergraduate students in Science, Technology, Engineering, and Mathematics.',
                'category_id' => $cat('education'),
                'org_name'    => 'University of Ibadan Foundation',
                'trust_level' => 'institution_verified',
                'trust_label' => 'Institution Verified',
                'location'    => 'Ibadan, Nigeria',
                'lat'         => 7.3775,
                'lng'         => 3.9470,
                'date'        => null,
                'deadline'    => '2026-06-30 23:59:59',
                'action_type' => 'apply',
                'is_live'     => 0,
                'is_active'   => 1,
                'status'      => 'approved',
                'like_count'  => 134,
                'comment_count' => 27,
            ],
            [
                'title'       => 'Street Art Festival — Port Harcourt',
                'description' => 'Celebrate local artists at the annual Port Harcourt Street Art Festival. Live murals, music, food vendors, and gallery showcases all weekend.',
                'category_id' => $cat('arts-culture'),
                'org_name'    => 'PH Creative Collective',
                'trust_level' => 'community_submitted',
                'trust_label' => 'Community Submitted',
                'location'    => 'Port Harcourt, Nigeria',
                'lat'         => 4.8156,
                'lng'         => 7.0498,
                'date'        => '2026-05-22 10:00:00',
                'deadline'    => null,
                'action_type' => 'save',
                'is_live'     => 0,
                'is_active'   => 1,
                'status'      => 'approved',
                'like_count'  => 67,
                'comment_count' => 12,
            ],
            [
                'title'       => 'Women in Business Networking Dinner',
                'description' => 'An exclusive networking dinner for women entrepreneurs and professionals. Keynote from CEO of AgroVentures Ltd. Table sponsorships available.',
                'category_id' => $cat('women'),
                'org_name'    => 'Women Who Lead NG',
                'trust_level' => 'curator_reviewed',
                'trust_label' => 'Curator Reviewed',
                'location'    => 'Lagos, Nigeria',
                'lat'         => 6.4281,
                'lng'         => 3.4219,
                'date'        => '2026-05-28 18:00:00',
                'deadline'    => '2026-05-20 23:59:59',
                'action_type' => 'rsvp',
                'is_live'     => 0,
                'is_active'   => 1,
                'status'      => 'approved',
                'like_count'  => 88,
                'comment_count' => 14,
            ],
        ];

        foreach ($listings as &$row) {
            $row['created_at'] = date('Y-m-d H:i:s');
            $row['updated_at'] = date('Y-m-d H:i:s');
        }
        unset($row);

        $this->db->table('listings')->insertBatch($listings);
    }
}
