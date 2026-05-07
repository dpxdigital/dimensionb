<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<!-- Filters -->
<div class="card mb-3" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-body py-2">
        <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
            <input type="text" name="q" class="form-control form-control-sm"
                   style="background:#1E1E1E;border-color:#333;color:#fff;max-width:240px"
                   placeholder="Name, email, phone…" value="<?= esc($search) ?>">

            <select name="state" class="form-control form-control-sm" style="background:#1E1E1E;border-color:#333;color:#fff;max-width:140px">
                <option value="">All States</option>
                <?php foreach ($states as $s): ?>
                <option value="<?= esc($s) ?>" <?= $state === $s ? 'selected' : '' ?>><?= esc($s) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="gender" class="form-control form-control-sm" style="background:#1E1E1E;border-color:#333;color:#fff;max-width:130px">
                <option value="">All Genders</option>
                <?php foreach (['Male','Female','Non-binary','Other','Prefer not to say'] as $g): ?>
                <option value="<?= $g ?>" <?= $gender === $g ? 'selected' : '' ?>><?= $g ?></option>
                <?php endforeach; ?>
            </select>

            <button class="btn btn-sm btn-secondary">Filter</button>
            <?php if ($search || $state || $gender): ?>
            <a href="<?= site_url() ?>manager/census" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>

            <span class="text-muted ml-2" style="font-size:.8rem"><?= number_format($total) ?> submissions</span>

            <a href="<?= site_url() ?>manager/census/export" class="btn btn-sm btn-outline-success ml-auto">
                <i class="fas fa-download mr-1"></i> Export CSV
            </a>
        </form>
    </div>
</div>

<div class="card" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616;--bs-table-striped-bg:#1a1a1a;font-size:.83rem">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Gender</th>
                    <th>City / State</th>
                    <th>Chapter</th>
                    <th>Community Status</th>
                    <th>Categories</th>
                    <th>Updates</th>
                    <th>Submitted</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $i => $r): ?>
                <tr>
                    <td class="text-muted"><?= ($page - 1) * 30 + $i + 1 ?></td>
                    <td>
                        <strong><?= esc(trim($r['first_name'] . ' ' . $r['last_name'])) ?></strong>
                        <?php if ($r['account_name']): ?>
                        <br><small class="text-muted">Account: <?= esc($r['account_name']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= esc($r['email']) ?></td>
                    <td><?= esc($r['phone'] ?? '—') ?></td>
                    <td><?= esc($r['gender'] ?? '—') ?></td>
                    <td>
                        <?php $loc = array_filter([$r['city'] ?? '', $r['state'] ?? '']); ?>
                        <?= esc(implode(', ', $loc) ?: '—') ?>
                        <?php if ($r['zip']): ?>
                        <br><small class="text-muted"><?= esc($r['zip']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= esc($r['chapter_name'] ?? '—') ?></td>
                    <td>
                        <?php
                        $interests = $r['interests'] ? json_decode($r['interests'], true) : [];
                        $communityStatus = $interests['community_status'] ?? null;
                        echo $communityStatus ? esc(ucfirst(str_replace('_', ' ', $communityStatus))) : '—';
                        ?>
                    </td>
                    <td>
                        <?php
                        $cats = $interests['categories'] ?? [];
                        if (!empty($cats)):
                            $catNames = array_column($cats, 'category');
                        ?>
                        <span title="<?= esc(implode(', ', $catNames)) ?>">
                            <?= count($cats) ?> categor<?= count($cats) === 1 ? 'y' : 'ies' ?>
                        </span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['sms_updates']): ?>
                        <span class="badge badge-success" title="SMS">SMS</span>
                        <?php endif; ?>
                        <?php if ($r['email_updates']): ?>
                        <span class="badge badge-info" title="Email">Email</span>
                        <?php endif; ?>
                        <?php if (!$r['sms_updates'] && !$r['email_updates']): ?>
                        <span class="text-muted">None</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted"><?= date('M j Y', strtotime($r['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($records)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No census submissions found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <?php if ($lastPage > 1): ?>
    <div class="card-footer" style="background:#161616;border-top:1px solid #2a2a2a">
        <nav>
            <ul class="pagination pagination-sm mb-0 justify-content-center">
                <?php if ($page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">‹ Prev</a>
                </li>
                <?php endif; ?>

                <?php for ($p = max(1, $page - 2); $p <= min($lastPage, $page + 2); $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>

                <?php if ($page < $lastPage): ?>
                <li class="page-item">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next ›</a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
        <p class="text-center text-muted mt-2 mb-0" style="font-size:.78rem">
            Page <?= $page ?> of <?= $lastPage ?> &middot; <?= number_format($total) ?> total submissions
        </p>
    </div>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
