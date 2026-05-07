<?php

namespace App\Controllers\Api\Marketplace;

use App\Controllers\Api\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Handles the one-time storefront activation fee collected by the platform.
 * Vendors pay via the platform's Stripe account. After payment:
 *   - activation_fee_paid = 1
 *   - is_approved = 1 (auto-approved after payment, admin can revoke)
 */
class ActivationController extends BaseApiController
{
    private function db() { return db_connect(); }

    private function setting(string $key, string $default = ''): string
    {
        $row = $this->db()->table('platform_settings')->where('key', $key)->get()->getRowArray();
        return $row ? (string) $row['value'] : $default;
    }

    // ── POST /v1/marketplace/activation/create-session ────────────────────────
    // Creates a Stripe Checkout Session and returns the redirect URL.

    public function createSession(): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = $this->db();

        $vendor = $db->table('vendors')->where('user_id', $userId)->get()->getRowArray();
        if (! $vendor) {
            return $this->error('You do not have a vendor store.', 404);
        }
        if ($vendor['activation_fee_paid']) {
            return $this->error('Activation fee already paid.', 409);
        }

        $stripeSecret = $this->setting('platform_stripe_secret');
        $feeAmount    = (float) $this->setting('activation_fee_amount', '9.99');
        $currency     = $this->setting('activation_fee_currency', 'usd');

        if (empty($stripeSecret)) {
            return $this->error('Platform payment not configured. Contact support.', 503);
        }

        // Create Stripe Checkout Session via Stripe REST API (no SDK needed)
        $amountCents = (int) ($feeAmount * 100);
        $successUrl  = base_url("v1/marketplace/activation/paid?session_id={CHECKOUT_SESSION_ID}&vendor_id={$vendor['id']}");
        $cancelUrl   = base_url("v1/marketplace/activation/cancel?vendor_id={$vendor['id']}");

        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => "{$stripeSecret}:",
            CURLOPT_POSTFIELDS     => http_build_query([
                'mode'                              => 'payment',
                'success_url'                       => $successUrl,
                'cancel_url'                        => $cancelUrl,
                'line_items[0][price_data][currency]'                   => $currency,
                'line_items[0][price_data][product_data][name]'         => 'Storefront Activation Fee',
                'line_items[0][price_data][product_data][description]'  => "One-time fee to activate {$vendor['name']} store on Dimensions.",
                'line_items[0][price_data][unit_amount]'                => $amountCents,
                'line_items[0][quantity]'                               => 1,
                'metadata[vendor_id]'               => $vendor['id'],
                'metadata[user_id]'                 => $userId,
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $session = json_decode($response, true);
        if ($httpCode !== 200 || empty($session['id'])) {
            log_message('error', 'Stripe session error: ' . $response);
            return $this->error('Failed to create payment session. Try again.', 502);
        }

        // Save session ID to vendor record
        $db->table('vendors')->where('id', $vendor['id'])->set([
            'activation_session_id' => $session['id'],
            'activation_fee_amount' => $feeAmount,
            'updated_at'            => date('Y-m-d H:i:s'),
        ])->update();

        return $this->success([
            'session_id'  => $session['id'],
            'payment_url' => $session['url'],
            'amount'      => $feeAmount,
            'currency'    => strtoupper($currency),
        ]);
    }

    // ── GET /v1/marketplace/activation/paid ───────────────────────────────────
    // Stripe redirects here after successful payment (server-side confirmation).

    public function paid(): ResponseInterface
    {
        $sessionId = $this->request->getGet('session_id');
        $vendorId  = (int) $this->request->getGet('vendor_id');

        if (! $sessionId || ! $vendorId) {
            return redirect()->to(env('APP_FRONTEND_URL', 'https://dimensions.app') . '/marketplace/activation?status=error');
        }

        $stripeSecret = $this->setting('platform_stripe_secret');
        // Verify session with Stripe
        $ch = curl_init("https://api.stripe.com/v1/checkout/sessions/{$sessionId}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => "{$stripeSecret}:",
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $session = json_decode($response, true);

        if (($session['payment_status'] ?? '') === 'paid' && (int) ($session['metadata']['vendor_id'] ?? 0) === $vendorId) {
            db_connect()->table('vendors')->where('id', $vendorId)->set([
                'activation_fee_paid'       => 1,
                'is_approved'               => 1,
                'activation_payment_intent' => $session['payment_intent'] ?? null,
                'updated_at'                => date('Y-m-d H:i:s'),
            ])->update();
        }

        return redirect()->to(env('APP_FRONTEND_URL', 'https://dimensions.app') . '/marketplace/activation?status=success');
    }

    // ── POST /v1/marketplace/activation/webhook ───────────────────────────────
    // Stripe webhook fallback (more reliable than redirect).

    public function webhook(): ResponseInterface
    {
        $payload   = $this->request->getBody();
        $sigHeader = $this->request->getHeaderLine('Stripe-Signature');
        $secret    = $this->setting('platform_stripe_webhook_secret');

        // Signature verification
        if (! empty($secret)) {
            $parts     = [];
            foreach (explode(',', $sigHeader) as $part) {
                [$k, $v] = explode('=', $part, 2);
                $parts[$k][] = $v;
            }
            $timestamp = $parts['t'][0] ?? 0;
            $expected  = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
            if (! in_array($expected, $parts['v1'] ?? [], true)) {
                return $this->respond(['error' => 'Invalid signature'], 400);
            }
        }

        $event = json_decode($payload, true);
        if ($event['type'] === 'checkout.session.completed') {
            $session  = $event['data']['object'];
            $vendorId = (int) ($session['metadata']['vendor_id'] ?? 0);
            if ($vendorId && ($session['payment_status'] ?? '') === 'paid') {
                db_connect()->table('vendors')->where('id', $vendorId)->set([
                    'activation_fee_paid'       => 1,
                    'is_approved'               => 1,
                    'activation_payment_intent' => $session['payment_intent'] ?? null,
                    'updated_at'                => date('Y-m-d H:i:s'),
                ])->update();
            }
        }

        return $this->respond(['received' => true]);
    }

    // ── GET /v1/marketplace/activation/status ─────────────────────────────────

    public function status(): ResponseInterface
    {
        $userId = $this->authUserId();
        $vendor = $this->db()->table('vendors')->where('user_id', $userId)->get()->getRowArray();

        // Always return the admin-configured fee settings so the app shows the correct amount,
        // even when the vendor record doesn't exist yet.
        $feeAmount   = (float) $this->setting('activation_fee_amount', '9.99');
        $feeCurrency = strtoupper($this->setting('activation_fee_currency', 'USD'));

        if (! $vendor) {
            return $this->success([
                'activation_fee_paid'     => false,
                'is_approved'             => false,
                'rejection_reason'        => null,
                'activation_fee_amount'   => $feeAmount,
                'activation_fee_currency' => $feeCurrency,
            ]);
        }

        return $this->success([
            'activation_fee_paid'     => (bool) $vendor['activation_fee_paid'],
            'is_approved'             => (bool) $vendor['is_approved'],
            'rejection_reason'        => $vendor['rejection_reason'] ?? null,
            'activation_fee_amount'   => $feeAmount,
            'activation_fee_currency' => $feeCurrency,
        ]);
    }
}
