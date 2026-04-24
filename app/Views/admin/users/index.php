<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<!-- Search & filters -->
<div class="card mb-3" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-body py-2">
        <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
            <input type="text" name="q" class="form-control form-control-sm"
                   style="background:#1E1E1E;border-color:#333;color:#fff;max-width:260px"
                   placeholder="Search name / email…" value="<?= esc($q ?? '') ?>">
            <button class="btn btn-sm btn-secondary">Search</button>
            <?php if (!empty($q)): ?>
            <a href="/manager/users" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
            <span class="ml-auto text-muted" style="font-size:.8rem"><?= number_format($total) ?> users</span>
        </form>
    </div>
</div>

<div class="card" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-body p-0">
        <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616;--bs-table-striped-bg:#1a1a1a">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Email</th>
                    <th>Trust</th>
                    <th>Joined</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?php if ($u['avatar_url']): ?>
                            <img src="<?= esc($u['avatar_url']) ?>" class="rounded-circle" width="28" height="28" style="object-fit:cover">
                            <?php else: ?>
                            <div class="rounded-circle d-flex align-items-center justify-content-center"
                                 style="width:28px;height:28px;background:#2a2a2a;font-size:.7rem;color:#888">
                                <?= strtoupper(substr($u['name'] ?? 'U', 0, 1)) ?>
                            </div>
                            <?php endif; ?>
                            <span style="font-size:.85rem"><?= esc($u['name']) ?></span>
                        </div>
                    </td>
                    <td style="font-size:.82rem;color:#aaa"><?= esc($u['email']) ?></td>
                    <td>
                        <?php
                        $tmap = [
                            'institution_verified' => ['iv',  'badge-trust-iv'],
                            'curator_reviewed'     => ['CR',  'badge-trust-cr'],
                            'community_submitted'  => ['CS',  'badge-trust-cs'],
                            'approved_live_host'   => ['ALH', 'badge-trust-alh'],
                        ];
                        $t = $tmap[$u['trust_level'] ?? ''] ?? null;
                        if ($t): ?>
                        <span class="badge <?= $t[1] ?>"><?= $t[0] ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.8rem;color:#888"><?= date('M j Y', strtotime($u['created_at'])) ?></td>
                    <td>
                        <span class="badge <?= $u['is_active'] ? 'badge-success' : 'badge-secondary' ?>">
                            <?= $u['is_active'] ? 'Active' : 'Banned' ?>
                        </span>
                    </td>
                    <td class="text-right">
                        <a href="/manager/users/<?= $u['id'] ?>" class="btn btn-xs btn-outline-secondary">View</a>
                        <button class="btn btn-xs <?= $u['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                onclick="toggleUser(<?= $u['id'] ?>, <?= $u['is_active'] ? 0 : 1 ?>)">
                            <?= $u['is_active'] ? 'Ban' : 'Unban' ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($users)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No users found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($lastPage > 1): ?>
    <div class="card-footer" style="background:transparent;border-top:1px solid #2a2a2a">
        <?= view('admin/_pagination', ['page' => $page, 'lastPage' => $lastPage, 'q' => $q ?? '']) ?>
    </div>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
function toggleUser(id, active) {
    if (!confirm(active ? 'Unban this user?' : 'Ban this user?')) return;
    fetch(`/manager/users/${id}/toggle-status`, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
        body: JSON.stringify({ _method: 'POST' })
    }).then(r => r.json()).then(d => { if (d.success) location.reload(); else alert(d.error); });
}
</script>
<?= $this->endSection() ?>
