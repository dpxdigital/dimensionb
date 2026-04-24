<?php

namespace App\Controllers\Admin;

class LiveController extends BaseAdminController
{
    private const PER_PAGE = 25;

    public function index()
    {
        $db     = db_connect();
        $status = $this->request->getGet('status') ?? '';
        $page   = max(1, (int) ($this->request->getGet('page') ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $query = $db->table('live_sessions ls')
            ->select('ls.*, u.name AS host_name, u.email AS host_email')
            ->join('users u', 'u.id = ls.host_id', 'left');

        $countQ = $db->table('live_sessions');

        if ($status !== '') {
            $query->where('ls.status', $status);
            $countQ->where('status', $status);
        }

        $total    = $countQ->countAllResults();
        $sessions = $query->orderBy('ls.started_at', 'DESC')->limit(self::PER_PAGE, $offset)->get()->getResultArray();
        $lastPage = (int) ceil($total / self::PER_PAGE);

        return $this->renderView('admin/live/index', compact('sessions', 'total', 'page', 'lastPage', 'status'));
    }

    public function endSession($id)
    {
        $session = db_connect()->table('live_sessions')->where('id', (int) $id)->get()->getRowArray();

        if (! $session) {
            return $this->jsonResponse(['error' => 'Session not found.'], 404);
        }

        if ($session['status'] === 'ended') {
            return $this->jsonResponse(['error' => 'Session already ended.'], 409);
        }

        db_connect()->table('live_sessions')->where('id', (int) $id)->update([
            'status'   => 'ended',
            'ended_at' => date('Y-m-d H:i:s'),
        ]);

        $this->audit('live_session_ended', 'live_session', (int) $id, "Remote end by admin");

        return $this->jsonResponse(['success' => true]);
    }

    public function delete($id)
    {
        $session = db_connect()->table('live_sessions')->where('id', (int) $id)->get()->getRowArray();

        if (! $session) {
            return redirect()->to('/manager/live')->with('error', 'Session not found.');
        }

        db_connect()->table('live_sessions')->where('id', (int) $id)->delete();
        $this->audit('live_session_deleted', 'live_session', (int) $id, $session['title'] ?? '');

        return redirect()->to('/manager/live')->with('success', 'Live session deleted.');
    }
}
