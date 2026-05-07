<?php

namespace App\Controllers\Api\Marketplace;

use App\Controllers\Api\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

class OrdersController extends BaseApiController
{
    private function db() { return db_connect(); }

    // ── GET /v1/cart ─────────────────────────────────────────────────────────

    public function cart(): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = $this->db();

        $items = $db->table('cart_items ci')
            ->select('ci.id, ci.quantity, p.id AS product_id, p.name, p.price, p.images, v.id AS vendor_id, v.name AS vendor_name')
            ->join('products p', 'p.id = ci.product_id')
            ->join('vendors v', 'v.id = p.vendor_id')
            ->where('ci.user_id', $userId)
            ->get()->getResultArray();

        $total = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $items));

        return $this->success([
            'items' => array_map([$this, 'formatCartItem'], $items),
            'total' => round($total, 2),
        ]);
    }

    // ── POST /v1/cart ─────────────────────────────────────────────────────────

    public function addToCart(): ResponseInterface
    {
        $userId = $this->authUserId();
        $input  = $this->inputJson();

        if (! $this->validateData($input, ['product_id' => 'required|integer'])) {
            return $this->validationError($this->validator->getErrors());
        }

        $db        = $this->db();
        $productId = (int) $input['product_id'];
        $quantity  = (int) ($input['quantity'] ?? 1);

        $product = $db->table('products')->where('id', $productId)->where('is_available', 1)->get()->getRowArray();
        if (! $product) return $this->error('Product not found.', 404);

        $existing = $db->table('cart_items')
            ->where('user_id', $userId)->where('product_id', $productId)->get()->getRowArray();

        if ($existing) {
            $db->table('cart_items')
                ->where('user_id', $userId)->where('product_id', $productId)
                ->set('quantity', $existing['quantity'] + $quantity)
                ->update();
        } else {
            $db->table('cart_items')->insert([
                'user_id'    => $userId,
                'product_id' => $productId,
                'quantity'   => $quantity,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return $this->success(null, 'Added to cart');
    }

    // ── DELETE /v1/cart/:product_id ───────────────────────────────────────────

    public function removeFromCart($productId = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $this->db()->table('cart_items')
            ->where('user_id', $userId)->where('product_id', (int) $productId)->delete();

        return $this->success(null, 'Removed from cart');
    }

    // ── PUT /v1/cart/:product_id ──────────────────────────────────────────────

    public function updateCart($productId = null): ResponseInterface
    {
        $userId   = $this->authUserId();
        $input    = $this->inputJson();
        $quantity = (int) ($input['quantity'] ?? 1);

        if ($quantity <= 0) {
            $this->db()->table('cart_items')->where('user_id', $userId)->where('product_id', (int) $productId)->delete();
            return $this->success(null, 'Item removed');
        }

        $this->db()->table('cart_items')
            ->where('user_id', $userId)->where('product_id', (int) $productId)
            ->set('quantity', $quantity)->update();

        return $this->success(null, 'Cart updated');
    }

    // ── POST /v1/orders/checkout ──────────────────────────────────────────────

    public function checkout(): ResponseInterface
    {
        $userId = $this->authUserId();
        $input  = $this->inputJson();
        $db     = $this->db();

        $cartItems = $db->table('cart_items ci')
            ->select('ci.quantity, p.id AS product_id, p.price, p.vendor_id, p.stock_quantity')
            ->join('products p', 'p.id = ci.product_id')
            ->where('ci.user_id', $userId)
            ->get()->getResultArray();

        if (empty($cartItems)) return $this->error('Your cart is empty.', 422);

        // Group by vendor
        $byVendor = [];
        foreach ($cartItems as $item) {
            $byVendor[$item['vendor_id']][] = $item;
        }

        $orderIds = [];
        foreach ($byVendor as $vendorId => $items) {
            $total = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $items));

            $db->table('orders')->insert([
                'buyer_id'      => $userId,
                'vendor_id'     => $vendorId,
                'status'        => 'pending',
                'total_amount'  => round($total, 2),
                'delivery_note' => $input['delivery_note'] ?? null,
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
            $orderId = $db->insertID();
            $orderIds[] = $orderId;

            foreach ($items as $item) {
                $db->table('order_items')->insert([
                    'order_id'   => $orderId,
                    'product_id' => $item['product_id'],
                    'quantity'   => $item['quantity'],
                    'unit_price' => $item['price'],
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        // Clear cart
        $db->table('cart_items')->where('user_id', $userId)->delete();

        return $this->success(['order_ids' => $orderIds], 'Order placed successfully', 201);
    }

    // ── GET /v1/orders ────────────────────────────────────────────────────────

    public function myOrders(): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = $this->db();

        $orders = $db->table('orders o')
            ->select('o.*, v.name AS vendor_name, v.logo_url AS vendor_logo')
            ->join('vendors v', 'v.id = o.vendor_id')
            ->where('o.buyer_id', $userId)
            ->orderBy('o.created_at', 'DESC')
            ->limit(50)
            ->get()->getResultArray();

        foreach ($orders as &$order) {
            $order['items'] = $db->table('order_items oi')
                ->select('oi.quantity, oi.unit_price, p.name, p.images')
                ->join('products p', 'p.id = oi.product_id')
                ->where('oi.order_id', $order['id'])
                ->get()->getResultArray();
        }

        return $this->success(array_map([$this, 'formatOrderFull'], $orders));
    }

    // ── PUT /v1/orders/:id/status (vendor only) ───────────────────────────────

    public function updateStatus($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $input  = $this->inputJson();
        $db     = $this->db();

        $order  = $db->table('orders o')
            ->select('o.*, v.user_id AS vendor_user_id')
            ->join('vendors v', 'v.id = o.vendor_id')
            ->where('o.id', (int) $id)
            ->get()->getRowArray();

        if (! $order) return $this->error('Order not found.', 404);
        if ($order['vendor_user_id'] != $userId) return $this->error('Not authorized.', 403);

        $allowed = ['pending', 'confirmed', 'shipped', 'delivered', 'cancelled'];
        $status  = $input['status'] ?? '';
        if (! in_array($status, $allowed)) return $this->error('Invalid status.', 422);

        $db->table('orders')->where('id', (int) $id)->set(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')])->update();
        return $this->success(['status' => $status], 'Order status updated');
    }

    // ── Payment redirect handlers ─────────────────────────────────────────────

    public function paymentSuccess(string $orderId): ResponseInterface
    {
        $gateway = $this->request->getGet('gateway') ?? 'unknown';
        db_connect()->table('orders')->where('id', (int) $orderId)->set([
            'status'         => 'paid',
            'payment_method' => $gateway,
            'updated_at'     => date('Y-m-d H:i:s'),
        ])->update();
        return $this->_brandedPage('success', "Order #{$orderId} paid!", 'Payment received. Return to the app to view your order.', "dimensions://marketplace/orders/{$orderId}?status=paid");
    }

    public function paymentCancel(string $orderId): ResponseInterface
    {
        return $this->_brandedPage('cancelled', 'Payment Cancelled', 'Your order was not charged. Return to the app to try again.', "dimensions://marketplace/orders/{$orderId}?status=cancelled");
    }

    private function _brandedPage(string $type, string $title, string $subtitle, string $deepLink): ResponseInterface
    {
        $color = $type === 'success' ? '#2A9D5C' : ($type === 'cancelled' ? '#EF9F27' : '#D94032');
        $icon  = $type === 'success' ? '✓' : ($type === 'cancelled' ? '←' : '✗');
        $html  = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#D94032">
<title>Dimensions</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:#0A0A0A;color:#fff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
       display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
  .card{background:#161616;border:1px solid #2a2a2a;border-radius:20px;padding:40px 32px;max-width:380px;width:100%;text-align:center}
  .dot{width:12px;height:12px;background:#D94032;border-radius:50%;display:inline-block;margin-right:8px;vertical-align:middle}
  .brand{font-size:18px;font-weight:700;margin-bottom:32px}
  .icon{width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;
        font-size:32px;margin:0 auto 20px;background:{$color}22;border:2px solid {$color};color:{$color}}
  h1{font-size:20px;font-weight:700;margin-bottom:10px;color:{$color}}
  p{font-size:14px;color:rgba(255,255,255,.6);line-height:1.6;margin-bottom:28px}
  .btn{display:block;width:100%;padding:14px;border-radius:12px;font-size:15px;font-weight:700;
       text-decoration:none;background:#D94032;color:#fff;border:none}
</style>
</head>
<body>
<div class="card">
  <div class="brand"><span class="dot"></span>Dimensions</div>
  <div class="icon">{$icon}</div>
  <h1>{$title}</h1>
  <p>{$subtitle}</p>
  <a href="{$deepLink}" class="btn">Return to Dimensions</a>
</div>
<script>setTimeout(function(){ window.location.href='{$deepLink}'; }, 800);</script>
</body></html>
HTML;
        return $this->response->setStatusCode(200)->setContentType('text/html')->setBody($html);
    }

    private function formatCartItem(array $row): array
    {
        $images = isset($row['images']) ? json_decode($row['images'], true) : [];
        return [
            'id'          => (string) $row['id'],
            'product_id'  => (string) $row['product_id'],
            'name'        => $row['name'],
            'price'       => (float) $row['price'],
            'quantity'    => (int) $row['quantity'],
            'image_url'   => $images[0] ?? null,
            'vendor_id'   => (string) $row['vendor_id'],
            'vendor_name' => $row['vendor_name'],
            'subtotal'    => round($row['price'] * $row['quantity'], 2),
        ];
    }

    private function formatOrderFull(array $row): array
    {
        return [
            'id'           => (string) $row['id'],
            'vendor_name'  => $row['vendor_name'] ?? null,
            'vendor_logo'  => $row['vendor_logo'] ?? null,
            'status'       => $row['status'],
            'total_amount' => (float) $row['total_amount'],
            'items'        => array_map(fn($i) => [
                'name'       => $i['name'],
                'quantity'   => (int) $i['quantity'],
                'unit_price' => (float) $i['unit_price'],
                'image_url'  => (isset($i['images']) ? (json_decode($i['images'], true)[0] ?? null) : null),
            ], $row['items'] ?? []),
            'created_at'   => $row['created_at'],
        ];
    }
}
