<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['label' => 'Total Users',         'value' => number_format($stats['total_users']),        'icon' => 'fa-users',           'color' => '#2A9D5C'],
        ['label' => 'Active Listings',     'value' => number_format($stats['active_listings']),    'icon' => 'fa-list-alt',        'color' => '#7F77DD'],
        ['label' => 'Pending Moderation',  'value' => number_format($stats['pending_moderation']), 'icon' => 'fa-shield-alt',      'color' => '#D94032'],
        ['label' => 'Live Sessions Today', 'value' => number_format($stats['live_today']),         'icon' => 'fa-broadcast-tower', 'color' => '#EF9F27'],
        ['label' => 'New Users (7d)',      'value' => number_format($stats['new_users_week']),     'icon' => 'fa-user-plus',       'color' => '#2A9D5C'],
        ['label' => 'Messages (24h)',      'value' => number_format($stats['messages_24h']),       'icon' => 'fa-comments',        'color' => '#7F77DD'],
        ['label' => 'Total RSVPs',         'value' => number_format($stats['total_rsvps']),        'icon' => 'fa-calendar-check',  'color' => '#D94032'],
        ['label' => 'Submissions (7d)',    'value' => number_format($stats['submissions_week']),   'icon' => 'fa-paper-plane',     'color' => '#EF9F27'],
    ];
    foreach ($cards as $c): ?>
    <div class="col-6 col-lg-3">
        <div class="card stat-card mb-0" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-body d-flex align-items-center py-3">
                <div class="mr-3">
                    <i class="fas <?= $c['icon'] ?> fa-2x" style="color:<?= $c['color'] ?>;opacity:.85"></i>
                </div>
                <div>
                    <div style="font-size:1.5rem;font-weight:700;color:#fff;line-height:1"><?= $c['value'] ?></div>
                    <div style="font-size:.75rem;color:#888;margin-top:2px"><?= $c['label'] ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row">
    <!-- New Users Chart -->
    <div class="col-lg-8 mb-4">
        <div class="card mb-0" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a">
                <h3 class="card-title" style="color:#fff;font-size:.9rem">New Users — Last 7 Days</h3>
            </div>
            <div class="card-body">
                <canvas id="usersChart" height="100"></canvas>
            </div>
        </div>
    </div>

    <!-- Recent Audit Log -->
    <div class="col-lg-4 mb-4">
        <div class="card mb-0" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a">
                <h3 class="card-title" style="color:#fff;font-size:.9rem">Recent Activity</h3>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush" style="background:transparent">
                    <?php foreach ($recentAudit as $log): ?>
                    <li class="list-group-item" style="background:transparent;border-color:#2a2a2a;padding:.65rem 1rem">
                        <div style="font-size:.8rem;color:#ddd"><?= esc($log['admin_name'] ?? 'System') ?></div>
                        <div style="font-size:.75rem;color:#888"><?= esc(str_replace('_', ' ', $log['action'])) ?></div>
                        <div style="font-size:.7rem;color:#555"><?= date('M j H:i', strtotime($log['created_at'])) ?></div>
                    </li>
                    <?php endforeach; ?>
                    <?php if (empty($recentAudit)): ?>
                    <li class="list-group-item" style="background:transparent;border:0;color:#555;font-size:.8rem;text-align:center;padding:1.5rem">No activity yet</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const ctx = document.getElementById('usersChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($newUsersChart, 'date')) ?>,
        datasets: [{
            label: 'New Users',
            data: <?= json_encode(array_column($newUsersChart, 'count')) ?>,
            backgroundColor: 'rgba(217,64,50,.7)',
            borderColor: '#D94032',
            borderWidth: 1,
            borderRadius: 4,
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            x: { ticks: { color: '#888' }, grid: { color: '#222' } },
            y: { ticks: { color: '#888', precision: 0 }, grid: { color: '#222' }, beginAtZero: true }
        }
    }
});
</script>
<?= $this->endSection() ?>
