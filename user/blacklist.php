<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';

$user   = require_login();
$active = 'blacklist';

user_blacklist_ensure_table();

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf(post('csrf_token'))) {
        $error = 'Session expired. Please try again.';
    } else {
        $action = post('action');

        if ($action === 'add') {
            $type   = in_array(post('entry_type'), ['upi', 'email', 'phone'], true) ? post('entry_type') : 'email';
            $value  = trim((string) post('value'));
            $reason = trim((string) post('reason'));

            if ($value === '') {
                $error = 'Enter a value to block.';
            } elseif ($type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $error = 'Enter a valid email address.';
            } elseif ($type === 'phone' && !preg_match('/^[0-9+\-\s]{6,20}$/', $value)) {
                $error = 'Enter a valid phone number.';
            } elseif ($type === 'upi' && (str_contains($value, ' ') || !str_contains($value, '@'))) {
                $error = 'Enter a valid UPI ID (e.g. name@okaxis).';
            } elseif (!user_blacklist_add((int) $user['id'], $type, $value, $reason !== '' ? $reason : null)) {
                $error = 'That entry is already on your blacklist.';
            } else {
                flash_set('success', 'Customer added to your blacklist. New orders from this identifier will be blocked.');
                redirect('user/blacklist.php');
            }
        } elseif ($action === 'delete') {
            $id = (int) post('id');
            db()->prepare('DELETE FROM user_blacklist WHERE id = ? AND user_id = ?')->execute([$id, $user['id']]);
            flash_set('success', 'Blacklist entry removed.');
            redirect('user/blacklist.php');
        }
    }
}

$entries = db()->prepare('SELECT * FROM user_blacklist WHERE user_id = ? ORDER BY created_at DESC');
$entries->execute([$user['id']]);
$entries = $entries->fetchAll();

$typeCounts = ['email' => 0, 'phone' => 0, 'upi' => 0];
foreach ($entries as $row) {
    if (isset($typeCounts[$row['entry_type']])) {
        $typeCounts[$row['entry_type']]++;
    }
}

$pageTitle       = 'Blacklist';
$pageHeading     = 'Blacklist';
$pageSub         = 'Block abusive customers so their new payment orders are rejected automatically.';
$hidePageHeading = true;
include __DIR__ . '/includes/header.php';
?>

<div class="dash-hero-stage-dark" data-reveal>
    <div class="hero-stage-mesh-top" aria-hidden="true"></div>
    <div class="hero-stage-mesh-bottom" aria-hidden="true"></div>

    <div class="hero-stage-header">
        <div class="hero-eyebrow">
            <span class="dot"></span>
            <span>Merchant Shield &middot; Customer Blacklist</span>
        </div>
        <div class="hero-sla-pill">
            <span class="sla-dot"></span>
            <span>Enforced at Order Creation</span>
        </div>
    </div>

    <div class="hero-stage-headline">
        <h1 class="dash-hero-heading">
            Merchant <span class="accent">Blacklist</span>
        </h1>
        <p class="dash-hero-desc">
            Block specific customer emails, phone numbers and UPI IDs from creating new orders.
        </p>
    </div>

    <div class="hero-meta-ribbon">
        <div class="meta-ribbon-cell" title="Total blocked identifiers">
            <div class="meta-cell-label">Total Blocked</div>
            <div class="meta-cell-value"><span class="val-accent"><?= count($entries) ?></span></div>
        </div>
        <div class="meta-ribbon-cell" title="Blocked email addresses">
            <div class="meta-cell-label">Emails</div>
            <div class="meta-cell-value"><span class="val-rate"><?= $typeCounts['email'] ?></span></div>
        </div>
        <div class="meta-ribbon-cell" title="Blocked phone numbers">
            <div class="meta-cell-label">Phones</div>
            <div class="meta-cell-value"><span class="val-rate"><?= $typeCounts['phone'] ?></span></div>
        </div>
        <div class="meta-ribbon-cell" title="Blocked UPI IDs">
            <div class="meta-cell-label">UPI IDs</div>
            <div class="meta-cell-value"><span class="val-rate"><?= $typeCounts['upi'] ?></span></div>
        </div>
    </div>
