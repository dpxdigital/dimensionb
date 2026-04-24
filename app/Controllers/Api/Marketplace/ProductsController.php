<?php

namespace App\Controllers\Api\Marketplace;

use App\Controllers\Api\BaseApiController;
use App\Libraries\S3Uploader;
use CodeIgniter\HTTP\ResponseInterface;

class ProductsController extends BaseApiController
{
    private function db() { return db_connect(); }

    // ── GET /v1/products/:id ──────────────────────────────────────────────────

    public function show($id = null): ResponseInterface
    {
        $db      = $this->db();
        $product = $db->table('products p')
            ->select('p.*, v.name AS vendor_name, v.slug AS vendor_slug, v.logo_url AS vendor_logo, v.rating AS vendor_rating')
            ->join('vendors v', 'v.id = p.vendor_id')
            ->where('p.id', (int) $id)
            ->where('p.is_available', 1)
            ->get()->getRowArray();

        if (! $product) return $this->error('Product not found.', 404);

        return $this->success($this->formatProduct($product));
    }

    // ── POST /v1/vendors/:vendor_id/products ──────────────────────────────────

    public function create($vendorId = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = $this->db();

        $vendor = $db->table('vendors')->where('id', (int) $vendorId)->where('user_id', $userId)->get()->getRowArray();
        if (! $vendor) return $this->error('Vendor not found or not yours.', 403);

        $isMultipart = str_contains($this->request->getHeaderLine('Content-Type'), 'multipart');
        $input       = $isMultipart ? $this->request->getPost() : $this->inputJson();

        if (! $this->validateData($input, [
            'name'  => 'required|max_length[255]',
            'price' => 'required|numeric',
        ])) {
            return $this->validationError($this->validator->getErrors());
        }

        $imageUrls = [];
        $s3        = new S3Uploader();
        $files     = $this->request->getFiles();
        $imageFiles = $files['images'] ?? [];

        if ($imageFiles && ! is_array($imageFiles)) $imageFiles = [$imageFiles];

        foreach ($imageFiles as $file) {
            if ($file && $file->isValid()) {
                $ext  = strtolower($file->getExtension());
                $fn   = 'product_' . time() . '_' . uniqid() . '.' . $ext;
                $file->move(WRITEPATH . 'uploads/', $fn);
                $imageUrls[] = $s3->uploadOrLocal(WRITEPATH . "uploads/{$fn}", "uploads/products/{$fn}", "image/{$ext}", 'products');
            }
        }

        $db->table('products')->insert([
            'vendor_id'      => (int) $vendorId,
            'name'           => trim($input['name']),
            'description'    => $input['description'] ?? null,
            'price'          => (float) $input['price'],
            'images'         => ! empty($imageUrls) ? json_encode($imageUrls) : null,
            'category'       => $input['category'] ?? null,
            'stock_quantity' => (int) ($input['stock_quantity'] ?? 0),
            'is_available'   => 1,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        $productId = $db->insertID();
        $product   = $db->table('products')->where('id', $productId)->get()->getRowArray();
        return $this->success($this->formatProduct($product), 'Product created', 201);
    }

    // ── PUT /v1/products/:id ──────────────────────────────────────────────────

    public function update($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = $this->db();

        $product = $db->table('products p')
            ->select('p.*, v.user_id AS vendor_user_id')
            ->join('vendors v', 'v.id = p.vendor_id')
            ->where('p.id', (int) $id)
            ->get()->getRowArray();

        if (! $product || $product['vendor_user_id'] != $userId) {
            return $this->error('Product not found or not yours.', 403);
        }

        $isMultipart = str_contains($this->request->getHeaderLine('Content-Type'), 'multipart');
        $input       = $isMultipart ? $this->request->getPost() : $this->inputJson();

        $set = array_filter([
            'name'           => isset($input['name']) ? trim($input['name']) : null,
            'description'    => $input['description'] ?? null,
            'price'          => isset($input['price']) ? (float) $input['price'] : null,
            'category'       => $input['category'] ?? null,
            'stock_quantity' => isset($input['stock_quantity']) ? (int) $input['stock_quantity'] : null,
            'is_available'   => isset($input['is_available']) ? (int) $input['is_available'] : null,
            'updated_at'     => date('Y-m-d H:i:s'),
        ], fn($v) => $v !== null);

        $db->table('products')->where('id', (int) $id)->set($set)->update();
        $updated = $db->table('products')->where('id', (int) $id)->get()->getRowArray();
        return $this->success($this->formatProduct($updated), 'Product updated');
    }

    // ── DELETE /v1/products/:id ───────────────────────────────────────────────

    public function delete($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = $this->db();

        $product = $db->table('products p')
            ->select('p.id, v.user_id AS vendor_user_id')
            ->join('vendors v', 'v.id = p.vendor_id')
            ->where('p.id', (int) $id)
            ->get()->getRowArray();

        if (! $product || $product['vendor_user_id'] != $userId) {
            return $this->error('Product not found or not yours.', 403);
        }

        $db->table('products')->where('id', (int) $id)->set('is_available', 0)->update();
        return $this->success(null, 'Product removed');
    }

    private function formatProduct(array $row): array
    {
        return [
            'id'             => (string) $row['id'],
            'vendor_id'      => (string) $row['vendor_id'],
            'vendor_name'    => $row['vendor_name'] ?? null,
            'vendor_slug'    => $row['vendor_slug'] ?? null,
            'vendor_logo'    => $row['vendor_logo'] ?? null,
            'vendor_rating'  => isset($row['vendor_rating']) ? (float) $row['vendor_rating'] : null,
            'name'           => $row['name'],
            'description'    => $row['description'] ?? null,
            'price'          => (float) $row['price'],
            'images'         => isset($row['images']) ? json_decode($row['images'], true) : [],
            'category'       => $row['category'] ?? null,
            'stock_quantity' => (int) ($row['stock_quantity'] ?? 0),
            'is_available'   => (bool) ($row['is_available'] ?? true),
            'created_at'     => $row['created_at'] ?? null,
        ];
    }
}
