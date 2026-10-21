<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';
require_once __DIR__ . '/includes/admin.php';

$user    = require_admin();
$active  = 'orders';
$pageKey = 'order';

$id = (int) get('id');
$stmt = db()->prepare('SELECT o.*, u.name AS merchant, u.email AS merchant_email, u.merchant_id FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ? LIMIT 1');
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    $pageHeading = 'Transaction not found';
    $pageTitle   = 'Not found';
    include __DIR__ . '/includes/header.php';
    echo '<div class="empty-state"><div class="empty-icon">' . icon('alert', 26) . '</div><p class="empty-state-title">Transaction not found</p><a class="btn btn-primary mt-4" href="' . url('admin/orders.php') . '">Back to transactions</a></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf_token'))) {
        $error = 'Session expired. Please try again.';
    } else {
        $action = post('action');

        if ($action === 'mark_paid') {
            $utr = trim(post('utr'));
            $err = admin_reconcile_order((string) $order['order_id'], $utr, null, (int) $user['id']);
            if ($err) {
                $error = $err;
            } else {
                flash_set('success', 'Order marked as settled and webhook dispatched.');
                redirect('admin/order.php?id=' . $id);
            }
        } elseif ($action === 'resend_webhook') {
            if ($order['status'] !== 'success') {
                $error = 'Webhooks can only be dispatched for settled orders.';
            } else {
                require_once APP_ROOT . '/includes/mail.php';
                fire_paid_webhook((int) $order['user_id'], $order);
                flash_set('success', 'Paid webhook dispatched to merchant endpoint.');
                redirect('admin/order.php?id=' . $id);
            }
        } elseif ($action === 'retry_log') {
            $err = admin_resend_webhook((string) $order['order_id']);
            if ($err) {
                $error = $err;
            } else {
                flash_set('success', 'Webhook retried.');
                redirect('admin/order.php?id=' . $id);
            }
        } elseif ($action === 'expire') {
            db()->prepare('UPDATE orders SET status = "expired" WHERE id = ?')->execute([$id]);
            flash_set('success', 'Order marked as expired.');
            redirect('admin/order.php?id=' . $id);
        } elseif ($action === 'reset') {
            db()->prepare('UPDATE orders SET status = "pending", paid_at = NULL, utr = NULL, bank_rrn = NULL WHERE id = ?')->execute([$id]);
            flash_set('success', 'Order reset to pending.');
            redirect('admin/order.php?id=' . $id);
        } elseif ($action === 'delete') {
            db()->prepare('DELETE FROM orders WHERE id = ?')->execute([$id]);
            flash_set('success', 'Order deleted.');
            redirect('admin/orders.php');
        }
    }
}

// Refresh order
$stmt = db()->prepare('SELECT o.*, u.name AS merchant, u.email AS merchant_email, u.merchant_id FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ? LIMIT 1');
$stmt->execute([$id]);
$order = $stmt->fetch();

$logStmt = db()->prepare('SELECT * FROM webhook_logs WHERE order_id = ? ORDER BY created_at DESC');
$logStmt->execute([$order['order_id']]);
$logs = $logStmt->fetchAll();

$pageTitle   = 'Order Forensic · ' . $order['order_id'];
$pageHeading = 'Transaction Inspection';
$pageSub     = 'Deep forensic telemetry for Order ID: ' . $order['order_id'];
$topbarActions = '<a class="btn btn-secondary btn-sm flex items-center gap-1" href="' . url('admin/orders.php') . '">' . icon('arrow-right', 13) . ' All Orders</a>';
include __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-error mb-4"><?= icon('alert', 18) ?><span><?= e($error) ?></span></div>
<?php endif; ?>
<?php if ($f = flash_get()): ?>
    <div class="alert alert-success mb-4"><?= icon('check-circle', 18) ?><span><?= e($f['message']) ?></span></div>
<?php endif; ?>

