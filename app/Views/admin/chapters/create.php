<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<?php if (session()->getFlashdata('error')): ?>
<div class="alert alert-danger py-2 mb-3" style="font-size:.85rem"><?= esc(session()->getFlashdata('error')) ?></div>
<?php endif; ?>

<div class="d-flex align-items-center gap-2 mb-3">
    <a href="<?= site_url() ?>manager/chapters" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-arrow-left mr-1"></i> Chapters
    </a>
    <h5 class="mb-0 ml-2" style="color:#fff">New Chapter</h5>
</div>

<div class="row">
    <div class="col-lg-7">
        <div class="card" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-body">
                <form method="post" action="<?= site_url() ?>manager/chapters" enctype="multipart/form-data">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label" style="color:#ccc;font-size:.82rem">Name <span style="color:#D94032">*</span></label>
                        <input type="text" name="name" class="form-control form-control-sm"
                               style="background:#1E1E1E;border-color:#333;color:#fff"
                               value="<?= esc(old('name')) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" style="color:#ccc;font-size:.82rem">Description</label>
                        <textarea name="description" rows="4" class="form-control form-control-sm"
                                  style="background:#1E1E1E;border-color:#333;color:#fff"><?= esc(old('description')) ?></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label" style="color:#ccc;font-size:.82rem">City</label>
                            <input type="text" name="city" class="form-control form-control-sm"
                                   style="background:#1E1E1E;border-color:#333;color:#fff"
                                   value="<?= esc(old('city')) ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label" style="color:#ccc;font-size:.82rem">State</label>
                            <input type="text" name="state" class="form-control form-control-sm"
                                   style="background:#1E1E1E;border-color:#333;color:#fff"
                                   value="<?= esc(old('state')) ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label" style="color:#ccc;font-size:.82rem">Country</label>
                            <input type="text" name="country" class="form-control form-control-sm"
                                   style="background:#1E1E1E;border-color:#333;color:#fff"
                                   value="<?= esc(old('country', 'US')) ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" style="color:#ccc;font-size:.82rem">Category</label>
                        <input type="text" name="category" class="form-control form-control-sm"
                               style="background:#1E1E1E;border-color:#333;color:#fff"
                               placeholder="e.g. Community, Technology, Health…"
                               value="<?= esc(old('category')) ?>">
                    </div>

                    <div class="mb-4">
                        <label class="form-label" style="color:#ccc;font-size:.82rem">Cover Image</label>
                        <input type="file" name="image" accept="image/jpeg,image/png,image/webp"
                               class="form-control form-control-sm"
                               style="background:#1E1E1E;border-color:#333;color:#fff"
                               onchange="previewCover(this)">
                        <div class="mt-2" id="coverPreviewWrap" style="display:none">
                            <img id="coverPreview" src="" alt="" style="height:120px;border-radius:8px;border:1px solid #333;object-fit:cover">
                        </div>
                        <small style="color:#666">JPG, PNG or WebP · max 5 MB</small>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-danger">Create Chapter</button>
                        <a href="<?= site_url() ?>manager/chapters" class="btn btn-sm btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
function previewCover(input) {
    if (input.files && input.files[0]) {
        const wrap = document.getElementById('coverPreviewWrap');
        const img  = document.getElementById('coverPreview');
        img.src = URL.createObjectURL(input.files[0]);
        wrap.style.display = 'block';
    }
}
</script>
<?= $this->endSection() ?>
