<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/admin-ops.php';

$user   = require_admin();
$active = 'risk';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf_token'))) {
        $error = 'Session expired. Please try again.';
    } else {
        $action = post('action');
        $id     = (int) post('id');

        if ($action === 'add_blacklist') {
            $value = post('value');
            if ($value === '') {
                $error = 'A value is required.';
            } else {
                $type = in_array(post('entry_type'), ['upi','email','ip','phone','card'], true) ? post('entry_type') : 'upi';
                try {
                    db()->prepare('INSERT INTO blacklist_entries (entry_type, value, reason) VALUES (?, ?, ?)')
                        ->execute([$type, $value, post('reason')]);
                    ops_audit('risk.blacklist.add', 'blacklist', $value, "Added {$type} to blacklist");
                    flash_set('success', 'Entry added to blacklist.');
                } catch (Throwable $e) {
                    $error = 'That entry already exists in the blacklist.';
                }
                if (!$error) { redirect('admin/risk.php'); }
            }
        } elseif ($action === 'delete_blacklist') {
            db()->prepare('DELETE FROM blacklist_entries WHERE id = ?')->execute([$id]);
            ops_audit('risk.blacklist.delete', 'blacklist', $id, 'Removed blacklist entry');
            flash_set('success', 'Blacklist entry removed.');
            redirect('admin/risk.php');
        }
    }
}

$blacklist = db()->query('SELECT * FROM blacklist_entries ORDER BY created_at DESC')->fetchAll();

$pageTitle   = 'Risk & Fraud';
$pageHeading = 'Risk & Fraud Control';
$pageSub     = 'Blocked UPI IDs, emails, IPs and phone numbers across the platform.';
include __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-error mb-4"><?= icon('alert', 18) ?><span><?= e($error) ?></span></div>
<?php endif; ?>
<?php if ($f = flash_get()): ?>
    <div class="alert alert-success mb-4"><?= icon('check-circle', 18) ?><span><?= e($f['message']) ?></span></div>
<?php endif; ?>

<div class="admin-kpi-grid mb-4" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
    <div class="admin-kpi-card tone-rose"><div class="admin-kpi-header"><span class="admin-kpi-label">Blacklist Entries</span><span class="admin-kpi-icon"><?= icon('alert', 18) ?></span></div><div class="admin-kpi-value tnum"><?= count($blacklist) ?></div><div class="admin-kpi-footer"><span class="admin-kpi-sub">Blocked identifiers</span></div></div>
    <div class="admin-kpi-card tone-amber">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Rate Limit Throttles</span>
            <span class="admin-kpi-icon"><?= icon('clock', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= security_count_active_limits() ?></div>
        <div class="admin-kpi-footer">
            <a href="<?= url('admin/settings.php') ?>" style="color:var(--color-primary);font-size:12px;font-weight:600;text-decoration:none;">
                Configure Rate Limits &rarr;
            </a>
        </div>
    </div>
</div>

<div class="card card-pad-0">
    <div class="card-header flex items-center justify-between">
        <h3 class="card-title flex items-center gap-2"><span style="color:#dc2626;"><?= icon('alert', 18) ?></span> Blacklist</h3>
        <button class="btn btn-primary btn-sm flex items-center gap-1" onclick="openAdminModal('modal-blacklist')"><?= icon('plus', 14) ?> Add Entry</button>
    </div>
    <?php if (!$blacklist): ?>
        <div class="empty-state" style="padding:44px 20px;"><div class="empty-icon"><?= icon('alert', 26) ?></div><p class="empty-state-title">Blacklist empty</p></div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead><tr><th>Type</th><th>Value</th><th>Reason</th><th>Added</th><th style="text-align:right;"></th></tr></thead>
            <tbody>
            <?php foreach ($blacklist as $b): ?>
                <tr>
                    <td data-label="Type"><span class="badge badge-neutral"><?= e(strtoupper($b['entry_type'])) ?></span></td>
                    <td data-label="Value" class="mono" style="font-size:12px;"><?= e($b['value']) ?></td>
                    <td data-label="Reason" class="text-mute" style="font-size:12px;"><?= e($b['reason'] ?: '—') ?></td>
                    <td data-label="Added" class="text-mute" style="font-size:12px;"><?= e(date('d M Y', strtotime((string) $b['created_at']))) ?></td>
                    <td data-label="Actions" style="text-align:right;">
                        <form method="post" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="action" value="delete_blacklist"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><button class="btn btn-ghost btn-sm" style="color:#dc2626;" type="submit">Remove</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div id="modal-blacklist" class="admin-modal-overlay">
    <div class="admin-modal-dialog">
        <div class="admin-modal-head"><h3>Add Blacklist Entry</h3><button type="button" class="admin-modal-close" onclick="closeAdminModal('modal-blacklist')"><?= icon('x', 16) ?></button></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_blacklist">
            <div class="admin-modal-body">
                <div class="dash-grid" style="grid-template-columns:1fr 1.6fr;gap:14px;">
                    <div class="form-group">
                        <label class="form-label">Type</label>
                        <select class="form-select" name="entry_type"><option value="upi">UPI / VPA</option><option value="email">Email</option><option value="ip">IP Address</option><option value="phone">Phone</option><option value="card">Card</option></select>
                    </div>
                    <div class="form-group"><label class="form-label">Value *</label><input class="form-input mono" type="text" name="value" placeholder="fraud@okaxis" required></div>
                </div>
                <div class="form-group" style="margin-top:14px;"><label class="form-label">Reason</label><input class="form-input" type="text" name="reason" placeholder="e.g. Chargeback fraud"></div>
            </div>
            <div class="admin-modal-foot">
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeAdminModal('modal-blacklist')">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm">Add to Blacklist</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
