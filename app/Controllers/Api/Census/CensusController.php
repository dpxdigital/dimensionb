<?php

namespace App\Controllers\Api\Census;

use App\Controllers\Api\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

class CensusController extends BaseApiController
{
    // ── POST /v1/census ───────────────────────────────────────────────────────

    public function submit(): ResponseInterface
    {
        $input = $this->inputJson();

        $rules = [
            'first_name' => 'required|max_length[100]',
            'last_name'  => 'required|max_length[100]',
            'email'      => 'required|valid_email|max_length[255]',
            'phone'      => 'permit_empty|max_length[30]',
            'city'       => 'permit_empty|max_length[100]',
            'state'      => 'permit_empty|max_length[100]',
            'zip'        => 'permit_empty|max_length[20]',
        ];

        if (! $this->validateData($input, $rules)) {
            return $this->validationError($this->validator->getErrors());
        }

        // Link to account if authenticated
        $userId = null;
        try { $userId = $this->authUserId(); } catch (\Throwable $e) {}

        db_connect()->table('census_records')->insert([
            'user_id'       => $userId,
            'first_name'    => trim($input['first_name']),
            'last_name'     => trim($input['last_name']),
            'email'         => strtolower(trim($input['email'])),
            'phone'         => $input['phone'] ?? null,
            'date_of_birth' => $input['date_of_birth'] ?? null,
            'gender'        => $input['gender'] ?? null,
            'city'          => $input['city'] ?? null,
            'state'         => $input['state'] ?? null,
            'zip'           => $input['zip'] ?? null,
            'chapter_id'    => isset($input['chapter_id']) ? (int) $input['chapter_id'] : null,
            'interests'     => isset($input['interests']) ? json_encode($input['interests']) : null,
            'sms_updates'   => (int) ($input['sms_updates'] ?? 0),
            'email_updates' => (int) ($input['email_updates'] ?? 1),
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        return $this->success(null, 'Census record submitted. Thank you!', 201);
    }
}
