<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<div class="row">
    <!-- Messages -->
    <div class="col-lg-8 mb-4">
        <div class="card" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a">
                <h6 class="mb-0" style="color:#fff">
                    <?= esc($conv['name'] ?? ($conv['type'] === 'group' ? 'Group Chat' : 'Direct Message')) ?>
                    <span class="badge badge-secondary ml-1"><?= esc($conv['type']) ?></span>
                </h6>
            </div>
            <div class="card-body" style="max-height:520px;overflow-y:auto;padding:.75rem">
                <?php foreach ($messages as $msg): ?>
                <?php if ($msg['is_deleted']): ?>
                <div class="mb-2 text-center"><small class="text-muted fst-italic">Message deleted</small></div>
                <?php else: ?>
                <div class="d-flex mb-2 gap-2">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                         style="width:28px;height:28px;background:#2a2a2a;font-size:.65rem;color:#888">
                        <?= strtoupper(substr($msg['sender_name'] ?? 'U', 0, 1)) ?>
                    </div>
                    <div>
                        <div style="font-size:.75rem;color:#888;margin-bottom:2px">
                            <?= esc($msg['sender_name'] ?? 'Unknown') ?>
                            <span class="ml-1"><?= date('M j H:i', strtotime($msg['created_at'])) ?></span>
                        </div>
                        <div style="background:#1E1E1E;border-radius:8px;padding:.4rem .65rem;font-size:.82rem;color:#ddd;display:inline-block;max-width:400px">
                            <?php if ($msg['type'] === 'text'): ?>
                                <?= nl2br(esc($msg['body'])) ?>
                            <?php elseif ($msg['type'] === 'image'): ?>
                                <img src="<?= esc($msg['file_url']) ?>" style="max-width:200px;border-radius:6px">
                            <?php elseif ($msg['type'] === 'file'): ?>
                                <i class="fas fa-file mr-1"></i><?= esc($msg['file_name'] ?? 'File') ?>
                            <?php elseif ($msg['type'] === 'listing_share'): ?>
                                <i class="fas fa-list-alt mr-1" style="color:#7F77DD"></i>Shared listing
                            <?php else: ?>
                                <em class="text-muted">[<?= esc($msg['type']) ?>]</em>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
                <?php if (empty($messages)): ?>
                <p class="text-center text-muted py-3">No messages</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Members -->
    <div class="col-lg-4">
        <div class="card" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a">
                <h6 class="mb-0" style="color:#fff">Members (<?= count($members) ?>)</h6>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush" style="background:transparent">
                    <?php foreach ($members as $m): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center"
                        style="background:transparent;border-color:#2a2a2a;padding:.5rem 1rem">
                        <div>
                            <div style="font-size:.82rem;color:#ddd">
                                <?= esc($m['name']) ?>
                                <?php if ($m['is_admin']): ?><span class="badge badge-warning ml-1" style="font-size:.65rem">Admin</span><?php endif; ?>
                            </div>
                            <div style="font-size:.75rem;color:#666"><?= esc($m['email']) ?></div>
                        </div>
                        <button class="btn btn-xs btn-outline-danger"
                                onclick="removeMember(<?= $conv['id'] ?>, <?= $m['id'] ?>)">Remove</button>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <div class="mt-3 d-grid gap-2">
            <button class="btn btn-sm btn-outline-danger" onclick="deleteConv(<?= $conv['id'] ?>)">
                <i class="fas fa-trash mr-1"></i> Delete Conversation
            </button>
            <a href="/manager/chat" class="btn btn-sm btn-outline-secondary">← Back to Chat</a>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
function removeMember(convId, userId) {
    if (!confirm('Remove this member?')) return;
    fetch(`/manager/chat/${convId}/member/${userId}/remove`, {
        method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(r => r.json()).then(d => { if (d.success) location.reload(); else alert(d.error); });
}
function deleteConv(id) {
    if (!confirm('Delete this conversation permanently?')) return;
    window.location.href = `/manager/chat/${id}/delete`;
}
</script>
<?= $this->endSection() ?>
