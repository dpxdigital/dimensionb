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
        $successUrl = base_url("v1/marketplace/activation/paid?session_id={CHECKOUT_SESSION_ID}&vendor_id={$vendor['id']}");
        $cancelUrl  = base_url("v1/marketplace/activation/cancel?vendor_id={$vendor['id']}");

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
        $status    = 'success';

        if (! $sessionId || ! $vendorId) {
            $status = 'error';
        } else {
            $stripeSecret = $this->setting('platform_stripe_secret');
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
        }

        return $this->_brandedReturnPage($status);
    }

    // ── GET /v1/marketplace/activation/cancel ─────────────────────────────────
    public function cancel(): ResponseInterface
    {
        return $this->_brandedReturnPage('cancelled');
    }

    private function _brandedReturnPage(string $status): ResponseInterface
    {
        $deepLink  = "dimensions://marketplace/activation?status={$status}";
        $isSuccess   = $status === 'success';
        $isCancelled = $status === 'cancelled';
        $icon    = $isSuccess ? '✓' : ($isCancelled ? '←' : '✗');
        $title   = $isSuccess ? 'Payment Successful!' : ($isCancelled ? 'Payment Cancelled' : 'Payment Error');
        $subtitle = $isSuccess
            ? 'Your activation fee has been received. Return to the app to check your store status.'
            : ($isCancelled
                ? 'You cancelled the payment. Return to the app to try again whenever you\'re ready.'
                : 'Something went wrong. Return to the app and try again.');
        $color = $isSuccess ? '#2A9D5C' : ($isCancelled ? '#EF9F27' : '#D94032');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#D94032">
<title>Dimensions</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:#0A0A0A;color:#fff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
       display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
  .card{background:#161616;border:1px solid #2a2a2a;border-radius:20px;padding:40px 32px;
        max-width:380px;width:100%;text-align:center}
  .dot{width:12px;height:12px;background:#D94032;border-radius:50%;display:inline-block;margin-right:8px;vertical-align:middle}
  .brand{font-size:18px;font-weight:700;color:#fff;margin-bottom:32px}
  .icon{width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;
        font-size:32px;margin:0 auto 20px;background:{$color}22;border:2px solid {$color}}
  h1{font-size:20px;font-weight:700;margin-bottom:10px;color:{$color}}
  p{font-size:14px;color:rgba(255,255,255,.6);line-height:1.6;margin-bottom:28px}
  .btn{display:block;width:100%;padding:14px;border-radius:12px;font-size:15px;font-weight:700;
       text-decoration:none;background:#D94032;color:#fff;margin-bottom:12px;cursor:pointer;
       border:none}
  .btn-outline{background:transparent;border:1px solid #333;color:rgba(255,255,255,.5);font-size:13px;padding:10px}
</style>
</head>
<body>
<div class="card">
  <div class="brand"><span class="dot"></span>Dimensions</div>
  <div class="icon" style="color:{$color}">{$icon}</div>
  <h1>{$title}</h1>
  <p>{$subtitle}</p>
  <a href="{$deepLink}" class="btn" id="openApp">Return to Dimensions</a>
  <p style="font-size:12px;color:rgba(255,255,255,.3);margin-top:8px">
    If the button doesn't work, close this browser tab and return to Dimensions.
  </p>
</div>
<script>
  // Attempt deep link navigation so the in-app WebView can intercept it
  setTimeout(function(){ try { window.location.href = '{$deepLink}'; } catch(e) {} }, 500);
</script>
</body>
</html>
HTML;

        return $this->response->setStatusCode(200)
            ->setContentType('text/html')
            ->setBody($html);
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
