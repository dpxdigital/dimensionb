<?php

namespace App\Controllers\Api\Marketplace;

use App\Controllers\Api\BaseApiController;
use App\Libraries\S3Uploader;
use CodeIgniter\HTTP\ResponseInterface;

class VendorsController extends BaseApiController
{
    private function db() { return db_connect(); }

    // ── GET /v1/vendors ───────────────────────────────────────────────────────

    public function index(): ResponseInterface
    {
        $q        = trim((string) ($this->request->getGet('q') ?? ''));
        $category = $this->request->getGet('category');
        $db       = $this->db();

        $query = $db->table('vendors')->where('is_active', 1)->orderBy('rating', 'DESC')->limit(20);
        if (! empty($q)) $query->like('name', $q);
        if ($category)   $query->where('category', $category);

        return $this->success(array_map([$this, 'formatVendor'], $query->get()->getResultArray()));
    }

    // ── GET /v1/vendors/:id ───────────────────────────────────────────────────

    public function show($id = null): ResponseInterface
    {
        $db     = $this->db();
        $vendor = $db->table('vendors')->where('id', (int) $id)->where('is_active', 1)->get()->getRowArray();
        if (! $vendor) return $this->error('Vendor not found.', 404);

        $products = $db->table('products')
            ->where('vendor_id', (int) $id)
            ->where('is_available', 1)
            ->orderBy('id', 'DESC')
            ->limit(50)
            ->get()->getResultArray();

        return $this->success([
            'vendor'   => $this->formatVendor($vendor),
            'products' => array_map([$this, 'formatProduct'], $products),
        ]);
    }

    // ── POST /v1/vendors (become a vendor) ───────────────────────────────────

