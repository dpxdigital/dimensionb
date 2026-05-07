<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<!-- Sub-nav -->
<div class="mb-3 d-flex gap-2 flex-wrap align-items-center">
    <span class="btn btn-sm btn-secondary disabled">Vendors</span>
    <a href="<?= site_url() ?>manager/marketplace/products" class="btn btn-sm btn-outline-secondary">Products</a>
    <a href="<?= site_url() ?>manager/marketplace/orders" class="btn btn-sm btn-outline-secondary">Orders</a>
    <a href="<?= site_url() ?>manager/marketplace/payment-settings" class="btn btn-sm btn-outline-danger ms-auto">⚙ Payment Settings</a>
</div>

<!-- Revenue summary -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card text-center py-2" style="background:#161616;border:1px solid #2a2a2a">
            <div style="font-size:1.4rem;font-weight:700;color:#2A9D5C">$<?= number_format($revenue['total'], 2) ?></div>
            <div style="font-size:.72rem;color:#888">Activation Revenue</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-2" style="background:#161616;border:1px solid #2a2a2a">
            <div style="font-size:1.4rem;font-weight:700;color:#fff"><?= $revenue['paid'] ?></div>
            <div style="font-size:.72rem;color:#888">Fee Paid</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-2" style="background:#161616;border:1px solid #2a2a2a">
            <div style="font-size:1.4rem;font-weight:700;color:#2A9D5C"><?= $revenue['approved'] ?></div>
            <div style="font-size:.72rem;color:#888">Approved</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-2" style="background:#161616;border:1px solid #EF9F27">
            <div style="font-size:1.4rem;font-weight:700;color:#EF9F27"><?= $revenue['pending'] ?></div>
            <div style="font-size:.72rem;color:#888">Pending Approval</div>
        </div>
    </div>
</div>

<!-- Approval filter tabs -->
<div class="mb-2 d-flex gap-1 flex-wrap">
    <?php
    $filters = ['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'];
    $current = $approval ?? 'all';
    foreach ($filters as $val => $label):
        $active = ($current === $val || ($val === 'all' && empty($current)));
        $href   = $val === 'all' ? '/manager/marketplace' : '/manager/marketplace?approval=' . $val;
    ?>
    <a href="<?= $href ?>" class="btn btn-xs <?= $active ? 'btn-danger' : 'btn-outline-secondary' ?>"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<!-- Search & filters -->
<div class="card mb-3" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-body py-2">
        <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
            <?php if (!empty($approval)): ?>
            <input type="hidden" name="approval" value="<?= esc($approval) ?>">
            <?php endif; ?>
            <input type="text" name="q" class="form-control form-control-sm"
                   style="background:#1E1E1E;border-color:#333;color:#fff;max-width:260px"
                   placeholder="Search vendor name, slug, category…" value="<?= esc($q ?? '') ?>">
            <button class="btn btn-sm btn-secondary">Search</button>
            <?php if (!empty($q)): ?>
            <a href="<?= site_url() ?>manager/marketplace<?= !empty($approval) ? '?approval=' . esc($approval) : '' ?>" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
            <span class="ms-auto text-muted" style="font-size:.8rem"><?= number_format($total) ?> vendors</span>
        </form>
    </div>
</div>

