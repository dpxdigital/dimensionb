<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<div class="row">

    <!-- Add Feed Form -->
    <div class="col-lg-4 mb-4">
        <div class="card" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a">
                <h6 class="mb-0" style="color:#fff"><i class="fas fa-plus-circle mr-1" style="color:#D94032"></i> Add RSS Feed</h6>
            </div>
            <div class="card-body">
                <form method="post" action="<?= site_url('manager/rss/store') ?>">
                    <?= csrf_field() ?>
                    <div class="form-group mb-3">
                        <label style="color:#aaa;font-size:.82rem">Feed Name</label>
                        <input type="text" name="name" class="form-control form-control-sm"
                               style="background:#1E1E1E;border-color:#333;color:#fff"
                               placeholder="e.g. BBC News" required maxlength="100">
                    </div>
                    <div class="form-group mb-4">
                        <label style="color:#aaa;font-size:.82rem">RSS / Atom URL</label>
                        <input type="url" name="url" class="form-control form-control-sm"
                               style="background:#1E1E1E;border-color:#333;color:#fff"
                               placeholder="https://example.com/feed.xml" required>
                    </div>
                    <button type="submit" class="btn btn-brand btn-sm w-100">
                        <i class="fas fa-plus mr-1"></i> Add Feed
                    </button>
                </form>
            </div>
        </div>

        <!-- Stats summary -->
        <div class="card mt-3" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-body py-3">
                <?php
                    $total    = count($feeds);
                    $active   = count(array_filter($feeds, fn($f) => $f['is_active']));
                    $articles = array_sum(array_column($feeds, 'article_count'));

                    // Compute next scheduled fetch (earliest overdue or never-fetched active feed)
                    $nextFetch = null;
                    foreach ($feeds as $f) {
                        if (! $f['is_active']) continue;
                        if (empty($f['last_fetched_at'])) { $nextFetch = 'Now (never fetched)'; break; }
                        $due = strtotime($f['last_fetched_at']) + 20 * 3600;
                        if ($due <= time()) { $nextFetch = 'Now (overdue)'; break; }
                        if ($nextFetch === null || $due < strtotime($nextFetch)) {
                            $nextFetch = date('M j, H:i', $due);
                        }
                    }
                ?>
                <div class="d-flex justify-content-between mb-2">
                    <span style="color:#aaa;font-size:.82rem">Total feeds</span>
                    <strong style="color:#fff"><?= $total ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span style="color:#aaa;font-size:.82rem">Active</span>
                    <strong style="color:#2A9D5C"><?= $active ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span style="color:#aaa;font-size:.82rem">Total articles</span>
                    <strong style="color:#fff"><?= number_format($articles) ?></strong>
                </div>
                <div class="d-flex justify-content-between">
                    <span style="color:#aaa;font-size:.82rem">Next auto-fetch</span>
                    <strong style="color:#EF9F27;font-size:.82rem">
                        <?= $nextFetch ?? '<span style="color:#555">—</span>' ?>
                    </strong>
                </div>
            </div>
        </div>

        <!-- Auto-schedule setup card -->
        <div class="card mt-3" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a">
                <h6 class="mb-0" style="color:#fff;font-size:.85rem">
                    <i class="fas fa-clock mr-1" style="color:#EF9F27"></i> Auto-Fetch Schedule
                </h6>
            </div>
            <div class="card-body py-3">
                <p style="color:#aaa;font-size:.78rem;line-height:1.5;margin-bottom:10px">
                    Feeds auto-fetch every <strong style="color:#fff">20 hours</strong> via a cron job.<br>
                    Add this to <strong style="color:#fff">cPanel → Cron Jobs</strong>:
                </p>
                <div style="background:#0d0d0d;border:1px solid #333;border-radius:6px;padding:8px 10px;font-family:monospace;font-size:.72rem;color:#2A9D5C;word-break:break-all;margin-bottom:10px">
                    0 * * * * curl -s "<?= rtrim(site_url(), '/') ?>/v1/cron/rss-feeds?token=<?= esc(env('RSS_CRON_TOKEN','YOUR_RSS_CRON_TOKEN')) ?>" &gt; /dev/null 2&gt;&amp;1
                </div>
                <p style="color:#666;font-size:.74rem;margin:0">
                    Runs every hour — self-throttles to only fetch feeds older than 20 h.
                    Set <code style="color:#aaa">RSS_CRON_TOKEN</code> in your <code style="color:#aaa">.env</code> file.
                </p>
            </div>
        </div>
    </div>

    <!-- Feed List -->
    <div class="col-lg-8">
        <div class="card" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header d-flex justify-content-between align-items-center"
                 style="background:transparent;border-bottom:1px solid #2a2a2a">
                <h6 class="mb-0" style="color:#fff"><i class="fas fa-rss mr-1" style="color:#D94032"></i> Configured Feeds</h6>
                <form method="post" action="<?= site_url('manager/rss/fetch-all') ?>" class="d-inline" id="fetch-all-form">
                    <?= csrf_field() ?>
                    <button type="button" class="btn btn-outline-success btn-xs"
                            onclick="fetchAll()">
                        <i class="fas fa-sync-alt mr-1"></i> Fetch All
                    </button>
                </form>
            </div>
            <div class="card-body p-0">
                <?php if (empty($feeds)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-rss fa-2x mb-2" style="opacity:.3"></i><br>
                    No feeds configured yet. Add one on the left.
                </div>
                <?php else: ?>
                <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>URL</th>
                            <th class="text-center">Articles</th>
                            <th>Last Fetched</th>
                            <th class="text-center">Active</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($feeds as $feed): ?>
                        <tr>
                            <td style="font-size:.83rem;color:#ddd;vertical-align:middle">
                                <?= esc($feed['name']) ?>
                            </td>
                            <td style="font-size:.75rem;color:#888;vertical-align:middle">
                                <span class="d-block text-truncate" style="max-width:160px"
                                      title="<?= esc($feed['url']) ?>">
                                    <?= esc(parse_url($feed['url'], PHP_URL_HOST) ?: $feed['url']) ?>
                                </span>
                            </td>
                            <td class="text-center" style="vertical-align:middle">
                                <span class="badge badge-secondary"><?= number_format($feed['article_count']) ?></span>
                            </td>
                            <td style="font-size:.78rem;color:#888;vertical-align:middle">
                                <?= $feed['last_fetched_at']
                                    ? date('M j, H:i', strtotime($feed['last_fetched_at']))
                                    : '<span class="text-muted">Never</span>' ?>
                            </td>
                            <td class="text-center" style="vertical-align:middle">
                                <form method="post"
                                      action="<?= site_url('manager/rss/' . $feed['id'] . '/toggle') ?>"
                                      class="d-inline">
                                    <?= csrf_field() ?>
                                    <button type="submit"
                                            class="btn btn-xs <?= $feed['is_active'] ? 'btn-success' : 'btn-secondary' ?>">
                                        <?= $feed['is_active'] ? 'On' : 'Off' ?>
                                    </button>
                                </form>
                            </td>
                            <td class="text-right" style="vertical-align:middle;white-space:nowrap">
                                <button type="button"
                                        class="btn btn-xs btn-outline-info"
                                        onclick="fetchFeed(<?= (int) $feed['id'] ?>)">
                                    Fetch
                                </button>
                                <button type="button"
                                        class="btn btn-xs btn-outline-danger ml-1"
                                        onclick="deleteFeed(<?= (int) $feed['id'] ?>, '<?= esc($feed['name'], 'js') ?>')">
                                    Delete
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<!-- Hidden action forms -->
<form id="fetch-form"  method="post" style="display:none"><?= csrf_field() ?></form>
<form id="delete-form" method="post" style="display:none"><?= csrf_field() ?></form>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
const BASE = '<?= rtrim(site_url(), '/') ?>/manager/rss';

function fetchFeed(id) {
    const form = document.getElementById('fetch-form');
    form.action = BASE + '/' + id + '/fetch';
    form.submit();
}

function deleteFeed(id, name) {
    if (!confirm('Delete "' + name + '" and all its articles? This cannot be undone.')) return;
    const form = document.getElementById('delete-form');
    form.action = BASE + '/' + id + '/delete';
    form.submit();
}

function fetchAll() {
    if (!confirm('Fetch all active feeds now?')) return;
    document.getElementById('fetch-all-form').submit();
}
</script>
<?= $this->endSection() ?>
