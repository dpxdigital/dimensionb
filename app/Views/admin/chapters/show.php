<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<?php if (session()->getFlashdata('success')): ?>
<div class="alert alert-success py-2 mb-3" style="font-size:.85rem"><?= esc(session()->getFlashdata('success')) ?></div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
<div class="alert alert-danger py-2 mb-3" style="font-size:.85rem"><?= esc(session()->getFlashdata('error')) ?></div>
<?php endif; ?>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <a href="<?= site_url() ?>manager/chapters" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-arrow-left mr-1"></i> Chapters
    </a>
    <span class="badge <?= $chapter['is_active'] ? 'badge-success' : 'badge-secondary' ?> ml-1" id="chapter-status-badge">
        <?= $chapter['is_active'] ? 'Active' : 'Inactive' ?>
    </span>
    <div class="ml-auto d-flex gap-2 flex-wrap">
        <button class="btn btn-sm <?= $chapter['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                id="chapter-toggle-btn"
                onclick="toggleChapter(<?= $chapter['id'] ?>, <?= $chapter['is_active'] ? 0 : 1 ?>)">
            <?= $chapter['is_active'] ? 'Disable Chapter' : 'Enable Chapter' ?>
        </button>
        <button class="btn btn-sm btn-outline-danger" onclick="deleteChapter(<?= $chapter['id'] ?>)">Delete</button>
    </div>
</div>

<div class="row">
    <!-- Left: Cover + Edit Info -->
    <div class="col-lg-4 mb-3">

        <!-- Cover image card -->
        <div class="card mb-3" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a;font-size:.85rem;color:#fff">
                Cover Image
            </div>
            <div class="card-body text-center">
                <div id="coverWrap" style="margin-bottom:12px">
                    <?php if (!empty($chapter['image_url'])): ?>
                    <img id="coverImg" src="<?= esc($chapter['image_url']) ?>" alt=""
                         style="width:100%;max-height:200px;object-fit:cover;border-radius:8px;border:1px solid #333">
                    <?php else: ?>
                    <div id="coverImg" style="width:100%;height:150px;background:#2a2a2a;border-radius:8px;display:flex;align-items:center;justify-content:center">
                        <i class="fas fa-layer-group" style="color:#555;font-size:2rem"></i>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Upload form -->
                <form id="coverForm" enctype="multipart/form-data">
                    <div class="mb-2">
                        <input type="file" name="image" id="coverInput" accept="image/jpeg,image/png,image/webp"
                               class="form-control form-control-sm"
                               style="background:#1E1E1E;border-color:#333;color:#fff;font-size:.78rem"
                               onchange="previewCover(this)">
                    </div>
                    <button type="button" class="btn btn-sm btn-brand w-100" onclick="uploadCover(<?= $chapter['id'] ?>)">
                        <i class="fas fa-upload mr-1"></i> Upload Cover
                    </button>
                    <small style="color:#666;font-size:.72rem">JPG, PNG or WebP · max 5 MB</small>
                </form>
            </div>
        </div>

        <!-- Stats -->
        <div class="card" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-body p-0">
                <table class="table table-sm mb-0" style="--bs-table-bg:transparent;color:#aaa;font-size:.83rem">
                    <tr><td style="color:#666">ID</td><td>#<?= $chapter['id'] ?></td></tr>
                    <tr><td style="color:#666">Slug</td><td><?= esc($chapter['slug']) ?></td></tr>
                    <tr><td style="color:#666">Members</td><td><?= number_format($chapter['member_count']) ?></td></tr>
                    <tr><td style="color:#666">Posts</td><td><?= number_format($chapter['post_count']) ?></td></tr>
                    <tr><td style="color:#666">Created by</td><td><?= esc($chapter['creator_name'] ?? '—') ?></td></tr>
                    <tr><td style="color:#666">Created</td><td><?= date('M j Y', strtotime($chapter['created_at'])) ?></td></tr>
                </table>
            </div>
        </div>

    </div>

    <!-- Right: Edit form + Members -->
    <div class="col-lg-8">

        <!-- Edit chapter info -->
        <div class="card mb-3" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a;font-size:.85rem;color:#fff">
                Edit Chapter Info
            </div>
            <div class="card-body">
                <form method="post" action="<?= site_url() ?>manager/chapters/<?= $chapter['id'] ?>/update">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label" style="color:#ccc;font-size:.82rem">Name <span style="color:#D94032">*</span></label>
                        <input type="text" name="name" class="form-control form-control-sm"
                               style="background:#1E1E1E;border-color:#333;color:#fff"
                               value="<?= esc($chapter['name']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" style="color:#ccc;font-size:.82rem">Description</label>
                        <textarea name="description" rows="3" class="form-control form-control-sm"
                                  style="background:#1E1E1E;border-color:#333;color:#fff"><?= esc($chapter['description'] ?? '') ?></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label" style="color:#ccc;font-size:.82rem">City</label>
                            <input type="text" name="city" class="form-control form-control-sm"
                                   style="background:#1E1E1E;border-color:#333;color:#fff"
                                   value="<?= esc($chapter['city'] ?? '') ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label" style="color:#ccc;font-size:.82rem">State</label>
                            <input type="text" name="state" class="form-control form-control-sm"
                                   style="background:#1E1E1E;border-color:#333;color:#fff"
                                   value="<?= esc($chapter['state'] ?? '') ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label" style="color:#ccc;font-size:.82rem">Country</label>
                            <input type="text" name="country" class="form-control form-control-sm"
                                   style="background:#1E1E1E;border-color:#333;color:#fff"
                                   value="<?= esc($chapter['country'] ?? 'US') ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" style="color:#ccc;font-size:.82rem">Category</label>
                        <input type="text" name="category" class="form-control form-control-sm"
                               style="background:#1E1E1E;border-color:#333;color:#fff"
                               value="<?= esc($chapter['category'] ?? '') ?>">
                    </div>

                    <button type="submit" class="btn btn-sm btn-danger">Save Changes</button>
                </form>
            </div>
        </div>

        <!-- Members table -->
        <div class="card" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a;font-size:.85rem;color:#fff">
                Members (<?= count($members) ?>)
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Joined</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $m): ?>
                        <tr>
                            <td style="font-size:.83rem">
                                <?php if (!empty($m['avatar_url'])): ?>
                                <img src="<?= esc($m['avatar_url']) ?>" style="width:24px;height:24px;border-radius:50%;object-fit:cover;margin-right:6px">
                                <?php endif; ?>
                                <?= esc($m['name']) ?>
                            </td>
                            <td style="font-size:.78rem;color:#888"><?= esc($m['email']) ?></td>
                            <td>
                                <span class="badge <?= $m['role'] === 'admin' ? 'badge-danger' : ($m['role'] === 'moderator' ? 'badge-warning' : 'badge-secondary') ?>">
                                    <?= ucfirst($m['role']) ?>
                                </span>
                            </td>
                            <td style="font-size:.78rem;color:#888"><?= $m['joined_at'] ? date('M j Y', strtotime($m['joined_at'])) : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($members)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">No members yet</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
