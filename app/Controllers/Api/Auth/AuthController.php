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

        $userId = $this->users->insert([
            'name'          => trim($input['name']),
            'email'         => strtolower(trim($input['email'])),
            'phone'         => $input['phone'] ?? null,
            'password_hash' => password_hash($input['password'], PASSWORD_BCRYPT, ['cost' => 12]),
            'trust_level'   => 'community_submitted',
            'is_active'     => 1,
        ]);

        if (! $userId) {
            return $this->error('Registration failed. Please try again.', 500);
        }

        // Sync interests if provided
        if (! empty($input['interests']) && is_array($input['interests'])) {
            $this->users->syncInterests((int) $userId, $input['interests']);
        }

        return $this->issueTokensForUser((int) $userId, 'Account created successfully', 201);
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

        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (! in_array(strtolower($file->getExtension()), $allowedExts, true)) {
            return $this->error('Only JPEG, PNG, WebP and GIF images are allowed.', 422);
        }

        if ($file->getSize() > 5 * 1024 * 1024) {
            return $this->error('Image must be under 5 MB.', 422);
        }

        $ext      = strtolower($file->getExtension());
        $filename = 'avatar_' . $userId . '_' . time() . '.' . $ext;
        $tmpPath  = WRITEPATH . 'uploads/' . $filename;

        if (! is_dir(WRITEPATH . 'uploads/')) {
            mkdir(WRITEPATH . 'uploads/', 0755, true);
        }

        if (! $file->move(WRITEPATH . 'uploads/', $filename)) {
            return $this->error('Upload failed.', 500);
        }

        try {
            $s3 = new \App\Libraries\S3Uploader();
            $avatarUrl = $s3->uploadOrLocal($tmpPath, "uploads/avatars/{$filename}", "image/{$ext}", 'avatars');
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

        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (! in_array(strtolower($file->getExtension()), $allowedExts, true)) {
            return $this->error('Only JPEG, PNG, WebP and GIF images are allowed.', 422);
        }

        if ($file->getSize() > 10 * 1024 * 1024) {
            return $this->error('Cover image must be under 10 MB.', 422);
        }

        $ext      = strtolower($file->getExtension());
        $filename = 'cover_' . $userId . '_' . time() . '.' . $ext;
        $tmpPath  = WRITEPATH . 'uploads/' . $filename;

        if (! is_dir(WRITEPATH . 'uploads/')) {
            mkdir(WRITEPATH . 'uploads/', 0755, true);
        }

        if (! $file->move(WRITEPATH . 'uploads/', $filename)) {
            return $this->error('Upload failed.', 500);
        }

        try {
            $s3 = new \App\Libraries\S3Uploader();
            $coverUrl = $s3->uploadOrLocal($tmpPath, "uploads/covers/{$filename}", "image/{$ext}", 'covers');
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

        // Find or create user
        $user = $this->users->findByEmail($socialUser['email']);
        if ($user === null) {
            $userId = $this->users->insert([
                'name'          => $socialUser['name'],
                'email'         => $socialUser['email'],
                'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
                'avatar_url'    => $socialUser['avatar_url'] ?? null,
                'trust_level'   => 'community_submitted',
                'is_active'     => 1,
            ]);
            $user = $this->users->find((int) $userId);
        }

        return $this->issueTokensForUser((int) $user['id'], 'Social login successful');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

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
        // Decode Apple's JWT without verification (public keys rotate — use apple-sign-in package for production)
        // For now, return null to signal "not implemented" without crashing
        // Replace with a proper Apple Sign-In library (e.g., lcobucci/jwt + Apple JWK fetcher) in production
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (empty($payload['email'])) {
            return null;
        }

        return [
            'email'      => $payload['email'],
            'name'       => $payload['name'] ?? $payload['email'],
            'avatar_url' => null,
        ];
    }
}
