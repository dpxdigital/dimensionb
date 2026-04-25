<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<!-- Vendor Info Card -->
<div class="card mb-4" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-header d-flex justify-content-between align-items-center" style="background:transparent;border-bottom:1px solid #2a2a2a">
        <h3 class="card-title mb-0" style="color:#fff;font-size:.95rem">Vendor Information</h3>
        <div>
            <span class="badge <?= $vendor['is_active'] ? 'badge-success' : 'badge-secondary' ?> mr-2" id="vendor-status-badge">
                <?= $vendor['is_active'] ? 'Active' : 'Inactive' ?>
            </span>
            <button class="btn btn-sm <?= $vendor['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                    id="vendor-toggle-btn"
                    onclick="toggleVendor(<?= $vendor['id'] ?>, <?= $vendor['is_active'] ? 0 : 1 ?>)">
                <?= $vendor['is_active'] ? 'Suspend Vendor' : 'Activate Vendor' ?>
            </button>
        </div>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <table class="table table-sm table-dark mb-0" style="--bs-table-bg:transparent">
                    <tr>
                        <td style="color:#888;width:140px">ID</td>
                        <td>#<?= $vendor['id'] ?></td>
                    </tr>
                    <tr>
                        <td style="color:#888">Name</td>
                        <td><?= esc($vendor['name']) ?></td>
                    </tr>
                    <tr>
                        <td style="color:#888">Slug</td>
                        <td style="color:#aaa"><?= esc($vendor['slug']) ?></td>
                    </tr>
                    <tr>
                        <td style="color:#888">Category</td>
                        <td><?= esc($vendor['category'] ?? '—') ?></td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <div class="row text-center">
                    <div class="col-4">
                        <div style="font-size:1.6rem;font-weight:700;color:#fff"><?= number_format($vendor['product_count']) ?></div>
                        <div style="font-size:.75rem;color:#888">Products</div>
                    </div>
                    <div class="col-4">
                        <div style="font-size:1.6rem;font-weight:700;color:#fff"><?= number_format($vendor['order_count']) ?></div>
                        <div style="font-size:.75rem;color:#888">Orders</div>
                    </div>
                    <div class="col-4">
                        <div style="font-size:1.6rem;font-weight:700;color:#2A9D5C">$<?= number_format($vendor['total_revenue'], 2) ?></div>
                        <div style="font-size:.75rem;color:#888">Revenue</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Products Table -->
<div class="card mb-4" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a">
        <h3 class="card-title mb-0" style="color:#fff;font-size:.9rem">Products (<?= count($products) ?>)</h3>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th>Available</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $p): ?>
                <tr>
                    <td style="font-size:.85rem"><?= esc($p['name']) ?></td>
                    <td style="font-size:.82rem">$<?= number_format($p['price'] ?? 0, 2) ?></td>
                    <td style="font-size:.82rem"><?= (int)($p['stock_quantity'] ?? 0) ?></td>
                    <td>
                        <span class="badge <?= $p['is_available'] ? 'badge-success' : 'badge-secondary' ?>">
                            <?= $p['is_available'] ? 'Yes' : 'No' ?>
                        </span>
                    </td>
                    <td style="font-size:.78rem;color:#888"><?= date('M j Y', strtotime($p['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($products)): ?>
                <tr><td colspan="5" class="text-center text-muted py-3">No products</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Recent Orders Table -->
<div class="card" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a">
        <h3 class="card-title mb-0" style="color:#fff;font-size:.9rem">Recent Orders (<?= count($recentOrders) ?>)</h3>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616">
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Buyer</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $statusClasses = [
                    'pending'   => 'badge-warning',
                    'confirmed' => 'badge-success',
                    'shipped'   => 'badge-info',
                    'delivered' => 'badge-success',
                    'cancelled' => 'badge-danger',
                ];
                foreach ($recentOrders as $o): ?>
                <tr>
                    <td style="font-size:.8rem;color:#888">#<?= $o['id'] ?></td>
                    <td style="font-size:.85rem"><?= esc($o['buyer_name'] ?? '—') ?></td>
                    <td style="font-size:.82rem">$<?= number_format($o['total_amount'] ?? 0, 2) ?></td>
                    <td>
                        <span class="badge <?= $statusClasses[$o['status']] ?? 'badge-secondary' ?>">
                            <?= ucfirst($o['status']) ?>
                        </span>
                    </td>
                    <td style="font-size:.78rem;color:#888"><?= date('M j Y', strtotime($o['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($recentOrders)): ?>
                <tr><td colspan="5" class="text-center text-muted py-3">No orders yet</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
function toggleVendor(id, activate) {
    if (!confirm((activate ? 'Activate' : 'Suspend') + ' this vendor?')) return;
    fetch(`/manager/marketplace/vendors/${id}/toggle`, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(d => {
        if (d.error) { alert(d.error); return; }
        const isActive = d.status === 'active';
        const badge = document.getElementById('vendor-status-badge');
        badge.className = `badge ${isActive ? 'badge-success' : 'badge-secondary'}`;
        badge.textContent = isActive ? 'Active' : 'Inactive';
        const btn = document.getElementById('vendor-toggle-btn');
        btn.className = `btn btn-sm ${isActive ? 'btn-outline-warning' : 'btn-outline-success'}`;
        btn.textContent = isActive ? 'Suspend Vendor' : 'Activate Vendor';
        btn.setAttribute('onclick', `toggleVendor(${id}, ${isActive ? 0 : 1})`);
    })
    .catch(() => alert('Request failed'));
}
</script>
<?= $this->endSection() ?>
