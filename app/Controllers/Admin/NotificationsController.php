<?php

namespace App\Controllers\Admin;

use App\Libraries\FCMNotificationService;

class NotificationsController extends BaseAdminController
{
    public function index()
    {
        $db        = db_connect();
        $history   = $db->table('notification_broadcasts nb')
            ->select('nb.*, au.name AS admin_name')
            ->join('admin_users au', 'au.id = nb.admin_id', 'left')
            ->orderBy('nb.created_at', 'DESC')
            ->limit(50)
            ->get()->getResultArray();

        $categories = $db->table('categories')->where('is_active', 1)->orderBy('name')->get()->getResultArray();

        return $this->renderView('admin/notifications/index', compact('history', 'categories'));
    }

    public function send()
    {
        $type       = $this->request->getPost('target_type') ?? 'all';
        $targetVal  = trim($this->request->getPost('target_value') ?? '');
        $title      = trim($this->request->getPost('title') ?? '');
        $body       = trim($this->request->getPost('body') ?? '');
        $deepLink   = trim($this->request->getPost('deep_link') ?? '');

        if (empty($title) || empty($body)) {
            return $this->jsonResponse(['error' => 'Title and body are required.'], 422);
        }

        $db  = db_connect();
        $fcm = new FCMNotificationService();

        $deliveryCount = 0;

        switch ($type) {
            case 'all':
                $userIds = array_column($db->table('users')->select('id')->where('is_active', 1)->get()->getResultArray(), 'id');
                $fcm->sendToMultiple($userIds, $title, $body, ['deep_link' => $deepLink]);
                $deliveryCount = count($userIds);
                break;

            case 'user':
                $user = $db->table('users')->select('id')->where('email', $targetVal)->get()->getRowArray();
                if ($user) {
                    $fcm->sendToUser((int) $user['id'], $title, $body, ['deep_link' => $deepLink]);
                    $deliveryCount = 1;
                }
                break;

            case 'category':
                $userIds = array_column(
                    $db->table('user_interests ui')
                        ->select('DISTINCT ui.user_id AS id')
                        ->join('categories c', 'c.id = ui.category_id')
                        ->where('c.slug', $targetVal)
                        ->get()->getResultArray(),
                    'id'
                );
                if (! empty($userIds)) {
                    $fcm->sendToMultiple($userIds, $title, $body, ['deep_link' => $deepLink]);
                    $deliveryCount = count($userIds);
                }
                break;
        }

        $db->table('notification_broadcasts')->insert([
            'admin_id'       => session('admin_id'),
            'title'          => $title,
            'body'           => $body,
            'target_type'    => $type,
            'target_value'   => $targetVal ?: null,
            'deep_link'      => $deepLink ?: null,
            'delivery_count' => $deliveryCount,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        $this->audit('notification_sent', 'broadcast', null, "{$type}: {$title} ({$deliveryCount} recipients)");

        return $this->jsonResponse(['success' => true, 'delivery_count' => $deliveryCount]);
    }
}
