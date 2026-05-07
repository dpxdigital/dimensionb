<?php

namespace App\Controllers\Admin;

class MarketplaceController extends BaseAdminController
{
    private int $perPage = 25;

    private function setting(string $key, string $default = ''): string
    {
        $db  = db_connect();
        $row = $db->table('platform_settings')->where('key', $key)->get()->getRowArray();
        return $row ? (string) $row['value'] : $default;
    }

    private function saveSetting(string $key, string $value): void
    {
        $db = db_connect();
        if ($db->table('platform_settings')->where('key', $key)->countAllResults()) {
            $db->table('platform_settings')->where('key', $key)->set(['value' => $value, 'updated_at' => date('Y-m-d H:i:s')])->update();
        } else {
            $db->table('platform_settings')->insert(['key' => $key, 'value' => $value, 'updated_at' => date('Y-m-d H:i:s')]);
        }
    }

    // GET  /manager/marketplace/payment-settings
    public function paymentSettings()
    {
        return $this->renderView('admin/marketplace/payment_settings', [
            'pageTitle'              => 'Marketplace — Payment Settings',
            'activation_fee_amount'  => $this->setting('activation_fee_amount', '9.99'),
            'activation_fee_currency'=> $this->setting('activation_fee_currency', 'usd'),
            'platform_stripe_key'    => $this->setting('platform_stripe_key'),
            'platform_stripe_secret' => $this->setting('platform_stripe_secret'),
            'platform_stripe_webhook_secret' => $this->setting('platform_stripe_webhook_secret'),
        ]);
    }

    // POST /manager/marketplace/payment-settings/save  (AJAX)
    public function savePaymentSettings()
    {
        $input = $this->request->getJSON(true) ?: $this->request->getPost();

        $fee      = (float) ($input['activation_fee_amount'] ?? 0);
        $currency = strtolower(trim($input['activation_fee_currency'] ?? 'usd'));
        $pk       = trim($input['platform_stripe_key'] ?? '');
        $sk       = trim($input['platform_stripe_secret'] ?? '');
        $whsec    = trim($input['platform_stripe_webhook_secret'] ?? '');

        if ($fee < 0) {
            return $this->jsonResponse(['error' => 'Fee must be ≥ 0.'], 422);
        }

        $this->saveSetting('activation_fee_amount', (string) $fee);
        $this->saveSetting('activation_fee_currency', $currency);
        $this->saveSetting('platform_stripe_key', $pk);
        if ($sk !== '') $this->saveSetting('platform_stripe_secret', $sk);
        if ($whsec !== '') $this->saveSetting('platform_stripe_webhook_secret', $whsec);

        $this->audit('payment_settings_updated', 'platform', 0, 'fee=' . $fee);

        return $this->jsonResponse([
            'success' => true,
            'message' => 'Settings saved successfully.',
            'activation_fee_amount'  => (string) $fee,
            'activation_fee_currency'=> $currency,
            'platform_stripe_key'    => $pk,
        ]);
    }

