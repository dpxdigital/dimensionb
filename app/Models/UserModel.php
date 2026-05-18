<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table            = 'users';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';

    // Profile-editable fields only. trust_level, trust_label, is_active, is_vendor
    // must be updated through explicit admin/system calls, never via user-facing save().
    protected $allowedFields = [
        'name',
        'email',
        'phone',
        'password_hash',
        'avatar_url',
        'cover_url',
        'bio',
        'location',
        'city',
        'lat',
        'lng',
        'email_verified_at',
    ];

    // Used only by registration and admin controllers — bypasses allowedFields restriction.
    public function setTrustLevel(int $userId, string $trustLevel, string $trustLabel): void
    {
        $this->db()->table($this->table)->where('id', $userId)->update([
            'trust_level' => $trustLevel,
            'trust_label' => $trustLabel,
        ]);
    }

    public function setActiveStatus(int $userId, bool $isActive): void
    {
        $this->db()->table($this->table)->where('id', $userId)->update(['is_active' => (int)$isActive]);
    }

    public function setVendorStatus(int $userId, bool $isVendor): void
    {
        $this->db()->table($this->table)->where('id', $userId)->update(['is_vendor' => (int)$isVendor]);
    }

    // ── Public finders ────────────────────────────────────────────────────────

    public function findByEmail(string $email): ?array
    {
        return $this->where('email', $email)->first();
    }

    public function findByPhone(string $phone): ?array
    {
        return $this->where('phone', $phone)->first();
    }

    /**
     * Returns the user safe for API responses (no password_hash), with computed counts.
     */
    public function safeUser(array $user): array
    {
        unset($user['password_hash']);
        $db  = db_connect();
        $uid = $user['id'];

        $user['followers_count'] = (int) $db->table('user_follows')->where('following_id', $uid)->countAllResults();
        $user['following_count'] = (int) $db->table('user_follows')->where('follower_id', $uid)->countAllResults();
        $user['listings_count']  = (int) $db->table('posts')->where('user_id', $uid)->countAllResults();
        $user['is_vendor']       = (bool) ($user['is_vendor'] ?? false);
        $user['city']            = $user['city'] ?? null;

        return $user;
    }

    /**
     * Returns user with their interests as an array.
     */
    public function withInterests(array $user): array
    {
        $interests = db_connect()
            ->table('user_interests')
            ->select('interest')
            ->where('user_id', $user['id'])
            ->get()
            ->getResultArray();

        $user['interests'] = array_column($interests, 'interest');
        return $user;
    }

    /**
     * Sync the user_interests pivot table.
     *
     * @param int      $userId
     * @param string[] $interests
     */
    public function syncInterests(int $userId, array $interests): void
    {
        $db = db_connect();
        $db->table('user_interests')->where('user_id', $userId)->delete();

        if (! empty($interests)) {
            $rows = array_map(
                fn(string $i) => ['user_id' => $userId, 'interest' => trim($i)],
                array_unique($interests)
            );
            $db->table('user_interests')->insertBatch($rows);
        }
    }
}