<div class="card" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-body p-0">
        <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616;--bs-table-striped-bg:#1a1a1a">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Vendor</th>
                    <th>Owner</th>
                    <th>Category</th>
                    <th>Fee</th>
                    <th>Approval</th>
                    <th>Active</th>
                    <th>Products</th>
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
                        <div style="font-size:.73rem;color:#888"><?= esc($v['slug']) ?></div>
                    </td>
                    <td style="font-size:.8rem;color:#aaa">
                        <?= esc($v['owner_name'] ?? '—') ?><br>
                        <span style="color:#666;font-size:.72rem"><?= esc($v['owner_email'] ?? '') ?></span>
                    </td>
                    <td style="font-size:.82rem;color:#aaa"><?= esc($v['category'] ?? '—') ?></td>
                    <td style="font-size:.8rem">
                        <?php if ($v['activation_fee_paid']): ?>
                        <span class="badge badge-success">Paid</span>
                        <div style="font-size:.7rem;color:#888">$<?= number_format($v['activation_fee_amount'] ?? 0, 2) ?></div>
                        <?php else: ?>
                        <span class="badge badge-secondary">Unpaid</span>
                        <?php endif; ?>
                    </td>
                    <td id="approval-cell-<?= $v['id'] ?>">
                        <?php if ($v['is_approved']): ?>
                        <span class="badge badge-success">Approved</span>
                        <?php elseif (!empty($v['rejection_reason'])): ?>
                        <span class="badge badge-danger" title="<?= esc($v['rejection_reason']) ?>">Rejected</span>
                        <?php elseif ($v['activation_fee_paid']): ?>
                        <span class="badge badge-warning">Pending</span>
                        <?php else: ?>
                        <span class="badge badge-secondary">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge vendor-status-badge-<?= $v['id'] ?> <?= $v['is_active'] ? 'badge-success' : 'badge-secondary' ?>">
                            <?= $v['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td style="font-size:.82rem"><?= number_format($v['product_count']) ?></td>
                    <td style="font-size:.82rem">$<?= number_format($v['total_revenue'], 2) ?></td>
                    <td class="text-right" style="white-space:nowrap">
                        <a href="<?= site_url() ?>manager/marketplace/vendors/<?= $v['id'] ?>" class="btn btn-xs btn-outline-secondary">View</a>
                        <?php if (!$v['is_approved']): ?>
                        <button class="btn btn-xs btn-outline-success" onclick="approveVendor(<?= $v['id'] ?>)">Approve</button>
                        <button class="btn btn-xs btn-outline-danger" onclick="openRejectModal(<?= $v['id'] ?>)">Reject</button>
                        <?php endif; ?>
                        <button class="btn btn-xs <?= $v['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                id="vendor-toggle-btn-<?= $v['id'] ?>"
                                onclick="toggleVendor(<?= $v['id'] ?>, <?= $v['is_active'] ? 0 : 1 ?>)">
                            <?= $v['is_active'] ? 'Suspend' : 'Activate' ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($vendors)): ?>
                <tr><td colspan="10" class="text-center text-muted py-4">No vendors found</td></tr>
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

<!-- Reject Modal -->
<div id="rejectModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9999;align-items:center;justify-content:center">
    <div style="background:#161616;border:1px solid #2a2a2a;border-radius:8px;padding:24px;max-width:420px;width:90%">
        <h5 style="color:#fff;margin-bottom:12px">Reject Vendor</h5>
        <input type="hidden" id="rejectVendorId">
        <div class="mb-3">
            <label style="color:#ccc;font-size:.82rem" class="form-label">Reason <span style="color:#D94032">*</span></label>
            <textarea id="rejectReason" class="form-control form-control-sm" rows="3"
                      placeholder="Provide a clear rejection reason visible to the vendor…"
                      style="background:#1E1E1E;border-color:#333;color:#fff"></textarea>
        </div>
        <div class="d-flex gap-2 justify-content-end">
            <button class="btn btn-sm btn-outline-secondary" onclick="closeRejectModal()">Cancel</button>
            <button class="btn btn-sm btn-danger" onclick="submitReject()">Reject Vendor</button>
        </div>
    </div>
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

function approveVendor(id) {
    if (!confirm('Approve this vendor?')) return;
    fetch(`/manager/marketplace/vendors/${id}/approve`, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(d => {
        if (d.error) { alert(d.error); return; }
        const cell = document.getElementById(`approval-cell-${id}`);
        if (cell) cell.innerHTML = '<span class="badge badge-success">Approved</span>';
        // remove approve/reject buttons
        const row = document.getElementById(`vendor-row-${id}`);
        if (row) row.querySelectorAll('.btn-outline-success,.btn-outline-danger').forEach(b => {
            if (b.textContent.trim() === 'Approve' || b.textContent.trim() === 'Reject') b.remove();
        });
    })
    .catch(() => alert('Request failed'));
}

function openRejectModal(id) {
    document.getElementById('rejectVendorId').value = id;
    document.getElementById('rejectReason').value = '';
    document.getElementById('rejectModal').style.display = 'flex';
}
function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
}
function submitReject() {
    const id     = document.getElementById('rejectVendorId').value;
    const reason = document.getElementById('rejectReason').value.trim();
    if (!reason) { alert('Reason is required.'); return; }
    const body = new FormData();
    body.append('reason', reason);
    fetch(`/manager/marketplace/vendors/${id}/reject`, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body
    })
    .then(r => r.json())
    .then(d => {
        if (d.error) { alert(d.error); return; }
        closeRejectModal();
        const cell = document.getElementById(`approval-cell-${id}`);
        if (cell) cell.innerHTML = '<span class="badge badge-danger">Rejected</span>';
    })
    .catch(() => alert('Request failed'));
}
</script>
<?= $this->endSection() ?>
