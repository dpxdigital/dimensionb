<?php

namespace App\Controllers\Api\Listings;

use App\Controllers\Api\BaseApiController;
use App\Models\ActivityLogModel;
use App\Models\ListingModel;
use CodeIgniter\HTTP\ResponseInterface;

class ListingsController extends BaseApiController
{
    private const COMMENTS_PER_PAGE = 20;

    // ── GET /v1/listings/:id ──────────────────────────────────────────────────

    public function show($id = null): ResponseInterface
    {
        $userId  = $this->authUserId();
        $listing = (new ListingModel())->withMeta($userId)->active()->find($id);

        if ($listing === null) {
            return $this->error('Listing not found.', 404);
        }

        // Comments preview (first 3)
        $commentsPreview = db_connect()
            ->table('listing_comments lc')
            ->select('lc.id, lc.body, lc.created_at, u.id AS user_id, u.name AS user_name, u.avatar_url')
            ->join('users u', 'u.id = lc.user_id')
            ->where('lc.listing_id', $id)
            ->where('lc.is_deleted', 0)
            ->orderBy('lc.created_at', 'DESC')
            ->limit(3)
            ->get()
            ->getResultArray();

        return $this->success([
            'listing'          => ListingModel::format($listing),
            'comments_preview' => $commentsPreview,
        ]);
    }

    // ── POST /v1/listings/:id/save ────────────────────────────────────────────

    public function save($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = db_connect();

        $existing = $db->table('listing_saves')
            ->where('listing_id', $id)
            ->where('user_id', $userId)
            ->get()->getRowArray();

        if ($existing) {
            $db->table('listing_saves')
               ->where('listing_id', $id)->where('user_id', $userId)->delete();
            (new ActivityLogModel())->remove($userId, $id, 'save');
            $isSaved = false;
        } else {
            $db->table('listing_saves')->insert(['listing_id' => $id, 'user_id' => $userId, 'created_at' => date('Y-m-d H:i:s')]);
            (new ActivityLogModel())->log($userId, $id, 'save');
            $isSaved = true;
        }

        return $this->success(['is_saved' => $isSaved]);
    }

    // ── POST /v1/listings/:id/rsvp ────────────────────────────────────────────

    public function rsvp($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = db_connect();

        $existing = $db->table('listing_rsvps')
            ->where('listing_id', $id)->where('user_id', $userId)->get()->getRowArray();

        if ($existing) {
            $db->table('listing_rsvps')
               ->where('listing_id', $id)->where('user_id', $userId)->delete();
            $db->table('listings')->where('id', $id)->set('rsvp_count', 'rsvp_count - 1', false)->update();
            (new ActivityLogModel())->remove($userId, $id, 'rsvp');
            $isRsvped = false;
        } else {
            $db->table('listing_rsvps')->insert(['listing_id' => $id, 'user_id' => $userId, 'created_at' => date('Y-m-d H:i:s')]);
            $db->table('listings')->where('id', $id)->set('rsvp_count', 'rsvp_count + 1', false)->update();
            (new ActivityLogModel())->log($userId, $id, 'rsvp');
            $isRsvped = true;
        }

        return $this->success(['is_rsvped' => $isRsvped]);
    }

    // ── POST /v1/listings/:id/apply ───────────────────────────────────────────