    public function create(): ResponseInterface
    {
        $userId      = $this->authUserId();
        $isMultipart = str_contains($this->request->getHeaderLine('Content-Type'), 'multipart');
        $input       = $isMultipart ? $this->request->getPost() : $this->inputJson();

        if (! $this->validateData($input, ['name' => 'required|max_length[255]'])) {
            return $this->validationError($this->validator->getErrors());
        }

        $db = $this->db();

        $existing = $db->table('vendors')->where('user_id', $userId)->get()->getRowArray();
        if ($existing) return $this->error('You already have a vendor store.', 409);

        $logoUrl   = null;
        $bannerUrl = null;
        $s3        = new S3Uploader();

        foreach (['logo' => &$logoUrl, 'banner' => &$bannerUrl] as $field => &$urlVar) {
            $file = $this->request->getFile($field);
            if ($file && $file->isValid()) {
                $ext     = strtolower($file->getExtension());
                $fn      = "vendor_{$field}_{$userId}_" . time() . ".{$ext}";
                $file->move(WRITEPATH . 'uploads/', $fn);
                $urlVar = $s3->uploadOrLocal(WRITEPATH . "uploads/{$fn}", "uploads/vendors/{$fn}", "image/{$ext}", 'vendors');
            }
        }

        $slug = $this->makeSlug($input['name']);
        $db->table('vendors')->insert([
            'user_id'       => $userId,
            'name'          => trim($input['name']),
            'slug'          => $slug,
            'description'   => $input['description'] ?? null,
            'category'      => $input['category'] ?? null,
            'logo_url'      => $logoUrl,
            'banner_url'    => $bannerUrl,
            'contact_email' => $input['contact_email'] ?? null,
            'contact_phone' => $input['contact_phone'] ?? null,
            'is_active'     => 1,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        $vendorId = $db->insertID();
        $db->table('users')->where('id', $userId)->set('is_vendor', 1)->update();

        $vendor = $db->table('vendors')->where('id', $vendorId)->get()->getRowArray();
        return $this->success($this->formatVendor($vendor), 'Vendor store created', 201);
    }

    // ── PUT /v1/vendors/:id ───────────────────────────────────────────────────

    public function update($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = $this->db();
        $vendor = $db->table('vendors')->where('id', (int) $id)->where('user_id', $userId)->get()->getRowArray();
        if (! $vendor) return $this->error('Vendor not found or not yours.', 404);

        $isMultipart = str_contains($this->request->getHeaderLine('Content-Type'), 'multipart');
        $input       = $isMultipart ? $this->request->getPost() : $this->inputJson();

        $set = array_filter([
            'name'          => isset($input['name']) ? trim($input['name']) : null,
            'description'   => $input['description'] ?? null,
            'category'      => $input['category'] ?? null,
            'contact_email' => $input['contact_email'] ?? null,
            'contact_phone' => $input['contact_phone'] ?? null,
            'updated_at'    => date('Y-m-d H:i:s'),
        ], fn($v) => $v !== null);

        $s3 = new S3Uploader();
        foreach (['logo' => 'logo_url', 'banner' => 'banner_url'] as $field => $col) {
            $file = $this->request->getFile($field);
            if ($file && $file->isValid()) {
                $ext      = strtolower($file->getExtension());
                $fn       = "vendor_{$field}_{$userId}_" . time() . ".{$ext}";
                $file->move(WRITEPATH . 'uploads/', $fn);
                $set[$col] = $s3->uploadOrLocal(WRITEPATH . "uploads/{$fn}", "uploads/vendors/{$fn}", "image/{$ext}", 'vendors');
            }
        }

        $db->table('vendors')->where('id', (int) $id)->set($set)->update();
        $updated = $db->table('vendors')->where('id', (int) $id)->get()->getRowArray();
        return $this->success($this->formatVendor($updated), 'Vendor updated');
    }

    // ── GET /v1/vendors/my ────────────────────────────────────────────────────

    public function myStore(): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = $this->db();
        $vendor = $db->table('vendors')->where('user_id', $userId)->get()->getRowArray();
        if (! $vendor) return $this->error('You do not have a vendor store.', 404);

        $products = $db->table('products')->where('vendor_id', $vendor['id'])->orderBy('id', 'DESC')->get()->getResultArray();
        $orders   = $db->table('orders o')
            ->select('o.*, u.name AS buyer_name, u.avatar_url AS buyer_avatar')
            ->join('users u', 'u.id = o.buyer_id')
            ->where('o.vendor_id', $vendor['id'])
            ->orderBy('o.created_at', 'DESC')
            ->limit(50)
            ->get()->getResultArray();

        return $this->success([
            'vendor'   => $this->formatVendor($vendor),
            'products' => array_map([$this, 'formatProduct'], $products),
            'orders'   => array_map([$this, 'formatOrder'], $orders),
        ]);
    }

    // ── PUT|POST /v1/vendors/:id/payment-settings ─────────────────────────────

    public function paymentSettings($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = $this->db();
        $vendor = $db->table('vendors')->where('id', (int) $id)->where('user_id', $userId)->get()->getRowArray();
        if (! $vendor) return $this->error('Vendor not found or not yours.', 404);

        $isMultipart = str_contains($this->request->getHeaderLine('Content-Type'), 'multipart');
        $input       = $isMultipart ? $this->request->getPost() : $this->inputJson();

        $set = [
            'stripe_enabled'      => (int) ($input['stripe_enabled'] ?? 0),
            'paypal_enabled'      => (int) ($input['paypal_enabled'] ?? 0),
            'flutterwave_enabled' => (int) ($input['flutterwave_enabled'] ?? 0),
            'free_shipping'       => (int) ($input['free_shipping'] ?? 0),
            'shipping_rate'       => (float) ($input['shipping_rate'] ?? 0),
            'delivery_time'       => $input['delivery_time'] ?? '3-5 business days',
            'is_activated'        => 1,
            'updated_at'          => date('Y-m-d H:i:s'),
        ];

        // Store payment gateway keys (encrypted in production)
        if (! empty($input['stripe_publishable_key'])) {
            $set['stripe_publishable_key'] = trim($input['stripe_publishable_key']);
        }
        if (! empty($input['stripe_secret_key'])) {
            $set['stripe_secret_key'] = trim($input['stripe_secret_key']);
        }
        if (! empty($input['paypal_client_id'])) {
            $set['paypal_client_id'] = trim($input['paypal_client_id']);
        }
        if (! empty($input['paypal_client_secret'])) {
            $set['paypal_client_secret'] = trim($input['paypal_client_secret']);
        }
        if (! empty($input['flutterwave_public_key'])) {
            $set['flutterwave_public_key'] = trim($input['flutterwave_public_key']);
        }
        if (! empty($input['flutterwave_secret_key'])) {
            $set['flutterwave_secret_key'] = trim($input['flutterwave_secret_key']);
        }

        $db->table('vendors')->where('id', (int) $id)->set($set)->update();

        return $this->success(['activated' => true], 'Payment settings saved');
    }

    private function formatVendor(array $row): array
    {
        return [
            'id'            => (string) $row['id'],
            'user_id'       => (string) $row['user_id'],
            'name'          => $row['name'],
            'slug'          => $row['slug'] ?? '',
            'description'   => $row['description'] ?? null,
            'category'      => $row['category'] ?? null,
            'logo_url'      => $row['logo_url'] ?? null,
            'banner_url'    => $row['banner_url'] ?? null,
            'rating'        => (float) ($row['rating'] ?? 0),
            'contact_email' => $row['contact_email'] ?? null,
            'contact_phone' => $row['contact_phone'] ?? null,
            'is_active'     => (bool) ($row['is_active'] ?? true),
        ];
    }

    private function formatProduct(array $row): array
    {
        return [
            'id'             => (string) $row['id'],
            'vendor_id'      => (string) $row['vendor_id'],
            'name'           => $row['name'],
            'description'    => $row['description'] ?? null,
            'price'          => (float) $row['price'],
            'images'         => isset($row['images']) ? json_decode($row['images'], true) : [],
            'category'       => $row['category'] ?? null,
            'stock_quantity' => (int) ($row['stock_quantity'] ?? 0),
            'is_available'   => (bool) ($row['is_available'] ?? true),
        ];
    }

    private function formatOrder(array $row): array
    {
        return [
            'id'           => (string) $row['id'],
            'buyer_name'   => $row['buyer_name'] ?? null,
            'buyer_avatar' => $row['buyer_avatar'] ?? null,
            'status'       => $row['status'],
            'total_amount' => (float) $row['total_amount'],
            'created_at'   => $row['created_at'],
        ];
    }

    private function makeSlug(string $name): string
    {
        $slug   = trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name)), '-');
        $exists = db_connect()->table('vendors')->where('slug', $slug)->countAllResults();
        return $exists > 0 ? $slug . '-' . time() : $slug;
    }
}
