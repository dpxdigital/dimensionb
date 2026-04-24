<?php

namespace App\Libraries;

class NotificationHelper
{
    /**
     * Insert a notification row and fire the FCM push.
     */
    public static function createAndSend(
        int    $userId,
        string $type,
        string $title,
        string $body,
        ?int   $referenceId   = null,
        string $referenceType = ''
    ): void {
        db_connect()->table('notifications')->insert([
            'user_id'        => $userId,
            'type'           => $type,
            'title'          => $title,
            'body'           => $body,
            'reference_id'   => $referenceId,
            'reference_type' => $referenceType,
            'is_read'        => 0,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        $fcm = new FCMNotificationService();
        $fcm->sendToUser($userId, $title, $body, [
            'type'           => $type,
            'reference_id'   => (string) ($referenceId ?? ''),
            'reference_type' => $referenceType,
        ]);
    }
}
