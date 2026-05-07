<?php

namespace App\Controllers\Api\Admin;

use App\Controllers\Api\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Admin API for vendor management. Only accessible to admin users.
 * Admin earns only from activation fees — does NOT touch vendor-customer transactions.
 */
class VendorsAdminController extends BaseApiController
{
    private function db() { return db_connect(); }

    private function requireAdmin(): bool
    {
        $userId = $this->authUserId();
        $user   = $this->db()->table('users')->where('id', $userId)->get()->getRowArray();
        return ($user['role'] ?? '') === 'admin';
    }

    // ── GET /v1/admin/vendors ─────────────────────────────────────────────────

    public function index(): ResponseInterface
    {
        if (! $this->requireAdmin()) return $this->error('Forbidden', 403);

        $status = $this->request->getGet('status'); // pending | approved | rejected | all
        $db     = $this->db();

        $query = $db->table('vendors v')
            ->select('v.*, u.name AS owner_name, u.email AS owner_email, u.avatar_url AS owner_avatar')
            ->join('users u', 'u.id = v.user_id', 'left')
            ->orderBy('v.created_at', 'DESC');

        match ($status) {
            'pending'  => $query->where('v.is_approved', 0)->where('v.activation_fee_paid', 0),
            'paid'     => $query->where('v.activation_fee_paid', 1)->where('v.is_approved', 0),
            'approved' => $query->where('v.is_approved', 1),
            'rejected' => $query->where('v.is_approved', 0)->where('v.rejection_reason !=', null),
            default    => null,
        };

        $vendors = $query->get()->getResultArray();

        return $this->success(array_map(fn($v) => [
            'id'                     => (string) $v['id'],
            'name'                   => $v['name'],
            'slug'                   => $v['slug'],
            'logo_url'               => $v['logo_url'] ?? null,
            'category'               => $v['category'] ?? null,
            'is_active'              => (bool) $v['is_active'],
            'is_approved'            => (bool) $v['is_approved'],
            'activation_fee_paid'    => (bool) $v['activation_fee_paid'],
            'activation_fee_amount'  => $v['activation_fee_amount'] ? (float) $v['activation_fee_amount'] : null,
            'rejection_reason'       => $v['rejection_reason'] ?? null,
            'owner_name'             => $v['owner_name'],
            'owner_email'            => $v['owner_email'],
            'owner_avatar'           => $v['owner_avatar'] ?? null,
            'created_at'             => $v['created_at'],
        ], $vendors));
    }

    // ── PUT /v1/admin/vendors/:id/approve ─────────────────────────────────────

    public function approve(string $id): ResponseInterface
    {
        if (! $this->requireAdmin()) return $this->error('Forbidden', 403);

        $vendor = $this->db()->table('vendors')->where('id', $id)->get()->getRowArray();
        if (! $vendor) return $this->error('Vendor not found.', 404);

        $this->db()->table('vendors')->where('id', $id)->set([
            'is_approved'     => 1,
            'rejection_reason'=> null,
            'updated_at'      => date('Y-m-d H:i:s'),
        ])->update();

        return $this->success(['approved' => true]);
    }

    // ── PUT /v1/admin/vendors/:id/reject ──────────────────────────────────────

    public function reject(string $id): ResponseInterface
    {
        if (! $this->requireAdmin()) return $this->error('Forbidden', 403);

        $input  = $this->inputJson();
        $reason = trim($input['reason'] ?? '');
        if (empty($reason)) return $this->validationError(['reason' => 'Rejection reason is required.']);

        $this->db()->table('vendors')->where('id', $id)->set([
            'is_approved'      => 0,
            'rejection_reason' => $reason,
            'updated_at'       => date('Y-m-d H:i:s'),
        ])->update();

        return $this->success(['rejected' => true]);
    }

    // ── GET/PUT /v1/admin/platform-settings ───────────────────────────────────

    public function platformSettings(): ResponseInterface
    {
        if (! $this->requireAdmin()) return $this->error('Forbidden', 403);

        $db = $this->db();

        if ($this->request->getMethod() === 'get') {
            $rows = $db->table('platform_settings')->get()->getResultArray();
            $map  = [];
            foreach ($rows as $r) {
                $key = $r['key'];
                if (in_array($key, ['platform_stripe_secret', 'platform_stripe_webhook_secret'], true)) {
                    $map[$key] = empty($r['value']) ? '' : '••••••••'; // never expose secrets
                } else {
                    $map[$key] = $r['value'];
                }
            }
            return $this->success($map);
        }

        // PUT
        $input  = $this->inputJson();
        $allowed = ['activation_fee_amount', 'activation_fee_currency',
                    'platform_stripe_key', 'platform_stripe_secret',
                    'platform_stripe_webhook_secret'];
        $db->transStart();
        foreach ($allowed as $key) {
            if (array_key_exists($key, $input)) {
                $exists = $db->table('platform_settings')->where('key', $key)->countAllResults();
                $exists
                    ? $db->table('platform_settings')->where('key', $key)->set(['value' => $input[$key], 'updated_at' => date('Y-m-d H:i:s')])->update()
                    : $db->table('platform_settings')->insert(['key' => $key, 'value' => $input[$key], 'updated_at' => date('Y-m-d H:i:s')]);
            }
        }
        $db->transComplete();

        return $this->success(['saved' => true]);
    }

    // ── GET /v1/admin/vendors/revenue ─────────────────────────────────────────

    public function revenue(): ResponseInterface
    {
        if (! $this->requireAdmin()) return $this->error('Forbidden', 403);

        $db = $this->db();

        $total      = (float) $db->table('vendors')->where('activation_fee_paid', 1)->selectSum('activation_fee_amount')->get()->getRowArray()['activation_fee_amount'] ?? 0;
        $paidCount  = $db->table('vendors')->where('activation_fee_paid', 1)->countAllResults();
        $totalCount = $db->table('vendors')->countAllResults();
        $approvedCount = $db->table('vendors')->where('is_approved', 1)->countAllResults();

        return $this->success([
            'total_activation_revenue' => $total,
            'paid_vendors'             => $paidCount,
            'approved_vendors'         => $approvedCount,
            'total_vendors'            => $totalCount,
        ]);
    }
}
