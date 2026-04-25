<?php

namespace App\Controllers\Admin;

use App\Libraries\FCMNotificationService;

class ModerationController extends BaseAdminController
{
    public function index()
    {
        $db = db_connect();

        $pendingSubmissions = $db->table('submissions s')
            ->select('s.*, u.name AS user_name, u.email AS user_email')
            ->join('users u', 'u.id = s.user_id', 'left')
            ->where('s.status', 'pending')
            ->orderBy('s.created_at', 'ASC')
            ->get()->getResultArray();

        $reportedListings = $db->table('moderation_queue mq')
            ->select('mq.*, u.name AS reporter_name, l.title AS reference_title')
            ->join('users u', 'u.id = mq.reported_by', 'left')
            ->join('listings l', 'l.id = mq.reference_id', 'left')
            ->where('mq.reference_type', 'listing')
            ->where('mq.status', 'pending')
            ->orderBy('mq.created_at', 'ASC')
            ->get()->getResultArray();

        $reportedUsers = $db->table('moderation_queue mq')
            ->select('mq.*, u.name AS reporter_name, tu.name AS reference_title')
            ->join('users u', 'u.id = mq.reported_by', 'left')
            ->join('users tu', 'tu.id = mq.reference_id', 'left')
            ->where('mq.reference_type', 'user')
            ->where('mq.status', 'pending')
            ->orderBy('mq.created_at', 'ASC')
            ->get()->getResultArray();

        $reportedConversations = $db->table('moderation_queue mq')
            ->select('mq.*, u.name AS reporter_name')
            ->join('users u', 'u.id = mq.reported_by', 'left')
            ->where('mq.reference_type', 'conversation')
            ->where('mq.status', 'pending')
            ->orderBy('mq.created_at', 'ASC')
            ->get()->getResultArray();

        return $this->renderView('admin/moderation/index', compact(
            'pendingSubmissions', 'reportedListings', 'reportedUsers', 'reportedConversations'
        ));
    }

    public function approveSubmission($id)
    {
        $db         = db_connect();
        $submission = $db->table('submissions')->where('id', (int) $id)->get()->getRowArray();

        if (! $submission) {
            return $this->jsonResponse(['error' => 'Not found.'], 404);
        }

        $trustLevel = $this->request->getPost('trust_level') ?? 'community_submitted';
        $trustLabel = trim($this->request->getPost('trust_label') ?? 'Community Submitted');

        // Move to listings
        $db->table('listings')->insert([
            'title'       => $submission['title'],
            'description' => $submission['description'],
            'org_id'      => null,
            'trust_level' => $trustLevel,
            'trust_label' => $trustLabel,
            'date'        => $submission['date'],
            'location'    => $submission['location'],
            'source_url'  => $submission['source_url'],
            'status'      => 'approved',
            'created_by'  => $submission['user_id'],
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        $db->table('submissions')->where('id', (int) $id)->update([
            'status'      => 'approved',
            'trust_label' => $trustLabel,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        // Notify submitter
        (new FCMNotificationService())->notifySubmissionStatus((int) $submission['user_id'], (int) $id, 'approved');
        $this->audit('submission_approved', 'submission', (int) $id, $submission['title']);

        return $this->jsonResponse(['success' => true]);
    }

    public function rejectSubmission($id)
    {
        $db         = db_connect();
        $submission = $db->table('submissions')->where('id', (int) $id)->get()->getRowArray();

        if (! $submission) {
            return $this->jsonResponse(['error' => 'Not found.'], 404);
        }

        $db->table('submissions')->where('id', (int) $id)->update([
            'status'     => 'rejected',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        (new FCMNotificationService())->notifySubmissionStatus((int) $submission['user_id'], (int) $id, 'rejected');
        $this->audit('submission_rejected', 'submission', (int) $id, $submission['title']);

        return $this->jsonResponse(['success' => true]);
    }

    public function resolveReport($id)
    {
        $db     = db_connect();
        $report = $db->table('moderation_queue')->where('id', (int) $id)->get()->getRowArray();

        if (! $report) {
            return $this->jsonResponse(['error' => 'Not found.'], 404);
        }

        $action = $this->request->getPost('action'); // approve | dismiss

        if ($action === 'approve') {
            // Suspend reported user or remove reported content
            switch ($report['reference_type']) {
                case 'user':
                    $db->table('users')->where('id', $report['reference_id'])->update(['is_active' => 0]);
                    $this->audit('report_user_suspended', 'user', (int) $report['reference_id']);
                    break;
                case 'listing':
                    $db->table('listings')->where('id', $report['reference_id'])->update(['status' => 'rejected']);
                    $this->audit('report_listing_deactivated', 'listing', (int) $report['reference_id']);
                    break;
                case 'conversation':
                    $db->table('conversations')->where('id', $report['reference_id'])->delete();
                    $this->audit('report_conversation_deleted', 'conversation', (int) $report['reference_id']);
                    break;
                case 'live_session':
                    $db->table('live_sessions')->where('id', $report['reference_id'])->update(['status' => 'ended']);
                    $this->audit('report_live_ended', 'live_session', (int) $report['reference_id']);
                    break;
            }
        }

        $db->table('moderation_queue')->where('id', (int) $id)->update([
            'status'     => $action === 'approve' ? 'approved' : 'dismissed',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->jsonResponse(['success' => true]);
    }
}
