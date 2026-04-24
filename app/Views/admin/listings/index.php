<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<div class="card mb-3" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-body py-2">
        <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
            <input type="text" name="q" class="form-control form-control-sm"
                   style="background:#1E1E1E;border-color:#333;color:#fff;max-width:220px"
                   placeholder="Search listings…" value="<?= esc($q ?? '') ?>">
            <select name="category" class="form-control form-control-sm" style="background:#1E1E1E;border-color:#333;color:#fff;max-width:150px">
                <option value="">All categories</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= esc($cat['slug']) ?>" <?= ($filters['category'] ?? '') === $cat['slug'] ? 'selected' : '' ?>>
                    <?= esc($cat['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select name="trust" class="form-control form-control-sm" style="background:#1E1E1E;border-color:#333;color:#fff;max-width:170px">
                <option value="">All trust levels</option>
                <?php foreach (['institution_verified','curator_reviewed','community_submitted','approved_live_host','needs_reconfirmation'] as $tl): ?>
                <option value="<?= $tl ?>" <?= ($filters['trust'] ?? '') === $tl ? 'selected' : '' ?>><?= esc(str_replace('_',' ', $tl)) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="form-control form-control-sm" style="background:#1E1E1E;border-color:#333;color:#fff;max-width:120px">
                <option value="">All status</option>
                <option value="1" <?= isset($filters['status']) && $filters['status'] === '1' ? 'selected' : '' ?>>Active</option>
                <option value="0" <?= isset($filters['status']) && $filters['status'] === '0' ? 'selected' : '' ?>>Hidden</option>
            </select>
            <button class="btn btn-sm btn-secondary">Filter</button>
            <a href="/manager/listings" class="btn btn-sm btn-outline-secondary">Clear</a>
            <span class="ml-auto text-muted" style="font-size:.8rem"><?= number_format($total) ?> listings</span>
        </form>
    </div>
</div>

<div class="card" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-body p-0">
        <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Category</th>
                    <th>Trust</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($listings as $l): ?>
                <tr>
                    <td>
                        <a href="/manager/listings/<?= $l['id'] ?>" style="color:#7F77DD;font-size:.85rem">
                            <?= esc(mb_strimwidth($l['title'], 0, 55, '…')) ?>
                        </a>
                        <?php if ($l['is_featured']): ?>
                        <span class="badge badge-warning badge-sm ml-1">Featured</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.8rem;color:#888"><?= esc($l['category_name'] ?? '—') ?></td>
                    <td>
                        <?php
                        $tmap = ['institution_verified'=>['IV','badge-trust-iv'],'curator_reviewed'=>['CR','badge-trust-cr'],
                                 'community_submitted'=>['CS','badge-trust-cs'],'approved_live_host'=>['ALH','badge-trust-alh'],
                                 'needs_reconfirmation'=>['NR','badge-trust-nr']];
                        $t = $tmap[$l['trust_level'] ?? ''] ?? null;
                        if ($t): ?><span class="badge <?= $t[1] ?>"><?= $t[0] ?></span><?php endif; ?>
                    </td>
                    <td style="font-size:.8rem;color:#888"><?= $l['date'] ? date('M j Y', strtotime($l['date'])) : '—' ?></td>
                    <td><span class="badge <?= $l['is_active'] ? 'badge-success' : 'badge-secondary' ?>"><?= $l['is_active'] ? 'Active' : 'Hidden' ?></span></td>
                    <td class="text-right">
                        <a href="/manager/listings/<?= $l['id'] ?>" class="btn btn-xs btn-outline-secondary">View</a>
                        <button class="btn btn-xs <?= $l['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                onclick="toggleListing(<?= $l['id'] ?>, <?= $l['is_active'] ? 0 : 1 ?>)">
                            <?= $l['is_active'] ? 'Hide' : 'Show' ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($listings)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No listings found</td></tr>
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
function toggleListing(id, active) {
    fetch(`/manager/listings/${id}/toggle-status`, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json()).then(d => { if (d.success) location.reload(); else alert(d.error); });
}
</script>
<?= $this->endSection() ?>
