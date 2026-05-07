<?php

namespace App\Controllers\Api;

use App\Libraries\S3Uploader;
use CodeIgniter\Controller;

class MediaController extends Controller
{
    /**
     * GET /v1/media?key={s3Key}
     *
     * Generates a short-lived presigned S3 GET URL and issues a 302 redirect.
     * The app follows the redirect transparently; cached images stay cached.
     */
    public function serve(): never
    {
        $key = trim($this->request->getGet('key') ?? '');

        // Basic sanity / path-traversal guard
        if ($key === '' || str_contains($key, '..') || ! preg_match('#^[\w\-./]+$#', $key)) {
            header('HTTP/1.1 400 Bad Request');
            exit('Invalid key');
        }

        $s3 = new S3Uploader();

        if (! $s3->isConfigured()) {
            // S3 not configured — try to serve from local FCPATH
            $localPath = FCPATH . $key;
            if (file_exists($localPath)) {
                header('Location: ' . base_url($key), true, 302);
            } else {
                header('HTTP/1.1 404 Not Found');
            }
            exit;
        }

        $presigned = $s3->generatePresignedGetUrl($key, 3600);
        header('Location: ' . $presigned, true, 302);
        header('Cache-Control: no-store'); // Browser should not cache the redirect itself
        exit;
    }
}
