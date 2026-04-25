<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<!-- Status filter tabs -->
<ul class="nav nav-tabs mb-3" style="border-color:#2a2a2a">
    <?php
    $tabs = [''=>'All', 'pending'=>'Pending', 'confirmed'=>'Confirmed', 'shipped'=>'Shipped', 'delivered'=>'Delivered', 'cancelled'=>'Cancelled'];
    foreach ($tabs as $val => $label): ?>
    <li class="nav-item">
        <a class="nav-link <?= ($status ?? '') === $val ? 'active' : '' ?>"
           href="/manager/marketplace/orders<?= $val ? '?status='.$val : '' ?>"
           style="<?= ($status ?? '') === $val ? 'background:#D94032;border-color:#D94032;color:#fff' : 'color:#888;border-color:transparent' ?>">
            <?= $label ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<div class="card" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-header d-flex align-items-center justify-content-between" style="background:transparent;border-color:#2a2a2a">
        <span style="color:#fff;font-size:.9rem">Orders <span class="badge badge-secondary ml-1"><?= number_format($total) ?></span></span>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616;--bs-table-striped-bg:#1a1a1a">
            <thead>
                <tr>
                    <th style="width:60px">#ID</th>
                    <th>Buyer</th>
                    <th>Vendor</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th style="width:160px">Update Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o): ?>
                <tr>
                    <td style="color:#666;font-size:.8rem">#<?= $o['id'] ?></td>
                    <td style="font-size:.85rem"><?= esc($o['buyer_name'] ?? '—') ?></td>
                    <td style="font-size:.85rem;color:#aaa"><?= esc($o['vendor_name'] ?? '—') ?></td>
                    <td style="font-size:.85rem;color:#2A9D5C;font-weight:600">$<?= number_format((float)($o['total_amount'] ?? 0), 2) ?></td>
                    <td>
                        <?php
                        $badgeMap = [
                            'pending'   => 'badge-warning',
                            'confirmed' => 'badge-info',
                            'shipped'   => 'badge-primary',
                            'delivered' => 'badge-success',
                            'cancelled' => 'badge-danger',
                        ];
                        $badge = $badgeMap[$o['status']] ?? 'badge-secondary';
                        ?>
                        <span class="badge <?= $badge ?>" id="status-badge-<?= $o['id'] ?>"><?= ucfirst($o['status'] ?? 'pending') ?></span>
                    </td>
                    <td style="font-size:.78rem;color:#666"><?= date('M j Y', strtotime($o['created_at'])) ?></td>
                    <td>
                        <select class="form-control form-control-sm"
                                style="background:#1E1E1E;border-color:#333;color:#fff;font-size:.78rem"
                                onchange="updateOrderStatus(<?= $o['id'] ?>, this.value, this)">
                            <?php foreach (['pending','confirmed','shipped','delivered','cancelled'] as $s): ?>
                            <option value="<?= $s ?>" <?= ($o['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($orders)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No orders found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($lastPage > 1): ?>
    <div class="card-footer" style="background:transparent;border-top:1px solid #2a2a2a">
        <?= view('admin/_pagination', ['page' => $page, 'lastPage' => $lastPage, 'q' => $status ? 'status='.$status : '']) ?>
    </div>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
const badgeColors = {
    pending:'badge-warning', confirmed:'badge-info', shipped:'badge-primary',
    delivered:'badge-success', cancelled:'badge-danger'
};

function updateOrderStatus(id, newStatus, selectEl) {
    selectEl.disabled = true;
    fetch(`/manager/marketplace/orders/${id}/status`, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded', 'X-Requested-With':'XMLHttpRequest'},
        body: `status=${newStatus}`
    })
    .then(r => r.json())
    .then(d => {
        if (d.status) {
            const badge = document.getElementById(`status-badge-${id}`);
            badge.className = 'badge ' + (badgeColors[d.status] || 'badge-secondary');
            badge.textContent = d.status.charAt(0).toUpperCase() + d.status.slice(1);
        } else {
            alert(d.error || 'Failed to update status');
        }
    })
    .catch(() => alert('Network error'))
    .finally(() => { selectEl.disabled = false; });
}
</script>
<?= $this->endSection() ?>