</div>

<?php if ($error): ?>
    <div class="dash-alert dash-alert-error" data-reveal style="margin-bottom:22px !important;">
        <div class="alert-icon-wrap"><?= icon('alert', 18) ?></div>
        <div class="alert-text-wrap"><strong>Notice:</strong> <?= e($error) ?></div>
    </div>
<?php endif; ?>

<?php if ($f = flash_get()): ?>
    <div class="dash-alert dash-alert-success" data-reveal style="margin-bottom:22px !important;">
        <div class="alert-icon-wrap"><?= icon('check-circle', 18) ?></div>
        <div class="alert-text-wrap"><strong>Success:</strong> <?= e($f['message']) ?></div>
    </div>
<?php endif; ?>

<div class="card" style="padding:22px 24px;margin-bottom:20px;" data-reveal>
    <h3 class="card-title" style="margin:0 0 16px;display:flex;align-items:center;gap:8px;">
        <span style="color:#dc2626;"><?= icon('blocks', 18) ?></span> Add to Blacklist
    </h3>
    <form method="post" action="<?= url('user/blacklist.php') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">

        <div style="display:grid;grid-template-columns:0.9fr 1.4fr 1.4fr auto;gap:14px;align-items:end;">
            <div class="form-group">
                <label class="form-label">Type</label>
                <select class="form-select" name="entry_type">
                    <option value="email">Email</option>
                    <option value="phone">Phone</option>
                    <option value="upi">UPI ID</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Value *</label>
                <input class="form-input" name="value" placeholder="customer@email.com" required value="<?= e(post('value')) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Reason</label>
                <input class="form-input" name="reason" placeholder="e.g. Repeated chargeback" value="<?= e(post('reason')) ?>">
            </div>
            <div>
                <button class="btn btn-primary" type="submit" style="display:inline-flex;align-items:center;gap:6px;">
                    <?= icon('plus', 15) ?> Block
                </button>
            </div>
        </div>
        <p class="form-hint" style="margin-top:10px;">
            Orders created through the API whose customer email or phone matches an entry here are rejected with HTTP 403.
        </p>
    </form>
</div>

<div class="card" style="padding:0;overflow:hidden;" data-reveal>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid var(--color-hairline);">
        <h3 class="card-title" style="margin:0;display:flex;align-items:center;gap:8px;">
            <span style="color:#dc2626;"><?= icon('shield', 18) ?></span> Blocked Customers
        </h3>
        <span class="badge"><?= count($entries) ?> <?= count($entries) === 1 ? 'entry' : 'entries' ?></span>
    </div>

    <?php if (!$entries): ?>
        <div class="empty-state" style="padding:52px 20px;">
            <div class="empty-icon"><?= icon('shield', 28) ?></div>
            <p class="empty-state-title">No blocked customers yet</p>
            <p class="empty-state-desc">Use the form above to block a customer by email, phone or UPI ID.</p>
        </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr><th>Type</th><th>Value</th><th>Reason</th><th>Added</th><th style="text-align:right;"></th></tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $row): ?>
                <tr>
                    <td><span class="badge"><?= e(strtoupper($row['entry_type'])) ?></span></td>
                    <td class="mono" style="font-size:12.5px;"><?= e($row['value']) ?></td>
                    <td class="text-mute" style="font-size:12.5px;"><?= $row['reason'] !== null && $row['reason'] !== '' ? e($row['reason']) : '&mdash;' ?></td>
                    <td class="text-mute" style="font-size:12.5px;"><?= e(date('d M Y', strtotime((string) $row['created_at']))) ?></td>
                    <td style="text-align:right;">
                        <form method="post" action="<?= url('user/blacklist.php') ?>" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <button class="btn btn-ghost btn-sm" type="submit" style="color:#dc2626;">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
