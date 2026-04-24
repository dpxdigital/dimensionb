<?php

namespace App\Controllers\Api\Onboarding;

use App\Controllers\Api\BaseApiController;
use App\Models\CategoryModel;
use CodeIgniter\HTTP\ResponseInterface;

class OnboardingController extends BaseApiController
{
    // ── GET /v1/interests ─────────────────────────────────────────────────────

    public function interests(): ResponseInterface
    {
        $categories = (new CategoryModel())->allOrdered();
        return $this->success($categories);
    }

    // ── POST /v1/onboarding/interests ─────────────────────────────────────────

    public function saveInterests(): ResponseInterface
    {
        $userId = $this->authUserId();
        $input  = $this->inputJson();

        if (! isset($input['interests']) || ! is_array($input['interests'])) {
            return $this->error('interests must be an array of category IDs.', 422);
        }

        $ids = array_map('intval', $input['interests']);

        $db = db_connect();
        $db->table('user_interests')->where('user_id', $userId)->delete();

        foreach ($ids as $categoryId) {
            $db->table('user_interests')->insert([
                'user_id'     => $userId,
                'category_id' => $categoryId,
            ]);
        }

        return $this->success(null, 'Interests saved');
    }

    // ── POST /v1/onboarding/location ──────────────────────────────────────────

    public function saveLocation(): ResponseInterface
    {
        $userId = $this->authUserId();
        $input  = $this->inputJson();

        $rules = [
            'city' => 'permit_empty|max_length[100]',
            'lat'  => 'permit_empty|decimal',
            'lng'  => 'permit_empty|decimal',
        ];

        if (! $this->validateData($input, $rules)) {
            return $this->validationError($this->validator->getErrors());
        }

        $update = array_filter([
            'location' => $input['city'] ?? null,
            'lat'      => isset($input['lat']) ? (float) $input['lat'] : null,
            'lng'      => isset($input['lng']) ? (float) $input['lng'] : null,
        ], fn($v) => $v !== null);

        if (! empty($update)) {
            db_connect()->table('users')->where('id', $userId)->update($update);
        }

        return $this->success(null, 'Location saved');
    }

    // ── POST /v1/onboarding/notifications ─────────────────────────────────────

    public function saveNotificationPreferences(): ResponseInterface
    {
        $userId = $this->authUserId();
        $input  = $this->inputJson();

        $update = [];
        if (isset($input['event_reminders'])) {
            $update['pref_event_reminders'] = (int) (bool) $input['event_reminders'];
        }
        if (isset($input['live_alerts'])) {
            $update['pref_live_alerts'] = (int) (bool) $input['live_alerts'];
        }
        if (isset($input['new_matches'])) {
            $update['pref_new_matches'] = (int) (bool) $input['new_matches'];
        }

        if (! empty($update)) {
            db_connect()->table('users')->where('id', $userId)->update($update);
        }

        return $this->success(null, 'Notification preferences saved');
    }
}
