<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<ul class="nav nav-tabs mb-3" style="border-color:#2a2a2a" id="modTabs">
    <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#submissions">Submissions <span class="badge badge-danger ml-1"><?= count($pendingSubmissions) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#listings">Reported Listings <span class="badge badge-warning ml-1"><?= count($reportedListings) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#users">Reported Users <span class="badge badge-warning ml-1"><?= count($reportedUsers) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#convs">Reported Conversations <span class="badge badge-warning ml-1"><?= count($reportedConversations) ?></span></a></li>
</ul>

<div class="tab-content">

    <!-- Submissions -->
    <div class="tab-pane active" id="submissions">
        <div class="card" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-body p-0">
                <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616">
                    <thead><tr><th>Title</th><th>Type</th><th>Org</th><th>Submitted</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($pendingSubmissions as $s): ?>
                        <tr id="sub-<?= $s['id'] ?>">
                            <td style="font-size:.85rem;color:#ddd"><?= esc(mb_strimwidth($s['title'], 0, 60, '…')) ?></td>
                            <td><span class="badge badge-secondary"><?= esc($s['type']) ?></span></td>
                            <td style="font-size:.8rem;color:#888"><?= esc($s['org_name'] ?? '—') ?></td>
                            <td style="font-size:.8rem;color:#888"><?= date('M j Y', strtotime($s['created_at'])) ?></td>
                            <td>
                                <div class="d-flex gap-1">
                                    <select class="form-control form-control-sm trust-select" style="background:#1E1E1E;border-color:#333;color:#fff;width:160px">
                                        <?php foreach (['institution_verified','curator_reviewed','community_submitted'] as $tl): ?>
                                        <option value="<?= $tl ?>"><?= esc(str_replace('_',' ',$tl)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-xs btn-success" onclick="approveSubmission(<?= $s['id'] ?>, this)">Approve</button>
                                    <button class="btn btn-xs btn-danger" onclick="rejectSubmission(<?= $s['id'] ?>)">Reject</button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($pendingSubmissions)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4"><i class="fas fa-check-circle mr-1"></i>All clear!</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Reported Listings -->
    <div class="tab-pane" id="listings">
        <?= $this->renderSection('_reportTable') ?>
        <div class="card" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-body p-0">
                <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616">
                    <thead><tr><th>Listing</th><th>Reason</th><th>Reporter</th><th>Date</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($reportedListings as $r): ?>
                        <tr id="rpt-<?= $r['id'] ?>">
                            <td style="font-size:.82rem;color:#ddd"><?= esc(mb_strimwidth($r['reference_title'] ?? 'Listing #'.$r['reference_id'], 0, 50, '…')) ?></td>
                            <td style="font-size:.8rem;color:#888"><?= esc($r['reason'] ?? '—') ?></td>
                            <td style="font-size:.8rem;color:#888"><?= esc($r['reporter_name'] ?? '—') ?></td>
                            <td style="font-size:.8rem;color:#888"><?= date('M j', strtotime($r['created_at'])) ?></td>
                            <td><button class="btn btn-xs btn-outline-secondary" onclick="resolveReport(<?= $r['id'] ?>)">Resolve</button></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($reportedListings)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No reports</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Reported Users -->
    <div class="tab-pane" id="users">
        <div class="card" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-body p-0">
                <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616">
                    <thead><tr><th>User</th><th>Reason</th><th>Reporter</th><th>Date</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($reportedUsers as $r): ?>
                        <tr id="rpt-u-<?= $r['id'] ?>">
                            <td style="font-size:.82rem;color:#ddd"><?= esc($r['reference_title'] ?? 'User #'.$r['reference_id']) ?></td>
                            <td style="font-size:.8rem;color:#888"><?= esc($r['reason'] ?? '—') ?></td>
                            <td style="font-size:.8rem;color:#888"><?= esc($r['reporter_name'] ?? '—') ?></td>
                            <td style="font-size:.8rem;color:#888"><?= date('M j', strtotime($r['created_at'])) ?></td>
                            <td><button class="btn btn-xs btn-outline-secondary" onclick="resolveReport(<?= $r['id'] ?>)">Resolve</button></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($reportedUsers)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No reports</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Reported Conversations -->
    <div class="tab-pane" id="convs">
        <div class="card" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-body p-0">
                <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616">
                    <thead><tr><th>Conversation</th><th>Reason</th><th>Reporter</th><th>Date</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($reportedConversations as $r): ?>
                        <tr id="rpt-c-<?= $r['id'] ?>">
                            <td style="font-size:.82rem;color:#ddd"><?= esc($r['reference_title'] ?? 'Conversation #'.$r['reference_id']) ?></td>
                            <td style="font-size:.8rem;color:#888"><?= esc($r['reason'] ?? '—') ?></td>
                            <td style="font-size:.8rem;color:#888"><?= esc($r['reporter_name'] ?? '—') ?></td>
                            <td style="font-size:.8rem;color:#888"><?= date('M j', strtotime($r['created_at'])) ?></td>
                            <td>
                                <a href="/manager/chat/<?= $r['reference_id'] ?>" class="btn btn-xs btn-outline-secondary mr-1">View</a>
                                <button class="btn btn-xs btn-outline-secondary" onclick="resolveReport(<?= $r['id'] ?>)">Resolve</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($reportedConversations)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No reports</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
function approveSubmission(id, btn) {
    const row = document.getElementById(`sub-${id}`);
    const trust = row.querySelector('.trust-select').value;
    fetch(`/manager/moderation/submissions/${id}/approve`, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
        body: JSON.stringify({ trust_level: trust })
    }).then(r => r.json()).then(d => {
        if (d.success) row.remove(); else alert(d.error);
    });
}
function rejectSubmission(id) {
    const reason = prompt('Rejection reason (optional):') ?? '';
    fetch(`/manager/moderation/submissions/${id}/reject`, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
        body: JSON.stringify({ reason })
    }).then(r => r.json()).then(d => {
        if (d.success) document.getElementById(`sub-${id}`).remove(); else alert(d.error);
    });
}
function resolveReport(id) {
    fetch(`/manager/moderation/reports/${id}/resolve`, {
        method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(r => r.json()).then(d => {
        if (d.success) { ['', '-u-', '-c-'].forEach(p => { const el = document.getElementById(`rpt${p}${id}`); if (el) el.remove(); }); }
        else alert(d.error);
    });
}
</script>
<?= $this->endSection() ?>
