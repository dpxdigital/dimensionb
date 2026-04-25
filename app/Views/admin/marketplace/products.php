<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<!-- Search -->
<div class="card mb-3" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-body py-2">
        <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
            <input type="text" name="q" class="form-control form-control-sm"
                   style="background:#1E1E1E;border-color:#333;color:#fff;max-width:260px"
                   placeholder="Search product or vendor name…" value="<?= esc($q ?? '') ?>">
            <button class="btn btn-sm btn-secondary">Search</button>
            <?php if (!empty($q)): ?>
            <a href="/manager/marketplace/products" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
            <span class="ml-auto text-muted" style="font-size:.8rem"><?= number_format($total) ?> products</span>
        </form>
    </div>
</div>

<div class="card" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-body p-0">
        <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616;--bs-table-striped-bg:#1a1a1a">
            <thead>
                <tr>
                    <th>Image</th>
                    <th>Name</th>
                    <th>Vendor</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th>Available</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $p): ?>
                <tr>
                    <td style="width:50px">
                        <?php if (!empty($p['image_url'])): ?>
                        <img src="<?= esc($p['image_url']) ?>" width="40" height="40"
                             style="object-fit:cover;border-radius:4px">
                        <?php else: ?>
                        <div style="width:40px;height:40px;background:#2a2a2a;border-radius:4px;display:flex;align-items:center;justify-content:center">
                            <i class="fas fa-image" style="color:#555;font-size:.75rem"></i>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.85rem"><?= esc($p['name']) ?></td>
                    <td style="font-size:.82rem;color:#aaa"><?= esc($p['vendor_name'] ?? '—') ?></td>
                    <td style="font-size:.82rem">$<?= number_format($p['price'] ?? 0, 2) ?></td>
                    <td style="font-size:.82rem"><?= (int)($p['stock_quantity'] ?? 0) ?></td>
                    <td>
                        <button class="btn btn-xs <?= $p['is_available'] ? 'btn-success' : 'btn-secondary' ?>"
                                id="prod-toggle-btn-<?= $p['id'] ?>"
                                onclick="toggleProduct(<?= $p['id'] ?>, <?= $p['is_available'] ? 0 : 1 ?>)"
                                style="min-width:60px">
                            <?= $p['is_available'] ? 'Yes' : 'No' ?>
                        </button>
                    </td>
                    <td style="font-size:.78rem;color:#888"><?= date('M j Y', strtotime($p['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($products)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No products found</td></tr>
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
function toggleProduct(id, makeAvailable) {
    fetch(`/manager/marketplace/products/${id}/toggle`, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(d => {
        if (d.error) { alert(d.error); return; }
        const isAvail = d.status === 'available';
        const btn = document.getElementById(`prod-toggle-btn-${id}`);
        btn.className = `btn btn-xs ${isAvail ? 'btn-success' : 'btn-secondary'}`;
        btn.textContent = isAvail ? 'Yes' : 'No';
        btn.setAttribute('onclick', `toggleProduct(${id}, ${isAvail ? 0 : 1})`);
    })
    .catch(() => alert('Request failed'));
}
</script>
<?= $this->endSection() ?>
