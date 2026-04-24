<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dimensions Manager — Login</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/dist/css/adminlte.min.css">
    <style>
        body { background: #0A0A0A; font-family: 'Inter', sans-serif; }
        .login-box { margin: 0 auto; }
        .login-logo a { color: #D94032; font-weight: 700; font-size: 1.5rem; text-decoration: none; }
        .login-logo small { display: block; color: #888; font-size: .75rem; font-weight: 400; margin-top: -4px; }
        .card { background: #161616; border: 1px solid #2a2a2a; border-radius: 12px; }
        .card-body { padding: 2rem; }
        .form-control { background: #1E1E1E; border-color: #2a2a2a; color: #fff; }
        .form-control:focus { background: #1E1E1E; border-color: #D94032; color: #fff; box-shadow: 0 0 0 .2rem rgba(217,64,50,.25); }
        .form-control::placeholder { color: #555; }
        .input-group-text { background: #1E1E1E; border-color: #2a2a2a; color: #888; }
        .btn-brand { background: #D94032; border-color: #D94032; color: #fff; font-weight: 600; }
        .btn-brand:hover { background: #c0362a; border-color: #c0362a; color: #fff; }
        label { color: #aaa; font-size: .85rem; }
        .alert-danger { background: rgba(217,64,50,.15); border-color: #D94032; color: #f88; }
    </style>
</head>
<body class="hold-transition">
<div class="login-box" style="margin-top: 8vh; max-width: 380px;">
    <div class="login-logo text-center mb-4">
        <a href="#">● Dimensions<small>Manager Portal</small></a>
    </div>
    <div class="card">
        <div class="card-body">
            <?php if ($error = session()->getFlashdata('error')): ?>
            <div class="alert alert-danger py-2 mb-3"><?= esc($error) ?></div>
            <?php endif; ?>

            <form action="/manager/login" method="post">
                <?= csrf_field() ?>
                <div class="form-group mb-3">
                    <label>Email address</label>
                    <div class="input-group">
                        <input type="email" name="email" class="form-control"
                               value="<?= esc(old('email')) ?>" placeholder="admin@example.com" required autofocus>
                        <div class="input-group-append">
                            <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                        </div>
                    </div>
                </div>
                <div class="form-group mb-4">
                    <label>Password</label>
                    <div class="input-group">
                        <input type="password" name="password" class="form-control" placeholder="••••••••" required>
                        <div class="input-group-append">
                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-brand btn-block w-100">Sign In</button>
            </form>
        </div>
    </div>
    <p class="text-center mt-3" style="color:#444;font-size:.75rem">Dimensions 2.0 Manager &copy; <?= date('Y') ?></p>
</div>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/plugins/jquery/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
