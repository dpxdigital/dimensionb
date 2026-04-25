<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<div class="mb-3 d-flex justify-content-end">
    <a href="/manager/admin-users/create" class="btn btn-brand btn-sm">
        <i class="fas fa-plus mr-1"></i> Create Admin User
    </a>
</div>

<div class="card" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-body p-0">
        <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616;--bs-table-striped-bg:#1a1a1a">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($adminUsers as $u): ?>
                <?php $isSelf = ((int) $u['id'] === (int) session('admin_id')); ?>
                <tr>
                    <td style="font-size:.85rem">
                        <?= esc($u['name']) ?>
                        <?php if ($isSelf): ?><span class="badge badge-secondary ml-1" style="font-size:.65rem">You</span><?php endif; ?>
                    </td>
                    <td style="font-size:.82rem;color:#aaa"><?= esc($u['email']) ?></td>
                    <td>
                        <span class="badge <?= $u['role'] === 'super_admin' ? 'badge-danger' : 'badge-warning' ?>">
                            <?= $u['role'] === 'super_admin' ? 'Super Admin' : 'Moderator' ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?= $u['is_active'] ? 'badge-success' : 'badge-secondary' ?>" id="status-badge-<?= $u['id'] ?>">
                            <?= $u['is_active'] ? 'Active' : 'Disabled' ?>
                        </span>
                    </td>
                    <td style="font-size:.78rem;color:#666">
                        <?= $u['last_login'] ? date('M j Y H:i', strtotime($u['last_login'])) : '—' ?>
                    </td>
                    <td style="font-size:.78rem;color:#666"><?= date('M j Y', strtotime($u['created_at'])) ?></td>
                    <td class="text-right">
                        <?php if (! $isSelf): ?>
                        <button class="btn btn-xs <?= $u['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                onclick="toggleAdmin(<?= $u['id'] ?>, <?= $u['is_active'] ?>)">
                            <?= $u['is_active'] ? 'Disable' : 'Enable' ?>
                        </button>
                        <button class="btn btn-xs btn-outline-danger ml-1"
                                onclick="deleteAdmin(<?= $u['id'] ?>, '<?= esc($u['name']) ?>')">
                            Delete
                        </button>
                        <?php else: ?>
                        <span style="color:#444;font-size:.78rem">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($adminUsers)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No admin users found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Delete form (hidden, submitted via JS) -->
<form id="delete-form" method="post" style="display:none">
    <?= csrf_field() ?>
</form>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
function toggleAdmin(id, currentActive) {
    if (! confirm(currentActive ? 'Disable this admin user?' : 'Enable this admin user?')) return;
    fetch(`/manager/admin-users/${id}/toggle`, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
        body: JSON.stringify({})
    })
    .then(r => r.json())
    .then(d => {
        if (d.status) {
            const badge = document.getElementById(`status-badge-${id}`);
            badge.className = 'badge ' + (d.status === 'active' ? 'badge-success' : 'badge-secondary');
            badge.textContent = d.status === 'active' ? 'Active' : 'Disabled';
            location.reload();
        } else {
            alert(d.error || 'Error');
        }
    });
}

function deleteAdmin(id, name) {
    if (! confirm(`Delete admin user "${name}"? This cannot be undone.`)) return;
    const form = document.getElementById('delete-form');
    form.action = `/manager/admin-users/${id}/delete`;
    form.submit();
}
</script>
<?= $this->endSection() ?>