    public function apply($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = db_connect();

        $existing = $db->table('listing_applications')
            ->where('listing_id', $id)->where('user_id', $userId)->get()->getRowArray();

        if ($existing) {
            return $this->success(['isApplied' => true, 'message' => 'Already applied.']);
        }

        $db->table('listing_applications')->insert([
            'listing_id' => $id,
            'user_id'    => $userId,
            'status'     => 'submitted',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        (new ActivityLogModel())->log($userId, $id, 'apply');

        return $this->success(['isApplied' => true], 'Application submitted', 201);
    }

    // ── POST /v1/listings/:id/like ────────────────────────────────────────────

    public function like($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $db     = db_connect();

        $existing = $db->table('listing_likes')
            ->where('listing_id', $id)->where('user_id', $userId)->get()->getRowArray();

        if ($existing) {
            $db->table('listing_likes')
               ->where('listing_id', $id)->where('user_id', $userId)->delete();
            $db->table('listings')->where('id', $id)->set('like_count', 'GREATEST(like_count - 1, 0)', false)->update();
            $isLiked = false;
        } else {
            $db->table('listing_likes')->insert(['listing_id' => $id, 'user_id' => $userId, 'created_at' => date('Y-m-d H:i:s')]);
            $db->table('listings')->where('id', $id)->set('like_count', 'like_count + 1', false)->update();
            $isLiked = true;
        }

        $listing = db_connect()->table('listings')->select('like_count')->where('id', $id)->get()->getRowArray();

        return $this->success([
            'is_liked'   => $isLiked,
            'like_count' => (int) ($listing['like_count'] ?? 0),
        ]);
    }

    // ── POST /v1/listings/:id/share ───────────────────────────────────────────

    public function share($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        (new ActivityLogModel())->log($userId, $id, 'share');
        return $this->success(null, 'Share logged');
    }

    // ── GET /v1/listings/:id/comments ─────────────────────────────────────────

    public function comments($id = null): ResponseInterface
    {
        $page   = max(1, (int) ($this->request->getGet('page') ?? 1));
        $offset = ($page - 1) * self::COMMENTS_PER_PAGE;
        $db     = db_connect();

        $total = (int) $db->table('listing_comments')
            ->where('listing_id', $id)->where('is_deleted', 0)->countAllResults();

        $rows = $db->table('listing_comments lc')
            ->select('lc.id, lc.body, lc.created_at, u.id AS user_id, u.name AS user_name, u.avatar_url')
            ->join('users u', 'u.id = lc.user_id')
            ->where('lc.listing_id', $id)
            ->where('lc.is_deleted', 0)
            ->orderBy('lc.created_at', 'DESC')
            ->limit(self::COMMENTS_PER_PAGE, $offset)
            ->get()->getResultArray();

        $lastPage = (int) ceil($total / self::COMMENTS_PER_PAGE);

        return $this->success($rows, 'OK', 200, [
            'current_page' => $page,
            'per_page'     => self::COMMENTS_PER_PAGE,
            'total'        => $total,
            'last_page'    => $lastPage,
        ]);
    }

    // ── POST /v1/listings/:id/comments ────────────────────────────────────────

    public function addComment($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $input  = $this->inputJson();

        if (! $this->validateData($input, ['body' => 'required|max_length[500]'])) {
            return $this->validationError($this->validator->getErrors());
        }

        $db = db_connect();
        $db->table('listing_comments')->insert([
            'listing_id' => $id,
            'user_id'    => $userId,
            'body'       => trim($input['body']),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $newCommentId = $db->insertID(); // capture before any further queries

        $db->table('listings')->where('id', $id)->set('comment_count', 'comment_count + 1', false)->update();

        $comment = $db->table('listing_comments lc')
            ->select('lc.id, lc.listing_id, lc.body, lc.created_at, u.id AS user_id, u.name AS user_name, u.avatar_url')
            ->join('users u', 'u.id = lc.user_id')
            ->where('lc.id', $newCommentId)
            ->get()->getRowArray();

        return $this->success($comment, 'Comment added', 201);
    }

    // ── POST /v1/listings/:id/report ──────────────────────────────────────────

    public function report($id = null): ResponseInterface
    {
        $userId = $this->authUserId();
        $input  = $this->inputJson();

        db_connect()->table('moderation_queue')->insert([
            'reference_type' => 'listing',
            'reference_id'   => $id,
            'reported_by'    => $userId,
            'reason'         => $input['reason'] ?? null,
            'status'         => 'pending',
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        return $this->success(null, 'Report submitted. Thank you.');
    }
}
