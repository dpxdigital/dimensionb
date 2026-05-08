<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>

<!-- Sub-nav -->
<div class="mb-3 d-flex gap-2">
    <a href="<?= site_url() ?>manager/marketplace" class="btn btn-sm btn-outline-secondary">← Vendors</a>
    <a href="<?= site_url() ?>manager/marketplace/products" class="btn btn-sm btn-outline-secondary">Products</a>
    <a href="<?= site_url() ?>manager/marketplace/orders" class="btn btn-sm btn-outline-secondary">Orders</a>
    <span class="btn btn-sm btn-secondary disabled">Payment Settings</span>
</div>

<div id="save-alert" style="display:none" class="alert py-2 mb-3"></div>

<!-- Activation Fee -->
<div class="card mb-4" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a">
        <h3 class="card-title mb-0" style="font-size:.9rem;color:#fff">Platform Activation Fee</h3>
        <small style="color:#888">One-time fee vendors pay to activate their storefront. Collected by the platform via Stripe.</small>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label" style="color:#ccc;font-size:.82rem">Fee Amount</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text" style="background:#2a2a2a;border-color:#333;color:#aaa">$</span>
                    <input type="number" id="activation_fee_amount" step="0.01" min="0"
                           class="form-control form-control-sm" value="<?= esc($activation_fee_amount) ?>"
                           style="background:#1E1E1E;border-color:#333;color:#fff">
                </div>
                <small style="color:#666">Set to 0 to disable the fee requirement.</small>
            </div>
            <div class="col-md-3">
                <label class="form-label" style="color:#ccc;font-size:.82rem">Currency</label>
                <select id="activation_fee_currency" class="form-select form-select-sm"
                        style="background:#1E1E1E;border-color:#333;color:#fff">
                    <?php foreach (['usd' => 'USD', 'gbp' => 'GBP', 'eur' => 'EUR', 'ngn' => 'NGN'] as $code => $label): ?>
                    <option value="<?= $code ?>" <?= $activation_fee_currency === $code ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
</div>

<!-- Platform Stripe Keys -->
<div class="card mb-4" style="background:#161616;border:1px solid #2a2a2a">
    <div class="card-header" style="background:transparent;border-bottom:1px solid #2a2a2a">
        <h3 class="card-title mb-0" style="font-size:.9rem;color:#fff">Platform Stripe Keys</h3>
        <small style="color:#888">Used to charge vendors the activation fee. These are <strong style="color:#D94032">platform</strong> credentials, not vendor credentials.</small>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" style="color:#ccc;font-size:.82rem">Publishable Key <span style="color:#666">(pk_live_…)</span></label>
                <input type="text" id="platform_stripe_key" class="form-control form-control-sm"
                       value="<?= esc($platform_stripe_key) ?>"
                       placeholder="pk_live_..."
                       style="background:#1E1E1E;border-color:#333;color:#fff;font-family:monospace;font-size:.78rem">
            </div>
            <div class="col-md-6">
                <label class="form-label" style="color:#ccc;font-size:.82rem">Secret Key <span style="color:#666">(sk_live_…)</span></label>
                <input type="password" id="platform_stripe_secret" class="form-control form-control-sm"
                       placeholder="sk_live_… (leave blank to keep current)"
                       style="background:#1E1E1E;border-color:#333;color:#fff;font-family:monospace;font-size:.78rem">
            </div>
            <div class="col-md-6">
                <label class="form-label" style="color:#ccc;font-size:.82rem">Webhook Signing Secret <span style="color:#666">(whsec_…)</span></label>
                <input type="password" id="platform_stripe_webhook_secret" class="form-control form-control-sm"
                       placeholder="whsec_… (leave blank to keep current)"
                       style="background:#1E1E1E;border-color:#333;color:#fff;font-family:monospace;font-size:.78rem">
            </div>
        </div>

        <div class="mt-3 p-3" style="background:#1a1a1a;border-radius:6px;border:1px solid #2a2a2a">
            <div style="font-size:.78rem;color:#888">
                <strong style="color:#aaa">Stripe Webhook URL</strong><br>
                <code style="color:#D94032;font-size:.75rem"><?= base_url('v1/marketplace/activation/webhook') ?></code><br>
                <span style="font-size:.72rem">Register in Stripe Dashboard → Developers → Webhooks. Listen for: <code>checkout.session.completed</code></span>
            </div>
        </div>
    </div>
</div>

<div class="d-flex gap-2 align-items-center">
    <button id="save-btn" onclick="saveSettings()" class="btn btn-sm btn-danger">Save Settings</button>
    <span id="save-spinner" style="display:none;font-size:.8rem;color:#888">Saving…</span>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
const BASE = '<?= rtrim(site_url(), '/') ?>';
function saveSettings() {
    const btn     = document.getElementById('save-btn');
    const spinner = document.getElementById('save-spinner');
    const alert   = document.getElementById('save-alert');

    btn.disabled = true;
    spinner.style.display = 'inline';
    alert.style.display   = 'none';

    const payload = {
        activation_fee_amount:           document.getElementById('activation_fee_amount').value,
        activation_fee_currency:         document.getElementById('activation_fee_currency').value,
        platform_stripe_key:             document.getElementById('platform_stripe_key').value,
        platform_stripe_secret:          document.getElementById('platform_stripe_secret').value,
        platform_stripe_webhook_secret:  document.getElementById('platform_stripe_webhook_secret').value,
    };

    fetch(BASE + '/manager/marketplace/payment-settings/save', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body:    JSON.stringify(payload),
    })
    .then(r => r.json())
    .then(d => {
        btn.disabled      = false;
        spinner.style.display = 'none';
        if (d.error) {
            alert.className   = 'alert alert-danger py-2 mb-3';
            alert.textContent = d.error;
        } else {
            alert.className   = 'alert alert-success py-2 mb-3';
            alert.textContent = d.message || 'Settings saved.';
            // Update displayed pk field
            if (d.platform_stripe_key !== undefined) {
                document.getElementById('platform_stripe_key').value = d.platform_stripe_key;
            }
            // Clear secret fields
            document.getElementById('platform_stripe_secret').value         = '';
            document.getElementById('platform_stripe_webhook_secret').value = '';
        }
        alert.style.display = 'block';
        window.scrollTo(0, 0);
    })
    .catch(err => {
        btn.disabled          = false;
        spinner.style.display = 'none';
        alert.className       = 'alert alert-danger py-2 mb-3';
        alert.textContent     = 'Network error. Please try again.';
        alert.style.display   = 'block';
    });
}
</script>
<?= $this->endSection() ?>
