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
        'trust_level',
        'trust_label',
        'is_active',
        'is_vendor',
    ];

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

        $user['followers_count'] = 0;
        $user['following_count'] = 0;
        $user['listings_count']  = (int) $db->table('listings')->where('submitted_by', $uid)->countAllResults();
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
