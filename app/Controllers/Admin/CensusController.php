<?php

namespace App\Controllers\Admin;

class CensusController extends BaseAdminController
{
    private const PER_PAGE = 30;

    public function index()
    {
        $db     = db_connect();
        $search = trim($this->request->getGet('q') ?? '');
        $state  = trim($this->request->getGet('state') ?? '');
        $gender = trim($this->request->getGet('gender') ?? '');
        $page   = max(1, (int) ($this->request->getGet('page') ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $query = $db->table('census_records cr')
            ->select('cr.*, u.name AS account_name, u.avatar_url,
                      ch.name AS chapter_name')
            ->join('users u', 'u.id = cr.user_id', 'left')
            ->join('chapters ch', 'ch.id = cr.chapter_id', 'left');

        if ($search !== '') {
            $query->groupStart()
                  ->like('cr.first_name', $search)
                  ->orLike('cr.last_name', $search)
                  ->orLike('cr.email', $search)
                  ->orLike('cr.phone', $search)
                  ->groupEnd();
        }

        if ($state !== '') {
            $query->where('cr.state', $state);
        }

        if ($gender !== '') {
            $query->where('cr.gender', $gender);
        }

        $countQuery = $db->table('census_records cr');
        if ($search !== '') {
            $countQuery->groupStart()
                       ->like('first_name', $search)
                       ->orLike('last_name', $search)
                       ->orLike('email', $search)
                       ->orLike('phone', $search)
                       ->groupEnd();
        }
        if ($state !== '') $countQuery->where('state', $state);
        if ($gender !== '') $countQuery->where('gender', $gender);
        $total = $countQuery->countAllResults();

        $records  = $query->orderBy('cr.created_at', 'DESC')->limit(self::PER_PAGE, $offset)->get()->getResultArray();
        $lastPage = (int) ceil($total / self::PER_PAGE);

        // Distinct states for filter dropdown
        $stateRows = $db->query("SELECT DISTINCT state FROM census_records WHERE state IS NOT NULL AND state != '' ORDER BY state")->getResultArray();
        $states = array_column($stateRows, 'state');

        $pageTitle = 'Black Census Submissions';

        return $this->renderView('admin/census/index', compact(
            'records', 'total', 'page', 'lastPage', 'search', 'state', 'gender', 'states', 'pageTitle'
        ));
    }

    public function export()
    {
        $db      = db_connect();
        $records = $db->table('census_records cr')
            ->select('cr.first_name, cr.last_name, cr.email, cr.phone, cr.date_of_birth, cr.gender,
                      cr.city, cr.state, cr.zip, cr.sms_updates, cr.email_updates, cr.created_at,
                      ch.name AS chapter')
            ->join('chapters ch', 'ch.id = cr.chapter_id', 'left')
            ->orderBy('cr.created_at', 'DESC')
            ->get()->getResultArray();

        $csv = "First Name,Last Name,Email,Phone,Date of Birth,Gender,City,State,ZIP,Chapter,SMS Updates,Email Updates,Submitted At\n";
        foreach ($records as $r) {
            $csv .= implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string) $v) . '"', [
                $r['first_name'], $r['last_name'], $r['email'], $r['phone'] ?? '',
                $r['date_of_birth'] ?? '', $r['gender'] ?? '', $r['city'] ?? '',
                $r['state'] ?? '', $r['zip'] ?? '', $r['chapter'] ?? '',
                $r['sms_updates'] ? 'Yes' : 'No', $r['email_updates'] ? 'Yes' : 'No',
                $r['created_at'],
            ])) . "\n";
        }

        $this->audit('census_export', 'census_records', null, count($records) . ' rows');

        return $this->response
            ->setHeader('Content-Type', 'text/csv')
            ->setHeader('Content-Disposition', 'attachment; filename="black_census_' . date('Y-m-d') . '.csv"')
            ->setBody($csv);
    }
}
