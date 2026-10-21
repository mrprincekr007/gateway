<?php
declare(strict_types=1);

/* =========================================================
   FatherGateway — Hosted Checkout Terminal
   Dual-Screen Experience (PC Terminal & Mobile Optimized)
   - Dynamic UPI QR Code (Zero UPI ID / VPA text leaks)
   - Real-Time Sub-Second IMAP Payment Reconciliation
   - Brand Logo & Gateway Identity
   - Pure Vector SVG (Zero text emojis)
   ========================================================= */

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/qr.php';

/** Create a fresh 5-minute pending order for a reusable payment link. */
function pay_new_order(array $link): ?array
{
    if (security_is_blacklisted('upi', (string) ($link['merchant_upi'] ?? ''))) {
        return null;
    }
    if (!security_check_order_create_rate(security_ip())) {
        return null;
    }

    db()->prepare(
        'INSERT INTO orders (user_id, order_id, link_id, customer_name, amount, payable_amount, status, upi_id, expires_at)
         VALUES (:uid, :oid, :lid, NULL, :amount, :payable, "pending", :upi, :exp)'
    )->execute([
        ':uid'     => $link['user_id'],
        ':oid'     => generate_order_id(),
        ':lid'     => $link['link_id'],
        ':amount'  => (float) $link['amount'],
        ':payable' => (float) $link['amount'],
        ':upi'     => $link['merchant_upi'],
        ':exp'     => date('Y-m-d H:i:s', strtotime('+5 minutes')),
    ]);
    $s = db()->query('SELECT * FROM orders WHERE id = ' . (int) db()->lastInsertId());
    return $s->fetch() ?: null;
}

$linkId   = get('link');
$orderRef = get('order') !== '' ? get('order') : get('order_id');

$error    = null;
$order    = null;
$link     = null;
$merchant = ['name' => '', 'upi' => '', 'avatar' => null];

if ($linkId !== '') {
    $stmt = db()->prepare(
        'SELECT l.*, u.name AS merchant_name, u.upi_id AS merchant_upi, u.avatar AS merchant_avatar
         FROM payment_links l JOIN users u ON u.id = l.user_id
         WHERE l.link_id = ? LIMIT 1'
    );
    $stmt->execute([$linkId]);
    $link = $stmt->fetch();

    if (!$link) {
        http_response_code(404);
    } elseif ($link['status'] !== 'active') {
        $error = 'This payment link is currently disabled by the merchant.';
    } elseif ($link['expires_at'] && strtotime((string) $link['expires_at']) <= time()) {
        $error = 'This payment link has expired.';
    } elseif (!$link['merchant_upi']) {
        $error = 'The merchant has not configured a destination UPI ID yet.';
    } elseif ($link['amount'] === null || (float) $link['amount'] <= 0) {
        $error = 'This payment link has an invalid amount.';
    }

    $merchant = [
        'name'   => (string) ($link['merchant_name'] ?? ''),
        'upi'    => (string) ($link['merchant_upi'] ?? ''),
        'avatar' => $link['merchant_avatar'] ?? null,
    ];

    if ($link && !$error) {
        $oid = get('order');
        if ($oid !== '') {
            $os = db()->prepare('SELECT * FROM orders WHERE order_id = ? AND user_id = ? LIMIT 1');
            $os->execute([$oid, $link['user_id']]);
            $order = $os->fetch() ?: null;
        }
        if ($order && $order['status'] === 'pending' && $order['expires_at'] && strtotime((string) $order['expires_at']) <= time()) {
            db()->prepare('UPDATE orders SET status = "expired" WHERE id = ?')->execute([$order['id']]);
            $order = null;
        }
        if (!$order || in_array($order['status'], ['expired', 'failed'], true)) {
            $order = pay_new_order($link);
            if ($order) {
                redirect('pay/?link=' . urlencode((string) $linkId) . '&order=' . urlencode((string) $order['order_id']));
            } else {
                $error = 'Order creation temporarily throttled or restricted. Please wait a moment.';
            }
        }
    }
} elseif ($orderRef !== '') {
    $os = db()->prepare(
        'SELECT o.*, u.name AS merchant_name, u.upi_id AS merchant_upi, u.avatar AS merchant_avatar
         FROM orders o JOIN users u ON u.id = o.user_id
         WHERE o.order_id = ? LIMIT 1'
    );
    $os->execute([$orderRef]);
    $order = $os->fetch() ?: null;

    if (!$order) {
        http_response_code(404);
    } else {
        $merchant = [
            'name'   => (string) $order['merchant_name'],
            'upi'    => (string) $order['merchant_upi'],
            'avatar' => $order['merchant_avatar'] ?? null,
        ];
        if (!$order['upi_id'] && !$order['merchant_upi']) {
            $error = 'The merchant has not set a destination UPI ID yet.';
        }
        if ($order['status'] === 'pending' && $order['expires_at'] && strtotime((string) $order['expires_at']) <= time()) {
            db()->prepare('UPDATE orders SET status = "expired" WHERE id = ?')->execute([$order['id']]);
            $order['status'] = 'expired';
        }
    }
}

