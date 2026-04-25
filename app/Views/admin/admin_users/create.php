<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card" style="background:#161616;border:1px solid #2a2a2a">
            <div class="card-header" style="background:transparent;border-color:#2a2a2a">
                <h3 class="card-title" style="color:#fff;font-size:.95rem">New Admin User</h3>
            </div>
            <div class="card-body">
                <?php if ($error = session()->getFlashdata('error')): ?>
                <div class="alert alert-danger py-2" style="background:rgba(217,64,50,.15);border-color:#D94032;color:#f88;font-size:.85rem">
                    <?= esc($error) ?>
                </div>
                <?php endif; ?>

                <form method="post" action="/manager/admin-users/create">
                    <?= csrf_field() ?>

                    <div class="form-group">
                        <label style="color:#aaa;font-size:.82rem">Full Name</label>
                        <input type="text" name="name" class="form-control"
                               style="background:#1E1E1E;border-color:#333;color:#fff"
                               value="<?= esc(old('name')) ?>" required>
                    </div>

                    <div class="form-group">
                        <label style="color:#aaa;font-size:.82rem">Email Address</label>
                        <input type="email" name="email" class="form-control"
                               style="background:#1E1E1E;border-color:#333;color:#fff"
                               value="<?= esc(old('email')) ?>" required>
                    </div>

                    <div class="form-group">
                        <label style="color:#aaa;font-size:.82rem">Password <span style="color:#666">(min 8 characters)</span></label>
                        <input type="password" name="password" class="form-control"
                               style="background:#1E1E1E;border-color:#333;color:#fff"
                               minlength="8" required>
                    </div>

                    <div class="form-group">
                        <label style="color:#aaa;font-size:.82rem">Role</label>
                        <select name="role" class="form-control" style="background:#1E1E1E;border-color:#333;color:#fff">
                            <option value="moderator" <?= old('role') === 'moderator' ? 'selected' : '' ?>>Moderator</option>
                            <option value="super_admin" <?= old('role') === 'super_admin' ? 'selected' : '' ?>>Super Admin</option>
                        </select>
                        <small style="color:#666">Moderators can only access moderation and chat. Super admins have full access.</small>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-brand">Create Admin User</button>
                        <a href="/manager/admin-users" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
