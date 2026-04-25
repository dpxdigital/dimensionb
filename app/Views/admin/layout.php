<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($pageTitle ?? 'Admin') ?> — Dimensions Manager</title>

    <!-- AdminLTE + Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Inter:300,400,500,600&display=swap">

    <style>
        :root { --brand: #D94032; }
        body { font-family: 'Inter', sans-serif; }
        .brand-link { background: #1a1a1a !important; }
        .brand-link .brand-text { color: #fff !important; font-weight: 600; }
        .sidebar { background: #1a1a1a !important; }
        .sidebar .nav-link, .sidebar .nav-link .nav-icon { color: rgba(255,255,255,.7) !important; }
        .sidebar .nav-link.active, .sidebar .nav-link:hover { color: #fff !important; }
        .sidebar .nav-link.active { background: rgba(217,64,50,.2) !important; border-left: 3px solid var(--brand); }
        .main-header { border-bottom: 1px solid #e9ecef; }
        .btn-brand { background: var(--brand); border-color: var(--brand); color: #fff; }
        .btn-brand:hover { background: #c0362a; border-color: #c0362a; color: #fff; }
        .stat-card { border-radius: 10px; border: none; }
        .stat-icon { font-size: 2.5rem; opacity: .2; }
        .table th { font-size: .75rem; font-weight: 600; text-transform: uppercase; color: #6c757d; }
        .badge-trust-iv   { background: #2A9D5C; }
        .badge-trust-cr   { background: #7F77DD; }
        .badge-trust-cs   { background: #EF9F27; color: #000; }
        .badge-trust-alh  { background: #D94032; }
        .badge-trust-nr   { background: #6c757d; }
    </style>
    <?= $this->renderSection('head') ?>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

<!-- Navbar -->
<nav class="main-header navbar navbar-expand navbar-white navbar-light">
    <ul class="navbar-nav">
        <li class="nav-item"><a class="nav-link" data-widget="pushmenu" href="#"><i class="fas fa-bars"></i></a></li>
    </ul>
    <ul class="navbar-nav ml-auto">
        <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" data-toggle="dropdown">
                <i class="fas fa-user-circle mr-1"></i>
                <?= esc($adminUser['name'] ?? 'Admin') ?>
                <span class="badge badge-sm <?= ($adminUser['role'] ?? '') === 'super_admin' ? 'badge-danger' : 'badge-warning' ?> ml-1">
                    <?= ($adminUser['role'] ?? '') === 'super_admin' ? 'Super Admin' : 'Moderator' ?>
                </span>
            </a>
            <div class="dropdown-menu dropdown-menu-right">
                <a class="dropdown-item text-danger" href="/manager/logout"><i class="fas fa-sign-out-alt mr-1"></i> Logout</a>
            </div>
        </li>
    </ul>
</nav>

<!-- Sidebar -->
<aside class="main-sidebar sidebar-dark-danger elevation-4" style="background:#1a1a1a">
    <a href="/manager" class="brand-link" style="background:#1a1a1a;border-bottom:1px solid #333">
        <span class="brand-text font-weight-bold ml-3" style="color:#D94032;font-size:1.1rem">● Dimensions</span>
        <small class="ml-1" style="color:#888;font-size:.7rem">Manager</small>
    </a>
    <div class="sidebar">
        <nav class="mt-2">
            <ul class="nav nav-pills nav-sidebar flex-column nav-child-indent" data-widget="treeview" role="menu">
                <li class="nav-item">
                    <a href="/manager" class="nav-link <?= (current_url(true)->getPath() === '/manager') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-tachometer-alt"></i><p>Dashboard</p>
                    </a>
                </li>
                <?php if (($adminUser['role'] ?? '') === 'super_admin'): ?>
                <li class="nav-item">
                    <a href="/manager/users" class="nav-link <?= str_contains(current_url(), '/manager/users') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-users"></i><p>Users</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/manager/listings" class="nav-link <?= str_contains(current_url(), '/manager/listings') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-list-alt"></i><p>Listings</p>
                    </a>
                </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a href="/manager/moderation" class="nav-link <?= str_contains(current_url(), '/manager/moderation') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-shield-alt"></i>
                        <p>Moderation
                            <?php
                            $pendingCount = db_connect()->table('submissions')->where('status','pending')->countAllResults()
                                          + db_connect()->table('moderation_queue')->where('status','pending')->countAllResults();
                            if ($pendingCount > 0): ?>
                            <span class="badge badge-danger right"><?= $pendingCount ?></span>
                            <?php endif; ?>
                        </p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/manager/chat" class="nav-link <?= str_contains(current_url(), '/manager/chat') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-comments"></i><p>Chat Moderation</p>
                    </a>
                </li>
                <?php if (($adminUser['role'] ?? '') === 'super_admin'): ?>
                <li class="nav-item">
                    <a href="/manager/live" class="nav-link <?= str_contains(current_url(), '/manager/live') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-broadcast-tower"></i><p>Live Sessions</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/manager/marketplace" class="nav-link <?= str_contains(current_url(), '/manager/marketplace') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-store"></i><p>Marketplace</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/manager/notifications" class="nav-link <?= str_contains(current_url(), '/manager/notifications') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-bell"></i><p>Notifications</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/manager/analytics" class="nav-link <?= str_contains(current_url(), '/manager/analytics') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-chart-line"></i><p>Analytics</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/manager/settings" class="nav-link <?= str_contains(current_url(), '/manager/settings') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-cog"></i><p>Settings</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/manager/admin-users" class="nav-link <?= str_contains(current_url(), '/manager/admin-users') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-user-shield"></i><p>Admin Users</p>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
</aside>

<!-- Content Wrapper -->
<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2 align-items-center">
                <div class="col-sm-6">
                    <h1 class="m-0" style="font-size:1.4rem;font-weight:600"><?= esc($pageTitle ?? 'Dashboard') ?></h1>
                </div>
                <?php if ($flash = session()->getFlashdata('success')): ?>
                <div class="col-12 mt-2">
                    <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
                        <?= esc($flash) ?> <button type="button" class="close" data-dismiss="alert">&times;</button>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($flash = session()->getFlashdata('error')): ?>
                <div class="col-12 mt-2">
                    <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
                        <?= esc($flash) ?> <button type="button" class="close" data-dismiss="alert">&times;</button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">
            <?= $this->renderSection('content') ?>
        </div>
    </section>
</div>

<footer class="main-footer text-sm text-muted">
    <strong>Dimensions 2.0</strong> Manager &copy; <?= date('Y') ?>
</footer>
</div><!-- /.wrapper -->

<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/plugins/jquery/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/dist/js/adminlte.min.js"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
