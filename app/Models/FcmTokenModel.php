<?php

namespace App\Models;

use CodeIgniter\Model;

class FcmTokenModel extends Model
{
    protected $table         = 'fcm_tokens';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['user_id', 'token', 'platform', 'updated_at'];

    public function upsert(int $userId, string $token, ?string $platform = null): void
    {
        $existing = $this->where('token', $token)->first();

        if ($existing) {
            $this->update($existing['id'], [
                'user_id'    => $userId,
                'platform'   => $platform,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $this->insert([
                'user_id'    => $userId,
                'token'      => $token,
                'platform'   => $platform,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
