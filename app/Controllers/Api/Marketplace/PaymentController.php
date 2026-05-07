<?php

namespace App\Controllers\Api\Marketplace;

use App\Controllers\Api\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Handles vendor-side customer payments via in-app SDKs.
 * Stripe  → returns client_secret + publishable_key for flutter_stripe SDK.
 * Flutterwave → returns tx_ref + public_key for flutterwave_standard SDK.
 * PayPal  → kept as redirect (no official Flutter SDK).
 */
class PaymentController extends BaseApiController
{
    private function db() { return db_connect(); }

    private function curl(string $url, array $opts): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 30,
        ] + $opts);
        $raw      = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$raw, $curlErr, $httpCode];
    }

    // ── POST /v1/marketplace/orders/:orderId/pay ──────────────────────────────

    public function createOrderPayment(string $orderId): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = $this->db();

        $order = $db->table('orders')->where('id', $orderId)->where('buyer_id', $userId)->get()->getRowArray();
        if (! $order) return $this->error('Order not found.', 404);
        if ($order['status'] !== 'pending') return $this->error('Order already processed.', 409);

        $vendor = $db->table('vendors')->where('id', $order['vendor_id'])->get()->getRowArray();
        if (! $vendor) return $this->error('Vendor not found.', 404);

        $input   = $this->inputJson();
        $gateway = $input['gateway'] ?? $this->preferredGateway($vendor);

        return match ($gateway) {
            'stripe'      => $this->stripeIntent($order, $vendor),
            'paypal'      => $this->paypalSession($order, $vendor),
            'flutterwave' => $this->flutterwaveInit($order, $vendor),
            default       => $this->error('No payment gateway available for this vendor.', 422),
        };
    }

    // ── POST /v1/marketplace/orders/:orderId/confirm-payment ─────────────────
    // Called by the Flutter SDK after a successful in-app payment.

    public function confirmPayment(string $orderId): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = $this->db();

        $order = $db->table('orders')->where('id', $orderId)->where('buyer_id', $userId)->get()->getRowArray();
        if (! $order) return $this->error('Order not found.', 404);
        if ($order['status'] === 'paid') return $this->success(['message' => 'Already paid.']);

        $input         = $this->inputJson();
        $gateway       = $input['gateway'] ?? '';
        $transactionId = $input['transaction_id'] ?? '';

        if (empty($gateway) || empty($transactionId)) {
            return $this->error('gateway and transaction_id are required.', 422);
        }

        $vendor = $db->table('vendors')->where('id', $order['vendor_id'])->get()->getRowArray();

        // Verify with the gateway before marking paid
        $verified = match ($gateway) {
            'stripe'      => $this->verifyStripe($transactionId, $vendor),
            'flutterwave' => $this->verifyFlutterwave($transactionId, $vendor, $order),
            default       => true, // PayPal verified via webhook
        };

        if (! $verified) return $this->error('Payment verification failed.', 402);

        $db->table('orders')->where('id', $orderId)->set([
            'status'            => 'paid',
            'payment_method'    => $gateway,
            'payment_reference' => $transactionId,
            'updated_at'        => date('Y-m-d H:i:s'),
        ])->update();

        return $this->success(['order_id' => $orderId, 'status' => 'paid']);
    }

    // ── GET /v1/marketplace/vendors/:vendorId/gateways ────────────────────────

    public function vendorGateways(string $vendorId): ResponseInterface
    {
        $vendor = $this->db()->table('vendors')->where('id', $vendorId)->get()->getRowArray();
        if (! $vendor) return $this->error('Vendor not found.', 404);

        return $this->success([
            'stripe'      => (bool) $vendor['stripe_enabled'],
            'paypal'      => (bool) $vendor['paypal_enabled'],
            'flutterwave' => (bool) $vendor['flutterwave_enabled'],
        ]);
    }

    // ── Webhooks ──────────────────────────────────────────────────────────────

    public function stripeWebhook(): ResponseInterface
    {
        $event = json_decode($this->request->getBody(), true);
        if (($event['type'] ?? '') === 'payment_intent.succeeded') {
            $pi      = $event['data']['object'];
            $orderId = $pi['metadata']['order_id'] ?? null;
            if ($orderId) {
                $this->db()->table('orders')->where('id', $orderId)->set([
                    'status'         => 'paid',
                    'payment_method' => 'stripe',
                    'updated_at'     => date('Y-m-d H:i:s'),
                ])->update();
            }
        }
        return $this->respond(['received' => true]);
    }

    public function paypalWebhook(): ResponseInterface
    {
        $payload = json_decode($this->request->getBody(), true);
        if (($payload['event_type'] ?? '') === 'CHECKOUT.ORDER.APPROVED') {
            $orderId = $payload['resource']['purchase_units'][0]['custom_id'] ?? null;
            if ($orderId) {
                $this->db()->table('orders')->where('id', $orderId)->set([
                    'status'         => 'paid',
                    'payment_method' => 'paypal',
                    'updated_at'     => date('Y-m-d H:i:s'),
                ])->update();
            }
        }
        return $this->respond(['received' => true]);
    }

    public function flutterwaveWebhook(): ResponseInterface
    {
        $payload = json_decode($this->request->getBody(), true);
        if (($payload['status'] ?? '') === 'successful') {
            $orderId = $payload['meta']['order_id'] ?? null;
            if ($orderId) {
                $this->db()->table('orders')->where('id', $orderId)->set([
                    'status'         => 'paid',
                    'payment_method' => 'flutterwave',
                    'updated_at'     => date('Y-m-d H:i:s'),
                ])->update();
            }
        }
        return $this->respond(['received' => true]);
    }

    // ── Private: gateway initialisers ─────────────────────────────────────────

    private function stripeIntent(array $order, array $vendor): ResponseInterface
    {
        $secret     = $vendor['stripe_secret_key']      ?? '';
        $pubKey     = $vendor['stripe_publishable_key'] ?? '';
        if (empty($secret) || empty($pubKey)) return $this->error('Vendor Stripe not configured.', 503);

        $amountCents = (int) round($order['total_amount'] * 100);

        [$raw, $curlErr, $code] = $this->curl('https://api.stripe.com/v1/payment_intents', [
            CURLOPT_POST       => true,
            CURLOPT_USERPWD    => "{$secret}:",
            CURLOPT_POSTFIELDS => http_build_query([
                'amount'                    => $amountCents,
                'currency'                  => 'usd',
                'automatic_payment_methods[enabled]' => 'true',
                'metadata[order_id]'        => $order['id'],
                'metadata[vendor_id]'       => $vendor['id'],
            ]),
        ]);

        if ($raw === false) return $this->error('Stripe connection failed: ' . $curlErr, 502);

        $pi = json_decode($raw, true);
        if ($code !== 200 || empty($pi['client_secret'])) {
            return $this->error($pi['error']['message'] ?? 'Stripe intent creation failed.', 502);
        }

        return $this->success([
            'gateway'         => 'stripe',
            'client_secret'   => $pi['client_secret'],
            'publishable_key' => $pubKey,
            'amount'          => $amountCents,
            'currency'        => 'usd',
        ]);
    }

    private function flutterwaveInit(array $order, array $vendor): ResponseInterface
    {
        $publicKey = $vendor['flutterwave_public_key'] ?? '';
        if (empty($publicKey)) return $this->error('Vendor Flutterwave not configured.', 503);

        $buyer = $this->db()->table('users')->where('id', $order['buyer_id'])->get()->getRowArray();
        $txRef = "order_{$order['id']}_" . time();
        $successUrl = base_url("v1/marketplace/orders/{$order['id']}/payment-success?gateway=flutterwave");

        // Flutter opens the hosted checkout page in a WebView (no server→Flutterwave API call needed)
        $checkoutUrl = base_url('v1/marketplace/flutterwave-checkout') . '?' . http_build_query([
            'public_key'   => $publicKey,
            'tx_ref'       => $txRef,
            'amount'       => $order['total_amount'],
            'currency'     => 'USD',
            'email'        => $buyer['email']  ?? 'customer@dimensions.app',
            'name'         => $buyer['name']   ?? 'Customer',
            'phone'        => $buyer['phone']  ?? '',
            'title'        => $vendor['name'],
            'description'  => "Order #{$order['id']}",
            'redirect_url' => $successUrl,
        ]);

        return $this->success([
            'gateway'     => 'flutterwave',
            'payment_url' => $checkoutUrl,
            'tx_ref'      => $txRef,
            'success_url' => $successUrl,
        ]);
    }

    // ── GET /v1/marketplace/flutterwave-checkout (public, no auth) ────────────
    // Serves the Flutterwave inline checkout page. Flutter opens this in WebView.

    public function flutterwaveCheckout(): ResponseInterface
    {
        $p = $this->request->getGet();
        $pubKey      = htmlspecialchars($p['public_key']   ?? '');
        $txRef       = htmlspecialchars($p['tx_ref']       ?? '');
        $amount      = (float) ($p['amount'] ?? 0);
        $currency    = htmlspecialchars($p['currency']     ?? 'USD');
        $email       = htmlspecialchars($p['email']        ?? '');
        $name        = htmlspecialchars($p['name']         ?? '');
        $phone       = htmlspecialchars($p['phone']        ?? '');
        $title       = htmlspecialchars($p['title']        ?? '');
        $description = htmlspecialchars($p['description']  ?? '');
        $redirectUrl = $p['redirect_url'] ?? '';

        if (empty($pubKey) || empty($txRef)) {
            return $this->error('Missing checkout parameters.', 422);
        }

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Payment</title>
  <style>
    body { margin: 0; background: #0A0A0A; display: flex; align-items: center;
           justify-content: center; min-height: 100vh; font-family: sans-serif; }
    .msg { color: #fff; font-size: 16px; }
  </style>
</head>
<body>
  <div class="msg">Loading payment...</div>
  <script src="https://checkout.flutterwave.com/v3.js"></script>
  <script>
    window.onload = function() {
      FlutterwaveCheckout({
        public_key:      "{$pubKey}",
        tx_ref:          "{$txRef}",
        amount:          {$amount},
        currency:        "{$currency}",
        payment_options: "card,ussd,banktransfer",
        redirect_url:    "{$redirectUrl}",
        customer: { email: "{$email}", name: "{$name}", phone_number: "{$phone}" },
        customizations:  { title: "{$title}", description: "{$description}", logo: "" },
        callback: function(data) {
          if (data.status === "successful" || data.status === "completed") {
            window.location.href = "{$redirectUrl}&transaction_id=" + data.transaction_id + "&status=" + data.status;
          }
        },
        onclose: function() {
          window.location.href = "{$redirectUrl}&status=cancelled";
        }
      });
    };
  </script>
</body>
</html>
HTML;

        return $this->response->setHeader('Content-Type', 'text/html')->setBody($html);
    }

    private function paypalSession(array $order, array $vendor): ResponseInterface
    {
        $clientId     = $vendor['paypal_client_id']     ?? '';
        $clientSecret = $vendor['paypal_client_secret'] ?? '';
        if (empty($clientId) || empty($clientSecret)) {
            return $this->error('Vendor PayPal not configured.', 503);
        }

        [$tokenRaw, $tokenErr] = $this->curl('https://api-m.paypal.com/v1/oauth2/token', [
            CURLOPT_POST       => true,
            CURLOPT_USERPWD    => "{$clientId}:{$clientSecret}",
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        ]);

        if ($tokenRaw === false) return $this->error('PayPal connection failed: ' . $tokenErr, 502);

        $tokenRes    = json_decode($tokenRaw, true);
        $accessToken = $tokenRes['access_token'] ?? '';
        if (empty($accessToken)) {
            return $this->error($tokenRes['error_description'] ?? 'PayPal auth failed.', 502);
        }

        $returnUrl = base_url("v1/marketplace/orders/{$order['id']}/payment-success?gateway=paypal");
        $cancelUrl = base_url("v1/marketplace/orders/{$order['id']}/payment-cancel");

        [$orderRaw, $orderErr] = $this->curl('https://api-m.paypal.com/v2/checkout/orders', [
            CURLOPT_POST       => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$accessToken}",
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'custom_id'   => $order['id'],
                    'amount'      => ['currency_code' => 'USD', 'value' => number_format($order['total_amount'], 2, '.', '')],
                    'description' => "Order #{$order['id']} — {$vendor['name']}",
                ]],
                'application_context' => [
                    'return_url'          => $returnUrl,
                    'cancel_url'          => $cancelUrl,
                    'shipping_preference' => 'NO_SHIPPING',
                ],
            ]),
        ]);

        if ($orderRaw === false) return $this->error('PayPal order connection failed: ' . $orderErr, 502);

        $orderRes    = json_decode($orderRaw, true);
        $approveLink = collect($orderRes['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null;
        if (! $approveLink) {
            return $this->error($orderRes['message'] ?? 'PayPal order creation failed.', 502);
        }

        return $this->success(['gateway' => 'paypal', 'payment_url' => $approveLink]);
    }

    // ── Private: verification ─────────────────────────────────────────────────

    private function verifyStripe(string $paymentIntentId, array $vendor): bool
    {
        $secret = $vendor['stripe_secret_key'] ?? '';
        if (empty($secret)) return false;

        [$raw] = $this->curl("https://api.stripe.com/v1/payment_intents/{$paymentIntentId}", [
            CURLOPT_USERPWD => "{$secret}:",
        ]);

        if ($raw === false) return false;
        $pi = json_decode($raw, true);
        return ($pi['status'] ?? '') === 'succeeded';
    }

    private function verifyFlutterwave(string $transactionId, array $vendor, array $order): bool
    {
        $secretKey = $vendor['flutterwave_secret_key'] ?? '';
        if (empty($secretKey)) return false;

        [$raw] = $this->curl("https://api.flutterwave.com/v3/transactions/{$transactionId}/verify", [
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$secretKey}"],
        ]);

        if ($raw === false) return false;
        $res = json_decode($raw, true);

        $data = $res['data'] ?? [];
        return ($data['status'] ?? '') === 'successful'
            && (float) ($data['amount'] ?? 0) >= (float) $order['total_amount']
            && strtoupper($data['currency'] ?? '') === 'USD';
    }

    // ── Private: helpers ──────────────────────────────────────────────────────

    private function preferredGateway(array $vendor): string
    {
        if ($vendor['stripe_enabled'])      return 'stripe';
        if ($vendor['paypal_enabled'])      return 'paypal';
        if ($vendor['flutterwave_enabled']) return 'flutterwave';
        return 'none';
    }
}
