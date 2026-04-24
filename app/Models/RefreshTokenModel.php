<?php

namespace App\Models;

use CodeIgniter\Model;

class RefreshTokenModel extends Model
{
    protected $table          = 'refresh_tokens';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = false;
    protected $allowedFields  = [
        'user_id',
        'token_hash',
        'expires_at',
        'created_at',
        'revoked_at',
    ];

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function store(int $userId, string $rawToken, int $ttlSeconds): void
    {
        // Invalidate all previous tokens for this user
        $this->where('user_id', $userId)->set(['revoked_at' => date('Y-m-d H:i:s')])->update();

        $this->insert([
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => date('Y-m-d H:i:s', time() + $ttlSeconds),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function findValid(string $rawToken): ?array
    {
        return $this
            ->where('token_hash', hash('sha256', $rawToken))
            ->where('revoked_at', null)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->first();
    }

    public function revoke(string $rawToken): void
    {
        $this->where('token_hash', hash('sha256', $rawToken))
             ->set(['revoked_at' => date('Y-m-d H:i:s')])
             ->update();
    }

    public function revokeAll(int $userId): void
    {
        $this->where('user_id', $userId)
             ->set(['revoked_at' => date('Y-m-d H:i:s')])
             ->update();
    }
}
