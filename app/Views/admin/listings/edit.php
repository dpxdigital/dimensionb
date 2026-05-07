<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger py-2 mb-3">
    <ul class="mb-0 pl-3">
        <?php foreach ($errors as $err): ?>
        <li style="font-size:.85rem"><?= esc($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="post" action="<?= site_url() ?>manager/listings/<?= $listing['id'] ?>/edit" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>

    <div class="row">

        <!-- Left column -->
        <div class="col-lg-8">

            <!-- Cover image -->
            <div class="card mb-3" style="background:#161616;border:1px solid #2a2a2a">
                <div class="card-body">
                    <label class="small text-muted d-block mb-2">COVER IMAGE <span class="text-muted">(leave empty to keep current)</span></label>
                    <?php if ($listing['cover_url']): ?>
                    <img src="<?= esc($listing['cover_url']) ?>" id="coverPreview"
                         style="max-height:180px;border-radius:8px;object-fit:cover;width:100%;margin-bottom:10px">
                    <?php else: ?>
                    <img id="coverPreview" src="" style="max-height:180px;border-radius:8px;object-fit:cover;width:100%;margin-bottom:10px;display:none">
                    <?php endif; ?>
                    <input type="file" name="cover_image" accept="image/*"
                           class="form-control-file"
                           style="color:#aaa;font-size:.85rem"
                           onchange="previewCover(this)">
                </div>
            </div>

            <!-- Core fields -->
            <div class="card mb-3" style="background:#161616;border:1px solid #2a2a2a">
                <div class="card-body">

                    <div class="form-group">
                        <label class="small text-muted">TITLE <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" style="background:#1E1E1E;border-color:#333;color:#fff"
                               value="<?= esc($listing['title']) ?>" required>
                    </div>

                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label class="small text-muted">TYPE / CATEGORY <span class="text-danger">*</span></label>
                            <select name="category_id" class="form-control" style="background:#1E1E1E;border-color:#333;color:#fff" required>
                                <option value="">— Select type —</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"
                                    <?= (int)$listing['category_id'] === (int)$cat['id'] ? 'selected' : '' ?>>
                                    <?= esc($cat['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="small text-muted">ORGANIZATION NAME</label>
                            <input type="text" name="org_name" class="form-control" style="background:#1E1E1E;border-color:#333;color:#fff"
                                   value="<?= esc($listing['org_name'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="small text-muted">DESCRIPTION <span class="text-danger">*</span></label>
                        <textarea name="description" rows="5" class="form-control" style="background:#1E1E1E;border-color:#333;color:#fff;resize:vertical"
                                  required><?= esc($listing['description'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="small text-muted">LOCATION</label>
                        <input type="text" name="location" class="form-control" style="background:#1E1E1E;border-color:#333;color:#fff"
                               value="<?= esc($listing['location'] ?? '') ?>">
                    </div>

                </div>
            </div>

            <!-- Dates -->
            <div class="card mb-3" style="background:#161616;border:1px solid #2a2a2a">
                <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a;font-size:.82rem;color:#aaa">DATES</div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label class="small text-muted">EVENT DATE</label>
                            <input type="datetime-local" name="date" class="form-control" style="background:#1E1E1E;border-color:#333;color:#fff"
                                   value="<?= esc($listing['date'] ? date('Y-m-d\TH:i', strtotime($listing['date'])) : '') ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="small text-muted">APPLICATION DEADLINE</label>
                            <input type="datetime-local" name="deadline" class="form-control" style="background:#1E1E1E;border-color:#333;color:#fff"
                                   value="<?= esc($listing['deadline'] ? date('Y-m-d\TH:i', strtotime($listing['deadline'])) : '') ?>">
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- Right column -->
        <div class="col-lg-4">

            <div class="card mb-3" style="background:#161616;border:1px solid #2a2a2a">
                <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a;font-size:.82rem;color:#aaa">ACTION</div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="small text-muted">ACTION TYPE <span class="text-danger">*</span></label>
                        <select name="action_type" id="actionType" class="form-control" style="background:#1E1E1E;border-color:#333;color:#fff"
                                required onchange="toggleExternalUrl()">
                            <?php foreach (['rsvp'=>'RSVP','apply'=>'Apply','external'=>'External Link','save'=>'Save Only'] as $v => $l): ?>
                            <option value="<?= $v ?>" <?= ($listing['action_type'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="externalUrlGroup">
                        <label class="small text-muted">EXTERNAL URL</label>
                        <input type="url" name="external_url" class="form-control" style="background:#1E1E1E;border-color:#333;color:#fff"
                               value="<?= esc($listing['external_url'] ?? '') ?>" placeholder="https://…">
                    </div>
                </div>
            </div>

            <div class="card mb-3" style="background:#161616;border:1px solid #2a2a2a">
                <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a;font-size:.82rem;color:#aaa">TRUST LEVEL</div>
                <div class="card-body">
                    <div class="form-group mb-2">
                        <select name="trust_level" class="form-control" style="background:#1E1E1E;border-color:#333;color:#fff">
                            <?php foreach (['institution_verified'=>'Institution Verified','curator_reviewed'=>'Curator Reviewed','community_submitted'=>'Community Submitted','approved_live_host'=>'Approved Live Host','needs_reconfirmation'=>'Needs Reconfirmation'] as $val => $label): ?>
                            <option value="<?= $val ?>" <?= ($listing['trust_level'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group mb-0">
                        <input type="text" name="trust_label" class="form-control" style="background:#1E1E1E;border-color:#333;color:#fff;font-size:.82rem"
                               value="<?= esc($listing['trust_label'] ?? '') ?>" placeholder="Trust badge label (optional)">
                    </div>
                </div>
            </div>

            <div class="card mb-3" style="background:#161616;border:1px solid #2a2a2a">
                <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a;font-size:.82rem;color:#aaa">VISIBILITY</div>
                <div class="card-body">
                    <div class="form-group mb-2">
                        <select name="status" class="form-control" style="background:#1E1E1E;border-color:#333;color:#fff">
                            <option value="approved" <?= ($listing['status'] ?? '') === 'approved' ? 'selected' : '' ?>>Approved (live)</option>
                            <option value="pending"  <?= ($listing['status'] ?? '') === 'pending'  ? 'selected' : '' ?>>Pending review</option>
                            <option value="rejected" <?= ($listing['status'] ?? '') === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" id="isActive" name="is_active"
                               <?= ($listing['is_active'] ?? 0) ? 'checked' : '' ?>>
                        <label class="custom-control-label small text-muted" for="isActive">Active (show in app)</label>
                    </div>
                </div>
            </div>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-brand btn-block">
                    <i class="fas fa-save mr-1"></i> Save Changes
                </button>
                <a href="<?= site_url() ?>manager/listings/<?= $listing['id'] ?>" class="btn btn-outline-secondary btn-block">Cancel</a>
            </div>

        </div>
    </div>

</form>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
function previewCover(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            const img = document.getElementById('coverPreview');
            img.src = e.target.result;
            img.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}
function toggleExternalUrl() {
    const v = document.getElementById('actionType').value;
    document.getElementById('externalUrlGroup').style.display = v === 'external' ? 'block' : 'none';
}
document.addEventListener('DOMContentLoaded', toggleExternalUrl);
</script>
<?= $this->endSection() ?>
