<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';

$user   = require_login();
$active = 'transactions';

$id = (int) get('id');
$stmt = db()->prepare('SELECT * FROM orders WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$id, $user['id']]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    $pageTitle       = 'Transaction Not Found';
    $pageHeading     = 'Not Found';
    $pageSub         = 'The requested order could not be located.';
    $hidePageHeading = true;
    include __DIR__ . '/includes/header.php';
    echo '<div class="dash-white-card" style="text-align:center;padding:60px 20px;">
            <div style="background:rgba(239,68,68,0.1);color:#ef4444;width:56px;height:56px;border-radius:18px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:14px;">
                ' . icon('alert', 28) . '
            </div>
            <h2 style="font-size:18px;font-weight:700;color:#0f172a;margin-bottom:6px;">Transaction Not Found</h2>
            <p style="color:#64748b;font-size:13.5px;max-width:380px;margin:0 auto 20px;">The order either does not exist or belongs to another merchant account.</p>
            <a class="btn btn-primary" href="' . url('user/transactions.php') . '" style="display:inline-flex;align-items:center;gap:6px;">
                ' . icon('arrow-right', 13) . ' Return to Transactions Feed
            </a>
          </div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle       = 'Order #' . $order['order_id'];
$pageHeading     = 'Details';
$pageSub         = 'Order audit receipt and settlement metadata';
$hidePageHeading = true;
include __DIR__ . '/includes/header.php';

$isSucc = ($order['status'] === 'success');
?>

<!-- =========================================================
     DARK HERO STAGE ("Kahi Kahi Pe Dark UI")
     ========================================================= -->
<div class="dash-hero-stage-dark" data-reveal>
    <div class="hero-stage-mesh-top" aria-hidden="true"></div>
    <div class="hero-stage-mesh-bottom" aria-hidden="true"></div>

    <div class="hero-stage-header">
        <div class="hero-eyebrow">
            <span class="dot" style="<?= $isSucc ? '' : 'background:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,0.2);' ?>"></span>
            <span>Receipt &middot; Audit Trail</span>
        </div>
        <div class="hero-sla-pill">
            <span class="sla-dot"></span>
            <span>Recorded on <?= e(nice_date($order['created_at'])) ?></span>
        </div>
    </div>

    <div class="hero-stage-headline">
        <h1 class="dash-hero-heading">
            Order <span class="accent">#<?= e($order['order_id']) ?></span>
        </h1>
        <p class="dash-hero-desc">
            Direct ledger audit record with cryptographic verification trail and customer payment metadata.
        </p>
    </div>

    <div class="hero-meta-ribbon">
        <div class="meta-ribbon-cell" title="Order amount payable">
            <div class="meta-cell-label">Amount Payable</div>
            <div class="meta-cell-value">
                <span class="val-money" style="color:<?= $isSucc ? '#4ade80' : '#ffffff' ?>;"><?= money((float) $order['payable_amount']) ?></span>
            </div>
        </div>

        <div class="meta-ribbon-cell" title="Transaction settlement status">
            <div class="meta-cell-label">Payment Status</div>
            <div class="meta-cell-value">
                <?= status_badge((string) $order['status']) ?>
            </div>
        </div>

        <div class="meta-ribbon-cell" title="Bank UTR reference number">
            <div class="meta-cell-label">Bank UTR</div>
            <div class="meta-cell-value">
                <?php if (!empty($order['utr'])): ?>
                    <span class="val-upi"><?= e($order['utr']) ?></span>
                    <button type="button" class="meta-copy-btn" data-copy="<?= e($order['utr']) ?>" aria-label="Copy UTR">
                        <?= icon('copy', 12) ?>
                    </button>
                <?php else: ?>
                    <span style="color:#94a3b8;font-weight:500;font-size:11.5px;">Pending Verification</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="meta-ribbon-cell" title="Settlement destination">
            <div class="meta-cell-label">Settlement Mode</div>
            <div class="meta-cell-value">
                <span class="meta-badge-ok"><span class="mini-beacon"></span> Direct UPI Credit</span>
            </div>
        </div>
    </div>

    <div class="hero-stage-btns">
        <a href="<?= url('user/transactions.php') ?>" class="btn-hero-secondary">
            &larr; Back to Feed
        </a>
        <?php if (!empty($order['link_id'])): ?>
        <a href="<?= url('pay.php?link=' . e($order['link_id'])) ?>" target="_blank" class="btn-hero-primary">
            <?= icon('external', 14) ?> Checkout Page
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- =========================================================
     DETAILS SPLIT GRID
     ========================================================= -->
<div class="dash-split-grid">
    <!-- Main Column: Payment Metadata -->
    <div class="dash-col-main">
        <div class="tx-receipt-card" data-reveal>
            <div class="tx-receipt-header">
                <div>
                    <h3 class="tx-receipt-title">
                        <span style="color:#2563eb;display:inline-flex;"><?= icon('receipt', 19) ?></span>
                        Payment Details
                    </h3>
                </div>
                <div>
                    <?= status_badge((string) $order['status']) ?>
                </div>
            </div>

            <!-- Hero Amount Banner -->
            <div class="tx-amount-hero-banner <?= $isSucc ? 'is-success' : ($order['status'] === 'pending' ? 'is-pending' : 'is-failed') ?>">
                <div>
                    <div class="tx-amount-hero-label">
                        <?= $isSucc ? 'Settled Amount' : 'Payable Amount' ?>
                    </div>
                    <div class="tx-amount-hero-value">
                        <?= money((float) $order['payable_amount']) ?>
                    </div>
                </div>
                <div>
                    <?php if ($isSucc): ?>
                        <span class="badge-clean success" style="font-size:12px;padding:6px 12px;">
                            <?= icon('check', 13) ?> Settled to UPI
                        </span>
                    <?php elseif ($order['status'] === 'pending'): ?>
                        <span class="badge-clean pending" style="font-size:12px;padding:6px 12px;">
                            <?= icon('clock', 13) ?> Awaiting Bank Settlement
                        </span>
                    <?php else: ?>
                        <span class="badge-clean failed" style="font-size:12px;padding:6px 12px;">
                            <?= icon('alert', 13) ?> <?= ucfirst(e($order['status'])) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tx-detail-list">
                <div class="tx-detail-row">
                    <span class="tx-detail-key">
                        <?= icon('hash', 13, 'style="color:#64748b;"') ?> Order ID
                    </span>
                    <span class="tx-detail-val">
                        <span class="mono" style="font-weight:700;letter-spacing:0.02em;">#<?= e($order['order_id']) ?></span>
                        <button type="button" class="dark-chip-copy" data-copy="<?= e($order['order_id']) ?>" title="Copy Order ID">
                            <?= icon('copy', 12) ?>
                        </button>
                    </span>
                </div>

                <div class="tx-detail-row">
                    <span class="tx-detail-key">
                        <?= icon('activity', 13, 'style="color:#64748b;"') ?> Settlement Status
                    </span>
                    <span class="tx-detail-val"><?= status_badge((string) $order['status']) ?></span>
                </div>

                <div class="tx-detail-row">
                    <span class="tx-detail-key">
                        <?= icon('wallet', 13, 'style="color:#64748b;"') ?> Payable Amount
                    </span>
                    <span class="tx-detail-val <?= $isSucc ? 'text-success' : '' ?>" style="font-family:'Sora',sans-serif;font-size:15px;font-weight:700;<?= $isSucc ? 'color:#059669;' : '' ?>">
                        <?= money((float) $order['payable_amount']) ?>
                    </span>
                </div>

                <?php if ((float) $order['payable_amount'] !== (float) $order['amount']): ?>
                <div class="tx-detail-row">
                    <span class="tx-detail-key">
                        <?= icon('tag', 13, 'style="color:#64748b;"') ?> Original Base Amount
                    </span>
                    <span class="tx-detail-val mono"><?= money((float) $order['amount']) ?></span>
                </div>
                <?php endif; ?>

                <div class="tx-detail-row">
                    <span class="tx-detail-key">
                        <?= icon('arrow-right', 13, 'style="color:#2563eb;"') ?> Destination UPI VPA
                    </span>
                    <span class="tx-detail-val">
                        <span class="mono" style="color:#2563eb;font-weight:700;"><?= e($order['upi_id'] ?: '—') ?></span>
                        <?php if (!empty($order['upi_id'])): ?>
                            <button type="button" class="dark-chip-copy" data-copy="<?= e($order['upi_id']) ?>" title="Copy UPI VPA">
                                <?= icon('copy', 12) ?>
                            </button>
                        <?php endif; ?>
                    </span>
                </div>

                <div class="tx-detail-row">
                    <span class="tx-detail-key">
                        <?= icon('zap', 13, 'style="color:#64748b;"') ?> Bank UTR Reference
                    </span>
                    <span class="tx-detail-val">
                        <?php if (!empty($order['utr'])): ?>
                            <span class="mono" style="font-weight:700;color:#059669;background:rgba(16,185,129,0.08);padding:3px 8px;border-radius:6px;"><?= e($order['utr']) ?></span>
                            <button type="button" class="dark-chip-copy" data-copy="<?= e($order['utr']) ?>" title="Copy UTR">
                                <?= icon('copy', 12) ?>
                            </button>
                        <?php else: ?>
                            <span style="color:#94a3b8;font-style:italic;font-size:12px;">Not submitted yet</span>
                        <?php endif; ?>
                    </span>
                </div>

                <?php if (!empty($order['bank_rrn'])): ?>
                <div class="tx-detail-row">
                    <span class="tx-detail-key">
                        <?= icon('check', 13, 'style="color:#64748b;"') ?> Bank RRN
                    </span>
                    <span class="tx-detail-val mono"><?= e($order['bank_rrn']) ?></span>
                </div>
                <?php endif; ?>

                <div class="tx-detail-row">
                    <span class="tx-detail-key">
                        <?= icon('clock', 13, 'style="color:#64748b;"') ?> Created Timestamp
                    </span>
                    <span class="tx-detail-val" style="font-family:'JetBrains Mono',monospace;font-size:12px;">
                        <?= e(nice_date($order['created_at'])) ?>
                    </span>
                </div>

                <div class="tx-detail-row">
                    <span class="tx-detail-key">
                        <?= icon('check-circle', 13, 'style="color:#64748b;"') ?> Paid Timestamp
                    </span>
                    <span class="tx-detail-val <?= $isSucc ? 'text-success' : '' ?>" style="font-family:'JetBrains Mono',monospace;font-size:12px;<?= $isSucc ? 'color:#059669;font-weight:700;' : '' ?>">
                        <?= $order['paid_at'] ? e(nice_date($order['paid_at'])) : '—' ?>
                    </span>
                </div>

                <div class="tx-detail-row">
                    <span class="tx-detail-key">
                        <?= icon('alert', 13, 'style="color:#64748b;"') ?> Expiry Timestamp
                    </span>
                    <span class="tx-detail-val" style="font-family:'JetBrains Mono',monospace;font-size:12px;">
                        <?= $order['expires_at'] ? e(nice_date($order['expires_at'])) : 'Never expires' ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Side Column: Customer Metadata & Actions -->
    <div class="dash-col-side">
        <!-- Customer Details -->
        <?php
        $custName = trim((string) ($order['customer_name'] ?: ($order['sender_name'] ?: 'Direct Payment')));
        $words = preg_split('/\s+/', $custName);
        $initials = '';
        if (!empty($words[0])) { $initials .= mb_strtoupper(mb_substr($words[0], 0, 1)); }
        if (!empty($words[1])) { $initials .= mb_strtoupper(mb_substr($words[1], 0, 1)); }
        if ($initials === '') { $initials = 'DP'; }
        ?>
        <div class="tx-receipt-card" data-reveal>
            <div class="tx-receipt-header">
                <div>
                    <h3 class="tx-receipt-title">
                        <span style="color:#2563eb;display:inline-flex;"><?= icon('user', 19) ?></span>
                        Customer Details
                    </h3>
                </div>
            </div>

            <!-- Customer Avatar Identity Block -->
            <div class="tx-cust-avatar-row">
                <div class="tx-cust-avatar-circle">
                    <?= e($initials) ?>
                </div>
                <div class="tx-cust-avatar-info">
                    <div class="tx-cust-avatar-name"><?= e($custName) ?></div>
                    <div class="tx-cust-avatar-meta">Customer Record</div>
                </div>
            </div>

            <div class="tx-detail-list">
                <div class="tx-detail-row">
                    <span class="tx-detail-key">Contact</span>
                    <span class="tx-detail-val">
                        <span class="mono"><?= e($order['customer_contact'] ?: '—') ?></span>
                        <?php if (!empty($order['customer_contact'])): ?>
                            <button type="button" class="dark-chip-copy" data-copy="<?= e($order['customer_contact']) ?>" title="Copy Phone">
                                <?= icon('copy', 11) ?>
                            </button>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="tx-detail-row">
                    <span class="tx-detail-key">Email</span>
                    <span class="tx-detail-val">
                        <?php if (!empty($order['customer_email'])): ?>
                            <a href="mailto:<?= e($order['customer_email']) ?>" style="color:#2563eb;text-decoration:none;">
                                <?= e($order['customer_email']) ?>
                            </a>
                        <?php else: ?>
                            <span>—</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>

        <?php if (!empty($order['qr_url'])): ?>
        <!-- Dynamic Payment QR -->
        <div class="tx-receipt-card" style="text-align:center;" data-reveal>
            <div class="tx-receipt-header" style="justify-content:center;">
                <h3 class="tx-receipt-title" style="display:flex;align-items:center;gap:6px;">
                    <?= icon('zap', 17) ?> Dynamic UPI QR
                </h3>
            </div>
            <div class="qr-preserve-white" style="background:#ffffff;padding:12px;border-radius:16px;border:1.5px solid #e2e8f0;display:inline-block;box-shadow:0 4px 12px rgba(0,0,0,0.06);">
                <img src="<?= e($order['qr_url']) ?>" alt="UPI QR" style="width:160px;height:160px;display:block;border-radius:8px;">
            </div>
            <p style="font-size:11.5px;color:#64748b;margin-top:8px;">Scannable via any UPI app</p>
        </div>
        <?php endif; ?>

        <!-- Quick Action Buttons -->
        <div class="tx-receipt-card" style="padding:16px;" data-reveal>
            <button type="button" onclick="window.print()" class="btn btn-secondary btn-block" style="width:100%;height:42px;display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:10px;font-weight:600;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                Print / Save Receipt
            </button>
            <a href="<?= url('user/transactions.php') ?>" class="btn btn-primary btn-block" style="width:100%;height:42px;display:flex;align-items:center;justify-content:center;gap:6px;font-weight:600;">
                &larr; Back to Transactions
            </a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>