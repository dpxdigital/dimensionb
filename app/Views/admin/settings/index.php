<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<div class="row">
    <!-- Categories -->
    <div class="col-lg-7 mb-4">
        <div class="card" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header d-flex justify-content-between align-items-center" style="background:transparent;border-bottom:1px solid #2a2a2a">
                <h6 class="mb-0" style="color:#fff">Categories</h6>
                <button class="btn btn-xs btn-brand" onclick="openCategoryModal(null)">+ Add</button>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616">
                    <thead><tr><th>Name</th><th>Slug</th><th>Icon</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td style="font-size:.82rem;color:#ddd"><?= esc($cat['name']) ?></td>
                            <td style="font-size:.8rem;color:#888"><?= esc($cat['slug']) ?></td>
                            <td style="font-size:.8rem;color:#888"><?= esc($cat['icon_name'] ?? '—') ?></td>
                            <td><span class="badge <?= $cat['is_active'] ? 'badge-success' : 'badge-secondary' ?>"><?= $cat['is_active'] ? 'Active' : 'Hidden' ?></span></td>
                            <td><button class="btn btn-xs btn-outline-secondary"
                                        onclick='openCategoryModal(<?= json_encode($cat) ?>)'>Edit</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- System Tools -->
    <div class="col-lg-5">
        <!-- JWT Rotation -->
        <div class="card mb-3" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a">
                <h6 class="mb-0" style="color:#fff">JWT Secret Rotation</h6>
            </div>
            <div class="card-body">
                <p style="font-size:.82rem;color:#888">Rotating the JWT secret will invalidate all current user sessions. All users will need to log in again.</p>
                <button class="btn btn-sm btn-outline-danger" onclick="rotateJwt()">
                    <i class="fas fa-sync-alt mr-1"></i> Rotate JWT Secret
                </button>
                <div id="jwtResult" class="mt-2" style="font-size:.82rem"></div>
            </div>
        </div>

        <!-- Cron Status -->
        <div class="card mb-3" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a">
                <h6 class="mb-0" style="color:#fff">Cron Jobs</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0" style="--bs-table-bg:transparent;color:#aaa;font-size:.82rem">
                    <tr>
                        <td style="color:#666">Deadline Reminders</td>
                        <td style="color:#2A9D5C"><?= esc($cronStatus['deadline_reminders']) ?></td>
                    </tr>
                    <tr>
                        <td style="color:#666">Follow-up Prompts</td>
                        <td style="color:#2A9D5C"><?= esc($cronStatus['followup_prompts']) ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Env Vars -->
        <div class="card" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header d-flex justify-content-between align-items-center" style="background:transparent;border-bottom:1px solid #2a2a2a">
                <h6 class="mb-0" style="color:#fff">Environment</h6>
                <button class="btn btn-xs btn-outline-secondary" onclick="loadEnvVars()">Refresh</button>
            </div>
            <div class="card-body p-0">
                <table id="envTable" class="table table-sm mb-0" style="--bs-table-bg:transparent;color:#aaa;font-size:.8rem">
                    <tr><td colspan="2" class="text-muted" style="font-size:.78rem">Click Refresh to load</td></tr>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Category Modal -->
<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="background:#161616;border:1px solid #2a2a2a">
            <div class="modal-header" style="border-bottom:1px solid #2a2a2a">
                <h5 class="modal-title" style="color:#fff" id="catModalTitle">Category</h5>
                <button type="button" class="close" data-dismiss="modal" style="color:#aaa">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="catId">
                <div class="form-group mb-3">
                    <label style="color:#aaa;font-size:.82rem">Name</label>
                    <input type="text" id="catName" class="form-control form-control-sm"
                           style="background:#1E1E1E;border-color:#333;color:#fff">
                </div>
                <div class="form-group mb-3">
                    <label style="color:#aaa;font-size:.82rem">Slug</label>
                    <input type="text" id="catSlug" class="form-control form-control-sm"
                           style="background:#1E1E1E;border-color:#333;color:#fff">
                </div>
                <div class="form-group mb-3">
                    <label style="color:#aaa;font-size:.82rem">Icon Name <small class="text-muted">(FontAwesome class, e.g. fa-star)</small></label>
                    <input type="text" id="catIcon" class="form-control form-control-sm"
                           style="background:#1E1E1E;border-color:#333;color:#fff">
                </div>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="catActive" checked>
                    <label class="form-check-label" style="color:#aaa;font-size:.82rem" for="catActive">Active</label>
                </div>
            </div>
            <div class="modal-footer" style="border-top:1px solid #2a2a2a">
                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-brand" onclick="saveCategory()">Save</button>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
function openCategoryModal(cat) {
    document.getElementById('catId').value   = cat ? cat.id   : '';
    document.getElementById('catName').value = cat ? cat.name : '';
    document.getElementById('catSlug').value = cat ? cat.slug : '';
    document.getElementById('catIcon').value = cat ? (cat.icon_name || '') : '';
    document.getElementById('catActive').checked = cat ? !!parseInt(cat.is_active) : true;
    document.getElementById('catModalTitle').textContent = cat ? 'Edit Category' : 'Add Category';
    $('#categoryModal').modal('show');
}
function saveCategory() {
    const fd = new FormData();
    fd.append('id',        document.getElementById('catId').value);
    fd.append('name',      document.getElementById('catName').value);
    fd.append('slug',      document.getElementById('catSlug').value);
    fd.append('icon_name', document.getElementById('catIcon').value);
    fd.append('is_active', document.getElementById('catActive').checked ? '1' : '0');
    fetch('/manager/settings/category/save', {
        method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd
    }).then(r => r.json()).then(d => {
        if (d.success) { $('#categoryModal').modal('hide'); location.reload(); } else alert(d.error);
    });
}
function rotateJwt() {
    if (!confirm('This will invalidate all active user sessions. Continue?')) return;
    fetch('/manager/settings/jwt/rotate', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json()).then(d => {
            const el = document.getElementById('jwtResult');
            if (d.success) el.innerHTML = `<span class="text-success"><i class="fas fa-check mr-1"></i>Rotated. Preview: ${d.preview}</span>`;
            else el.innerHTML = `<span class="text-danger">${d.error}</span>`;
        });
}
function loadEnvVars() {
    fetch('/manager/settings/env', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json()).then(d => {
            const rows = Object.entries(d).map(([k,v]) =>
                `<tr><td style="color:#666;width:45%">${k}</td><td style="color:#ddd">${v}</td></tr>`
            ).join('');
            document.getElementById('envTable').innerHTML = rows;
        });
}
</script>
<?= $this->endSection() ?>
