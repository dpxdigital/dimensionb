<?= $this->extend('admin/layout') ?>

<?= $this->section('head') ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<!-- Time-series row -->
<div class="row mb-4">
    <?php
    $charts = [
        ['id' => 'usersChart',       'label' => 'New Users (30d)',          'data' => $usersPerDay,       'color' => '#2A9D5C'],
        ['id' => 'liveChart',        'label' => 'Live Sessions (30d)',       'data' => $livePerDay,        'color' => '#D94032'],
        ['id' => 'messagesChart',    'label' => 'Messages Sent (30d)',       'data' => $messagesPerDay,    'color' => '#7F77DD'],
        ['id' => 'connectionsChart', 'label' => 'Connection Requests (30d)', 'data' => $connectionsPerDay, 'color' => '#EF9F27'],
    ];
    foreach ($charts as $ch): ?>
    <div class="col-lg-6 mb-4">
        <div class="card" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a">
                <h6 class="mb-0" style="color:#fff;font-size:.85rem"><?= $ch['label'] ?></h6>
            </div>
            <div class="card-body"><canvas id="<?= $ch['id'] ?>" height="120"></canvas></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Tables row -->
<div class="row">
    <div class="col-lg-4 mb-4">
        <div class="card" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a">
                <h6 class="mb-0" style="color:#fff;font-size:.85rem">Top Categories by Listings</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616">
                    <thead><tr><th>#</th><th>Category</th><th>Listings</th></tr></thead>
                    <tbody>
                        <?php foreach ($popularCategories as $i => $cat): ?>
                        <tr>
                            <td style="color:#666;font-size:.8rem"><?= $i+1 ?></td>
                            <td style="font-size:.82rem;color:#ddd"><?= esc($cat['name'] ?? 'Uncategorized') ?></td>
                            <td style="font-size:.82rem;color:#2A9D5C"><?= number_format($cat['listing_count']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4 mb-4">
        <div class="card" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a">
                <h6 class="mb-0" style="color:#fff;font-size:.85rem">Most Saved Listings</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616">
                    <thead><tr><th>#</th><th>Title</th><th>Saves</th></tr></thead>
                    <tbody>
                        <?php foreach ($mostSaved as $i => $item): ?>
                        <tr>
                            <td style="color:#666;font-size:.8rem"><?= $i+1 ?></td>
                            <td style="font-size:.82rem"><a href="/manager/listings/<?= $item['id'] ?>" style="color:#7F77DD"><?= esc(mb_strimwidth($item['title'] ?? '—', 0, 35, '…')) ?></a></td>
                            <td style="font-size:.82rem;color:#7F77DD"><?= number_format($item['save_count']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4 mb-4">
        <div class="card" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a">
                <h6 class="mb-0" style="color:#fff;font-size:.85rem">Most RSVPed Listings</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-dark mb-0" style="--bs-table-bg:#161616">
                    <thead><tr><th>#</th><th>Title</th><th>RSVPs</th></tr></thead>
                    <tbody>
                        <?php foreach ($mostRsvped as $i => $item): ?>
                        <tr>
                            <td style="color:#666;font-size:.8rem"><?= $i+1 ?></td>
                            <td style="font-size:.82rem"><a href="/manager/listings/<?= $item['id'] ?>" style="color:#7F77DD"><?= esc(mb_strimwidth($item['title'] ?? '—', 0, 35, '…')) ?></a></td>
                            <td style="font-size:.82rem;color:#EF9F27"><?= number_format($item['rsvp_count']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
const chartConfigs = <?= json_encode([
    ['id' => 'usersChart',       'labels' => array_column($usersPerDay, 'date'),       'data' => array_column($usersPerDay, 'count'),       'color' => '#2A9D5C'],
    ['id' => 'liveChart',        'labels' => array_column($livePerDay, 'date'),        'data' => array_column($livePerDay, 'count'),        'color' => '#D94032'],
    ['id' => 'messagesChart',    'labels' => array_column($messagesPerDay, 'date'),    'data' => array_column($messagesPerDay, 'count'),    'color' => '#7F77DD'],
    ['id' => 'connectionsChart', 'labels' => array_column($connectionsPerDay, 'date'), 'data' => array_column($connectionsPerDay, 'count'), 'color' => '#EF9F27'],
]) ?>;

chartConfigs.forEach(cfg => {
    new Chart(document.getElementById(cfg.id).getContext('2d'), {
        type: 'line',
        data: {
            labels: cfg.labels,
            datasets: [{
                data: cfg.data,
                borderColor: cfg.color,
                backgroundColor: cfg.color + '22',
                borderWidth: 2,
                pointRadius: 0,
                fill: true,
                tension: 0.4,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { color: '#888', maxTicksLimit: 8 }, grid: { color: '#222' } },
                y: { ticks: { color: '#888', precision: 0 }, grid: { color: '#222' }, beginAtZero: true }
            }
        }
    });
});
</script>
<?= $this->endSection() ?>
