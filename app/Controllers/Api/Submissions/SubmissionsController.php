<?php

namespace App\Controllers\Api\Submissions;

use App\Controllers\Api\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

class SubmissionsController extends BaseApiController
{
    // ── POST /v1/submissions ──────────────────────────────────────────────────

    public function create(): ResponseInterface
    {
        $userId = $this->authUserId();

        // Support both JSON and multipart (for image uploads)
        $isMultipart = str_contains($this->request->getHeaderLine('Content-Type'), 'multipart');
        $input = $isMultipart ? $this->request->getPost() : $this->inputJson();

        // Normalise type to lowercase
        if (isset($input['type'])) {
            $input['type'] = strtolower($input['type']);
        }

        $rules = [
            'type'        => 'required|in_list[event,cause,program,scholarship,blackwins,other]',
            'title'       => 'required|max_length[255]',
            'org_name'    => 'required|max_length[255]',
            'description' => 'required',
            'source_url'  => 'permit_empty|valid_url|max_length[500]',
            'date'        => 'permit_empty|valid_date[Y-m-d]',
            'location'    => 'permit_empty|max_length[255]',
        ];

        if (! $this->validateData($input, $rules)) {
            return $this->validationError($this->validator->getErrors());
        }

        // Handle optional cover image upload
        $coverUrl = null;
        $imageFile = $this->request->getFile('cover_image');
        if ($imageFile && $imageFile->isValid()) {
            $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
            $ext = strtolower($imageFile->getExtension());
            if (in_array($ext, $allowedExts, true) && $imageFile->getSize() <= 5 * 1024 * 1024) {
                $uploadDir = FCPATH . 'uploads/submissions/';
                if (! is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $filename = 'sub_' . $userId . '_' . time() . '.' . $ext;
                if ($imageFile->move($uploadDir, $filename)) {
                    $coverUrl = base_url('uploads/submissions/' . $filename);
                }
            }
        }

        $db = db_connect();
        $db->table('submissions')->insert([
            'user_id'     => $userId,
            'type'        => $input['type'],
            'title'       => trim($input['title']),
            'org_name'    => trim($input['org_name']),
            'description' => trim($input['description']),
            'source_url'  => $input['source_url'] ?? null,
            'date'        => $input['date'] ?? null,
            'location'    => $input['location'] ?? null,
            'cover_url'   => $coverUrl,
            'status'      => 'pending',
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        $id = $db->insertID();

        $row = $db->table('submissions')->where('id', $id)->get()->getRowArray();

        return $this->success(self::formatRow($row), 'Submission received', 201);
    }

    // ── GET /v1/submissions ───────────────────────────────────────────────────

    public function mySubmissions(): ResponseInterface
    {
        $userId = $this->authUserId();
        $page   = max(1, (int) ($this->request->getGet('page') ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        $db    = db_connect();
        $total = $db->table('submissions')->where('user_id', $userId)->countAllResults();
        $rows  = $db->table('submissions')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->limit($limit, $offset)
            ->get()->getResultArray();

        return $this->success(
            array_map([self::class, 'formatRow'], $rows),
            'OK',
            200,
            [
                'current_page' => $page,
                'per_page'     => $limit,
                'total'        => $total,
                'last_page'    => (int) ceil($total / $limit),
            ]
        );
    }

    // ── GET /v1/submissions/:id ───────────────────────────────────────────────

    public function show($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $row    = db_connect()->table('submissions')
            ->where('id', (int) $id)
            ->where('user_id', $userId)
            ->get()->getRowArray();

        if ($row === null) {
            return $this->error('Submission not found.', 404);
        }

        return $this->success(self::formatRow($row));
    }

    // ── Formatter ─────────────────────────────────────────────────────────────

    public static function formatRow(array $row): array
    {
        return [
            'id'          => (string) $row['id'],
            'type'        =>          $row['type'],
            'title'       =>          $row['title'],
            'org_name'    =>          $row['org_name'],
            'description' =>          $row['description'],
            'source_url'  =>          $row['source_url'] ?? null,
            'date'        =>          $row['date'] ?? null,
            'location'    =>          $row['location'] ?? null,
            'cover_url'   =>          $row['cover_url'] ?? null,
            'status'      =>          $row['status'],
            'trust_label' =>          $row['trust_label'] ?? null,
            'created_at'  =>          $row['created_at'],
        ];
    }
}
