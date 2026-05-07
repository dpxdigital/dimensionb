<?php

namespace App\Libraries;

/**
 * Minimal AWS S3 uploader using Signature V4 — no SDK required.
 */
class S3Uploader
{
    private string $key;
    private string $secret;
    private string $region;
    private string $bucket;
    private string $endpoint;

    public function __construct()
    {
        $this->key      = env('AWS_ACCESS_KEY_ID', '');
        $this->secret   = env('AWS_SECRET_ACCESS_KEY', '');
        $this->region   = env('AWS_DEFAULT_REGION', 'us-east-1');
        $this->bucket   = env('AWS_BUCKET', '');
        $this->endpoint = env('AWS_ENDPOINT', "https://{$this->bucket}.s3.{$this->region}.amazonaws.com");
    }

    public function isConfigured(): bool
    {
        return ! empty($this->key) && ! empty($this->secret) && ! empty($this->bucket);
    }

    /**
     * Upload a file to S3 and return its public URL.
     *
     * @param string $localPath  Absolute path to the file on disk
     * @param string $s3Key      Destination key in the bucket, e.g. 'uploads/avatars/foo.jpg'
     * @param string $mimeType   MIME type, e.g. 'image/jpeg'
     * @return string            Public URL
     * @throws \RuntimeException on failure
     */
    public function upload(string $localPath, string $s3Key, string $mimeType = 'application/octet-stream'): string
    {
        $body    = file_get_contents($localPath);
        $date    = gmdate('Ymd');
        $datetime = gmdate('Ymd\THis\Z');

        $bodyHash   = hash('sha256', $body);
        $contentLen = strlen($body);

        $host = "{$this->bucket}.s3.{$this->region}.amazonaws.com";
        $url  = "https://{$host}/{$s3Key}";

        // Canonical headers (sorted lowercase) — no x-amz-acl, bucket policy handles public reads
        $headers = [
            'content-length' => $contentLen,
            'content-type'   => $mimeType,
            'host'           => $host,
            'x-amz-content-sha256' => $bodyHash,
            'x-amz-date'     => $datetime,
        ];
        ksort($headers);

        $canonicalHeaders = '';
        $signedHeaders    = '';
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= "{$k}:{$v}\n";
            $signedHeaders    .= "{$k};";
        }
        $signedHeaders = rtrim($signedHeaders, ';');

        $canonicalRequest = implode("\n", [
            'PUT',
            '/' . $s3Key,
            '',
            $canonicalHeaders,
            $signedHeaders,
            $bodyHash,
        ]);

        $credentialScope = "{$date}/{$this->region}/s3/aws4_request";
        $stringToSign    = implode("\n", [
            'AWS4-HMAC-SHA256',
            $datetime,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->getSigningKey($date);
        $signature  = hash_hmac('sha256', $stringToSign, $signingKey);

        $authHeader = "AWS4-HMAC-SHA256 Credential={$this->key}/{$credentialScope}, "
            . "SignedHeaders={$signedHeaders}, Signature={$signature}";

        $curlHeaders = [
            "Authorization: {$authHeader}",
            "Content-Length: {$contentLen}",
            "Content-Type: {$mimeType}",
            "x-amz-content-sha256: {$bodyHash}",
            "x-amz-date: {$datetime}",
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response   = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($statusCode !== 200) {
            throw new \RuntimeException("S3 upload failed (HTTP {$statusCode}): {$response}");
        }

        return "https://{$host}/{$s3Key}";
    }

    private function getSigningKey(string $date): string
    {
        $kDate    = hash_hmac('sha256', $date,           "AWS4{$this->secret}", true);
        $kRegion  = hash_hmac('sha256', $this->region,   $kDate,   true);
        $kService = hash_hmac('sha256', 's3',            $kRegion, true);
        return     hash_hmac('sha256', 'aws4_request',   $kService, true);
    }

    /**
     * Upload a file, falling back to local FCPATH storage if S3 is not configured.
     * When S3 is configured, returns a proxy URL (v1/media?key=…) so the object
     * is served via presigned redirect rather than a direct (blocked) S3 URL.
     *
     * @return string  Public URL of the stored file
     */
    public function uploadOrLocal(string $localPath, string $s3Key, string $mimeType, string $subfolder): string
    {
        if ($this->isConfigured()) {
            try {
                $this->upload($localPath, $s3Key, $mimeType);
                // Return a proxy URL; the MediaController generates a fresh presigned URL on each request.
                return rtrim(base_url(), '/') . '/v1/media?key=' . urlencode($s3Key);
            } catch (\Throwable $e) {
                log_message('error', 'S3 upload failed, using local fallback: ' . $e->getMessage());
            }
        }

        // Local fallback
        $dest = FCPATH . 'uploads/' . $subfolder . '/';
        if (! is_dir($dest)) {
            @mkdir($dest, 0755, true);
        }
        $filename = basename($localPath);
        $target   = $dest . $filename;
        if ($localPath !== $target) {
            @copy($localPath, $target);
        }
        return base_url('uploads/' . $subfolder . '/' . $filename);
    }

    /**
     * Generate a presigned GET URL for an S3 object.
     *
     * @param string $s3Key      e.g. 'uploads/listings/foo.jpg'
     * @param int    $expiresIn  Seconds until expiry (default 1 hour)
     */
    public function generatePresignedGetUrl(string $s3Key, int $expiresIn = 3600): string
    {
        $date      = gmdate('Ymd');
        $datetime  = gmdate('Ymd\THis\Z');
        $host      = "{$this->bucket}.s3.{$this->region}.amazonaws.com";
        $credScope = "{$date}/{$this->region}/s3/aws4_request";

        $queryParams = [
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => "{$this->key}/{$credScope}",
            'X-Amz-Date'          => $datetime,
            'X-Amz-Expires'       => (string) $expiresIn,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($queryParams);
        $qs = http_build_query($queryParams);

        $canonicalRequest = implode("\n", [
            'GET',
            '/' . $s3Key,
            $qs,
            "host:{$host}\n",
            'host',
            'UNSIGNED-PAYLOAD',
        ]);

        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $datetime,
            $credScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->getSigningKey($date);
        $signature  = hash_hmac('sha256', $stringToSign, $signingKey);

        return "https://{$host}/{$s3Key}?{$qs}&X-Amz-Signature={$signature}";
    }
}
