<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;

/**
 * Base controller for all API endpoints.
 * Provides consistent JSON response helpers and input access.
 */
abstract class BaseApiController extends ResourceController
{
    protected $format = 'json';

    // ── Response helpers ──────────────────────────────────────────────────────

    protected function success(mixed $data = null, string $message = 'OK', int $code = 200, ?array $pagination = null): \CodeIgniter\HTTP\Response
    {
        $body = [
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
        ];
        if ($pagination !== null) {
            $body['pagination'] = $pagination;
        }
        return $this->respond($body, $code);
    }

    protected function error(string $message, int $code = 400, mixed $data = null): \CodeIgniter\HTTP\Response
    {
        return $this->respond([
            'status'  => 'error',
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    protected function validationError(array $errors): \CodeIgniter\HTTP\Response
    {
        return $this->respond([
            'status'  => 'error',
            'message' => 'Validation failed',
            'data'    => null,
            'errors'  => $errors,
        ], 422);
    }

    // ── Input helpers ─────────────────────────────────────────────────────────

    /**
     * Returns JSON body decoded as array.
     * Falls back to POST form data if Content-Type is not JSON.
     */
    protected function inputJson(): array
    {
        $body = $this->request->getJSON(true);
        if (! empty($body)) {
            return $body;
        }
        return $this->request->getPost() ?? [];
    }

    protected function authUserId(): int
    {
        return (int) ($this->request->userId ?? 0);
    }

    // ── Email ─────────────────────────────────────────────────────────────────

    /**
     * Send a transactional email via ZeptoMail REST API.
     */
    protected function sendMailViaZeptoMail(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody
    ): bool {
        $apiUrl    = rtrim(env('ZEPTO_API_URL',    'https://api.zeptomail.ca/v1.1/email'), '/');
        $apiToken  = env('ZEPTO_API_TOKEN',  '');
        $fromEmail = env('ZEPTO_FROM_EMAIL', 'info@kemafy.com');
        $fromName  = env('ZEPTO_FROM_NAME',  'Dimensions');

        if (empty($apiToken)) {
            log_message('error', '[ZeptoMail] ZEPTO_API_TOKEN not set in .env — email not sent.');
            return false;
        }

        $payload = json_encode([
            'from'     => ['address' => $fromEmail, 'name' => $fromName],
            'to'       => [['email_address' => ['address' => $toEmail, 'name' => $toName]]],
            'subject'  => $subject,
            'htmlbody' => $htmlBody,
        ]);

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Zoho-enczapikey ' . $apiToken,
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            log_message('error', '[ZeptoMail] cURL error: ' . $curlError);
            return false;
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            log_message('error', '[ZeptoMail] HTTP ' . $httpCode . ': ' . $response);
            return false;
        }

        return true;
    }

    protected function emailHtml(string $title, string $bodyHtml, string $code, string $expiry): string
    {
        return <<<HTML
<!DOCTYPE html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  body{margin:0;padding:24px;background:#0A0A0A;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
  .wrap{max-width:520px;margin:0 auto;background:#161616;border-radius:14px;overflow:hidden}
  .top{background:#D94032;padding:20px 32px}
  .top-logo{color:#fff;font-size:22px;font-weight:700;letter-spacing:-0.5px}
  .body{padding:32px}
  h2{color:#fff;font-size:20px;margin:0 0 12px}
  p{color:#AAAAAA;font-size:14px;line-height:1.65;margin:0 0 20px}
  .code-box{background:#1E1E1E;border-radius:10px;text-align:center;padding:22px 16px;margin:24px 0}
  .code{font-size:38px;font-weight:800;letter-spacing:12px;color:#fff}
  .expiry{color:#666;font-size:12px;margin-top:8px}
  .footer{border-top:1px solid #222;padding:20px 32px;color:#555;font-size:11px}
</style></head>
<body><div class="wrap">
  <div class="top"><span class="top-logo">Dimensions</span></div>
  <div class="body">
    <h2>{$title}</h2>
    {$bodyHtml}
    <div class="code-box">
      <div class="code">{$code}</div>
      <div class="expiry">Expires in {$expiry}</div>
    </div>
    <p>If you didn't request this, you can safely ignore this email.</p>
  </div>
  <div class="footer">Dimensions &mdash; Community Discovery &amp; Live Engagement<br>
  This is an automated message, please do not reply.</div>
</div></body></html>
HTML;
    }

    /**
     * If $ext is heic/heif, convert to WebP (or JPEG fallback) in-place.
     * Updates $filename, $ext, and $mimeType by reference.
     * Returns the new $tmpPath.
     * Throws RuntimeException if conversion fails.
     */
    protected function convertHeicIfNeeded(
        string $tmpPath,
        string $tmpDir,
        string &$filename,
        string &$ext,
        string &$mimeType
    ): string {
        if (! in_array($ext, ['heic', 'heif'], true)) return $tmpPath;

        $base = pathinfo($filename, PATHINFO_FILENAME);

        foreach (['webp' => 'image/webp', 'jpg' => 'image/jpeg'] as $targetExt => $targetMime) {
            $newFilename = $base . '.' . $targetExt;
            $newPath     = $tmpDir . $newFilename;

            // Try 1: Imagick PHP extension
            if (class_exists('Imagick')) {
                try {
                    $im = new \Imagick();
                    $im->readImage($tmpPath);
                    $im->setImageFormat($targetExt === 'jpg' ? 'jpeg' : 'webp');
                    $im->setImageCompressionQuality(85);
                    $im->stripImage();
                    $im->writeImage($newPath);
                    $im->clear();
                    $im->destroy();
                    @unlink($tmpPath);
                    $filename = $newFilename;
                    $ext      = $targetExt;
                    $mimeType = $targetMime;
                    return $newPath;
                } catch (\Throwable $e) {
                    @unlink($newPath);
                }
            }

            // Try 2: ImageMagick CLI `convert`
            if (function_exists('exec')) {
                @unlink($newPath);
                $cmd        = sprintf('convert %s %s 2>&1', escapeshellarg($tmpPath), escapeshellarg($newPath));
                $returnCode = 0;
                exec($cmd, $cmdOut, $returnCode);
                if ($returnCode === 0 && file_exists($newPath) && filesize($newPath) > 0) {
                    @unlink($tmpPath);
                    $filename = $newFilename;
                    $ext      = $targetExt;
                    $mimeType = $targetMime;
                    return $newPath;
                }
                @unlink($newPath);
            }
        }
        throw new \RuntimeException('HEIC/HEIF images are not supported on this server. Please upload as JPEG or PNG.');
    }
}
