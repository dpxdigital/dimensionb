<?php

namespace App\Models;

use CodeIgniter\Model;

class ActivityLogModel extends Model
{
    protected $table         = 'activity_log';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['user_id', 'listing_id', 'action_type', 'created_at'];

    public function log(int $userId, int $listingId, string $actionType): void
    {
        // Upsert: one log entry per user+listing+action
        $exists = $this
            ->where('user_id', $userId)
            ->where('listing_id', $listingId)
            ->where('action_type', $actionType)
            ->first();

        if (! $exists) {
            $this->insert([
                'user_id'     => $userId,
                'listing_id'  => $listingId,
                'action_type' => $actionType,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function remove(int $userId, int $listingId, string $actionType): void
    {
        $this->where('user_id', $userId)
             ->where('listing_id', $listingId)
             ->where('action_type', $actionType)
             ->delete();
    }
}
