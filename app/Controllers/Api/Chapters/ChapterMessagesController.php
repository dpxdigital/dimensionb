<?php

namespace App\Controllers\Api\Chapters;

use App\Controllers\Api\BaseApiController;
use App\Libraries\S3Uploader;
use CodeIgniter\HTTP\ResponseInterface;

class ChapterMessagesController extends BaseApiController
{
    private function db() { return db_connect(); }

    private function isMember(int $chapterId, int $userId): bool
    {
        return (bool) $this->db()
            ->table('chapter_members')
            ->where('chapter_id', $chapterId)
            ->where('user_id', $userId)
            ->countAllResults();
    }

    // ── GET /v1/chapters/:id/messages ─────────────────────────────────────────
    // Supports polling via `since` (ISO timestamp) for new-messages-only fetch.
    // Supports cursor pagination via `before_id` for loading history.

    public function index($chapterId = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = $this->db();
        $id     = (int) $chapterId;

        // Must be a member (or chapter is public — keep open for now)
        $chapter = $db->table('chapters')->where('id', $id)->get()->getRowArray();
        if (! $chapter) return $this->error('Chapter not found.', 404);

        $since    = $this->request->getGet('since');   // ISO datetime → poll mode
        $beforeId = (int) ($this->request->getGet('before_id') ?? 0);
        $limit    = 40;

        $query = $db->table('chapter_messages m')
            ->select('m.*, u.name AS user_name, u.avatar_url,
                      r.id AS reply_msg_id, r.body AS reply_body, ru.name AS reply_user_name')
            ->join('users u', 'u.id = m.user_id')
            ->join('chapter_messages r', 'r.id = m.reply_to_id', 'left')
            ->join('users ru', 'ru.id = r.user_id', 'left')
            ->where('m.chapter_id', $id)
            ->where('m.is_deleted', 0)
            ->limit($limit);

        if ($since) {
            // Poll: return messages newer than timestamp
            $query->where('m.created_at >', $since)->orderBy('m.created_at', 'ASC');
        } elseif ($beforeId > 0) {
            // Pagination: messages older than cursor
            $query->where('m.id <', $beforeId)->orderBy('m.id', 'DESC');
        } else {
            // Initial load: latest messages
            $query->orderBy('m.id', 'DESC');
        }

        $rows = $query->get()->getResultArray();

        // Attach reactions
        $msgIds = array_column($rows, 'id');
        $reactionMap = [];
        if (! empty($msgIds)) {
            $reactions = $db->table('chapter_message_reactions')
                ->whereIn('message_id', $msgIds)
                ->get()->getResultArray();
            foreach ($reactions as $r) {
                $reactionMap[$r['message_id']][$r['emoji']][] = $r['user_id'];
            }
        }

        $formatted = array_map(fn($r) => $this->formatMsg($r, $reactionMap, $userId), $rows);

        // For poll mode keep chronological; for history keep DESC (client reverses)
        return $this->success($formatted, 'OK', 200, [
            'has_more' => count($rows) === $limit,
        ]);
    }

    // ── POST /v1/chapters/:id/messages ────────────────────────────────────────

    public function create($chapterId = null): ResponseInterface
    {
        $userId  = $this->authUserId();
        $id      = (int) $chapterId;

        if (! $this->isMember($id, $userId)) {
            return $this->error('You must join this chapter to post.', 403);
        }

        $isMultipart = str_contains($this->request->getHeaderLine('Content-Type'), 'multipart');
        $input       = $isMultipart ? $this->request->getPost() : $this->inputJson();

        $body      = trim($input['body'] ?? '');
        $replyToId = isset($input['reply_to_id']) ? (int) $input['reply_to_id'] : null;

        $mediaUrl  = null;
        $mediaType = null;

        if ($isMultipart) {
            $file = $this->request->getFile('media');
            if ($file && $file->isValid()) {
                $ext      = strtolower($file->getExtension());
                $fn       = "cm_{$id}_{$userId}_" . time() . ".{$ext}";
                $file->move(WRITEPATH . 'uploads/', $fn);
                $s3       = new S3Uploader();
                $mediaUrl = $s3->uploadOrLocal(WRITEPATH . "uploads/{$fn}", "uploads/chapter_media/{$fn}", "image/{$ext}", 'chapters');
                $mediaType = in_array($ext, ['mp4', 'mov', 'webm']) ? 'video' : 'image';
            }
        }

        if (empty($body) && $mediaUrl === null) {
            return $this->validationError(['body' => 'Message cannot be empty.']);
        }

        $db  = $this->db();
        $now = date('Y-m-d H:i:s');
        $db->table('chapter_messages')->insert([
            'chapter_id'  => $id,
            'user_id'     => $userId,
            'body'        => $body ?: null,
            'media_url'   => $mediaUrl,
            'media_type'  => $mediaType,
            'reply_to_id' => $replyToId,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $msgId = $db->insertID();
        $msg   = $db->table('chapter_messages m')
            ->select('m.*, u.name AS user_name, u.avatar_url')
            ->join('users u', 'u.id = m.user_id')
            ->where('m.id', $msgId)
            ->get()->getRowArray();

        // Bump post count on chapter
        $db->table('chapters')->where('id', $id)->set('post_count', 'post_count + 1', false)->update();

        return $this->success($this->formatMsg($msg, [], $userId), 'Message sent', 201);
    }

    // ── DELETE /v1/chapters/:id/messages/:msgId ───────────────────────────────

    public function delete($chapterId = null, $msgId = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = $this->db();

        $msg = $db->table('chapter_messages')->where('id', (int) $msgId)->get()->getRowArray();
        if (! $msg) return $this->error('Message not found.', 404);
        if ((int) $msg['user_id'] !== $userId) return $this->error('Not your message.', 403);

        $db->table('chapter_messages')->where('id', (int) $msgId)->set(['is_deleted' => 1, 'updated_at' => date('Y-m-d H:i:s')])->update();
        return $this->success(null, 'Deleted');
    }

    // ── POST /v1/chapters/:id/messages/:msgId/react ───────────────────────────

    public function react($chapterId = null, $msgId = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $input  = $this->inputJson();
        $emoji  = trim($input['emoji'] ?? '');
        $db     = $this->db();

        if (empty($emoji)) return $this->validationError(['emoji' => 'Emoji is required.']);

        $existing = $db->table('chapter_message_reactions')
            ->where('message_id', (int) $msgId)
            ->where('user_id', $userId)
            ->where('emoji', $emoji)
            ->get()->getRowArray();

        if ($existing) {
            $db->table('chapter_message_reactions')->where('id', $existing['id'])->delete();
            return $this->success(['reacted' => false]);
        }

        $db->table('chapter_message_reactions')->insert([
            'message_id' => (int) $msgId,
            'user_id'    => $userId,
            'emoji'      => $emoji,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->success(['reacted' => true]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function formatMsg(array $r, array $reactionMap, int $myUserId): array
    {
        $reactions = [];
        foreach ($reactionMap[(int) $r['id']] ?? [] as $emoji => $userIds) {
            $reactions[] = [
                'emoji'    => $emoji,
                'count'    => count($userIds),
                'reacted_by_me' => in_array($myUserId, $userIds, true),
            ];
        }
        return [
            'id'              => (string) $r['id'],
            'chapter_id'      => (string) $r['chapter_id'],
            'user_id'         => (string) $r['user_id'],
            'user_name'       => $r['user_name'],
            'avatar_url'      => $r['avatar_url'] ?? null,
            'body'            => $r['body'] ?? null,
            'media_url'       => $r['media_url'] ?? null,
            'media_type'      => $r['media_type'] ?? null,
            'reply_to_id'     => $r['reply_to_id'] ? (string) $r['reply_to_id'] : null,
            'reply_body'      => $r['reply_body'] ?? null,
            'reply_user_name' => $r['reply_user_name'] ?? null,
            'reactions'       => $reactions,
            'is_mine'         => (int) $r['user_id'] === $myUserId,
            'created_at'      => $r['created_at'],
        ];
    }
}
