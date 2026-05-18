<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<div class="card mb-3" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-body py-2">
        <form method="get" class="d-flex gap-2 align-items-center">
            <select name="status" class="form-control form-control-sm" style="background:#1E1E1E;border-color:#333;color:#fff;max-width:160px">
                <option value="">All status</option>
                <option value="active"    <?= ($status ?? '') === 'active'    ? 'selected' : '' ?>>Live now</option>
                <option value="scheduled" <?= ($status ?? '') === 'scheduled' ? 'selected' : '' ?>>Upcoming</option>
                <option value="ended"     <?= ($status ?? '') === 'ended'     ? 'selected' : '' ?>>Ended</option>
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
                    <th>Time</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sessions as $s): ?>
                <tr>
                    <td style="font-size:.82rem;color:#ddd"><?= esc($s['host_name'] ?? 'Unknown') ?></td>
                    <td style="font-size:.82rem;color:#ddd"><?= esc(mb_strimwidth($s['title'] ?? '', 0, 45, '…')) ?></td>
                    <td style="font-size:.8rem;color:#888"><?= esc($s['category'] ?? '—') ?></td>
                    <td style="font-size:.8rem"><?= number_format($s['viewer_count'] ?? 0) ?></td>
                    <td style="font-size:.8rem;color:#888">
                        <?php
                            if ($s['status'] === 'scheduled' && ! empty($s['scheduled_at'])) {
                                echo date('M j H:i', strtotime($s['scheduled_at']));
                            } elseif (! empty($s['started_at'])) {
                                echo date('M j H:i', strtotime($s['started_at']));
                            } else {
                                echo '—';
                            }
                        ?>
                    </td>
                    <td>
                        <?php if ($s['status'] === 'active'): ?>
                            <span class="badge badge-danger"><i class="fas fa-circle mr-1" style="font-size:.5rem"></i>LIVE</span>
                        <?php elseif ($s['status'] === 'scheduled'): ?>
                            <span class="badge badge-primary">Upcoming</span>
                        <?php else: ?>
                            <span class="badge badge-secondary">Ended</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right">
                        <?php if ($s['status'] === 'active'): ?>
                        <button class="btn btn-xs btn-outline-danger" onclick="endSession(<?= (int)$s['id'] ?>)">End</button>
                        <?php endif; ?>
                        <button class="btn btn-xs btn-outline-secondary ml-1" onclick="deleteSession(<?= (int)$s['id'] ?>)">Delete</button>
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
const BASE = '<?= rtrim(site_url(), '/') ?>';
function endSession(id) {
    if (!confirm('End this live session?')) return;
    fetch(BASE + `/manager/live/${id}/end`, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json()).then(d => { if (d.success) location.reload(); else alert(d.error); });
}
function deleteSession(id) {
    if (!confirm('Delete this session record?')) return;
    fetch(BASE + `/manager/live/${id}/delete`, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json()).then(d => { if (d.success) location.reload(); else alert(d.error || 'Error'); });
}
</script>
<?= $this->endSection() ?>
