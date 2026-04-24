<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class CategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Education',      'slug' => 'education',      'icon_name' => 'school_outlined',         'sort_order' => 1],
            ['name' => 'Health',         'slug' => 'health',         'icon_name' => 'favorite_outline',        'sort_order' => 2],
            ['name' => 'Arts & Culture', 'slug' => 'arts-culture',   'icon_name' => 'palette_outlined',        'sort_order' => 3],
            ['name' => 'Technology',     'slug' => 'technology',     'icon_name' => 'computer_outlined',       'sort_order' => 4],
            ['name' => 'Environment',    'slug' => 'environment',    'icon_name' => 'eco_outlined',            'sort_order' => 5],
            ['name' => 'Sports',         'slug' => 'sports',         'icon_name' => 'sports_outlined',         'sort_order' => 6],
            ['name' => 'Business',       'slug' => 'business',       'icon_name' => 'business_center_outlined','sort_order' => 7],
            ['name' => 'Community',      'slug' => 'community',      'icon_name' => 'groups_outlined',         'sort_order' => 8],
            ['name' => 'Finance',        'slug' => 'finance',        'icon_name' => 'attach_money_outlined',   'sort_order' => 9],
            ['name' => 'Faith',          'slug' => 'faith',          'icon_name' => 'church_outlined',         'sort_order' => 10],
            ['name' => 'Youth',          'slug' => 'youth',          'icon_name' => 'child_care_outlined',     'sort_order' => 11],
            ['name' => 'Women',          'slug' => 'women',          'icon_name' => 'female_outlined',         'sort_order' => 12],
            ['name' => 'Government',     'slug' => 'government',     'icon_name' => 'account_balance_outlined','sort_order' => 13],
            ['name' => 'Media',          'slug' => 'media',          'icon_name' => 'movie_outlined',          'sort_order' => 14],
            ['name' => 'Food',           'slug' => 'food',           'icon_name' => 'restaurant_outlined',     'sort_order' => 15],
        ];

        // Skip if already seeded
        if ($this->db->table('categories')->countAllResults() > 0) {
            return;
        }

        $this->db->table('categories')->insertBatch($categories);
    }
}
