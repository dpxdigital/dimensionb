<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<div class="row">
    <!-- Send form -->
    <div class="col-lg-5 mb-4">
        <div class="card" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a">
                <h6 class="mb-0" style="color:#fff">Send Push Notification</h6>
            </div>
            <div class="card-body">
                <div class="form-group mb-3">
                    <label style="color:#aaa;font-size:.82rem">Target</label>
                    <select id="targetType" class="form-control form-control-sm" style="background:#1E1E1E;border-color:#333;color:#fff"
                            onchange="toggleTargetValue()">
                        <option value="all">All Active Users</option>
                        <option value="user">Specific User (email)</option>
                        <option value="category">Category Interest</option>
                    </select>
                </div>
                <div class="form-group mb-3" id="targetValueWrap" style="display:none">
                    <label style="color:#aaa;font-size:.82rem" id="targetValueLabel">Value</label>
                    <input type="text" id="targetValue" class="form-control form-control-sm"
                           style="background:#1E1E1E;border-color:#333;color:#fff" placeholder="">
                    <div id="categorySelect" style="display:none;margin-top:.4rem">
                        <select id="categorySlug" class="form-control form-control-sm" style="background:#1E1E1E;border-color:#333;color:#fff">
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= esc($cat['slug']) ?>"><?= esc($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group mb-3">
                    <label style="color:#aaa;font-size:.82rem">Title</label>
                    <input type="text" id="notifTitle" class="form-control form-control-sm"
                           style="background:#1E1E1E;border-color:#333;color:#fff" placeholder="Notification title" maxlength="100">
                </div>
                <div class="form-group mb-3">
                    <label style="color:#aaa;font-size:.82rem">Body</label>
                    <textarea id="notifBody" class="form-control form-control-sm" rows="3"
                              style="background:#1E1E1E;border-color:#333;color:#fff;resize:none"
                              placeholder="Notification message…" maxlength="250"></textarea>
                </div>
                <div class="form-group mb-4">
                    <label style="color:#aaa;font-size:.82rem">Deep Link <small class="text-muted">(optional)</small></label>
                    <input type="text" id="deepLink" class="form-control form-control-sm"
                           style="background:#1E1E1E;border-color:#333;color:#fff" placeholder="/listing/123">
                </div>
                <button class="btn btn-brand w-100" onclick="sendNotification()" id="sendBtn">
                    <i class="fas fa-paper-plane mr-1"></i> Send
                </button>
                <div id="sendResult" class="mt-2" style="font-size:.82rem"></div>
            </div>
        </div>
    </div>

    <!-- History -->
    <div class="col-lg-7">
        <div class="card" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a">
                <h6 class="mb-0" style="color:#fff">Broadcast History</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616">
                    <thead><tr><th>Title</th><th>Target</th><th>Sent By</th><th>Delivered</th><th>Date</th></tr></thead>
                    <tbody>
                        <?php foreach ($history as $h): ?>
                        <tr>
                            <td style="font-size:.82rem;color:#ddd"><?= esc(mb_strimwidth($h['title'], 0, 35, '…')) ?></td>
                            <td>
                                <span class="badge badge-secondary"><?= esc($h['target_type']) ?></span>
                                <?php if ($h['target_value']): ?>
                                <small class="text-muted ml-1"><?= esc(mb_strimwidth($h['target_value'], 0, 20, '…')) ?></small>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:.8rem;color:#888"><?= esc($h['admin_name'] ?? '—') ?></td>
                            <td style="font-size:.82rem;color:#2A9D5C"><?= number_format($h['delivery_count']) ?></td>
                            <td style="font-size:.8rem;color:#888"><?= date('M j H:i', strtotime($h['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($history)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No broadcasts yet</td></tr>
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
function toggleTargetValue() {
    const type = document.getElementById('targetType').value;
    const wrap = document.getElementById('targetValueWrap');
    const label = document.getElementById('targetValueLabel');
    const input = document.getElementById('targetValue');
    const catSel = document.getElementById('categorySelect');
    if (type === 'all') {
        wrap.style.display = 'none';
    } else if (type === 'user') {
        wrap.style.display = '';
        catSel.style.display = 'none';
        input.style.display = '';
        input.placeholder = 'user@example.com';
        label.textContent = 'User Email';
    } else {
        wrap.style.display = '';
        catSel.style.display = '';
        input.style.display = 'none';
        label.textContent = 'Category';
    }
}
function sendNotification() {
    const type = document.getElementById('targetType').value;
    const title = document.getElementById('notifTitle').value.trim();
    const body = document.getElementById('notifBody').value.trim();
    const deepLink = document.getElementById('deepLink').value.trim();
    let targetValue = '';
    if (type === 'user') targetValue = document.getElementById('targetValue').value.trim();
    else if (type === 'category') targetValue = document.getElementById('categorySlug').value;

    if (!title || !body) { alert('Title and body are required.'); return; }

    const btn = document.getElementById('sendBtn');
    btn.disabled = true; btn.textContent = 'Sending…';

    const fd = new FormData();
    fd.append('target_type', type);
    fd.append('target_value', targetValue);
    fd.append('title', title);
    fd.append('body', body);
    fd.append('deep_link', deepLink);

    fetch('/manager/notifications/send', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    }).then(r => r.json()).then(d => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane mr-1"></i> Send';
        const res = document.getElementById('sendResult');
        if (d.success) {
            res.innerHTML = `<span class="text-success"><i class="fas fa-check mr-1"></i>Sent to ${d.delivery_count} recipients</span>`;
            setTimeout(() => location.reload(), 1500);
        } else {
            res.innerHTML = `<span class="text-danger">${d.error}</span>`;
        }
    });
}
</script>
<?= $this->endSection() ?>
