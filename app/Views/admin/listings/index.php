<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<div class="card mb-3" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-body py-2">
        <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
            <input type="text" name="q" class="form-control form-control-sm"
                   style="background:#1E1E1E;border-color:#333;color:#fff;max-width:220px"
                   placeholder="Search listings…" value="<?= esc($search ?? '') ?>">
            <select name="category" class="form-control form-control-sm" style="background:#1E1E1E;border-color:#333;color:#fff;max-width:150px">
                <option value="">All categories</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= esc($cat['slug']) ?>" <?= ($category ?? '') === $cat['slug'] ? 'selected' : '' ?>>
                    <?= esc($cat['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select name="trust" class="form-control form-control-sm" style="background:#1E1E1E;border-color:#333;color:#fff;max-width:170px">
                <option value="">All trust levels</option>
                <?php foreach (['institution_verified','curator_reviewed','community_submitted','approved_live_host','needs_reconfirmation'] as $tl): ?>
                <option value="<?= $tl ?>" <?= ($trustLevel ?? '') === $tl ? 'selected' : '' ?>><?= esc(str_replace('_',' ', $tl)) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="form-control form-control-sm" style="background:#1E1E1E;border-color:#333;color:#fff;max-width:120px">
                <option value="">All status</option>
                <option value="approved" <?= ($status ?? '') === 'approved' ? 'selected' : '' ?>>Approved</option>
                <option value="pending"  <?= ($status ?? '') === 'pending'  ? 'selected' : '' ?>>Pending</option>
                <option value="rejected" <?= ($status ?? '') === 'rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>
            <button class="btn btn-sm btn-secondary">Filter</button>
            <a href="<?= site_url() ?>manager/listings" class="btn btn-sm btn-outline-secondary">Clear</a>
            <a href="<?= site_url() ?>manager/listings/create" class="btn btn-sm btn-brand ml-auto">
                <i class="fas fa-plus mr-1"></i> New Listing
            </a>
            <span class="text-muted" style="font-size:.8rem"><?= number_format($total) ?> listings</span>
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
                        <a href="<?= site_url() ?>manager/listings/<?= $l['id'] ?>" style="color:#7F77DD;font-size:.85rem">
                            <?= esc(mb_strimwidth($l['title'], 0, 55, '…')) ?>
                        </a>
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
                    <td>
                        <?php
                        $smap = ['approved'=>['Approved','badge-success'],'pending'=>['Pending','badge-warning'],'rejected'=>['Rejected','badge-danger']];
                        $sb = $smap[$l['status'] ?? ''] ?? ['Unknown','badge-secondary'];
                        ?>
                        <span class="badge <?= $sb[1] ?>"><?= $sb[0] ?></span>
                    </td>
                    <td class="text-right">
                        <a href="<?= site_url() ?>manager/listings/<?= $l['id'] ?>" class="btn btn-xs btn-outline-secondary">View</a>
                        <a href="<?= site_url() ?>manager/listings/<?= $l['id'] ?>/edit" class="btn btn-xs btn-outline-primary">Edit</a>
                        <button class="btn btn-xs <?= $l['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                onclick="toggleListing(<?= $l['id'] ?>)">
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
        <?= view('admin/_pagination', ['page' => $page, 'lastPage' => $lastPage, 'q' => $search ?? '']) ?>
    </div>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
const BASE = '<?= rtrim(site_url(), '/') ?>';
function toggleListing(id) {
    fetch(BASE + `/manager/listings/${id}/toggle-status`, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json()).then(d => { if (d.success) location.reload(); else alert(d.error || 'Error'); });
}
</script>
<?= $this->endSection() ?>
