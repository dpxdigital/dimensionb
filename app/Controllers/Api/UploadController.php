<?php

namespace App\Controllers\Api;

use App\Libraries\S3Uploader;
use CodeIgniter\HTTP\ResponseInterface;

class UploadController extends BaseApiController
{
    // POST /v1/upload
    // Accepts a single file under the field name "file".
    // Returns { "url": "https://..." }
    public function upload(): ResponseInterface
    {
        $userId = $this->authUserId();

        $file = $this->request->getFile('file');
        if (! $file || ! $file->isValid()) {
            return $this->error('No valid file provided.', 422);
        }

        $ext = strtolower($file->getClientExtension() ?: $file->guessExtension() ?: '');

        $imageExts   = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $heicExts    = ['heic', 'heif'];
        $videoExts   = ['mp4', 'mov', 'avi', 'mkv', 'webm'];
        $allowedExts = array_merge($imageExts, $heicExts, $videoExts);
        $blockedExts = ['exe', 'apk', 'sh', 'bat', 'cmd', 'php', 'php5', 'phtml', 'py', 'rb', 'pl', 'js', 'jsp', 'asp', 'aspx'];

        if (in_array($ext, $blockedExts, true) || ! in_array($ext, $allowedExts, true)) {
            return $this->error('Unsupported file type.', 422);
        }

        // Verify actual MIME type using magic bytes (not client-supplied Content-Type)
        $tmpUploadPath = $file->getTempName();
        if ($tmpUploadPath && function_exists('finfo_file')) {
            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $realMime = finfo_file($finfo, $tmpUploadPath);
            finfo_close($finfo);

            $allowedMimes = [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                'image/heic', 'image/heif',
                'video/mp4', 'video/quicktime', 'video/x-msvideo',
                'video/x-matroska', 'video/webm',
            ];
            if ($realMime && ! in_array($realMime, $allowedMimes, true)) {
                return $this->error('Unsupported file type.', 422);
            }
        }

        $isVideo  = in_array($ext, $videoExts, true);
        $maxBytes = $isVideo ? 100 * 1024 * 1024 : 10 * 1024 * 1024;
        if ($file->getSize() > $maxBytes) {
            $limit = $isVideo ? '100 MB' : '10 MB';
            return $this->error("File must be under {$limit}.", 422);
        }

        $tmpDir = WRITEPATH . 'uploads/tmp/';
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $filename = 'upload_' . $userId . '_' . time() . '_' . uniqid() . '.' . $ext;
        $file->move($tmpDir, $filename);
        $tmpPath  = $tmpDir . $filename;
        $mimeType = $isVideo ? "video/{$ext}" : 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);

        // Transcode HEIC/HEIF → WebP (JPEG fallback)
        if (in_array($ext, $heicExts, true)) {
            try {
                $tmpPath = $this->convertHeicIfNeeded($tmpPath, $tmpDir, $filename, $ext, $mimeType);
            } catch (\Throwable $e) {
                @unlink($tmpPath);
                log_message('error', 'HEIC conversion failed: ' . $e->getMessage());
                return $this->error('Image conversion failed. Please upload as JPEG or PNG.', 422);
            }
        }

        try {
            $s3  = new S3Uploader();
            $url = $s3->uploadOrLocal($tmpPath, "uploads/circles/{$filename}", $mimeType, 'circles');
        } catch (\Throwable $e) {
            @unlink($tmpPath);
            log_message('error', 'Upload failed: ' . $e->getMessage());
            return $this->error('Upload failed. Please try again.', 500);
        }

        @unlink($tmpPath);
        return $this->success(['url' => $url], 'Uploaded', 201);
    }
}
