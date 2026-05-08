<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<?php if (session()->getFlashdata('success')): ?>
<div class="alert alert-success py-2 mb-3" style="font-size:.85rem"><?= esc(session()->getFlashdata('success')) ?></div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
<div class="alert alert-danger py-2 mb-3" style="font-size:.85rem"><?= esc(session()->getFlashdata('error')) ?></div>
<?php endif; ?>

<!-- Header -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h5 class="mb-0" style="color:#fff">Chapters <span class="badge badge-secondary ml-2"><?= number_format($total) ?></span></h5>
    <a href="<?= site_url() ?>manager/chapters/create" class="btn btn-sm btn-danger">
        <i class="fas fa-plus mr-1"></i> New Chapter
    </a>
</div>

<!-- Search -->
<div class="card mb-3" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-body py-2">
        <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
            <input type="text" name="q" class="form-control form-control-sm"
                   style="background:#1E1E1E;border-color:#333;color:#fff;max-width:280px"
                   placeholder="Search name, city, category…" value="<?= esc($q ?? '') ?>">
            <button class="btn btn-sm btn-secondary">Search</button>
            <?php if (!empty($q)): ?>
            <a href="<?= site_url() ?>manager/chapters" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-body p-0">
        <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616;--bs-table-striped-bg:#1a1a1a">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Cover</th>
                    <th>Name</th>
                    <th>Location</th>
                    <th>Category</th>
                    <th>Members</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($chapters as $c): ?>
                <tr id="chapter-row-<?= $c['id'] ?>">
                    <td style="color:#888;font-size:.8rem">#<?= $c['id'] ?></td>
                    <td>
                        <?php if (!empty($c['image_url'])): ?>
                        <img src="<?= esc($c['image_url']) ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:6px;border:1px solid #333">
                        <?php else: ?>
                        <div style="width:40px;height:40px;background:#2a2a2a;border-radius:6px;display:flex;align-items:center;justify-content:center">
                            <i class="fas fa-layer-group" style="color:#555;font-size:.8rem"></i>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-size:.85rem;font-weight:500;color:#fff"><?= esc($c['name']) ?></div>
                        <div style="font-size:.72rem;color:#666"><?= esc($c['slug']) ?></div>
                    </td>
                    <td style="font-size:.82rem;color:#aaa">
                        <?= esc(array_filter([$c['city'] ?? null, $c['state'] ?? null]) ? implode(', ', array_filter([$c['city'] ?? '', $c['state'] ?? ''])) : '—') ?>
                    </td>
                    <td style="font-size:.82rem;color:#aaa"><?= esc($c['category'] ?? '—') ?></td>
                    <td style="font-size:.82rem"><?= number_format($c['member_count']) ?></td>
                    <td>
                        <span class="badge chapter-status-badge-<?= $c['id'] ?> <?= $c['is_active'] ? 'badge-success' : 'badge-secondary' ?>">
                            <?= $c['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td style="font-size:.78rem;color:#888"><?= date('M j Y', strtotime($c['created_at'])) ?></td>
                    <td style="white-space:nowrap">
                        <a href="<?= site_url() ?>manager/chapters/<?= $c['id'] ?>" class="btn btn-xs btn-outline-secondary">View</a>
                        <button class="btn btn-xs <?= $c['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                id="chapter-toggle-<?= $c['id'] ?>"
                                onclick="toggleChapter(<?= $c['id'] ?>, <?= $c['is_active'] ? 0 : 1 ?>)">
                            <?= $c['is_active'] ? 'Disable' : 'Enable' ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($chapters)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No chapters found</td></tr>
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
const BASE = '<?= rtrim(site_url(), '/') ?>';
function toggleChapter(id, activate) {
    if (!confirm((activate ? 'Enable' : 'Disable') + ' this chapter?')) return;
    fetch(BASE + `/manager/chapters/${id}/toggle`, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(d => {
        if (d.error) { alert(d.error); return; }
        const isActive = d.status === 'active';
        const badge = document.querySelector(`.chapter-status-badge-${id}`);
        badge.className = `badge chapter-status-badge-${id} ${isActive ? 'badge-success' : 'badge-secondary'}`;
        badge.textContent = isActive ? 'Active' : 'Inactive';
        const btn = document.getElementById(`chapter-toggle-${id}`);
        btn.className = `btn btn-xs ${isActive ? 'btn-outline-warning' : 'btn-outline-success'}`;
        btn.textContent = isActive ? 'Disable' : 'Enable';
        btn.setAttribute('onclick', `toggleChapter(${id}, ${isActive ? 0 : 1})`);
    })
    .catch(() => alert('Request failed'));
}
</script>
<?= $this->endSection() ?>
