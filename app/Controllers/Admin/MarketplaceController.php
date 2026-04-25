<?php

namespace App\Controllers\Admin;

class MarketplaceController extends BaseAdminController
{
    private int $perPage = 25;

    // GET /manager/marketplace — vendors list
    public function index()
    {
        $db   = db_connect();
        $q    = $this->request->getGet('q');
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));

        $builder = $db->table('vendors v')
            ->select('v.id, v.name, v.slug, v.category, v.is_active,
                      COUNT(DISTINCT p.id) AS product_count,
                      COUNT(DISTINCT o.id) AS order_count,
                      COALESCE(SUM(o.total_amount), 0) AS total_revenue')
            ->join('products p', 'p.vendor_id = v.id', 'left')
            ->join('orders o', 'o.vendor_id = v.id AND o.status != \'cancelled\'', 'left')
            ->groupBy('v.id');

        if ($q) {
            $builder->like('v.name', $q)->orLike('v.slug', $q)->orLike('v.category', $q);
        }

        $total    = $db->table('vendors v')->select('COUNT(DISTINCT v.id) AS cnt');
        if ($q) {
            $total->like('v.name', $q)->orLike('v.slug', $q)->orLike('v.category', $q);
        }
        $totalCount = (int) ($total->get()->getRow()->cnt ?? 0);
        $lastPage   = max(1, (int) ceil($totalCount / $this->perPage));

        $vendors = $builder
            ->orderBy('v.id', 'DESC')
            ->limit($this->perPage, ($page - 1) * $this->perPage)
            ->get()->getResultArray();

        return $this->renderView('admin/marketplace/index', [
            'pageTitle' => 'Marketplace — Vendors',
            'vendors'   => $vendors,
            'q'         => $q,
            'page'      => $page,
            'lastPage'  => $lastPage,
            'total'     => $totalCount,
        ]);
    }

    // GET /manager/marketplace/vendors/:id
    public function showVendor(int $id)
    {
        $db = db_connect();

        $vendor = $db->table('vendors v')
            ->select('v.*, COUNT(DISTINCT p.id) AS product_count, COUNT(DISTINCT o.id) AS order_count, COALESCE(SUM(o.total_amount),0) AS total_revenue')
            ->join('products p', 'p.vendor_id = v.id', 'left')
            ->join('orders o', 'o.vendor_id = v.id AND o.status != \'cancelled\'', 'left')
            ->where('v.id', $id)
            ->groupBy('v.id')
            ->get()->getRowArray();

        if (! $vendor) {
            return redirect()->to('/manager/marketplace')->with('error', 'Vendor not found.');
        }

        $products = $db->table('products')
            ->where('vendor_id', $id)
            ->orderBy('created_at', 'DESC')
            ->limit(50)
            ->get()->getResultArray();

        $recentOrders = $db->table('orders o')
            ->select('o.*, u.name AS buyer_name')
            ->join('users u', 'u.id = o.buyer_id', 'left')
            ->where('o.vendor_id', $id)
            ->orderBy('o.created_at', 'DESC')
            ->limit(20)
            ->get()->getResultArray();

        return $this->renderView('admin/marketplace/vendor', [
            'pageTitle'    => 'Vendor: ' . esc($vendor['name']),
            'vendor'       => $vendor,
            'products'     => $products,
            'recentOrders' => $recentOrders,
        ]);
    }

    // POST /manager/marketplace/vendors/:id/toggle
    public function toggleVendor(int $id)
    {
        $db     = db_connect();
        $vendor = $db->table('vendors')->where('id', $id)->get()->getRowArray();

        if (! $vendor) {
            return $this->jsonResponse(['error' => 'Vendor not found'], 404);
        }

        $newActive = $vendor['is_active'] ? 0 : 1;
        $db->table('vendors')->where('id', $id)->update(['is_active' => $newActive]);

        $action = $newActive ? 'vendor_activated' : 'vendor_suspended';
        $this->audit($action, 'vendor', $id, $vendor['name']);

        return $this->jsonResponse(['status' => $newActive ? 'active' : 'inactive']);
    }

    // GET /manager/marketplace/products
    public function products()
    {
        $db   = db_connect();
        $q    = $this->request->getGet('q');
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));

        $builder = $db->table('products p')
            ->select('p.*, v.name AS vendor_name')
            ->join('vendors v', 'v.id = p.vendor_id', 'left');

        if ($q) {
            $builder->like('p.name', $q)->orLike('v.name', $q);
        }

        $countBuilder = $db->table('products p')->select('COUNT(*) AS cnt')->join('vendors v', 'v.id = p.vendor_id', 'left');
        if ($q) {
            $countBuilder->like('p.name', $q)->orLike('v.name', $q);
        }
        $totalCount = (int) ($countBuilder->get()->getRow()->cnt ?? 0);
        $lastPage   = max(1, (int) ceil($totalCount / $this->perPage));

        $products = $builder
            ->orderBy('p.created_at', 'DESC')
            ->limit($this->perPage, ($page - 1) * $this->perPage)
            ->get()->getResultArray();

        return $this->renderView('admin/marketplace/products', [
            'pageTitle' => 'Marketplace — Products',
            'products'  => $products,
            'q'         => $q,
            'page'      => $page,
            'lastPage'  => $lastPage,
            'total'     => $totalCount,
        ]);
    }

    // POST /manager/marketplace/products/:id/toggle
    public function toggleProduct(int $id)
    {
        $db      = db_connect();
        $product = $db->table('products')->where('id', $id)->get()->getRowArray();

        if (! $product) {
            return $this->jsonResponse(['error' => 'Product not found'], 404);
        }

        $newAvailable = $product['is_available'] ? 0 : 1;
        $db->table('products')->where('id', $id)->update(['is_available' => $newAvailable]);

        $this->audit(
            $newAvailable ? 'product_enabled' : 'product_disabled',
            'product',
            $id,
            $product['name'] ?? ''
        );

        return $this->jsonResponse(['status' => $newAvailable ? 'available' : 'unavailable']);
    }

    // GET /manager/marketplace/orders
    public function orders()
    {
        $db     = db_connect();
        $status = $this->request->getGet('status');
        $page   = max(1, (int) ($this->request->getGet('page') ?? 1));

        $builder = $db->table('orders o')
            ->select('o.*, u.name AS buyer_name, v.name AS vendor_name')
            ->join('users u', 'u.id = o.buyer_id', 'left')
            ->join('vendors v', 'v.id = o.vendor_id', 'left');

        if ($status) {
            $builder->where('o.status', $status);
        }

        $countBuilder = $db->table('orders o')->select('COUNT(*) AS cnt')
            ->join('users u', 'u.id = o.buyer_id', 'left')
            ->join('vendors v', 'v.id = o.vendor_id', 'left');
        if ($status) {
            $countBuilder->where('o.status', $status);
        }
        $totalCount = (int) ($countBuilder->get()->getRow()->cnt ?? 0);
        $lastPage   = max(1, (int) ceil($totalCount / $this->perPage));

        $orders = $builder
            ->orderBy('o.created_at', 'DESC')
            ->limit($this->perPage, ($page - 1) * $this->perPage)
            ->get()->getResultArray();

        return $this->renderView('admin/marketplace/orders', [
            'pageTitle' => 'Marketplace — Orders',
            'orders'    => $orders,
            'status'    => $status,
            'page'      => $page,
            'lastPage'  => $lastPage,
            'total'     => $totalCount,
        ]);
    }

    // POST /manager/marketplace/orders/:id/status
    public function updateOrderStatus(int $id)
    {
        $db    = db_connect();
        $order = $db->table('orders')->where('id', $id)->get()->getRowArray();

        if (! $order) {
            return $this->jsonResponse(['error' => 'Order not found'], 404);
        }

        $newStatus = $this->request->getPost('status');
        $allowed   = ['pending', 'confirmed', 'shipped', 'delivered', 'cancelled'];

        if (! in_array($newStatus, $allowed, true)) {
            return $this->jsonResponse(['error' => 'Invalid status'], 422);
        }

        $db->table('orders')->where('id', $id)->update(['status' => $newStatus]);

        $this->audit('order_status_updated', 'order', $id, "status→{$newStatus}");

        return $this->jsonResponse(['status' => $newStatus]);
    }
}
