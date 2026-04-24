<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<div class="row">
    <div class="col-lg-8">
        <div class="card mb-3" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-body">
                <h4 style="color:#fff"><?= esc($listing['title']) ?></h4>
                <p style="color:#aaa;font-size:.9rem"><?= nl2br(esc($listing['description'] ?? '')) ?></p>
                <?php if ($listing['external_url']): ?>
                <a href="<?= esc($listing['external_url']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-external-link-alt mr-1"></i> External URL
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-body p-0">
                <table class="table table-sm mb-0" style="--bs-table-bg:transparent;color:#aaa;font-size:.82rem">
                    <tr><td style="color:#666;width:40%">Category</td><td><?= esc($listing['category_name'] ?? '—') ?></td></tr>
                    <tr><td style="color:#666">Org Name</td><td><?= esc($listing['org_name'] ?? '—') ?></td></tr>
                    <tr><td style="color:#666">Location</td><td><?= esc($listing['location'] ?? '—') ?></td></tr>
                    <tr><td style="color:#666">Date</td><td><?= $listing['date'] ? date('M j Y', strtotime($listing['date'])) : '—' ?></td></tr>
                    <tr><td style="color:#666">Deadline</td><td><?= $listing['deadline'] ? date('M j Y', strtotime($listing['deadline'])) : '—' ?></td></tr>
                    <tr><td style="color:#666">Trust Level</td><td><?= esc(str_replace('_',' ', $listing['trust_level'] ?? '—')) ?></td></tr>
                    <tr><td style="color:#666">Status</td><td><span class="badge <?= $listing['is_active'] ? 'badge-success' : 'badge-secondary' ?>"><?= $listing['is_active'] ? 'Active' : 'Hidden' ?></span></td></tr>
                    <tr><td style="color:#666">Saves</td><td><?= number_format($listing['save_count'] ?? 0) ?></td></tr>
                    <tr><td style="color:#666">RSVPs</td><td><?= number_format($listing['rsvp_count'] ?? 0) ?></td></tr>
                    <tr><td style="color:#666">Created</td><td><?= date('M j Y', strtotime($listing['created_at'])) ?></td></tr>
                </table>
            </div>
        </div>

        <!-- Trust level override -->
        <div class="card mb-3" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a;font-size:.85rem;color:#fff">
                Update Trust Level
            </div>
            <div class="card-body">
                <select id="trustSelect" class="form-control form-control-sm mb-2" style="background:#1E1E1E;border-color:#333;color:#fff">
                    <?php foreach (['institution_verified','curator_reviewed','community_submitted','approved_live_host','needs_reconfirmation'] as $tl): ?>
                    <option value="<?= $tl ?>" <?= ($listing['trust_level'] ?? '') === $tl ? 'selected' : '' ?>><?= esc(str_replace('_',' ',$tl)) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-brand w-100" onclick="updateTrust(<?= $listing['id'] ?>)">Save</button>
            </div>
        </div>

        <div class="d-grid gap-2">
            <button class="btn btn-sm <?= $listing['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                    onclick="toggleListing(<?= $listing['id'] ?>, <?= $listing['is_active'] ? 0 : 1 ?>)">
                <?= $listing['is_active'] ? 'Hide Listing' : 'Show Listing' ?>
            </button>
            <button class="btn btn-sm btn-outline-danger" onclick="deleteListing(<?= $listing['id'] ?>)">Delete Listing</button>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
function updateTrust(id) {
    const trust = document.getElementById('trustSelect').value;
    fetch(`/manager/listings/${id}/trust`, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
        body: JSON.stringify({ trust_level: trust })
    }).then(r => r.json()).then(d => { if (d.success) location.reload(); else alert(d.error); });
}
function toggleListing(id, active) {
    fetch(`/manager/listings/${id}/toggle-status`, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json()).then(d => { if (d.success) location.reload(); else alert(d.error); });
}
function deleteListing(id) {
    if (!confirm('Delete this listing permanently?')) return;
    fetch(`/manager/listings/${id}/delete`, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json()).then(d => { if (d.success) location.href = '/manager/listings'; else alert(d.error); });
}
</script>
<?= $this->endSection() ?>
