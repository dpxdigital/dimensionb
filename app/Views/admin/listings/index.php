<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<!-- RSS Feed Manager -->
<div class="row mb-3">

    <!-- Add Feed Form -->
    <div class="col-lg-4 mb-3 mb-lg-0">
        <div class="card h-100" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a">
                <h6 class="mb-0" style="color:#fff">
                    <i class="fas fa-rss mr-1" style="color:#D94032"></i> RSS Feed Subscriptions
                </h6>
            </div>
            <div class="card-body">
                <form method="post" action="<?= site_url('manager/listings/feeds/store') ?>">
                    <?= csrf_field() ?>
                    <div class="form-group mb-2">
                        <label style="color:#aaa;font-size:.8rem">Feed Name</label>
                        <input type="text" name="name" class="form-control form-control-sm"
                               style="background:#1E1E1E;border-color:#333;color:#fff"
                               placeholder="e.g. City Events Board" required maxlength="200">
                    </div>
                    <div class="form-group mb-2">
                        <label style="color:#aaa;font-size:.8rem">RSS / XML URL</label>
                        <input type="url" name="url" class="form-control form-control-sm"
                               style="background:#1E1E1E;border-color:#333;color:#fff"
                               placeholder="https://example.com/feed.xml" required>
                    </div>
                    <div class="form-group mb-2">
                        <label style="color:#aaa;font-size:.8rem">Category</label>
                        <select name="category_id" class="form-control form-control-sm"
                                style="background:#1E1E1E;border-color:#333;color:#fff" required>
                            <option value="">Select category…</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= esc($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group mb-2">
                        <label style="color:#aaa;font-size:.8rem">Trust Level</label>
                        <select name="trust_level" class="form-control form-control-sm"
                                style="background:#1E1E1E;border-color:#333;color:#fff">
                            <option value="community_submitted">Community Submitted</option>
                            <option value="curator_reviewed">Curator Reviewed</option>
                            <option value="institution_verified">Institution Verified</option>
                        </select>
                    </div>
                    <div class="form-group mb-3">
                        <label style="color:#aaa;font-size:.8rem">Import Status</label>
                        <select name="import_status" class="form-control form-control-sm"
                                style="background:#1E1E1E;border-color:#333;color:#fff">
                            <option value="pending">Pending (review first)</option>
                            <option value="approved">Approved (publish immediately)</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-brand btn-sm w-100">
                        <i class="fas fa-plus mr-1"></i> Add Feed
                    </button>
                </form>
            </div>

            <?php if ($cronToken): ?>
            <div class="card-footer" style="background:transparent;border-top:1px solid #2a2a2a">
                <p style="color:#666;font-size:.72rem;margin-bottom:4px">Auto-fetch cron URL (add to cPanel):</p>
                <code style="font-size:.68rem;color:#aaa;word-break:break-all">
                    <?= esc(base_url('cron/listing-feeds') . '?token=' . $cronToken) ?>
                </code>
            </div>
            <?php else: ?>
            <div class="card-footer" style="background:transparent;border-top:1px solid #2a2a2a">
                <p style="color:#888;font-size:.72rem;margin:0">
                    Set <code>LISTING_FEED_CRON_TOKEN=your_secret</code> in <code>.env</code> to enable auto-fetch via cPanel cron.
                </p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Feed List -->
    <div class="col-lg-8">
        <div class="card h-100" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header d-flex justify-content-between align-items-center"
                 style="background:transparent;border-bottom:1px solid #2a2a2a">
                <h6 class="mb-0" style="color:#fff">
                    <i class="fas fa-list mr-1" style="color:#D94032"></i>
                    Configured Feeds
                    <span class="badge badge-secondary ml-1"><?= count($feeds) ?></span>
                </h6>
                <?php if (! empty($feeds)): ?>
                <form method="post" action="<?= site_url('manager/listings/feeds/fetch-all') ?>"
                      class="d-inline" id="fetch-all-form">
                    <?= csrf_field() ?>
                    <button type="button" class="btn btn-outline-success btn-xs" onclick="confirmFetchAll()">
                        <i class="fas fa-sync-alt mr-1"></i> Fetch All
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($feeds)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-rss fa-2x mb-2" style="opacity:.3"></i><br>
                    No feeds yet. Add one on the left.
                </div>
                <?php else: ?>
                <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616">
                    <thead>
                        <tr>
                            <th>Name / URL</th>
                            <th>Category</th>
                            <th class="text-center">Imported</th>
                            <th>Last Fetch</th>
                            <th class="text-center">On</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($feeds as $f): ?>
                        <tr>
                            <td style="vertical-align:middle">
                                <span style="font-size:.83rem;color:#ddd;display:block"><?= esc($f['name']) ?></span>
                                <span class="d-block text-truncate" style="font-size:.72rem;color:#666;max-width:200px"
                                      title="<?= esc($f['url']) ?>">
                                    <?= esc(parse_url($f['url'], PHP_URL_HOST) ?: $f['url']) ?>
                                </span>
                            </td>
                            <td style="font-size:.78rem;color:#888;vertical-align:middle">
                                <?= esc($f['category_name'] ?? '—') ?>
                            </td>
                            <td class="text-center" style="vertical-align:middle">
                                <span class="badge badge-secondary"><?= number_format($f['item_count']) ?></span>
                            </td>
                            <td style="font-size:.78rem;color:#888;vertical-align:middle">
                                <?= $f['last_fetched_at']
                                    ? date('M j, H:i', strtotime($f['last_fetched_at']))
                                    : '<span class="text-muted" style="font-size:.75rem">Never</span>' ?>
                            </td>
                            <td class="text-center" style="vertical-align:middle">
                                <button class="btn btn-xs <?= $f['is_active'] ? 'btn-success' : 'btn-secondary' ?>"
                                        onclick="toggleFeed(<?= (int) $f['id'] ?>, this)">
                                    <?= $f['is_active'] ? 'On' : 'Off' ?>
                                </button>
                            </td>
                            <td class="text-right" style="vertical-align:middle;white-space:nowrap">
                                <button class="btn btn-xs btn-outline-info"
                                        onclick="fetchFeed(<?= (int) $f['id'] ?>)">Fetch</button>
                                <button class="btn btn-xs btn-outline-danger ml-1"
                                        onclick="deleteFeed(<?= (int) $f['id'] ?>, '<?= esc($f['name'], 'js') ?>')">Delete</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<!-- Listings Filter + Table -->
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

<!-- Bulk action bar (shown when rows are selected) -->
<div id="bulk-bar" class="d-none d-flex align-items-center mb-2 px-2 py-2"
     style="background:#1E1E1E;border:1px solid #333;border-radius:6px;gap:8px">
    <span id="bulk-count" style="color:#aaa;font-size:.83rem">0 selected</span>
    <button class="btn btn-danger btn-sm ml-auto" onclick="bulkDelete()">
        <i class="fas fa-trash mr-1"></i> Delete Selected
    </button>
    <button class="btn btn-sm btn-outline-secondary" onclick="clearSelection()">Cancel</button>
</div>

<div class="card" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-body p-0">
        <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616">
            <thead>
                <tr>
                    <th style="width:36px">
                        <input type="checkbox" id="select-all" onchange="toggleAll(this)"
                               style="cursor:pointer;width:15px;height:15px">
                    </th>
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
                        <input type="checkbox" class="row-cb" value="<?= $l['id'] ?>"
                               onchange="updateBulkBar()"
                               style="cursor:pointer;width:15px;height:15px">
                    </td>
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
                    <td class="text-right" style="white-space:nowrap">
                        <a href="<?= site_url() ?>manager/listings/<?= $l['id'] ?>" class="btn btn-xs btn-outline-secondary">View</a>
                        <a href="<?= site_url() ?>manager/listings/<?= $l['id'] ?>/edit" class="btn btn-xs btn-outline-primary">Edit</a>
                        <button class="btn btn-xs <?= $l['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                onclick="toggleListing(<?= $l['id'] ?>)">
                            <?= $l['is_active'] ? 'Hide' : 'Show' ?>
                        </button>
                        <button class="btn btn-xs btn-danger ml-1"
                                onclick="deleteListing(<?= $l['id'] ?>, '<?= esc($l['title'], 'js') ?>')">
                            Delete
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($listings)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No listings found</td></tr>
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

<!-- Hidden action forms -->
<form id="fetch-form"  method="post" style="display:none"><?= csrf_field() ?></form>
<form id="delete-form" method="post" style="display:none"><?= csrf_field() ?></form>
<form id="bulk-delete-form" method="post" style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="ids" id="bulk-ids">
</form>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
const BASE = '<?= rtrim(site_url(), '/') ?>/manager/listings';

function toggleListing(id) {
    fetch(BASE + '/' + id + '/toggle-status', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json()).then(d => { if (d.success) location.reload(); else alert(d.error || 'Error'); });
}

function toggleFeed(id, btn) {
    fetch(BASE + '/feeds/' + id + '/toggle', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json()).then(d => {
            if (d.success) {
                btn.textContent = d.is_active ? 'On' : 'Off';
                btn.className   = 'btn btn-xs ' + (d.is_active ? 'btn-success' : 'btn-secondary');
            } else { alert(d.error || 'Error'); }
        });
}

function fetchFeed(id) {
    const form = document.getElementById('fetch-form');
    form.action = BASE + '/feeds/' + id + '/fetch';
    form.submit();
}

function deleteFeed(id, name) {
    if (! confirm('Delete feed "' + name + '"? Imported listings are kept.')) return;
    const form = document.getElementById('delete-form');
    form.action = BASE + '/feeds/' + id + '/delete';
    form.submit();
}

function deleteListing(id, title) {
    if (! confirm('Permanently delete "' + title + '"?\n\nThis cannot be undone.')) return;
    const form = document.getElementById('delete-form');
    form.action = BASE + '/' + id + '/delete';
    form.submit();
}

// ── Bulk selection helpers ────────────────────────────────────────────────────

function toggleAll(cb) {
    document.querySelectorAll('.row-cb').forEach(c => c.checked = cb.checked);
    updateBulkBar();
}

function updateBulkBar() {
    const checked = document.querySelectorAll('.row-cb:checked');
    const bar = document.getElementById('bulk-bar');
    document.getElementById('bulk-count').textContent = checked.length + ' selected';
    bar.classList.toggle('d-none', checked.length === 0);
    bar.classList.toggle('d-flex', checked.length > 0);
    // Update select-all indeterminate state
    const all = document.querySelectorAll('.row-cb');
    const selectAll = document.getElementById('select-all');
    selectAll.indeterminate = checked.length > 0 && checked.length < all.length;
    selectAll.checked = all.length > 0 && checked.length === all.length;
}

function clearSelection() {
    document.querySelectorAll('.row-cb').forEach(c => c.checked = false);
    document.getElementById('select-all').checked = false;
    updateBulkBar();
}

function bulkDelete() {
    const ids = Array.from(document.querySelectorAll('.row-cb:checked')).map(c => c.value);
    if (ids.length === 0) return;
    if (! confirm('Permanently delete ' + ids.length + ' listing(s)?\n\nThis cannot be undone.')) return;
    document.getElementById('bulk-ids').value = ids.join(',');
    const form = document.getElementById('bulk-delete-form');
    form.action = BASE + '/bulk-delete';
    form.submit();
}

function confirmFetchAll() {
    if (! confirm('Fetch all active feeds now?')) return;
    document.getElementById('fetch-all-form').submit();
}
</script>
<?= $this->endSection() ?>