const BASE = '<?= rtrim(site_url(), '/') ?>';

function previewCover(input) {
    if (input.files && input.files[0]) {
        const wrap = document.getElementById('coverWrap');
        wrap.innerHTML = `<img id="coverImg" src="${URL.createObjectURL(input.files[0])}"
            style="width:100%;max-height:200px;object-fit:cover;border-radius:8px;border:1px solid #333">`;
    }
}

function uploadCover(id) {
    const input = document.getElementById('coverInput');
    if (!input.files || !input.files[0]) { alert('Choose an image first.'); return; }
    const fd = new FormData();
    fd.append('image', input.files[0]);
    fetch(BASE + `/manager/chapters/${id}/cover`, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        if (d.error) { alert(d.error); return; }
        const wrap = document.getElementById('coverWrap');
        wrap.innerHTML = `<img id="coverImg" src="${d.image_url}"
            style="width:100%;max-height:200px;object-fit:cover;border-radius:8px;border:1px solid #333">`;
        input.value = '';
        alert('Cover updated successfully.');
    })
    .catch(() => alert('Upload failed'));
}

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
        const badge = document.getElementById('chapter-status-badge');
        badge.className = `badge ${isActive ? 'badge-success' : 'badge-secondary'}`;
        badge.textContent = isActive ? 'Active' : 'Inactive';
        const btn = document.getElementById('chapter-toggle-btn');
        btn.className = `btn btn-sm ${isActive ? 'btn-outline-warning' : 'btn-outline-success'}`;
        btn.textContent = isActive ? 'Disable Chapter' : 'Enable Chapter';
        btn.setAttribute('onclick', `toggleChapter(${id}, ${isActive ? 0 : 1})`);
    })
    .catch(() => alert('Request failed'));
}

function deleteChapter(id) {
    if (!confirm('Delete this chapter? It will be deactivated.')) return;
    fetch(BASE + `/manager/chapters/${id}/delete`, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(d => {
        if (d.error) { alert(d.error); return; }
        window.location.href = BASE + '/manager/chapters';
    })
    .catch(() => alert('Request failed'));
}
</script>
<?= $this->endSection() ?>
