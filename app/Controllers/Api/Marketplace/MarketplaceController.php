<?php

namespace App\Controllers\Api\Marketplace;

use App\Controllers\Api\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

class MarketplaceController extends BaseApiController
{
    private function db() { return db_connect(); }

    // ── GET /v1/marketplace ───────────────────────────────────────────────────

    public function index(): ResponseInterface
    {
        $db       = $this->db();
        $category = $this->request->getGet('category');
        $q        = trim((string) ($this->request->getGet('q') ?? ''));

        $vendorQuery = $db->table('vendors v')
            ->select('v.id, v.name, v.slug, v.description, v.category, v.logo_url, v.banner_url, v.rating, v.contact_email')
            ->where('v.is_active', 1)
            ->orderBy('v.rating', 'DESC')
            ->limit(20);

        if ($category) $vendorQuery->where('v.category', $category);
        if (! empty($q)) $vendorQuery->like('v.name', $q);

        $vendors = $vendorQuery->get()->getResultArray();

        $featured = $db->table('vendors')
            ->where('is_active', 1)
            ->orderBy('rating', 'DESC')
            ->limit(5)
            ->get()->getResultArray();

        $trending = $db->table('products p')
            ->select('p.*, v.name AS vendor_name, v.logo_url AS vendor_logo')
            ->join('vendors v', 'v.id = p.vendor_id')
            ->where('p.is_available', 1)
            ->where('v.is_active', 1)
            ->orderBy('p.id', 'DESC')
            ->limit(10)
            ->get()->getResultArray();

        $categories = ['Food & Beverage', 'Fashion', 'Beauty', 'Tech', 'Home', 'Services', 'Art & Culture', 'Health & Wellness'];

        return $this->success([
            'featured_stores'  => array_map([$this, 'formatVendor'], $featured),
            'trending_products' => array_map([$this, 'formatProduct'], $trending),
            'vendors'          => array_map([$this, 'formatVendor'], $vendors),
            'categories'       => $categories,
        ]);
    }

    // ── GET /v1/marketplace/categories ───────────────────────────────────────

    public function categories(): ResponseInterface
    {
        $categories = ['Food & Beverage', 'Fashion', 'Beauty', 'Tech', 'Home', 'Services', 'Art & Culture', 'Health & Wellness'];
        return $this->success($categories);
    }

    private function formatVendor(array $row): array
    {
        return [
            'id'            => (string) $row['id'],
            'name'          => $row['name'],
            'slug'          => $row['slug'] ?? '',
            'description'   => $row['description'] ?? null,
            'category'      => $row['category'] ?? null,
            'logo_url'      => $row['logo_url'] ?? null,
            'banner_url'    => $row['banner_url'] ?? null,
            'rating'        => (float) ($row['rating'] ?? 0),
            'contact_email' => $row['contact_email'] ?? null,
        ];
    }

    private function formatProduct(array $row): array
    {
        return [
            'id'             => (string) $row['id'],
            'vendor_id'      => (string) $row['vendor_id'],
            'vendor_name'    => $row['vendor_name'] ?? null,
            'vendor_logo'    => $row['vendor_logo'] ?? null,
            'name'           => $row['name'],
            'description'    => $row['description'] ?? null,
            'price'          => (float) $row['price'],
            'images'         => isset($row['images']) ? json_decode($row['images'], true) : [],
            'category'       => $row['category'] ?? null,
            'stock_quantity' => (int) ($row['stock_quantity'] ?? 0),
            'is_available'   => (bool) ($row['is_available'] ?? true),
        ];
    }
}
