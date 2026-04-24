<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<div class="row">
    <!-- Profile card -->
    <div class="col-lg-3 mb-4">
        <div class="card" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-body text-center">
                <?php if ($user['avatar_url']): ?>
                <img src="<?= esc($user['avatar_url']) ?>" class="rounded-circle mb-2" width="72" height="72" style="object-fit:cover">
                <?php else: ?>
                <div class="rounded-circle d-flex align-items-center justify-content-center mx-auto mb-2"
                     style="width:72px;height:72px;background:#2a2a2a;font-size:1.6rem;color:#888">
                    <?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>
                </div>
                <?php endif; ?>
                <h5 class="mb-0" style="color:#fff"><?= esc($user['name']) ?></h5>
                <small class="text-muted"><?= esc($user['email']) ?></small>
                <div class="mt-2">
                    <span class="badge <?= $user['is_active'] ? 'badge-success' : 'badge-secondary' ?>">
                        <?= $user['is_active'] ? 'Active' : 'Banned' ?>
                    </span>
                </div>
            </div>
            <div class="card-footer p-0" style="border-top:1px solid #2a2a2a">
                <table class="table table-sm mb-0" style="--bs-table-bg:transparent;color:#aaa;font-size:.8rem">
                    <tr><td style="color:#666">Joined</td><td><?= date('M j Y', strtotime($user['created_at'])) ?></td></tr>
                    <tr><td style="color:#666">Location</td><td><?= esc($user['location'] ?? '—') ?></td></tr>
                    <tr><td style="color:#666">Trust</td><td><?= esc(str_replace('_', ' ', $user['trust_level'] ?? '—')) ?></td></tr>
                </table>
            </div>
        </div>

        <div class="mt-3 d-grid gap-2">
            <button class="btn btn-sm <?= $user['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                    onclick="toggleUser(<?= $user['id'] ?>, <?= $user['is_active'] ? 0 : 1 ?>)">
                <i class="fas <?= $user['is_active'] ? 'fa-ban' : 'fa-check' ?> mr-1"></i>
                <?= $user['is_active'] ? 'Ban User' : 'Unban User' ?>
            </button>
            <button class="btn btn-sm btn-outline-danger" onclick="deleteUser(<?= $user['id'] ?>)">
                <i class="fas fa-trash mr-1"></i> Delete Account
            </button>
        </div>
    </div>

    <!-- Tabs -->
    <div class="col-lg-9">
        <ul class="nav nav-tabs mb-3" style="border-color:#2a2a2a">
            <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#saved" style="color:#aaa">Saved (<?= count($saved) ?>)</a></li>
            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#rsvped" style="color:#aaa">RSVPs (<?= count($rsvped) ?>)</a></li>
            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#submissions" style="color:#aaa">Submissions (<?= count($submissions) ?>)</a></li>
            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#interests" style="color:#aaa">Interests</a></li>
        </ul>
        <div class="tab-content">
            <?php foreach ([['saved', $saved], ['rsvped', $rsvped], ['submissions', $submissions]] as [$tabId, $items]): ?>
            <div class="tab-pane <?= $tabId === 'saved' ? 'active' : '' ?>" id="<?= $tabId ?>">
                <div class="card" style="background:#161616;border:1px solid #2a2a2a">
                    <div class="card-body p-0">
                        <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616">
                            <thead><tr><th>Title</th><th>Category</th><th>Date</th></tr></thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                <tr>
                                    <td style="font-size:.82rem">
                                        <a href="/manager/listings/<?= $item['id'] ?>" style="color:#7F77DD">
                                            <?= esc($item['title']) ?>
                                        </a>
                                    </td>
                                    <td style="font-size:.8rem;color:#888"><?= esc($item['category_name'] ?? '—') ?></td>
                                    <td style="font-size:.8rem;color:#888"><?= $item['created_at'] ? date('M j Y', strtotime($item['created_at'])) : '—' ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($items)): ?>
                                <tr><td colspan="3" class="text-center text-muted py-3">None</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="tab-pane" id="interests">
                <div class="card" style="background:#161616;border:1px solid #2a2a2a">
                    <div class="card-body">
                        <?php if (!empty($interests)): ?>
                            <?php foreach ($interests as $i): ?>
                            <span class="badge badge-secondary mr-1 mb-1" style="font-size:.8rem"><?= esc($i['name']) ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="text-muted">No interests set</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
function toggleUser(id, active) {
    if (!confirm(active ? 'Unban this user?' : 'Ban this user?')) return;
    fetch(`/manager/users/${id}/toggle-status`, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json()).then(d => { if (d.success) location.reload(); else alert(d.error); });
}
function deleteUser(id) {
    if (!confirm('Permanently delete this user? This cannot be undone.')) return;
    fetch(`/manager/users/${id}/delete`, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json()).then(d => { if (d.success) location.href = '/manager/users'; else alert(d.error); });
}
</script>
<?= $this->endSection() ?>