$hasTarget  = (bool) $link || (bool) $order;
$isDone     = $order && $order['status'] === 'success';
$isPay      = $order && !$isDone && $error === null;
$ownerId    = (int) ($link['user_id'] ?? $order['user_id'] ?? 0);

$allowManualUtr = false;
try {
    $rowUtr = db()->query("SELECT svalue FROM app_settings WHERE skey = 'allow_manual_utr' LIMIT 1")->fetchColumn();
    $allowManualUtr = ($rowUtr === '1');
} catch (Throwable $e) {
    $allowManualUtr = false;
}

$pageTitle = $link ? 'Pay ' . $link['title'] : ($order ? 'Pay Order ' . $order['order_id'] : 'Complete Payment');
$bodyClass = 'pay-page';
$extraCss  = ['assets/css/pay.css'];
include APP_ROOT . '/includes/header.php';
?>

<canvas id="confettiCanvas"></canvas>

<div class="pay-shell">
    <?php if (!$hasTarget): ?>
        <div class="pay-card" style="padding:48px 24px;text-align:center;">
            <div style="width:56px;height:56px;border-radius:50%;background:#fee2e2;color:#dc2626;display:inline-flex;align-items:center;justify-content:center;margin-bottom:14px;">
                <?= icon('alert', 26) ?>
            </div>
            <h2 style="font-size:20px;font-weight:700;margin:0 0 6px;">Payment Link Unavailable</h2>
            <p style="font-size:13px;color:#64748b;margin:0 0 20px;">This order or payment link does not exist or has expired.</p>
            <a class="btn btn-primary btn-sm" href="<?= url('index.php') ?>">Return to <?= e(SITE_NAME) ?></a>
        </div>

    <?php elseif ($isDone): ?>
        <!-- Confirmed Payment Receipt Screen (High-Conversion Executive Fintech Box) -->
        <div class="pay-card pay-receipt-card">
            <div class="pay-receipt-accent-top"></div>

            <!-- Brand Pill Badge (Centered on its own row) -->
            <div class="pay-receipt-brand-bar">
                <div class="pay-receipt-brand-badge">
                    <img src="<?= asset('assets/img/logo.png') ?>" alt="<?= e(SITE_NAME) ?>" class="pay-receipt-logo">
                    <span class="pay-receipt-brand-name"><?= e(SITE_NAME) ?></span>
                    <span class="pay-receipt-verified-badge"><?= icon('shield', 11) ?> Verified Gateway</span>
                </div>
            </div>

            <!-- Pulsing Emerald Success Icon (Centered on its own row) -->
            <div class="pay-success-icon-wrap">
                <div class="pay-success-icon-ripple"></div>
                <div class="pay-success-icon-circle">
                    <?= icon('check', 36) ?>
                </div>
            </div>

            <h2 class="pay-receipt-title">Payment Confirmed</h2>
            <p class="pay-receipt-sub">Transferred securely via <?= e(SITE_NAME) ?></p>

            <!-- Hero Settled Amount Card -->
            <div class="pay-receipt-amount-card">
                <div class="pay-receipt-amount-tag">AMOUNT SETTLED</div>
                <div class="pay-receipt-amount-row">
                    <span class="pay-receipt-currency">&#8377;</span>
                    <span class="pay-receipt-val-num"><?= number_format((float) $order['payable_amount'], 2) ?></span>
                </div>
                <div class="pay-receipt-status-pill">
                    <?= icon('check', 11) ?> <span>PAID &bull; VERIFIED</span>
                </div>
            </div>

            <!-- Receipt Metadata Ticket / Ledger Card -->
            <div class="pay-receipt-ledger">
                <div class="pay-receipt-ledger-head">
                    <span>Transaction Details</span>
                    <span class="pay-receipt-live-tag"><span class="pay-receipt-live-dot"></span> Instant Verified</span>
                </div>

                <div class="pay-receipt-row">
                    <span class="pay-receipt-key">Order Reference</span>
                    <div class="pay-receipt-val-group">
                        <span class="pay-receipt-val mono">#<?= e($order['order_id']) ?></span>
                        <button type="button" class="pay-copy-pill" data-copy="<?= e($order['order_id']) ?>" title="Copy Order ID" aria-label="Copy Order ID">
                            <?= icon('copy', 12) ?> <span>Copy</span>
                        </button>
                    </div>
                </div>

                <div class="pay-receipt-row">
                    <span class="pay-receipt-key">Bank Ref / UTR</span>
                    <div class="pay-receipt-val-group">
                        <span class="pay-receipt-val mono pay-val-utr"><?= e($order['utr'] ?: 'VERIFIED') ?></span>
                        <button type="button" class="pay-copy-pill pay-copy-pill-utr" data-copy="<?= e($order['utr'] ?: 'VERIFIED') ?>" title="Copy UTR" aria-label="Copy UTR">
                            <?= icon('copy', 12) ?> <span>Copy</span>
                        </button>
                    </div>
                </div>

                <?php if (!empty($order['sender_name'])): ?>
                <div class="pay-receipt-row">
                    <span class="pay-receipt-key">Sender Name</span>
                    <span class="pay-receipt-val"><?= e($order['sender_name']) ?></span>
                </div>
                <?php endif; ?>

                <div class="pay-receipt-row">
                    <span class="pay-receipt-key">Merchant</span>
                    <span class="pay-receipt-val pay-val-merchant">
                        <?= e($merchant['name'] ?: SITE_NAME) ?>
                        <span class="pay-verified-check" title="Verified Merchant"><?= icon('shield', 11) ?></span>
                    </span>
                </div>

                <div class="pay-receipt-row">
                    <span class="pay-receipt-key">Settlement Mode</span>
                    <span class="pay-receipt-val pay-val-mode">
                        <?= icon('zap', 12) ?> Instant Bank UPI
                    </span>
                </div>

                <div class="pay-receipt-row">
                    <span class="pay-receipt-key">Date &amp; Time</span>
                    <span class="pay-receipt-val"><?= !empty($order['paid_at']) ? ist_datetime((string)$order['paid_at']) : ist_datetime() ?></span>
                </div>
            </div>

            <!-- Receipt Action Buttons -->
            <div class="pay-receipt-actions">
                <?php if (!empty($order['redirect_url'])): ?>
                    <div class="pay-redirect-wrap">
                        <div class="pay-redirect-line"><div class="pay-redirect-line-fill"></div></div>
                        <p class="pay-redirect-timer">Redirecting to merchant in 3 seconds...</p>
                        <a href="<?= e($order['redirect_url']) ?>" class="pay-receipt-btn pay-receipt-btn-primary">
                            <span>Continue to Merchant</span> <?= icon('arrow-right', 13) ?>
                        </a>
                    </div>
                <?php else: ?>
                    <button type="button" class="pay-receipt-btn pay-receipt-btn-save" onclick="window.print()">
                        <?= icon('download', 14) ?> <span>Save Receipt</span>
                    </button>
                    <a href="<?= url('index.php') ?>" class="pay-receipt-btn pay-receipt-btn-home">
                        <span>Return Home</span>
                    </a>
                <?php endif; ?>
            </div>

            <!-- Footnote Security -->
            <div class="pay-micro-trust" style="justify-content:center;margin-top:20px;padding-top:0;">
                <?= icon('shield', 12) ?>
                <span>Official UPI Payment Receipt &bull; 256-Bit Encrypted</span>
            </div>
        </div>

    <?php elseif ($isPay): ?>
        <?php
            $payable = (float) $order['payable_amount'];
            $destUpi = (string) ($order['upi_id'] ?: $merchant['upi']);
            $queryParam = [
                'pa' => $destUpi,
                'pn' => $merchant['name'] !== '' ? $merchant['name'] : SITE_NAME,
                'am' => number_format($payable, 2, '.', ''),
                'cu' => 'INR',
                'tn' => $order['order_id'],
            ];
            $genericIntent = 'upi://pay?' . http_build_query($queryParam);
            $qrSvg = qr_svg($genericIntent, 6, 2);

            $doneUrl = $link
                ? url('pay/?link=' . urlencode((string) $link['link_id']) . '&step=done&order=' . urlencode((string) $order['order_id']))
                : url('pay/?order=' . urlencode((string) $order['order_id']));
            $redirectUrl = (string) ($order['redirect_url'] ?? '');
            $statusUrl   = url('api/checkout-status.php');

            $secsLeft = $order['expires_at'] ? max(0, strtotime((string) $order['expires_at']) - time()) : 300;
        ?>

        <!-- Active Hosted Checkout Terminal: Dual-Column (PC) & Fluid Stack (Mobile) -->
        <div class="pay-card pay-card-terminal" id="payCheckoutCard"
             data-order-id="<?= e($order['order_id']) ?>"
             data-status-url="<?= e($statusUrl) ?>"
             data-done-url="<?= e($doneUrl) ?>"
             data-redirect-url="<?= e($redirectUrl) ?>">

            <!-- ================= LEFT COLUMN: BRAND & ORDER INTELLIGENCE ================= -->
            <div class="pay-col-left">
                <div class="pay-left-main">
                    <!-- Brand Logo & Site Name Header -->
                    <div class="pay-brand-header">
                        <div class="pay-brand-identity">
                            <div class="pay-brand-logo">
                                <img src="<?= asset('assets/img/logo.png') ?>" alt="<?= e(SITE_NAME) ?>">
                            </div>
                            <div class="pay-brand-text">
                                <div class="pay-brand-name">
                                    <?= e(SITE_NAME) ?>
                                </div>
                                <div class="pay-brand-tagline">
                                    Instant UPI Settlement
                                </div>
                            </div>
                        </div>
                        <span class="pay-gateway-badge">
                            <?= icon('shield', 12) ?> Verified
                        </span>
                    </div>

                    <!-- Order Details Summary (Merchant on left, Order ID on right) -->
                    <div class="pay-order-block">
                        <div class="pay-order-merchant-name">
                            Merchant: <strong><?= e($merchant['name'] ?: SITE_NAME) ?></strong>
                        </div>
                        <div class="pay-order-pill-wrap" data-copy="<?= e($order['order_id']) ?>" data-original-label="#<?= e($order['order_id']) ?>" title="Click to copy Order ID">
                            <span>#<?= e($order['order_id']) ?></span>
                            <?= icon('copy', 11) ?>
                        </div>
                    </div>

                    <!-- Hero Amount Display Box -->
                    <div class="pay-hero-amount-box">
                        <div class="pay-amount-label-row">
                            <span class="pay-amount-label-text">TOTAL PAYABLE</span>
                            <span class="pay-amount-currency-pill">INR</span>
                        </div>
                        <div class="pay-amount-val">
                            <span class="pay-amount-symbol">&#8377;</span>
                            <span><?= number_format($payable, 2) ?></span>
                        </div>
                    </div>

                    <!-- Live Sentinel Radar & Expiry Countdown -->
                    <div class="pay-sentinel-bar">
                        <div class="pay-sentinel-radar">
                            <span class="pulse-beacon"></span>
                            <span>Auto-Detecting</span>
                        </div>
                        <div class="pay-timer-pill" id="countdownTimer" data-secs="<?= $secsLeft ?>">
                            <?= icon('clock', 12) ?>
                            <span id="countdownClock">05:00</span>
                        </div>
                    </div>

                    <!-- Supported NPCI UPI Apps Ribbon (Desktop on Left Side) -->
                    <div class="pay-supported-ribbon desktop-only">
                        <span class="pay-app-tag bhim">BHIM</span> &bull; 
                        <span class="pay-app-tag gpay">Google Pay</span> &bull; 
                        <span class="pay-app-tag phonepe">PhonePe</span> &bull; 
                        <span class="pay-app-tag paytm">Paytm</span> &bull; 
                        <span class="pay-app-tag cred">Cred</span>
                    </div>
                </div>

                <!-- Clean Single-Line Micro-Trust Footnote (Desktop only at left bottom) -->
                <div class="pay-micro-trust desktop-only">
                    <?= icon('lock', 13) ?>
                    <span>256-Bit Encrypted &bull; Direct Bank UPI</span>
                </div>
            </div>

            <!-- ================= RIGHT COLUMN: PURE DYNAMIC QR STAGE ================= -->
            <div class="pay-col-right">
                <div class="pay-qr-container">
                    <div class="pay-qr-heading">Scan QR Code to Pay</div>

                    <!-- Framed QR Stage with Animated Laser Beam & Viewfinders -->
                    <div class="pay-qr-stage-box">
                        <div class="qr-scanner-line"></div>
                        <div class="qr-bracket qr-bracket-tl"></div>
                        <div class="qr-bracket qr-bracket-tr"></div>
                        <div class="qr-bracket qr-bracket-bl"></div>
                        <div class="qr-bracket qr-bracket-br"></div>
                        <?= $qrSvg ?>
                    </div>

                    <!-- Centered Download QR Button directly under QR code -->
                    <button type="button" class="pay-btn-download-center" id="btnDownloadQr" onclick="downloadQrCode()">
                        <?= icon('download', 13) ?>
                        <span>Download QR</span>
                    </button>

                    <!-- Large Prominent Open UPI App Button -->
                    <a href="<?= e($genericIntent) ?>" class="pay-btn-app-large" id="btnOpenUpiApp">
                        <?= icon('arrow-right', 15) ?>
                        <span>Open UPI App</span>
                    </a>

                    <!-- Supported NPCI UPI Apps Ribbon (Mobile only in right column) -->
                    <div class="pay-supported-ribbon mobile-only">
                        <span class="pay-app-tag bhim">BHIM</span> &bull; 
                        <span class="pay-app-tag gpay">Google Pay</span> &bull; 
                        <span class="pay-app-tag phonepe">PhonePe</span> &bull; 
                        <span class="pay-app-tag paytm">Paytm</span> &bull; 
                        <span class="pay-app-tag cred">Cred</span>
                    </div>

                    <?php if (!empty($allowManualUtr)): ?>
                    <!-- Discreet Manual UTR Verification Accordion (Fallback) -->
                    <div class="pay-utr-accordion">
                        <button type="button" class="pay-utr-toggle-btn" onclick="toggleUtrAccordion()">
                            <span>Already paid? Verify UTR</span>
                            <span id="utrIconChevron" style="transition:transform 0.2s;display:inline-flex;">
                                <?= icon('chevron-right', 13) ?>
                            </span>
                        </button>
                        <div class="pay-utr-panel" id="utrPanel">
                            <div class="pay-utr-input-row">
                                <input type="text" id="manualUtrInput" class="pay-utr-input-field" placeholder="12-digit UTR" maxlength="22" oninput="this.value = this.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase()">
                                <button type="button" id="btnVerifyUtr" class="pay-utr-action-btn" onclick="submitManualUtr()">
                                    <?= icon('check', 12) ?> Verify
                                </button>
                            </div>
                            <div id="utrFeedbackMsg"></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Clean Single-Line Micro-Trust Footnote (Mobile only at card bottom) -->
                    <div class="pay-micro-trust mobile-only">
                        <?= icon('lock', 13) ?>
                        <span>256-Bit Encrypted &bull; Direct Bank UPI</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Real-Time Payment Confirmed Popup Modal -->
        <div class="pay-modal-backdrop" id="paySuccessModal" style="display:none;" aria-hidden="true">
            <div class="pay-card pay-receipt-card pay-modal-card">
                <div class="pay-receipt-accent-top"></div>

                <div class="pay-receipt-brand-bar">
                    <div class="pay-receipt-brand-badge">
                        <img src="<?= asset('assets/img/logo.png') ?>" alt="<?= e(SITE_NAME) ?>" class="pay-receipt-logo">
                        <span class="pay-receipt-brand-name"><?= e(SITE_NAME) ?></span>
                        <span class="pay-receipt-verified-badge"><?= icon('shield', 11) ?> Verified Gateway</span>
                    </div>
                </div>

                <div class="pay-success-icon-wrap">
                    <div class="pay-success-icon-ripple"></div>
                    <div class="pay-success-icon-circle">
                        <?= icon('check', 36) ?>
                    </div>
                </div>

                <h2 class="pay-receipt-title">Payment Confirmed</h2>
                <p class="pay-receipt-sub">Transferred securely via <?= e(SITE_NAME) ?></p>

                <div class="pay-receipt-amount-card">
                    <div class="pay-receipt-amount-tag">AMOUNT RECEIVED</div>
                    <div class="pay-receipt-amount-row">
                        <span class="pay-receipt-currency">&#8377;</span>
                        <span class="pay-receipt-val-num" id="modalConfirmedAmount"><?= number_format((float) $order['payable_amount'], 2) ?></span>
                    </div>
                    <div class="pay-receipt-status-pill">
                        <?= icon('check', 11) ?> <span>PAID &bull; VERIFIED</span>
                    </div>
                </div>

                <div class="pay-receipt-ledger" style="margin-bottom:16px;">
                    <div class="pay-receipt-row">
                        <span class="pay-receipt-key">Order Reference</span>
                        <span class="pay-receipt-val mono">#<?= e($order['order_id']) ?></span>
                    </div>
                    <div class="pay-receipt-row" id="modalUtrRow" style="display:none;">
                        <span class="pay-receipt-key">Bank Ref / UTR</span>
                        <span class="pay-receipt-val mono pay-val-utr" id="modalUtrVal"></span>
                    </div>
                    <div class="pay-receipt-row">
                        <span class="pay-receipt-key">Merchant</span>
                        <span class="pay-receipt-val"><?= e($merchant['name'] ?: SITE_NAME) ?></span>
                    </div>
                </div>

                <div class="pay-receipt-actions">
                    <a href="<?= e($doneUrl) ?>" id="modalBtnViewReceipt" class="pay-receipt-btn pay-receipt-btn-primary">
                        <span>View Official Receipt</span> <?= icon('arrow-right', 13) ?>
                    </a>
                </div>
            </div>
        </div>

        <!-- Real-Time Auto-Detection Configuration & External Script -->
        <script>
            window.__FG_PAY_CONFIG__ = {
                orderId: <?= json_encode((string) $order['order_id']) ?>,
                statusUrl: <?= json_encode($statusUrl) ?>,
                doneUrl: <?= json_encode($doneUrl) ?>,
                redirectUrl: <?= json_encode($redirectUrl) ?>
            };
        </script>
    <?php else: ?>
        <div class="pay-card" style="padding:40px 24px;text-align:center;">
            <div style="width:50px;height:50px;border-radius:50%;background:#fee2e2;color:#dc2626;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;">
                <?= icon('alert', 24) ?>
            </div>
            <h2 style="font-size:18px;font-weight:700;margin:0 0 4px;">Payment Unavailable</h2>
            <p style="font-size:13px;color:#64748b;margin:0 0 16px;"><?= e($error ?: 'This payment can no longer be completed.') ?></p>
            <a class="btn btn-secondary btn-sm" href="<?= url('index.php') ?>">Return to <?= e(SITE_NAME) ?></a>
        </div>
    <?php endif; ?>
</div>

<script src="<?= asset('assets/js/pay.js') ?>" defer></script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
