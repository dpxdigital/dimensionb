<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<div class="card mb-3" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-body py-2">
        <form method="get" class="d-flex gap-2 align-items-center">
            <select name="status" class="form-control form-control-sm" style="background:#1E1E1E;border-color:#333;color:#fff;max-width:140px">
                <option value="">All status</option>
                <option value="live" <?= ($statusFilter ?? '') === 'live' ? 'selected' : '' ?>>Live now</option>
                <option value="ended" <?= ($statusFilter ?? '') === 'ended' ? 'selected' : '' ?>>Ended</option>
            </select>
            <button class="btn btn-sm btn-secondary">Filter</button>
            <span class="ml-auto text-muted" style="font-size:.8rem"><?= number_format($total) ?> sessions</span>
        </form>
    </div>
</div>

<div class="card" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-body p-0">
        <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616">
            <thead>
                <tr>
                    <th>Host</th>
                    <th>Title</th>
                    <th>Category</th>
                    <th>Viewers</th>
                    <th>Started</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sessions as $s): ?>
                <tr>
                    <td style="font-size:.82rem;color:#ddd"><?= esc($s['host_name'] ?? 'Unknown') ?></td>
                    <td style="font-size:.82rem;color:#ddd"><?= esc(mb_strimwidth($s['title'], 0, 45, '…')) ?></td>
                    <td style="font-size:.8rem;color:#888"><?= esc($s['category_name'] ?? '—') ?></td>
                    <td style="font-size:.8rem"><?= number_format($s['viewer_count'] ?? 0) ?></td>
                    <td style="font-size:.8rem;color:#888"><?= date('M j H:i', strtotime($s['started_at'])) ?></td>
                    <td>
                        <?php if ($s['status'] === 'live'): ?>
                        <span class="badge badge-danger"><i class="fas fa-circle mr-1" style="font-size:.5rem"></i>LIVE</span>
                        <?php else: ?>
                        <span class="badge badge-secondary">Ended</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right">
                        <?php if ($s['status'] === 'live'): ?>
                        <button class="btn btn-xs btn-outline-danger" onclick="endSession(<?= $s['id'] ?>)">End</button>
                        <?php endif; ?>
                        <button class="btn btn-xs btn-outline-secondary ml-1" onclick="deleteSession(<?= $s['id'] ?>)">Delete</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($sessions)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No sessions found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($lastPage > 1): ?>
    <div class="card-footer" style="background:transparent;border-top:1px solid #2a2a2a">
        <?= view('admin/_pagination', ['page' => $page, 'lastPage' => $lastPage, 'q' => '']) ?>
    </div>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
function endSession(id) {
    if (!confirm('End this live session?')) return;
    fetch(`/manager/live/${id}/end`, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json()).then(d => { if (d.success) location.reload(); else alert(d.error); });
}
function deleteSession(id) {
    if (!confirm('Delete this session record?')) return;
    fetch(`/manager/live/${id}/delete`, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json()).then(d => { if (d.success) location.reload(); else alert(d.error); });
}
</script>
<?= $this->endSection() ?>
