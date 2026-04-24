<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<ul class="nav nav-tabs mb-3" style="border-color:#2a2a2a">
    <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#reports">Reported Conversations <span class="badge badge-danger ml-1"><?= $total ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#groups">Active Groups <span class="badge badge-secondary ml-1"><?= count($groups) ?></span></a></li>
</ul>

<div class="tab-content">
    <!-- Reports tab -->
    <div class="tab-pane active" id="reports">
        <div class="card" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-body p-0">
                <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616">
                    <thead><tr><th>Conversation</th><th>Type</th><th>Reporter</th><th>Date</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($reports as $r): ?>
                        <tr>
                            <td style="font-size:.82rem;color:#ddd"><?= esc($r['conv_name'] ?? 'Direct #'.$r['reference_id']) ?></td>
                            <td><span class="badge badge-secondary"><?= esc($r['conv_type'] ?? '—') ?></span></td>
                            <td style="font-size:.8rem;color:#888"><?= esc($r['reporter_name'] ?? '—') ?></td>
                            <td style="font-size:.8rem;color:#888"><?= date('M j H:i', strtotime($r['created_at'])) ?></td>
                            <td class="text-right">
                                <a href="/manager/chat/<?= $r['reference_id'] ?>" class="btn btn-xs btn-outline-secondary">View</a>
                                <button class="btn btn-xs btn-outline-danger ml-1" onclick="deleteConv(<?= $r['reference_id'] ?>)">Delete</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($reports)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4"><i class="fas fa-check-circle mr-1"></i>No reports</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($lastPage > 1): ?>
            <div class="card-footer" style="background:transparent;border-top:1px solid #2a2a2a">
                <?= view('admin/_pagination', ['page' => $page, 'lastPage' => $lastPage, 'q' => '']) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Groups tab -->
    <div class="tab-pane" id="groups">
        <div class="card" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-body p-0">
                <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616">
                    <thead><tr><th>Group Name</th><th>Members</th><th>Created By</th><th>Last Active</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($groups as $g): ?>
                        <tr>
                            <td style="font-size:.82rem;color:#ddd"><?= esc($g['name'] ?? 'Group #'.$g['id']) ?></td>
                            <td style="font-size:.82rem"><?= $g['member_count'] ?></td>
                            <td style="font-size:.8rem;color:#888"><?= esc($g['created_by_name'] ?? '—') ?></td>
                            <td style="font-size:.8rem;color:#888"><?= $g['last_message_at'] ? date('M j H:i', strtotime($g['last_message_at'])) : '—' ?></td>
                            <td class="text-right">
                                <a href="/manager/chat/<?= $g['id'] ?>" class="btn btn-xs btn-outline-secondary">View</a>
                                <button class="btn btn-xs btn-outline-danger ml-1" onclick="deleteConv(<?= $g['id'] ?>)">Delete</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($groups)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No groups</td></tr>
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
function deleteConv(id) {
    if (!confirm('Delete this conversation and all its messages?')) return;
    window.location.href = `/manager/chat/${id}/delete`;
}
</script>
<?= $this->endSection() ?>
