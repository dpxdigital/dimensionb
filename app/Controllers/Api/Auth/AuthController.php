<?php

namespace App\Controllers\Api\Auth;

use App\Controllers\Api\BaseApiController;
use App\Libraries\JWTHandler;
use App\Models\FcmTokenModel;
use App\Models\RefreshTokenModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

class AuthController extends BaseApiController
{
    private UserModel         $users;
    private RefreshTokenModel $refreshTokens;
    private JWTHandler        $jwt;
    private int               $refreshTtl;

    public function __construct()
    {
        $this->users         = new UserModel();
        $this->refreshTokens = new RefreshTokenModel();
        $this->jwt           = new JWTHandler();
        $this->refreshTtl    = (int) (env('JWT_REFRESH_EXPIRY') ?: 2592000);
    }

    // ── POST /v1/auth/register ────────────────────────────────────────────────

    public function register(): ResponseInterface
    {
        $db = db_connect();
        $this->ensureEmailTables($db);

        $input = $this->inputJson();

        $rules = [
            'name'     => 'required|min_length[2]|max_length[120]',
            'email'    => 'required|valid_email|max_length[191]|is_unique[users.email]',
            'password' => 'required|min_length[8]|max_length[72]',
            'phone'    => 'permit_empty|max_length[30]',
        ];

        if (! $this->validateData($input, $rules)) {
            return $this->validationError($this->validator->getErrors());
        }

        $email  = strtolower(trim($input['email']));
        // Bypass UserModel::$allowedFields for system-set fields (trust_level, is_active)
        $db->table('users')->insert([
            'name'              => trim($input['name']),
            'email'             => $email,
            'phone'             => $input['phone'] ?? null,
            'password_hash'     => password_hash($input['password'], PASSWORD_BCRYPT, ['cost' => 12]),
            'trust_level'       => 'community_submitted',
            'trust_label'       => 'Community Submitted',
            'is_active'         => 1,
            'email_verified_at' => null,
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);
        $userId = $db->insertID();

        if (! $userId) {
            return $this->error('Registration failed. Please try again.', 500);
        }

        if (! empty($input['interests']) && is_array($input['interests'])) {
            $this->users->syncInterests((int) $userId, $input['interests']);
        }

        $code = $this->sendVerificationCode($db, (int) $userId, $email, trim($input['name']));

        $responseData = ['requires_verification' => true, 'email' => $email];
        if (ENVIRONMENT === 'development') {
            $responseData['dev_code'] = $code;
        }

        return $this->success($responseData, 'Account created. Please verify your email.', 201);
    }

    // ── POST /v1/auth/login ───────────────────────────────────────────────────

    public function login(): ResponseInterface
    {
        // Rate limit: 3 login attempts per minute per IP
        if (! $this->checkLoginRateLimit()) {
            return $this->error('Too many login attempts. Please wait a minute.', 429);
        }

        $input = $this->inputJson();

        $rules = [
            'password' => 'required',
        ];
        // Accept email or phone
        if (! empty($input['email'])) {
            $rules['email'] = 'required|valid_email';
        } elseif (! empty($input['phone'])) {
            $rules['phone'] = 'required';
        } else {
            return $this->error('Email or phone is required.', 422);
        }

        if (! $this->validateData($input, $rules)) {
            return $this->validationError($this->validator->getErrors());
        }

        // Find user
        $user = ! empty($input['email'])
            ? $this->users->findByEmail(strtolower(trim($input['email'])))
            : $this->users->findByPhone(trim($input['phone']));

        if ($user === null || ! password_verify($input['password'], $user['password_hash'])) {
            return $this->error('Invalid credentials.', 401);
        }

        if (! $user['is_active']) {
            return $this->error('Account is suspended. Contact support.', 403);
        }

        if (empty($user['email_verified_at'])) {
            return $this->error('Please verify your email before logging in.', 403, [
                'requires_verification' => true,
                'email'                 => $user['email'],
            ]);
        }

        return $this->issueTokensForUser((int) $user['id'], 'Login successful');
    }

    // ── POST /v1/auth/refresh ─────────────────────────────────────────────────

    public function refresh(): ResponseInterface
    {
        $input        = $this->inputJson();
        $rawToken     = trim($input['refresh_token'] ?? '');

        if (empty($rawToken)) {
            return $this->error('refresh_token is required.', 422);
        }

        // Validate JWT signature + expiry
        $payload = $this->jwt->validateRefreshToken($rawToken);
        if ($payload === null) {
            return $this->error('Invalid or expired refresh token.', 401);
        }

        // Validate token is in DB and not revoked
        $storedToken = $this->refreshTokens->findValid($rawToken);
        if ($storedToken === null) {
            return $this->error('Refresh token has been revoked or does not exist.', 401);
        }

        $userId = (int) $payload->sub;

        // Rotate: revoke old, issue new pair
        $this->refreshTokens->revoke($rawToken);

        $newAccess  = $this->jwt->generateAccessToken($userId);
        $newRefresh = $this->jwt->generateRefreshToken($userId);
        $this->refreshTokens->store($userId, $newRefresh, $this->refreshTtl);

        return $this->success([
            'access_token'  => $newAccess,
            'refresh_token' => $newRefresh,
        ], 'Token refreshed successfully');
    }

    // ── POST /v1/auth/logout — requires AuthFilter ────────────────────────────

    public function logout(): ResponseInterface
    {
        $input    = $this->inputJson();
        $rawToken = trim($input['refresh_token'] ?? '');

        // Revoke the specific device token if provided; otherwise revoke all
        if (! empty($rawToken)) {
            $this->refreshTokens->revoke($rawToken);
        } else {
            $this->refreshTokens->revokeAll($this->authUserId());
        }

        return $this->success(null, 'Logged out successfully');
    }

    // ── GET /v1/auth/me — requires AuthFilter ─────────────────────────────────

    public function me(): ResponseInterface
    {
        $user = $this->users->find($this->authUserId());
        if ($user === null) {
            return $this->error('User not found.', 404);
        }

        return $this->success(
            $this->users->safeUser($this->users->withInterests($user))
        );
    }

    // ── PUT /v1/auth/me — requires AuthFilter ─────────────────────────────────

    public function updateMe(): ResponseInterface
    {
        $input = $this->inputJson();

        $rules = [
            'name'       => 'permit_empty|min_length[2]|max_length[120]',
            'bio'        => 'permit_empty|max_length[500]',
            'location'   => 'permit_empty|max_length[255]',
            'avatar_url' => 'permit_empty|valid_url_strict|max_length[500]',
            'cover_url'  => 'permit_empty|valid_url_strict|max_length[500]',
            'phone'      => 'permit_empty|max_length[30]',
        ];

        if (! $this->validateData($input, $rules)) {
            return $this->validationError($this->validator->getErrors());
        }

        $userId     = $this->authUserId();
        $updateData = array_filter([
            'name'       => isset($input['name'])       ? trim($input['name']) : null,
            'bio'        => isset($input['bio'])        ? trim($input['bio']) : null,
            'location'   => isset($input['location'])   ? trim($input['location']) : null,
            'avatar_url' => $input['avatar_url'] ?? null,
            'cover_url'  => $input['cover_url']  ?? null,
            'phone'      => isset($input['phone'])      ? trim($input['phone']) : null,
        ], fn($v) => $v !== null);

        if (! empty($updateData)) {
            $this->users->update($userId, $updateData);
        }

        // Sync interests if provided
        if (isset($input['interests']) && is_array($input['interests'])) {
            $this->users->syncInterests($userId, $input['interests']);
        }

        $user = $this->users->withInterests($this->users->find($userId));
        return $this->success($this->users->safeUser($user), 'Profile updated');
    }

    // ── POST /v1/auth/me/avatar — requires AuthFilter ────────────────────────

    public function uploadAvatar(): ResponseInterface
    {
        $userId = $this->authUserId();
        $file   = $this->request->getFile('avatar');

        if (! $file || ! $file->isValid()) {
            return $this->error('No valid file uploaded.', 422);
        }

        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif'];
        $ext = strtolower($file->getClientExtension() ?: $file->getExtension() ?: '');
        if (! in_array($ext, $allowedExts, true)) {
            return $this->error('Only JPEG, PNG, WebP, GIF and HEIC images are allowed.', 422);
        }

        if ($file->getSize() > 5 * 1024 * 1024) {
            return $this->error('Image must be under 5 MB.', 422);
        }

        $tmpDir  = WRITEPATH . 'uploads/';
        if (! is_dir($tmpDir)) mkdir($tmpDir, 0755, true);

        $filename = 'avatar_' . $userId . '_' . time() . '.' . $ext;
        $tmpPath  = $tmpDir . $filename;

        if (! $file->move($tmpDir, $filename)) {
            return $this->error('Upload failed.', 500);
        }

        $mimeType = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
        if (in_array($ext, ['heic', 'heif'], true)) {
            try {
                $tmpPath = $this->convertHeicIfNeeded($tmpPath, $tmpDir, $filename, $ext, $mimeType);
            } catch (\Throwable $e) {
                @unlink($tmpPath);
                return $this->error('HEIC conversion failed: ' . $e->getMessage(), 422);
            }
        }

        try {
            $s3 = new \App\Libraries\S3Uploader();
            $avatarUrl = $s3->uploadOrLocal($tmpPath, "uploads/avatars/{$filename}", $mimeType, 'avatars');
        } catch (\Throwable $e) {
            return $this->error('Upload failed: ' . $e->getMessage(), 500);
        }

        $this->users->update($userId, ['avatar_url' => $avatarUrl]);

        return $this->success(['avatar_url' => $avatarUrl], 'Avatar updated');
    }

    // ── POST /v1/auth/me/cover — requires AuthFilter ─────────────────────────

    public function uploadCover(): ResponseInterface
    {
        $userId = $this->authUserId();
        $file   = $this->request->getFile('cover');

        if (! $file || ! $file->isValid()) {
            return $this->error('No valid file uploaded.', 422);
        }

        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif'];
        $ext = strtolower($file->getClientExtension() ?: $file->getExtension() ?: '');
        if (! in_array($ext, $allowedExts, true)) {
            return $this->error('Only JPEG, PNG, WebP, GIF and HEIC images are allowed.', 422);
        }

        if ($file->getSize() > 10 * 1024 * 1024) {
            return $this->error('Cover image must be under 10 MB.', 422);
        }

        $tmpDir  = WRITEPATH . 'uploads/';
        if (! is_dir($tmpDir)) mkdir($tmpDir, 0755, true);

        $filename = 'cover_' . $userId . '_' . time() . '.' . $ext;
        $tmpPath  = $tmpDir . $filename;

        if (! $file->move($tmpDir, $filename)) {
            return $this->error('Upload failed.', 500);
        }

        $mimeType = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
        if (in_array($ext, ['heic', 'heif'], true)) {
            try {
                $tmpPath = $this->convertHeicIfNeeded($tmpPath, $tmpDir, $filename, $ext, $mimeType);
            } catch (\Throwable $e) {
                @unlink($tmpPath);
                return $this->error('HEIC conversion failed: ' . $e->getMessage(), 422);
            }
        }

        try {
            $s3 = new \App\Libraries\S3Uploader();
            $coverUrl = $s3->uploadOrLocal($tmpPath, "uploads/covers/{$filename}", $mimeType, 'covers');
        } catch (\Throwable $e) {
            return $this->error('Upload failed: ' . $e->getMessage(), 500);
        }

        $this->users->update($userId, ['cover_url' => $coverUrl]);

        return $this->success(['cover_url' => $coverUrl], 'Cover photo updated');
    }

    // ── POST /v1/auth/fcm-token — requires AuthFilter ─────────────────────────

    public function registerFcmToken(): ResponseInterface
    {
        $input = $this->inputJson();

        $rules = [
            'token'    => 'required|max_length[500]',
            'platform' => 'permit_empty|in_list[android,ios]',
        ];

        if (! $this->validateData($input, $rules)) {
            return $this->validationError($this->validator->getErrors());
        }

        $fcmModel = new FcmTokenModel();
        $fcmModel->upsert(
            $this->authUserId(),
            trim($input['token']),
            $input['platform'] ?? null
        );

        return $this->success(null, 'FCM token registered');
    }

    // ── POST /v1/auth/social ──────────────────────────────────────────────────

    public function social(): ResponseInterface
    {
        $input = $this->inputJson();

        $rules = [
            'provider' => 'required|in_list[google,apple]',
            'id_token' => 'required',
        ];

        if (! $this->validateData($input, $rules)) {
            return $this->validationError($this->validator->getErrors());
        }

        $provider = $input['provider'];
        $idToken  = trim($input['id_token']);

        // Verify with the appropriate provider
        $socialUser = match ($provider) {
            'google' => $this->verifyGoogleToken($idToken),
            'apple'  => $this->verifyAppleToken($idToken),
        };

        if ($socialUser === null) {
            return $this->error("Invalid {$provider} token.", 401);
        }

        // Find or create user — social providers pre-verify the email
        $user = $this->users->findByEmail($socialUser['email']);
        if ($user === null) {
            $userId = $this->users->insert([
                'name'              => $socialUser['name'],
                'email'             => $socialUser['email'],
                'password_hash'     => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
                'avatar_url'        => $socialUser['avatar_url'] ?? null,
                'trust_level'       => 'community_submitted',
                'is_active'         => 1,
                'email_verified_at' => date('Y-m-d H:i:s'),
            ]);
            $user = $this->users->find((int) $userId);
        } elseif (empty($user['email_verified_at'])) {
            $this->users->update((int) $user['id'], ['email_verified_at' => date('Y-m-d H:i:s')]);
        }

        return $this->issueTokensForUser((int) $user['id'], 'Social login successful');
    }

    // ── POST /v1/auth/forgot-password ────────────────────────────────────────

    public function forgotPassword(): ResponseInterface
    {
        $db = db_connect();
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
                `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `email`      VARCHAR(191)    NOT NULL,
                `token`      VARCHAR(10)     NOT NULL,
                `expires_at` DATETIME        NOT NULL,
                `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {}

        $input = $this->inputJson();
        $email = strtolower(trim($input['email'] ?? ''));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->validationError(['email' => 'A valid email address is required.']);
        }

        $user = $this->users->where('email', $email)->first();
        if (! $user) {
            // Don't reveal whether email exists
            return $this->success(['sent' => true], 'If this email is registered, a reset code has been sent.');
        }

        try {
            $db->table('password_reset_tokens')->where('email', $email)->delete();

            $code = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $db->table('password_reset_tokens')->insert([
                'email'      => $email,
                'token'      => $code,
                'expires_at' => date('Y-m-d H:i:s', time() + 900),
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[forgotPassword] DB error: ' . $e->getMessage());
            return $this->error('Unable to process request. Please try again later.', 500);
        }

        $html = $this->emailHtml(
            'Password Reset Code',
            '<p>We received a request to reset the password for your Dimensions account. Use the code below to set a new password.</p>',
            $code,
            '15 minutes'
        );
        $this->sendMailViaZeptoMail($email, $user['name'] ?? '', 'Your Dimensions password reset code', $html);

        $responseData = ['sent' => true];
        if (ENVIRONMENT === 'development') {
            $responseData['dev_code'] = $code;
        }

        return $this->success($responseData, 'If this email is registered, a reset code has been sent.');
    }

    // ── POST /v1/auth/reset-password ─────────────────────────────────────────

    public function resetPassword(): ResponseInterface
    {
        $db    = db_connect();
        $input = $this->inputJson();

        $email    = strtolower(trim($input['email']        ?? ''));
        $code     = trim($input['code']                    ?? '');
        $password = $input['new_password']                 ?? '';

        $errors = [];
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email']        = 'Valid email is required.';
        if (strlen($code) !== 6)                          $errors['code']         = 'Enter the 6-digit reset code.';
        if (strlen($password) < 8)                        $errors['new_password'] = 'Password must be at least 8 characters.';

        if ($errors) {
            return $this->validationError($errors);
        }

        $token = $db->table('password_reset_tokens')
            ->where('email', $email)
            ->where('token', $code)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->get()->getRowArray();

        if (! $token) {
            return $this->error('Invalid or expired reset code.', 400);
        }

        $user = $this->users->where('email', $email)->first();
        if (! $user) {
            return $this->error('Account not found.', 404);
        }

        $this->users->update((int) $user['id'], [
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
        ]);

        $db->table('password_reset_tokens')->where('email', $email)->delete();

        return $this->success(null, 'Password reset successfully. Please log in.');
    }

    // ── POST /v1/auth/verify-email ────────────────────────────────────────────

    public function verifyEmail(): ResponseInterface
    {
        $db    = db_connect();
        $input = $this->inputJson();

        $email = strtolower(trim($input['email'] ?? ''));
        $code  = trim($input['code'] ?? '');

        $errors = [];
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Valid email is required.';
        if (strlen($code) !== 6)                          $errors['code']  = 'Enter the 6-digit verification code.';
        if ($errors) return $this->validationError($errors);

        // Brute-force protection: max 5 failed guesses per email per 15 minutes
        $cache       = \Config\Services::cache();
        $attemptKey  = 'otp_fail_' . md5($email);
        $otpAttempts = (int) ($cache->get($attemptKey) ?? 0);
        if ($otpAttempts >= 5) {
            return $this->error('Too many failed attempts. Please request a new verification code.', 429);
        }

        $record = $db->table('email_verifications')
            ->where('email', $email)
            ->where('code', $code)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->get()->getRowArray();

        if (! $record) {
            // Increment failure counter
            $ttl  = 900;
            $meta = $cache->getMetaData($attemptKey);
            if ($meta && isset($meta['expire'])) {
                $ttl = max(1, (int)($meta['expire'] - time()));
            }
            $cache->save($attemptKey, $otpAttempts + 1, $ttl);
            return $this->error('Invalid or expired verification code.', 400);
        }

        $cache->delete($attemptKey);

        $user = $this->users->where('email', $email)->first();
        if (! $user) {
            return $this->error('Account not found.', 404);
        }

        $this->users->update((int) $user['id'], ['email_verified_at' => date('Y-m-d H:i:s')]);
        $db->table('email_verifications')->where('email', $email)->delete();

        return $this->issueTokensForUser((int) $user['id'], 'Email verified. Welcome to Dimensions!');
    }

    // ── POST /v1/auth/resend-verification ─────────────────────────────────────

    public function resendVerification(): ResponseInterface
    {
        $db    = db_connect();
        $this->ensureEmailTables($db);
        $input = $this->inputJson();

        $email = strtolower(trim($input['email'] ?? ''));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->validationError(['email' => 'A valid email address is required.']);
        }

        $user = $this->users->where('email', $email)->first();
        if (! $user) {
            return $this->success(['sent' => true], 'If this email is registered, a new code has been sent.');
        }

        if (! empty($user['email_verified_at'])) {
            return $this->error('This account is already verified.', 400);
        }

        // Rate-limit: max one resend per 60 seconds
        $lastSent = $db->table('email_verifications')
            ->where('email', $email)
            ->get()->getRowArray();

        if ($lastSent && strtotime($lastSent['created_at']) > time() - 60) {
            return $this->error('Please wait a moment before requesting a new code.', 429);
        }

        $code = $this->sendVerificationCode($db, (int) $user['id'], $email, $user['name'] ?? '');

        $responseData = ['sent' => true];
        if (ENVIRONMENT === 'development') {
            $responseData['dev_code'] = $code;
        }

        return $this->success($responseData, 'Verification code resent.');
    }

    // ── POST /v1/auth/change-password — requires AuthFilter ───────────────────

    public function changePassword(): ResponseInterface
    {
        $uid   = $this->authUserId();
        $input = $this->inputJson();

        $currentPassword = $input['current_password'] ?? '';
        $newPassword     = $input['new_password']     ?? '';
        $confirmPassword = $input['confirm_password'] ?? '';

        $errors = [];
        if (strlen($currentPassword) < 1)  $errors['current_password'] = 'Current password is required.';
        if (strlen($newPassword) < 8)       $errors['new_password']     = 'New password must be at least 8 characters.';
        if ($newPassword !== $confirmPassword) $errors['confirm_password'] = 'Passwords do not match.';

        if ($errors) {
            return $this->validationError($errors);
        }

        $user = $this->users->find($uid);
        if (! $user) {
            return $this->error('User not found.', 404);
        }

        if (! password_verify($currentPassword, $user['password_hash'])) {
            return $this->validationError(['current_password' => 'Current password is incorrect.']);
        }

        if (password_verify($newPassword, $user['password_hash'])) {
            return $this->validationError(['new_password' => 'New password must be different from current password.']);
        }

        $this->users->update($uid, [
            'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]),
        ]);

        return $this->success(null, 'Password changed successfully.');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function ensureEmailTables(\CodeIgniter\Database\BaseConnection $db): void
    {
        // MySQL 8.x does NOT support ADD COLUMN IF NOT EXISTS — use SHOW COLUMNS instead
        try {
            $col = $db->query("SHOW COLUMNS FROM `users` LIKE 'email_verified_at'")->getResultArray();
            if (empty($col)) {
                $db->query("ALTER TABLE `users` ADD COLUMN `email_verified_at` DATETIME DEFAULT NULL");
            }
        } catch (\Throwable $e) {
            log_message('error', '[ensureEmailTables] email_verified_at: ' . $e->getMessage());
        }

        try {
            $db->query("CREATE TABLE IF NOT EXISTS `email_verifications` (
                `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`    BIGINT UNSIGNED NOT NULL,
                `email`      VARCHAR(191)    NOT NULL,
                `code`       VARCHAR(10)     NOT NULL,
                `expires_at` DATETIME        NOT NULL,
                `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {}

        // Existing users (pre-verification) are treated as verified so they aren't locked out
        try {
            $db->query("UPDATE `users` SET `email_verified_at` = `created_at`
                WHERE `email_verified_at` IS NULL AND `created_at` < NOW() - INTERVAL 5 MINUTE");
        } catch (\Throwable $e) {}
    }

    private function sendVerificationCode(
        \CodeIgniter\Database\BaseConnection $db,
        int $userId,
        string $email,
        string $name
    ): string {
        $db->table('email_verifications')->where('email', $email)->delete();

        $code = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $db->table('email_verifications')->insert([
            'user_id'    => $userId,
            'email'      => $email,
            'code'       => $code,
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ]);

        $html = $this->emailHtml(
            'Verify Your Email',
            '<p>Welcome to Dimensions! Use the code below to verify your email address and get started.</p>',
            $code,
            '1 hour'
        );
        $this->sendMailViaZeptoMail($email, $name, 'Verify your Dimensions account', $html);

        return $code;
    }

    private function issueTokensForUser(int $userId, string $message, int $code = 200): ResponseInterface
    {
        $user = $this->users->withInterests($this->users->find($userId));

        $accessToken  = $this->jwt->generateAccessToken($userId);
        $refreshToken = $this->jwt->generateRefreshToken($userId);

        $this->refreshTokens->store($userId, $refreshToken, $this->refreshTtl);

        return $this->success([
            'user'          => $this->users->safeUser($user),
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
        ], $message, $code);
    }

    private function checkLoginRateLimit(): bool
    {
        $ip      = $this->request->getIPAddress();
        $cacheKey = 'login_attempts_' . md5($ip);
        $cache    = \Config\Services::cache();

        $attempts = (int) ($cache->get($cacheKey) ?? 0);
        if ($attempts >= 3) {
            return false;
        }

        $cache->save($cacheKey, $attempts + 1, 60); // 60-second window
        return true;
    }

    private function verifyGoogleToken(string $idToken): ?array
    {
        // Server-side verification via Google tokeninfo endpoint
        $url      = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
        $response = @file_get_contents($url);
        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);
        if (empty($data['email']) || empty($data['email_verified'])) {
            return null;
        }

        return [
            'email'      => $data['email'],
            'name'       => $data['name'] ?? $data['email'],
            'avatar_url' => $data['picture'] ?? null,
        ];
    }

    private function verifyAppleToken(string $idToken): ?array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) return null;

        [$b64Header, $b64Payload, $b64Sig] = $parts;

        $header  = json_decode($this->b64urlDecode($b64Header), true);
        $payload = json_decode($this->b64urlDecode($b64Payload), true);

        if (empty($header['kid']) || ($header['alg'] ?? '') !== 'RS256') return null;
        if (empty($payload['email']))                                      return null;
        if (($payload['iss'] ?? '') !== 'https://appleid.apple.com')       return null;
        if (($payload['exp'] ?? 0) < time())                               return null;

        // Validate audience against configured bundle ID (if set)
        $bundleId = env('APPLE_BUNDLE_ID', '');
        if ($bundleId !== '') {
            $aud = $payload['aud'] ?? '';
            $aud = is_array($aud) ? $aud : [$aud];
            if (! in_array($bundleId, $aud, true)) return null;
        }

        // Fetch Apple's JWK set (cache 1 hour)
        $cache = \Config\Services::cache();
        $jwks  = $cache->get('apple_jwks');
        if ($jwks === null) {
            $ch = curl_init('https://appleid.apple.com/auth/keys');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => true]);
            $raw = curl_exec($ch);
            curl_close($ch);
            if (! $raw) return null;
            $jwks = json_decode($raw, true);
            if (! isset($jwks['keys'])) return null;
            $cache->save('apple_jwks', $jwks, 3600);
        }

        // Find the key matching the token's kid
        $jwk = null;
        foreach ($jwks['keys'] as $k) {
            if (($k['kid'] ?? '') === $header['kid']) { $jwk = $k; break; }
        }
        if ($jwk === null) return null;

        // Convert JWK to PEM and verify RS256 signature
        $pem = $this->jwkRsaToPem($jwk);
        if ($pem === null) return null;

        $signingInput = "{$b64Header}.{$b64Payload}";
        $signature    = $this->b64urlDecode($b64Sig);
        if (openssl_verify($signingInput, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) return null;

        return [
            'email'      => $payload['email'],
            'name'       => $payload['name'] ?? $payload['email'],
            'avatar_url' => null,
        ];
    }

    private function jwkRsaToPem(array $key): ?string
    {
        if (($key['kty'] ?? '') !== 'RSA' || empty($key['n']) || empty($key['e'])) return null;

        $n = $this->b64urlDecode($key['n']);
        $e = $this->b64urlDecode($key['e']);

        // Prepend null byte if high bit set (ensure positive integer in DER)
        if (ord($n[0]) & 0x80) $n = "\x00" . $n;
        if (ord($e[0]) & 0x80) $e = "\x00" . $e;

        $modulus  = "\x02" . $this->derLen(strlen($n)) . $n;
        $exponent = "\x02" . $this->derLen(strlen($e)) . $e;
        $seq      = "\x30" . $this->derLen(strlen($modulus . $exponent)) . $modulus . $exponent;

        // RSA OID: 1.2.840.113549.1.1.1 with NULL params
        $oid       = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $bitString = "\x03" . $this->derLen(strlen($seq) + 1) . "\x00" . $seq;
        $spki      = "\x30" . $this->derLen(strlen($oid) + strlen($bitString)) . $oid . $bitString;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----";
    }

    private function derLen(int $len): string
    {
        if ($len < 128)   return chr($len);
        if ($len < 256)   return "\x81" . chr($len);
        return "\x82" . chr($len >> 8) . chr($len & 0xff);
    }

    private function b64urlDecode(string $data): string
    {
        $pad = (4 - strlen($data) % 4) % 4;
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', $pad));
    }
}

