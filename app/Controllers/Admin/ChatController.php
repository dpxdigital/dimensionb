<?php

namespace App\Controllers\Admin;

class ChatController extends BaseAdminController
{
    private const PER_PAGE = 20;

    public function index()
    {
        $db   = db_connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;

        // Reported conversations
        $total = $db->table('moderation_queue')->where('reference_type', 'conversation')->countAllResults();
        $reports = $db->table('moderation_queue mq')
            ->select('mq.*, u.name AS reporter_name, c.type AS conv_type, c.name AS conv_name')
            ->join('users u', 'u.id = mq.reported_by', 'left')
            ->join('conversations c', 'c.id = mq.reference_id', 'left')
            ->where('mq.reference_type', 'conversation')
            ->orderBy('mq.created_at', 'DESC')
            ->limit(self::PER_PAGE, $offset)
            ->get()->getResultArray();

        // Active group chats
        $groups = $db->table('conversations c')
            ->select('c.id, c.name, c.avatar_url, c.created_at, c.last_message_at,
                      COUNT(cm.user_id) AS member_count, u.name AS created_by_name')
            ->join('conversation_members cm', 'cm.conversation_id = c.id', 'left')
            ->join('users u', 'u.id = c.created_by', 'left')
            ->where('c.type', 'group')
            ->groupBy('c.id')
            ->orderBy('c.last_message_at', 'DESC')
            ->limit(50)
            ->get()->getResultArray();

        $lastPage = (int) ceil($total / self::PER_PAGE);

        return $this->renderView('admin/chat/index', compact('reports', 'groups', 'total', 'page', 'lastPage'));
    }

    public function viewConversation($id)
    {
        $db   = db_connect();
        $conv = $db->table('conversations')->where('id', (int) $id)->get()->getRowArray();

        if (! $conv) {
            return redirect()->to('/manager/chat')->with('error', 'Conversation not found.');
        }

        $messages = $db->table('messages m')
            ->select('m.*, u.name AS sender_name, u.avatar_url AS sender_avatar')
            ->join('users u', 'u.id = m.sender_id', 'left')
            ->where('m.conversation_id', (int) $id)
            ->orderBy('m.created_at', 'ASC')
            ->get()->getResultArray();

        $members = $db->table('conversation_members cm')
            ->select('u.id, u.name, u.email, u.avatar_url, cm.is_admin')
            ->join('users u', 'u.id = cm.user_id')
            ->where('cm.conversation_id', (int) $id)
            ->get()->getResultArray();

        return $this->renderView('admin/chat/conversation', compact('conv', 'messages', 'members'));
    }

    public function removeMember($convId, $userId)
    {
        db_connect()->table('conversation_members')
            ->where('conversation_id', (int) $convId)
            ->where('user_id', (int) $userId)
            ->delete();

        $this->audit('chat_member_removed', 'conversation', (int) $convId, "User #{$userId} removed");

        return $this->jsonResponse(['success' => true]);
    }

    public function deleteConversation($id)
    {
        db_connect()->table('conversations')->where('id', (int) $id)->delete();
        $this->audit('conversation_deleted', 'conversation', (int) $id);

        return redirect()->to('/manager/chat')->with('success', 'Conversation deleted.');
    }

    public function warnUser($userId)
    {
        $user = db_connect()->table('users')->select('id, name')->where('id', (int) $userId)->get()->getRowArray();
        if (! $user) {
            return $this->jsonResponse(['error' => 'User not found.'], 404);
        }
        $this->audit('user_warned', 'user', (int) $userId, "Chat warning");
        return $this->jsonResponse(['success' => true]);
    }
}
