<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h5 class="mb-0" style="color:#fff">App Content &amp; Legal Pages</h5>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success py-2"><?= esc(session()->getFlashdata('success')) ?></div>
<?php endif; ?>

<div class="row">
    <?php foreach ($pages as $key => $label): ?>
    <?php $row = $content[$key] ?? null; ?>
    <div class="col-md-6 mb-4">
        <div class="card h-100" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header d-flex justify-content-between align-items-center"
                 style="background:transparent;border-bottom:1px solid #2a2a2a">
                <div>
                    <h6 class="mb-0" style="color:#fff"><?= esc($label) ?></h6>
                    <?php if ($row): ?>
                        <small style="color:#666">Last updated: <?= esc(date('M j, Y', strtotime($row['updated_at']))) ?></small>
                    <?php else: ?>
                        <small style="color:#e05050">Not yet set</small>
                    <?php endif; ?>
                </div>
                <a href="<?= site_url() ?>manager/app-content/<?= esc($key) ?>/edit"
                   class="btn btn-sm btn-brand">Edit</a>
            </div>
            <div class="card-body">
                <?php if ($row && ! empty($row['content'])): ?>
                    <p style="color:#aaa;font-size:.82rem;white-space:pre-wrap;max-height:120px;overflow:hidden">
                        <?= esc(substr($row['content'], 0, 300)) ?><?= strlen($row['content']) > 300 ? '…' : '' ?>
                    </p>
                <?php else: ?>
                    <p style="color:#555;font-size:.82rem">No content yet. Click Edit to add content.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?= $this->endSection() ?>