    // POST /manager/marketplace/vendors/:id/approve
    public function approveVendor(int $id)
    {
        $db     = db_connect();
        $vendor = $db->table('vendors')->where('id', $id)->get()->getRowArray();
        if (! $vendor) {
            return $this->jsonResponse(['error' => 'Vendor not found'], 404);
        }

        $db->table('vendors')->where('id', $id)->update([
            'is_approved'      => 1,
            'rejection_reason' => null,
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        $this->audit('vendor_approved', 'vendor', $id, $vendor['name']);
        return $this->jsonResponse(['status' => 'approved']);
    }

    // POST /manager/marketplace/vendors/:id/reject
    public function rejectVendor(int $id)
    {
        $db     = db_connect();
        $vendor = $db->table('vendors')->where('id', $id)->get()->getRowArray();
        if (! $vendor) {
            return $this->jsonResponse(['error' => 'Vendor not found'], 404);
        }

        $reason = trim($this->request->getPost('reason') ?? '');
        if (empty($reason)) {
            return $this->jsonResponse(['error' => 'Rejection reason is required.'], 422);
        }

        $db->table('vendors')->where('id', $id)->update([
            'is_approved'      => 0,
            'rejection_reason' => $reason,
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        $this->audit('vendor_rejected', 'vendor', $id, $vendor['name'] . ': ' . $reason);
        return $this->jsonResponse(['status' => 'rejected']);
    }

    // GET /manager/marketplace/vendors/:id/test-payment
    // Creates a Stripe Checkout Session for a vendor so admin can test the payment flow.
    public function testActivationPayment(int $id)
    {
        $db     = db_connect();
        $vendor = $db->table('vendors')->where('id', $id)->get()->getRowArray();
        if (! $vendor) {
            return $this->jsonResponse(['error' => 'Vendor not found'], 404);
        }

        $stripeSecret = $this->setting('platform_stripe_secret');
        $feeAmount    = (float) $this->setting('activation_fee_amount', '9.99');
        $currency     = $this->setting('activation_fee_currency', 'usd');

        if (empty($stripeSecret)) {
            return $this->jsonResponse(['error' => 'Platform Stripe secret key not configured. Go to Payment Settings first.'], 503);
        }

        $amountCents = (int) ($feeAmount * 100);
        $successUrl  = base_url("v1/marketplace/activation/paid?session_id={CHECKOUT_SESSION_ID}&vendor_id={$vendor['id']}");
        $cancelUrl   = base_url("manager/marketplace/vendors/{$id}");

        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => "{$stripeSecret}:",
            CURLOPT_POSTFIELDS     => http_build_query([
                'mode'                                                     => 'payment',
                'success_url'                                              => $successUrl,
                'cancel_url'                                               => $cancelUrl,
                'line_items[0][price_data][currency]'                      => $currency,
                'line_items[0][price_data][product_data][name]'            => 'Storefront Activation Fee (Admin Test)',
                'line_items[0][price_data][product_data][description]'     => "Test payment for {$vendor['name']}",
                'line_items[0][price_data][unit_amount]'                   => $amountCents,
                'line_items[0][quantity]'                                  => 1,
                'metadata[vendor_id]'                                      => $vendor['id'],
                'metadata[user_id]'                                        => $vendor['user_id'],
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $session = json_decode($response, true);
        if ($httpCode !== 200 || empty($session['url'])) {
            $err = $session['error']['message'] ?? $response;
            return $this->jsonResponse(['error' => 'Stripe error: ' . $err], 502);
        }

        // Save session ID on the vendor record
        $db->table('vendors')->where('id', $id)->set([
            'activation_session_id' => $session['id'],
            'activation_fee_amount' => $feeAmount,
            'updated_at'            => date('Y-m-d H:i:s'),
        ])->update();

        // Redirect admin directly to Stripe Checkout
        return redirect()->to($session['url']);
    }

    // GET /manager/marketplace — vendors list
    public function index()
    {
        $db     = db_connect();
        $q      = $this->request->getGet('q');
        $status = $this->request->getGet('approval'); // all | pending | approved | rejected
        $page   = max(1, (int) ($this->request->getGet('page') ?? 1));

        // Revenue summary
        $revenue = [
            'total'    => (float) ($db->table('vendors')->where('activation_fee_paid', 1)->selectSum('activation_fee_amount')->get()->getRow()->activation_fee_amount ?? 0),
            'paid'     => $db->table('vendors')->where('activation_fee_paid', 1)->countAllResults(),
            'approved' => $db->table('vendors')->where('is_approved', 1)->countAllResults(),
            'pending'  => $db->table('vendors')->where('activation_fee_paid', 1)->where('is_approved', 0)->countAllResults(),
        ];

        $builder = $db->table('vendors v')
            ->select('v.id, v.name, v.slug, v.category, v.is_active, v.is_approved,
                      v.activation_fee_paid, v.activation_fee_amount, v.rejection_reason,
                      u.name AS owner_name, u.email AS owner_email,
                      COUNT(DISTINCT p.id) AS product_count,
                      COUNT(DISTINCT o.id) AS order_count,
                      COALESCE(SUM(o.total_amount), 0) AS total_revenue')
            ->join('users u', 'u.id = v.user_id', 'left')
            ->join('products p', 'p.vendor_id = v.id', 'left')
            ->join('orders o', 'o.vendor_id = v.id AND o.status != \'cancelled\'', 'left')
            ->groupBy('v.id');

        if ($status === 'pending')  $builder->where('v.activation_fee_paid', 1)->where('v.is_approved', 0);
        if ($status === 'approved') $builder->where('v.is_approved', 1);
        if ($status === 'rejected') $builder->where('v.is_approved', 0)->where('v.rejection_reason IS NOT NULL', null, false);

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
            'revenue'   => $revenue,
            'approval'  => $status,
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