<!-- Top Order Banner -->
<div class="card mb-4" style="background:var(--color-bg-card);border:1px solid var(--color-border);">
    <div class="flex items-center justify-between" style="flex-wrap:wrap;gap:16px;">
        <div class="flex items-center gap-3">
            <div style="width:48px;height:48px;border-radius:12px;background:rgba(99,102,241,0.12);color:var(--color-primary);display:flex;align-items:center;justify-content:center;font-size:22px;">
                <?= icon('receipt', 22) ?>
            </div>
            <div>
                <div style="font-size:24px;font-weight:700;letter-spacing:-0.02em;color:var(--color-ink-base);"><?= money((float) $order['payable_amount']) ?></div>
                <div class="text-mute flex items-center gap-2" style="font-size:13px;">
                    <span class="mono">Order: <?= e($order['order_id']) ?></span>
                    <span>·</span>
                    <span>Created <?= e(nice_date($order['created_at'])) ?></span>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-2" style="flex-wrap:wrap;">
            <a class="btn btn-secondary btn-sm flex items-center gap-1" href="<?= url('admin/orders.php') ?>">
                <?= icon('arrow-left', 13) ?> All Orders
            </a>
            <?= status_badge((string) $order['status']) ?>
            <?php if ($order['status'] === 'success'): ?>
                <form method="post" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="resend_webhook">
                    <button class="btn btn-secondary btn-sm flex items-center gap-1" type="submit">
                        <?= icon('send', 13) ?> Fire Webhook Again
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="dash-grid" style="grid-template-columns:minmax(0,1fr) 360px;gap:20px;">
    <div>
        <!-- Payment Details Breakdown -->
        <div class="card">
            <div class="card-header"><div class="card-title"><?= icon('shield', 18) ?> Payment Telemetry</div></div>
            <div class="card-body" style="padding: 18px 22px 22px;">
                <div class="kv"><span class="kv-key">Order Identifier</span><span class="kv-val mono font-semibold"><?= e($order['order_id']) ?></span></div>
                <div class="kv"><span class="kv-key">Status</span><span class="kv-val"><?= status_badge((string) $order['status']) ?></span></div>
                <div class="kv"><span class="kv-key">Requested Amount</span><span class="kv-val tnum"><?= money((float) $order['amount']) ?></span></div>
                <div class="kv"><span class="kv-key">Payable Amount</span><span class="kv-val tnum font-semibold" style="color:var(--color-primary);"><?= money((float) $order['payable_amount']) ?></span></div>
                <div class="kv"><span class="kv-key">Merchant UPI VPA</span><span class="kv-val mono"><?= e($order['upi_id'] ?: '—') ?></span></div>
                <div class="kv"><span class="kv-key">Settlement UTR</span><span class="kv-val mono font-semibold" style="color:var(--color-success);"><?= e($order['utr'] ?: 'Pending UTR receipt') ?></span></div>
                <div class="kv"><span class="kv-key">Bank RRN</span><span class="kv-val mono"><?= e($order['bank_rrn'] ?: '—') ?></span></div>
                <div class="kv"><span class="kv-key">Merchant Webhook Endpoint</span><span class="kv-val mono" style="font-size:12px;max-width:320px;overflow:hidden;text-overflow:ellipsis;"><?= e($order['webhook_url'] ?: 'No webhook set') ?></span></div>
                <div class="kv"><span class="kv-key">Session Expiration</span><span class="kv-val"><?= e($order['expires_at'] ? nice_date($order['expires_at']) : 'Never expires') ?></span></div>
                <div class="kv"><span class="kv-key">Paid Timestamp</span><span class="kv-val"><?= e($order['paid_at'] ? nice_date($order['paid_at']) : 'Not settled') ?></span></div>
                <div class="kv" style="border-bottom:none;"><span class="kv-key">Created Timestamp</span><span class="kv-val"><?= e(nice_date($order['created_at'])) ?></span></div>
            </div>
        </div>

        <!-- Webhook Audit Trail -->
        <div class="card card-pad-0 mt-4">
            <div class="card-header flex items-center justify-between">
                <div class="card-title"><?= icon('send', 18) ?> Outgoing Webhook Deliveries</div>
                <?php if ($order['status'] === 'success'): ?>
                    <form method="post" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="resend_webhook">
                        <button class="btn btn-ghost btn-sm flex items-center gap-1" type="submit">
                            <?= icon('refresh', 12) ?> Re-dispatch
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if (!$logs): ?>
                <div class="empty-state" style="padding:40px 20px;">
                    <div class="empty-icon"><?= icon('send', 26) ?></div>
                    <p class="empty-state-title">No webhook attempts recorded</p>
                    <p class="empty-state-sub">Webhooks will be logged once the payment is settled.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Endpoint URL</th>
                                <th>Status</th>
                                <th>HTTP Code</th>
                                <th>Dispatched At</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $l): ?>
                            <tr>
                                <td data-label="Endpoint URL" class="mono" style="font-size:12px;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?= e($l['endpoint_url']) ?>
                                </td>
                                <td data-label="Status"><?= status_badge((string) $l['status']) ?></td>
                                <td data-label="HTTP Code">
                                    <span class="mono badge <?= (int)$l['response_code'] >= 200 && (int)$l['response_code'] < 300 ? 'badge-success' : 'badge-danger' ?>">
                                        <?= $l['response_code'] ?: 'ERR' ?>
                                    </span>
                                </td>
                                <td data-label="Dispatched At" class="text-mute" style="font-size:12px;"><?= e(nice_date($l['created_at'])) ?></td>
                                <td data-label="Action" style="text-align:right;">
                                    <form method="post" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="retry_log">
                                        <input type="hidden" name="log_id" value="<?= (int) $l['id'] ?>">
                                        <button class="btn btn-secondary btn-sm" type="submit">Retry</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <!-- Merchant & Customer Info -->
        <div class="card">
            <div class="card-header"><div class="card-title"><?= icon('user', 18) ?> Merchant & Customer</div></div>
            <div class="card-body" style="padding: 18px 22px 22px;">
                <div class="kv">
                    <span class="kv-key">Merchant</span>
                    <span class="kv-val font-semibold">
                        <a href="<?= url('admin/user.php?id=' . (int) $order['user_id']) ?>"><?= e($order['merchant']) ?></a>
                    </span>
                </div>
                <div class="kv"><span class="kv-key">Merchant Email</span><span class="kv-val" style="font-size:12px;"><?= e($order['merchant_email']) ?></span></div>
                <div class="kv"><span class="kv-key">Merchant ID</span><span class="kv-val mono"><?= e($order['merchant_id']) ?></span></div>
                <div class="kv"><span class="kv-key">Customer Name</span><span class="kv-val"><?= e($order['customer_name'] ?: 'Guest') ?></span></div>
                <div class="kv"><span class="kv-key">Customer Email</span><span class="kv-val" style="font-size:12px;"><?= e($order['customer_email'] ?: '—') ?></span></div>
                <div class="kv" style="border-bottom:none;"><span class="kv-key">Customer Mobile</span><span class="kv-val mono"><?= e($order['customer_contact'] ?: '—') ?></span></div>
            </div>
        </div>

        <!-- Payment QR Preview -->
        <?php if ($order['qr_url']): ?>
            <div class="card mt-4" style="text-align:center;">
                <div class="card-header"><div class="card-title"><?= icon('scan-line', 18) ?> Dynamic Payment QR</div></div>
                <div class="card-body" style="padding: 18px 22px 22px;">
                    <div style="background:#fff;padding:12px;border-radius:12px;display:inline-block;margin:0 auto 10px;border:1px solid var(--color-border);">
                        <img src="<?= e($order['qr_url']) ?>" alt="Payment QR" style="width:160px;height:160px;display:block;">
                    </div>
                    <div class="text-mute mono" style="font-size:11.5px;"><?= e($order['upi_id'] ?: '') ?></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Administrative Controls -->
        <div class="card mt-4">
            <div class="card-header"><div class="card-title"><?= icon('settings', 18) ?> Admin Controls</div></div>
            <div class="card-body" style="padding: 18px 22px 22px;">
                <?php if ($order['status'] !== 'success'): ?>
                    <form method="post" class="mb-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="mark_paid">
                        <div class="form-group mb-2">
                            <label class="form-label">Bank UTR / Settlement Ref</label>
                            <input class="form-input mono" name="utr" placeholder="e.g. 415922837103" required>
                        </div>
                        <button class="btn btn-primary btn-block flex items-center justify-center gap-1" type="submit">
                            <?= icon('check-circle', 14) ?> Mark Paid & Fire Webhook
                        </button>
                    </form>
                <?php endif; ?>

                <div class="flex flex-col gap-2">
                    <?php if ($order['status'] !== 'expired'): ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="expire">
                            <button class="btn btn-secondary btn-block" type="submit">Mark As Expired</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($order['status'] !== 'pending'): ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reset">
                            <button class="btn btn-secondary btn-block" type="submit">Reset to Pending</button>
                        </form>
                    <?php endif; ?>

                    <form method="post" onsubmit="return confirm('DANGER: Delete this order permanently?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <button class="btn btn-block" type="submit" style="background:var(--danger-bg);color:var(--danger-text);">
                            <?= icon('trash', 14) ?> Delete Order
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
