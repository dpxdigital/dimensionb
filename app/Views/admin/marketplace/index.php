<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<!-- Search & filters -->
<div class="card mb-3" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-body py-2">
        <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
            <input type="text" name="q" class="form-control form-control-sm"
                   style="background:#1E1E1E;border-color:#333;color:#fff;max-width:260px"
                   placeholder="Search vendor name, slug, category…" value="<?= esc($q ?? '') ?>">
            <button class="btn btn-sm btn-secondary">Search</button>
            <?php if (!empty($q)): ?>
            <a href="/manager/marketplace" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
            <span class="ml-auto text-muted" style="font-size:.8rem"><?= number_format($total) ?> vendors</span>
        </form>
    </div>
</div>

<div class="card" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-body p-0">
        <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616;--bs-table-striped-bg:#1a1a1a">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Status</th>
                    <th>Products</th>
                    <th>Orders</th>
                    <th>Revenue</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vendors as $v): ?>
                <tr id="vendor-row-<?= $v['id'] ?>">
                    <td style="color:#888;font-size:.8rem">#<?= $v['id'] ?></td>
                    <td>
                        <div style="font-size:.85rem;font-weight:500"><?= esc($v['name']) ?></div>
                        <div style="font-size:.75rem;color:#888"><?= esc($v['slug']) ?></div>
                    </td>
                    <td style="font-size:.82rem;color:#aaa"><?= esc($v['category'] ?? '—') ?></td>
                    <td>
                        <span class="badge vendor-status-badge-<?= $v['id'] ?> <?= $v['is_active'] ? 'badge-success' : 'badge-secondary' ?>">
                            <?= $v['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td style="font-size:.82rem"><?= number_format($v['product_count']) ?></td>
                    <td style="font-size:.82rem"><?= number_format($v['order_count']) ?></td>
                    <td style="font-size:.82rem">$<?= number_format($v['total_revenue'], 2) ?></td>
                    <td class="text-right">
                        <a href="/manager/marketplace/vendors/<?= $v['id'] ?>" class="btn btn-xs btn-outline-secondary">View</a>
                        <button class="btn btn-xs <?= $v['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                id="vendor-toggle-btn-<?= $v['id'] ?>"
                                onclick="toggleVendor(<?= $v['id'] ?>, <?= $v['is_active'] ? 0 : 1 ?>)">
                            <?= $v['is_active'] ? 'Suspend' : 'Activate' ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($vendors)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No vendors found</td></tr>
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
function toggleVendor(id, activate) {
    const label = activate ? 'Activate' : 'Suspend';
    if (!confirm(label + ' this vendor?')) return;
    fetch(`/manager/marketplace/vendors/${id}/toggle`, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(d => {
        if (d.error) { alert(d.error); return; }
        const isActive = d.status === 'active';
        document.querySelector(`.vendor-status-badge-${id}`).className =
            `badge vendor-status-badge-${id} ${isActive ? 'badge-success' : 'badge-secondary'}`;
        document.querySelector(`.vendor-status-badge-${id}`).textContent = isActive ? 'Active' : 'Inactive';
        const btn = document.getElementById(`vendor-toggle-btn-${id}`);
        btn.className = `btn btn-xs ${isActive ? 'btn-outline-warning' : 'btn-outline-success'}`;
        btn.textContent = isActive ? 'Suspend' : 'Activate';
        btn.setAttribute('onclick', `toggleVendor(${id}, ${isActive ? 0 : 1})`);
    })
    .catch(() => alert('Request failed'));
}
</script>
<?= $this->endSection() ?>
