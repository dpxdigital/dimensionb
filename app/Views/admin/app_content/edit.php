<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<div class="d-flex align-items-center mb-4">
    <a href="<?= site_url() ?>manager/app-content" class="me-3" style="color:#aaa;text-decoration:none">← App Content</a>
    <h5 class="mb-0" style="color:#fff">Edit: <?= esc($title) ?></h5>
</div>

<form method="post" action="<?= site_url() ?>manager/app-content/<?= esc($key) ?>/save">
    <?= csrf_field() ?>

    <div class="mb-3">
        <label class="form-label" style="color:#aaa;font-size:.85rem">Page Title</label>
        <input type="text" name="title" value="<?= esc($title) ?>"
               class="form-control" style="background:#1e1e1e;color:#fff;border:1px solid #333">
    </div>

    <div class="mb-3">
        <label class="form-label" style="color:#aaa;font-size:.85rem">
            Content
            <span style="color:#666;font-weight:400"> — supports Markdown: ## Heading, **bold**, - bullet</span>
        </label>
        <textarea name="content" rows="28"
                  class="form-control font-monospace"
                  style="background:#1e1e1e;color:#e8e8e8;border:1px solid #333;font-size:.82rem;resize:vertical"
                  placeholder="Write content in Markdown…"><?= esc($content) ?></textarea>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-brand px-4">Save</button>
        <a href="<?= site_url() ?>manager/app-content" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>

<?= $this->endSection() ?>
