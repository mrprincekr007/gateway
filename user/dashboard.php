<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';
require_once APP_ROOT . '/includes/subscriptions.php';

$user   = require_login();
$active = 'dashboard';

$subCurrent = sub_bootstrap((int) $user['id']);
$subDaysLeft = $subCurrent ? (int) max(0, floor((strtotime((string) $subCurrent['expires_at']) - time()) / 86400)) : 0;
$currency = defined('APP_CURRENCY') ? APP_CURRENCY : (defined('CURRENCY_SYMBOL') && is_string(CURRENCY_SYMBOL) ? CURRENCY_SYMBOL : '₹');

/* =========================================================
   Handle In-Dashboard Fast POST Actions (AJAX & Form)
   ========================================================= */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || post('ajax') === '1';
    $action = post('action');

    if (!verify_csrf(post('csrf_token'))) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Session expired. Please refresh the page.']);
            exit;
        }
        flash_set('danger', 'Session expired. Please try again.');
        redirect('user/dashboard.php');
    }

    /* Action: Quick Payment Link Creation */
    if ($action === 'create_link' || $action === 'quick_link') {
        require_once APP_ROOT . '/includes/subscriptions.php';
        $can = sub_can((int) $user['id'], 'links');
        if (!$can['ok']) {
            $msg = sub_reason_message($can['reason']) . ' (Links used: ' . $can['used'] . '/' . ($can['limit'] > 0 ? $can['limit'] : 'unlimited') . ')';
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $msg]);
                exit;
            }
            flash_set('danger', $msg);
            redirect('user/dashboard.php');
        }

        $amount = (float) post('amount');
        $note   = trim(post('note') ?: post('title', 'Payment'));
        if ($note === '') { $note = 'Payment'; }

        if ($amount <= 0) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Amount must be greater than zero.']);
                exit;
            }
            flash_set('danger', 'Amount must be greater than zero.');
            redirect('user/dashboard.php');
        }

        $linkId = generate_link_id();
        $stmt = db()->prepare(
            'INSERT INTO payment_links (user_id, link_id, title, amount, description, status, expires_at)
             VALUES (:uid, :lid, :title, :amount, :desc, "active", NULL)'
        );
        $stmt->execute([
            ':uid'    => $user['id'],
            ':lid'    => $linkId,
            ':title'  => $note,
            ':amount' => $amount,
            ':desc'   => 'Generated from Dashboard Quick Collector',
        ]);

        $linkUrl = url('pay.php?link=' . $linkId);

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success'  => true,
                'link_id'  => $linkId,
                'link_url' => $linkUrl,
                'amount'   => $amount,
                'title'    => $note
            ]);
            exit;
        }

        flash_set('success', 'Payment link created: ' . $linkUrl);
        redirect('user/dashboard.php');
    }

    /* Action: Trigger IMAP Bank Alert Sync */
    if ($action === 'sync_imap') {
        require_once APP_ROOT . '/includes/mail.php';
        $res = mail_process_user((int) $user['id']);

        $lastCheckStr = date('M j, Y g:i a') . ' — checked: ' . ($res['seen'] ?? 0) . ', matched: ' . ($res['matched'] ?? 0);
        setting_set((int) $user['id'], 'imap_last_check', $lastCheckStr);

        if ($isAjax) {
            header('Content-Type: application/json');
            if (!$res['connected']) {
                echo json_encode([
                    'success' => false,
                    'error'   => 'IMAP Sync Failed: ' . ($res['error'] ?? 'Connection issue. Check settings.')
                ]);
            } else {
                $msg = 'IMAP Scan Complete! ' . $res['seen'] . ' bank email(s) checked, ' . $res['matched'] . ' order(s) matched.';
                echo json_encode([
                    'success'    => true,
                    'seen'       => $res['seen'],
                    'matched'    => $res['matched'],
                    'webhooks'   => $res['webhooks'],
                    'last_check' => $lastCheckStr,
                    'message'    => $msg
                ]);
            }
            exit;
        }

        if ($res['connected']) {
            flash_set('success', 'IMAP checked. Matched: ' . $res['matched']);
        } else {
            flash_set('danger', 'IMAP check error: ' . ($res['error'] ?? 'failed'));
        }
        redirect('user/dashboard.php');
    }
}

/* =========================================================
   1. Lifetime Analytics & KPIs
   ========================================================= */
$statsStmt = db()->prepare('
    SELECT
        COALESCE(SUM(CASE WHEN status = "success" THEN payable_amount ELSE 0 END), 0) AS total_collected,
        COUNT(*) AS total_orders,
        COALESCE(SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END), 0) AS total_success,
        COALESCE(SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END), 0) AS total_pending,
        COALESCE(SUM(CASE WHEN status IN ("failed", "expired") THEN 1 ELSE 0 END), 0) AS total_failed,
        COALESCE(SUM(CASE WHEN status = "pending" THEN payable_amount ELSE 0 END), 0) AS pending_amount
    FROM orders WHERE user_id = ?
');
$statsStmt->execute([$user['id']]);
$stats = $statsStmt->fetch() ?: [];

$totalCollected = (float) ($stats['total_collected'] ?? 0);
$totalOrders    = (int) ($stats['total_orders'] ?? 0);
$totalSuccess   = (int) ($stats['total_success'] ?? 0);
$totalPending   = (int) ($stats['total_pending'] ?? 0);
$totalFailed    = (int) ($stats['total_failed'] ?? 0);
$pendingAmount  = (float) ($stats['pending_amount'] ?? 0);

$successRate = $totalOrders > 0 ? (int) round(($totalSuccess / $totalOrders) * 100) : 0;
$aov = $totalSuccess > 0 ? round($totalCollected / $totalSuccess, 2) : 0.0;

/* =========================================================
   2. Today vs Yesterday Collections
   ========================================================= */
$todayStmt = db()->prepare('
    SELECT
        COALESCE(SUM(CASE WHEN status = "success" AND DATE(COALESCE(paid_at, created_at)) = CURDATE() THEN payable_amount ELSE 0 END), 0) AS today_collected,
        COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) AS today_count,
        COALESCE(SUM(CASE WHEN status = "success" AND DATE(COALESCE(paid_at, created_at)) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) THEN payable_amount ELSE 0 END), 0) AS yest_collected
    FROM orders WHERE user_id = ?
');
$todayStmt->execute([$user['id']]);
$todayStats = $todayStmt->fetch() ?: [];

$todayCollected = (float) ($todayStats['today_collected'] ?? 0);
$todayCount     = (int) ($todayStats['today_count'] ?? 0);
$yestCollected  = (float) ($todayStats['yest_collected'] ?? 0);

$todayGrowthPct = 0;
if ($yestCollected > 0) {
    $todayGrowthPct = round((($todayCollected - $yestCollected) / $yestCollected) * 100, 1);
} elseif ($todayCollected > 0) {
    $todayGrowthPct = 100.0;
}

/* =========================================================
   3. 30-Day Activity Data (For 7D, 15D, 30D Interactive Graph)
   ========================================================= */
$activityStmt = db()->prepare('
    SELECT
        DATE(created_at) AS order_date,
        COALESCE(SUM(CASE WHEN status = "success" THEN payable_amount ELSE 0 END), 0) AS daily_rev,
        COUNT(*) AS daily_requests,
        COALESCE(SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END), 0) AS daily_success
    FROM orders
    WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY DATE(created_at)
');
$activityStmt->execute([$user['id']]);
$rawActivity = $activityStmt->fetchAll();
$activityMap = [];
foreach ($rawActivity as $r) {
    $activityMap[$r['order_date']] = [
        'rev'      => (float) $r['daily_rev'],
        'requests' => (int) $r['daily_requests'],
        'success'  => (int) $r['daily_success'],
    ];
}

$buildRangeData = function(int $days) use ($activityMap) {
    $data = [];
    $totalRev = 0.0;
    $peakRev = 0.0;
    $peakDayDate = '';

    for ($i = $days - 1; $i >= 0; $i--) {
        $dStr = date('Y-m-d', strtotime("-$i days"));
        $label = date('M d', strtotime($dStr));
        $rev   = $activityMap[$dStr]['rev'] ?? 0.0;
        $reqs  = $activityMap[$dStr]['requests'] ?? 0;

        $totalRev += $rev;
        if ($rev > $peakRev) {
            $peakRev = $rev;
            $peakDayDate = $dStr;
        }

        $data[] = [
            'date'     => $dStr,
            'label'    => $label,
            'rev'      => $rev,
            'rev_fmt'  => money($rev),
            'requests' => $reqs,
        ];
    }

    if (!$peakDayDate && count($data) > 1) {
        $peakDayDate = $data[1]['date'];
    }

    foreach ($data as &$pt) {
        $pt['is_peak'] = ($pt['date'] === $peakDayDate);
    }
    unset($pt);

    return [
        'totalRev' => $totalRev,
        'peakRev'  => $peakRev,
        'points'   => $data,
    ];
};

$activityPayload = [
    'currency' => $currency,
    'd7'       => $buildRangeData(7),
    'd15'      => $buildRangeData(15),
    'd30'      => $buildRangeData(30),
];

/* =========================================================
   3b. Leads & Payment Sources Breakdown (Donut Chart)
   ========================================================= */
$donutSourcesStmt = db()->prepare('
    SELECT 
        COUNT(*) as total_count,
        SUM(CASE WHEN link_id IS NOT NULL THEN 1 ELSE 0 END) as link_count,
        SUM(CASE WHEN link_id IS NULL THEN 1 ELSE 0 END) as api_count
    FROM orders 
    WHERE user_id = ?
');
$donutSourcesStmt->execute([$user['id']]);
$donutSummary = $donutSourcesStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$totalOrdersCount = (int)($donutSummary['total_count'] ?? 0);
$linkOrdersCount  = (int)($donutSummary['link_count'] ?? 0);
$apiOrdersCount   = (int)($donutSummary['api_count'] ?? 0);

// Real breakdown only: orders created via a Payment Link vs via the API/Checkout.
// (The orders table has no separate "channel" column, so we do not invent extra slices.)
$sumDonut = max(1, $linkOrdersCount + $apiOrdersCount);
$donutChannels = [
    [
        'label' => 'Payment Links',
        'count' => $linkOrdersCount,
        'color' => '#10b981', // Emerald Green
        'pct'   => round(($linkOrdersCount / $sumDonut) * 100),
    ],
    [
        'label' => 'API Checkout',
        'count' => $apiOrdersCount,
        'color' => '#8b5cf6', // Violet/Purple
        'pct'   => round(($apiOrdersCount / $sumDonut) * 100),
    ],
];

// Ensure percentages sum to 100%
$pctSum = array_sum(array_column($donutChannels, 'pct'));
if ($pctSum !== 100 && !empty($donutChannels)) {
    $donutChannels[0]['pct'] += (100 - $pctSum);
}

$donutRadius = 72;
$donutCircumference = 2 * M_PI * $donutRadius; // ~452.389

/* =========================================================
   4. Recent Transactions Table (Top 10)
   ========================================================= */
$recentStmt = db()->prepare('
    SELECT id, order_id, link_id, customer_name, customer_contact, customer_email, amount, payable_amount, status, utr, sender_name, created_at, paid_at
    FROM orders
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 10
');
$recentStmt->execute([$user['id']]);
$recentOrders = $recentStmt->fetchAll();

/* =========================================================
   5. Live Webhook Logs & Automation Health
   ========================================================= */
$whLogsStmt = db()->prepare('
    SELECT id, order_id, endpoint_url, response_code, status, created_at
    FROM webhook_logs
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 8
');
$whLogsStmt->execute([$user['id']]);
$whLogs = $whLogsStmt->fetchAll();

$keysStmt = db()->prepare('SELECT COUNT(*) FROM api_keys WHERE user_id = ? AND status = "active"');
$keysStmt->execute([$user['id']]);
$activeKeys = (int) $keysStmt->fetchColumn();

$linksStmt = db()->prepare('SELECT COUNT(*) FROM payment_links WHERE user_id = ? AND status = "active"');
$linksStmt->execute([$user['id']]);
$activeLinks = (int) $linksStmt->fetchColumn();

$lastImapCheck = setting_get((int) $user['id'], 'imap_last_check', 'Auto-listening enabled');

$pageTitle   = 'Dashboard';
$pageHeading = 'Overview';
$pageSub     = 'Live payment analytics & gateway operations';
include __DIR__ . '/includes/header.php';
?>

<?php if (!$subCurrent): ?>
<div class="dash-alert dash-alert-error" style="margin-bottom:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
    <div class="alert-icon-wrap"><?= icon('shield', 18) ?></div>
    <div class="alert-text-wrap" style="flex:1;"><strong>Plan ended:</strong> Subscribe to reactivate your dashboard, API and payment links.</div>
    <a href="<?= url('user/subscription.php') ?>" class="btn btn-primary btn-sm"><?= icon('rocket', 14) ?> Subscribe</a>
</div>
<?php elseif ($subCurrent['is_trial']): ?>
<div class="dash-alert dash-alert-info" style="margin-bottom:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
    <div class="alert-icon-wrap"><?= icon('sparkles', 18) ?></div>
    <div class="alert-text-wrap" style="flex:1;"><strong>Free trial:</strong> <?= $subDaysLeft ?> day(s) left. Upgrade for unlimited access.</div>
    <a href="<?= url('user/subscription.php') ?>" class="btn btn-primary btn-sm"><?= icon('rocket', 14) ?> Upgrade</a>
</div>
<?php elseif ($subDaysLeft <= 3): ?>
<div class="dash-alert dash-alert-warning" style="margin-bottom:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
    <div class="alert-icon-wrap"><?= icon('clock', 18) ?></div>
    <div class="alert-text-wrap" style="flex:1;">Your plan expires in <strong><?= $subDaysLeft ?> day(s)</strong>. Renew to avoid interruption.</div>
    <a href="<?= url('user/subscription.php') ?>" class="btn btn-primary btn-sm"><?= icon('refresh', 14) ?> Renew</a>
</div>
<?php endif; ?>

<!-- =========================================================
     DARK HERO STAGE ("Kahi Kahi Pe Dark UI")
     Matching index.php Hero Aesthetics
     ========================================================= -->
<!-- =========================================================
     DARK HERO STAGE WITH 3D PHONE GRAPHIC & REAL-TIME STATUS
     Matching Exact Reference UI
     ========================================================= -->
<div class="dash-hero-stage-dark" data-reveal>
    <div class="hero-stage-mesh-top" aria-hidden="true"></div>
    <div class="hero-stage-mesh-bottom" aria-hidden="true"></div>
    <div class="hero-cyber-wave" aria-hidden="true"></div>

    <div class="hero-stage-layout">
        <!-- Left Column: Details, Ribbon & Action Buttons -->
        <div class="hero-stage-main">
            <div class="hero-stage-header">
                <div class="hero-eyebrow">
                    <span class="dot"></span>
                    <span>Production Gateway &middot; Direct UPI</span>
                </div>
                <div class="hero-sla-pill">
                    <span class="sla-dot green"></span>
                    <span>Real-time Active</span>
                </div>
            </div>

            <div class="hero-stage-headline">
                <h1 class="dash-hero-heading">
                    Welcome back, <?= e(explode(' ', $user['name'])[0]) ?> 👋
                </h1>
                <p class="dash-hero-desc">
                    Direct bank settlements with instant UTR auto-match to your linked UPI.
                </p>
            </div>

            <div class="hero-meta-ribbon">
                <div class="meta-ribbon-cell" title="Click to copy Merchant ID">
                    <div class="meta-cell-label">MERCHANT ID</div>
                    <div class="meta-cell-value">
                        <span><?= e($user['merchant_id']) ?></span>
                        <button type="button" class="meta-copy-btn" data-copy="<?= e($user['merchant_id']) ?>" aria-label="Copy Merchant ID">
                            <?= icon('copy', 12) ?>
                        </button>
                    </div>
                </div>

                <div class="meta-ribbon-cell" title="Active Receiving UPI VPA">
                    <div class="meta-cell-label">RECEIVING VPA</div>
                    <div class="meta-cell-value">
                        <span class="val-upi"><?= e($user['upi_id'] ?? 'Not set') ?></span>
                        <?php if (!empty($user['upi_id'])): ?>
                        <button type="button" class="meta-copy-btn" data-copy="<?= e($user['upi_id']) ?>" aria-label="Copy UPI VPA">
                            <?= icon('copy', 12) ?>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="meta-ribbon-cell" title="Bank Alert Auto-Reconciler">
                    <div class="meta-cell-label">BANK SCANNER</div>
                    <div class="meta-cell-value">
                        <?php if ($user['imap_status'] === 'connected'): ?>
                            <span class="meta-badge-ok"><span class="mini-beacon"></span> Live &middot; Auto-Match</span>
                        <?php else: ?>
                            <span class="meta-badge-warn"><span class="mini-beacon warn"></span> Standby</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="meta-ribbon-cell" title="Platform Gateway Fee">
                    <div class="meta-cell-label">PLATFORM FEE</div>
                    <div class="meta-cell-value">
                        <span class="val-rate"><span class="fee-percent-bubble">%</span> 0% Zero MDR</span>
                    </div>
                </div>
            </div>

            <div class="hero-stage-btns">
                <button type="button" class="btn-hero-primary" onclick="openQuickQrModal(100)">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    Quick QR
                </button>
                <a href="#quickPayCard" class="btn-hero-secondary">
                    <?= icon('link', 14) ?> Create Link
                </a>
                <button type="button" class="btn-hero-secondary" id="dashImapSyncBtn" title="Sync Bank Alerts">
                    <?= icon('bell', 14) ?> Sync Alerts
                </button>
                <a href="<?= url('user/docs.php') ?>" class="btn-hero-secondary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    Docs
                </a>
            </div>
        </div>

        <!-- Right Column: Premium 3D Device + Floating Stat Cards -->
        <div class="hero-stage-visual" aria-hidden="true">
            <div class="hv-aura"></div>
            <div class="hv-dots"></div>

            <div class="hero-phone-wrap">
                <div class="hv-orbit"></div>

                <div class="hv-stat-stack">
                    <div class="hero-stat-card sc-instant">
                        <span class="sc-icon">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                        </span>
                        <span class="sc-body">
                            <span class="sc-top"><b>Instant UPI</b><i class="sc-dot"></i></span>
                            <small>Settled in 8 sec</small>
                            <span class="sc-bar"><i></i></span>
                        </span>
                    </div>

                    <div class="hero-stat-card sc-match">
                        <span class="sc-icon">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        </span>
                        <span class="sc-body">
                            <span class="sc-top"><b>Auto Match</b><i class="sc-dot"></i></span>
                            <small>UTR auto-matched</small>
                            <span class="sc-bar"><i></i></span>
                        </span>
                    </div>

                    <div class="hero-stat-card sc-bank">
                        <span class="sc-icon">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M3 10h18M5 10v11M19 10v11M9 10v11M15 10v11M12 2L2 7h20L12 2z"></path></svg>
                        </span>
                        <span class="sc-body">
                            <span class="sc-top"><b>Direct Bank</b><i class="sc-dot"></i></span>
                            <small>0% MDR &middot; 24&times;7</small>
                            <span class="sc-bar"><i></i></span>
                        </span>
                    </div>
                </div>

                <div class="hero-phone-body">
                    <div class="hero-phone-screen">
                        <div class="hero-phone-notch"></div>

                        <div class="hp-status">
                            <span class="hp-time">9:41</span>
                            <span class="hp-sig"><i></i><i></i><i></i></span>
                        </div>

                        <div class="hp-brand">
                            <span class="hp-brand-dot"></span>
                            <span class="hp-brand-name"><?= e(SITE_NAME) ?></span>
                        </div>

                        <div class="hp-amount">
                            <small>Received today</small>
                            <strong>&#8377;1,24,800</strong>
                            <em>&#9650; 18.2%</em>
                        </div>

                        <div class="hero-phone-qr">
                            <svg class="hero-qr-svg" viewBox="0 0 100 100" width="48" height="48">
                                <rect x="2" y="2" width="28" height="28" fill="none" stroke="#38bdf8" stroke-width="4.5" rx="4"/>
                                <rect x="9" y="9" width="14" height="14" fill="#38bdf8" rx="2"/>
                                <rect x="70" y="2" width="28" height="28" fill="none" stroke="#38bdf8" stroke-width="4.5" rx="4"/>
                                <rect x="77" y="9" width="14" height="14" fill="#38bdf8" rx="2"/>
                                <rect x="2" y="70" width="28" height="28" fill="none" stroke="#38bdf8" stroke-width="4.5" rx="4"/>
                                <rect x="9" y="77" width="14" height="14" fill="#38bdf8" rx="2"/>
                                <rect x="40" y="8" width="20" height="5" fill="#38bdf8" rx="2"/>
                                <rect x="40" y="22" width="20" height="5" fill="#38bdf8" rx="2"/>
                                <rect x="8" y="40" width="5" height="20" fill="#38bdf8" rx="2"/>
                                <rect x="22" y="40" width="5" height="20" fill="#38bdf8" rx="2"/>
                                <rect x="38" y="38" width="24" height="24" fill="#38bdf8" rx="3"/>
                                <circle cx="50" cy="50" r="5" fill="#070e24"/>
                                <rect x="70" y="40" width="22" height="5" fill="#38bdf8" rx="2"/>
                                <rect x="85" y="54" width="5" height="18" fill="#38bdf8" rx="2"/>
                                <rect x="40" y="72" width="18" height="5" fill="#38bdf8" rx="2"/>
                                <rect x="40" y="86" width="28" height="5" fill="#38bdf8" rx="2"/>
                                <rect x="76" y="80" width="16" height="12" fill="#38bdf8" rx="2"/>
                            </svg>
                        </div>

                        <div class="hp-toast">
                            <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="#34d399" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            <span>UTR auto-matched</span>
                        </div>

                        <span class="hero-phone-sheen"></span>
                    </div>

                    <div class="hero-phone-badge">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================
     5 VIBRANT COLORFUL METRIC CARDS (WHITE SURFACE)
     ========================================================= -->
<div class="dash-kpi-grid-light" data-stagger>
    <!-- Total Revenue -->
    <div class="light-kpi-card card-theme-blue">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Total Revenue</span>
            <div class="kpi-icon-glow">
                <?= icon('wallet', 20) ?>
            </div>
        </div>
        <div class="kpi-number-display" data-count="<?= $totalCollected ?>" data-decimals="2" data-prefix="<?= e($currency) ?>">
            <?= money($totalCollected) ?>
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill up">
                <?= icon('trending', 11) ?> Lifetime
            </span>
            <span class="kpi-meta-text"><?= $totalSuccess ?> settled</span>
        </div>
    </div>

    <!-- Today's Volume -->
    <div class="light-kpi-card card-theme-green">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Today's Volume</span>
            <div class="kpi-icon-glow">
                <?= icon('receipt', 20) ?>
            </div>
        </div>
        <div class="kpi-number-display" data-count="<?= $todayCollected ?>" data-decimals="2" data-prefix="<?= e($currency) ?>">
            <?= money($todayCollected) ?>
        </div>
        <div class="kpi-footer-row">
            <?php if ($todayGrowthPct >= 0): ?>
                <span class="trend-pill up">
                    <?= icon('arrow-up', 10) ?> +<?= $todayGrowthPct ?>% vs yest
                </span>
            <?php else: ?>
                <span class="trend-pill down">
                    <?= icon('arrow-down', 10) ?> <?= $todayGrowthPct ?>% vs yest
                </span>
            <?php endif; ?>
            <span class="kpi-meta-text"><?= $todayCount ?> today</span>
        </div>
    </div>

    <!-- Success Conversion -->
    <div class="light-kpi-card card-theme-amber">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Success Rate</span>
            <div class="kpi-icon-glow">
                <?= icon('activity', 20) ?>
            </div>
        </div>
        <div class="kpi-number-display" data-count="<?= $successRate ?>" data-suffix="%">
            <?= $successRate ?>%
        </div>
        <div class="kpi-footer-row">
            <div style="flex:1;height:5px;background:#e2e8f0;border-radius:4px;overflow:hidden;margin-right:8px;">
                <div style="width:<?= max(5, $successRate) ?>%;height:100%;background:linear-gradient(90deg,#f59e0b,#10b981);border-radius:4px;"></div>
            </div>
            <span class="kpi-meta-text"><?= $totalSuccess ?>/<?= $totalOrders ?></span>
        </div>
    </div>

    <!-- Pending Verification -->
    <div class="light-kpi-card card-theme-purple">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Pending Orders</span>
            <div class="kpi-icon-glow">
                <?= icon('clock', 20) ?>
            </div>
        </div>
        <div class="kpi-number-display" data-count="<?= $totalPending ?>">
            <?= $totalPending ?>
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill neutral">
                <?= money($pendingAmount) ?>
            </span>
            <span class="kpi-meta-text">Awaiting UTR</span>
        </div>
    </div>

    <!-- Average Ticket Size (AOV) -->
    <div class="light-kpi-card card-theme-cyan">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Avg Ticket Size</span>
            <div class="kpi-icon-glow">
                <?= icon('rupee', 20) ?>
            </div>
        </div>
        <div class="kpi-number-display" data-count="<?= $aov ?>" data-decimals="2" data-prefix="<?= e($currency) ?>">
            <?= money($aov) ?>
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill neutral">Per Order</span>
            <span class="kpi-meta-text">Settled avg</span>
        </div>
    </div>
</div>

<!-- =========================================================
     ACTIVITY & LEADS DUAL CHARTS GRID (MATCHING SCREENSHOT)
     ========================================================= -->
<div class="dash-charts-grid">
    <!-- 1. Dual-Axis Activity Line Chart -->
    <div class="dash-white-card activity-chart-card" data-reveal>
        <div class="activity-card-header">
            <div class="activity-header-left">
                <h3 class="activity-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="activity-trend-icon">
                        <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
                        <polyline points="17 6 23 6 23 12"></polyline>
                    </svg>
                    <span id="activityTitleText">Activity — Last 7 Days</span>
                </h3>
            </div>

            <div class="activity-header-right">
                <div class="activity-legend">
                    <span class="activity-legend-pill legend-revenue">
                        <span class="legend-dot" style="background:#2563eb;"></span>
                        <span>Revenue</span>
                    </span>
                    <span class="activity-legend-pill legend-requests">
                        <span class="legend-dot" style="background:#7c3aed;"></span>
                        <span>Requests</span>
                    </span>
                </div>

                <div class="activity-range-pills" role="tablist">
                    <button type="button" class="activity-range-btn active" data-range="7">7D</button>
                    <button type="button" class="activity-range-btn" data-range="15">15D</button>
                    <button type="button" class="activity-range-btn" data-range="30">30D</button>
                </div>
            </div>
        </div>

        <div class="activity-chart-stage" id="activityChartStage">
            <div class="activity-svg-container" id="activitySvgContainer"></div>

            <!-- Floating Dark Tooltip Card (Matching Screenshot 2) -->
            <div class="activity-tooltip-card" id="activityTooltipCard" style="display:none;">
                <div class="tooltip-caret"></div>
                <div class="tooltip-date" id="tooltipDate">Oct 11</div>
                <div class="tooltip-item">
                    <span class="tooltip-dot" style="background:#2563eb;"></span>
                    <span class="tooltip-label">Revenue:</span>
                    <span class="tooltip-val" id="tooltipRev">₹0.00</span>
                </div>
                <div class="tooltip-item">
                    <span class="tooltip-dot" style="background:#7c3aed;"></span>
                    <span class="tooltip-label">API Requests:</span>
                    <span class="tooltip-val" id="tooltipReq">0 reqs</span>
                </div>
            </div>
        </div>

        <script>
            window.__ACTIVITY_PAYLOAD = <?= json_encode($activityPayload, JSON_UNESCAPED_UNICODE) ?>;
        </script>
    </div>

    <!-- 2. Leads By Lead Sources Donut Chart (Exact Match to Reference Image) -->
    <div class="dash-white-card donut-chart-card" data-reveal>
        <div class="donut-chart-header">
            <div class="donut-title-wrap">
                <h3>
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <path d="M12 2a10 10 0 0 1 10 10h-10z"></path>
                    </svg>
                    <span>Lead Sources</span>
                </h3>
            </div>
            <div class="donut-header-badge">
                <span class="trend-pill positive" style="font-size:10.5px;padding:3px 8px;font-weight:700;">Live Share</span>
            </div>
        </div>

        <div class="donut-stage-center">
            <svg class="donut-svg" viewBox="0 0 200 200" aria-label="Leads by Lead Sources Donut Chart">
                <!-- Base subtle track ring -->
                <circle cx="100" cy="100" r="<?= $donutRadius ?>" fill="none" stroke="rgba(226, 232, 240, 0.4)" stroke-width="26"></circle>
                <?php
                $runningOffset = 0;
                foreach ($donutChannels as $idx => $channel):
                    $dash = ($channel['pct'] / 100) * $donutCircumference;
                    $gap = 1.5;
                    $dashSlice = max(0.5, $dash - $gap);
                    $dashArr = sprintf('%.2f %.2f', $dashSlice, $donutCircumference - $dashSlice);
                    $dashOff = sprintf('%.2f', -$runningOffset);
                    $runningOffset += $dash;
                ?>
                    <circle class="donut-segment" 
                            id="donutSeg<?= $idx ?>"
                            cx="100" cy="100" r="<?= $donutRadius ?>" 
                            fill="none" 
                            stroke="<?= $channel['color'] ?>" 
                            stroke-width="26" 
                            stroke-dasharray="<?= $dashArr ?>" 
                            stroke-dashoffset="<?= $dashOff ?>"
                            stroke-linecap="butt"
                            data-index="<?= $idx ?>"
                            data-label="<?= e($channel['label']) ?>"
                            data-source-count="<?= $channel['count'] ?>"
                            data-pct="<?= $channel['pct'] ?>%"
                            data-color="<?= $channel['color'] ?>">
                    </circle>
                <?php endforeach; ?>
            </svg>

            <!-- Center Summary Overlay -->
            <div class="donut-center-overlay">
                <div class="donut-center-num" id="donutCenterNum"><?= number_format($totalOrdersCount > 0 ? $totalOrdersCount : $sumDonut) ?></div>
                <div class="donut-center-sub" id="donutCenterSub">Total Orders</div>
            </div>
        </div>

        <!-- Horizontal Legend Bar (Matching Uploaded Screenshot) -->
        <div class="donut-legend-wrap">
            <?php foreach ($donutChannels as $idx => $channel): ?>
                <div class="donut-legend-item" 
                     id="donutLeg<?= $idx ?>"
                     data-index="<?= $idx ?>"
                     data-label="<?= e($channel['label']) ?>"
                     data-source-count="<?= $channel['count'] ?>"
                     data-pct="<?= $channel['pct'] ?>%"
                     data-color="<?= $channel['color'] ?>">
                    <span class="donut-legend-dot" style="background: <?= $channel['color'] ?>;"></span>
                    <span class="donut-legend-name"><?= e($channel['label']) ?></span>
                    <span class="donut-legend-pct"><?= $channel['pct'] ?>%</span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- =========================================================
     RECENT TRANSACTIONS TABLE (FULL-WIDTH 100% WIDE CARD)
     ========================================================= -->
<div class="dash-white-card" style="padding:0;overflow:hidden;margin-top:20px;margin-bottom:24px;" data-reveal>
    <div class="panel-head-clean">
        <div class="panel-head-left">
            <h3 class="panel-head-title">
                <?= icon('receipt', 19, 'style="color:#2563eb;"') ?>
                <span>Recent Transactions</span>
            </h3>
        </div>
        <a href="<?= url('user/transactions.php') ?>" class="btn btn-secondary btn-sm panel-head-action">
            <span>View All</span> (<?= $totalOrders ?>) &rarr;
        </a>
    </div>

    <?php
    $txCounts = [
        'all'     => count($recentOrders),
        'success' => 0,
        'pending' => 0,
        'failed'  => 0,
    ];
    foreach ($recentOrders as $ro) {
        $st = strtolower((string) ($ro['status'] ?? ''));
        if ($st === 'success' || $st === 'captured' || $st === 'completed') {
            $txCounts['success']++;
        } elseif ($st === 'pending') {
            $txCounts['pending']++;
        } else {
            $txCounts['failed']++;
        }
    }
    ?>
    <!-- Table Filter & Real-Time Search Bar -->
    <div class="transactions-filter-strip">
        <div class="tx-filter-tabs" id="txFilterTabs">
            <!-- Liquid Jelly Glider Pill -->
            <div class="tx-liquid-glider" id="txLiquidGlider" aria-hidden="true">
                <span class="tx-glider-wave"></span>
            </div>

            <button type="button" class="tx-tab-btn active" data-tab="all">
                <span class="tx-tab-dot dot-all"></span>
                <span class="tx-tab-text">All</span>
                <span class="tx-tab-badge badge-all"><?= $txCounts['all'] ?></span>
            </button>
            <button type="button" class="tx-tab-btn" data-tab="success">
                <span class="tx-tab-dot dot-success"></span>
                <span class="tx-tab-text">Completed</span>
                <span class="tx-tab-badge badge-success"><?= $txCounts['success'] ?></span>
            </button>
            <button type="button" class="tx-tab-btn" data-tab="pending">
                <span class="tx-tab-dot dot-pending"></span>
                <span class="tx-tab-text">Pending</span>
                <span class="tx-tab-badge badge-pending"><?= $txCounts['pending'] ?></span>
            </button>
            <button type="button" class="tx-tab-btn" data-tab="failed">
                <span class="tx-tab-dot dot-failed"></span>
                <span class="tx-tab-text">Failed</span>
                <span class="tx-tab-badge badge-failed"><?= $txCounts['failed'] ?></span>
            </button>
        </div>

        <div class="tx-search-box">
            <?= icon('search', 14, 'style="color:#64748b;"') ?>
            <input type="text" id="dashTableSearchInput" placeholder="Filter rows...">
        </div>
    </div>

    <?php if (!$recentOrders): ?>
        <div style="padding:36px 20px;text-align:center;">
            <div style="width:44px;height:44px;margin:0 auto 12px;border-radius:14px;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;">
                <?= icon('receipt', 22) ?>
            </div>
            <p style="font-size:14.5px;font-weight:700;color:#0f172a;margin:0 0 4px;">No transactions recorded yet</p>
            <p style="font-size:12px;color:#64748b;max-width:340px;margin:0 auto 14px;">
                Create a quick payment link or scan QR to test.
            </p>
            <button type="button" class="btn btn-primary btn-sm" onclick="openQuickQrModal(50)">
                <?= icon('zap', 13) ?> Collect ₹50 Test
            </button>
        </div>
    <?php else: ?>
        <div class="clean-table-wrap">
            <table class="clean-data-table" id="dashTransactionsTable">
                <thead>
                    <tr>
                        <th style="width:24%;">Order / Time</th>
                        <th style="width:25%;">Customer</th>
                        <th style="width:14%;">Amount</th>
                        <th style="width:18%;">UTR Reference</th>
                        <th style="width:11%;">Status</th>
                        <th style="width:8%;text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentOrders as $o): ?>
                    <tr data-status="<?= e($o['status']) ?>">
                        <td>
                            <div class="order-badge-mono">
                                <span><?= e($o['order_id']) ?></span>
                                <button type="button" class="dark-chip-copy" data-copy="<?= e($o['order_id']) ?>" title="Copy Order ID">
                                    <?= icon('copy', 11) ?>
                                </button>
                            </div>
                            <div class="tx-table-time-sub">
                                <?= e(time_ago($o['created_at'])) ?> &middot; <?= date('H:i', strtotime($o['created_at'])) ?>
                            </div>
                        </td>

                        <td>
                            <div class="tx-table-cust-name">
                                <?= e($o['customer_name'] ?: ($o['sender_name'] ?: 'Direct UPI Scan')) ?>
                            </div>
                            <div class="tx-table-cust-sub">
                                <?= e($o['customer_contact'] ?: ($o['customer_email'] ?: '—')) ?>
                            </div>
                        </td>

                        <td>
                            <span class="tx-table-amount">
                                <?= money((float) $o['payable_amount']) ?>
                            </span>
                        </td>

                        <td>
                            <?php if (!empty($o['utr'])): ?>
                                <button type="button" class="utr-pill-copy" data-copy="<?= e($o['utr']) ?>" title="Click to copy UTR">
                                    <span><?= e($o['utr']) ?></span>
                                    <?= icon('copy', 11) ?>
                                </button>
                            <?php else: ?>
                                <span class="utr-empty-dash">—</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($o['status'] === 'success'): ?>
                                <span class="badge-clean success"><?= icon('check', 11) ?> Success</span>
                            <?php elseif ($o['status'] === 'pending'): ?>
                                <span class="badge-clean pending"><?= icon('clock', 11) ?> Pending</span>
                            <?php else: ?>
                                <span class="badge-clean failed"><?= icon('x', 11) ?> <?= ucfirst(e($o['status'])) ?></span>
                            <?php endif; ?>
                        </td>

                        <td style="text-align:right;">
                            <a href="<?= url('user/transaction-details.php?id=' . $o['id']) ?>" class="btn-table-view">
                                View
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="tx-filter-empty-row" style="display:none;">
                        <td colspan="6" style="text-align:center;padding:36px 16px;color:#64748b;font-size:13px;">
                            <div style="font-size:13.5px;font-weight:600;color:#94a3b8;margin-bottom:4px;">No transactions found</div>
                            <div style="font-size:11.5px;color:#64748b;">No recent transactions match the selected status filter.</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Mobile Card List View (Visible on mobile <= 768px, eliminates horizontal blowout) -->
        <div class="dash-tx-mobile-list">
            <?php foreach ($recentOrders as $o): ?>
            <?php $isSucc = ($o['status'] === 'success'); ?>
            <div class="dash-tx-card-mobile" data-status="<?= e($o['status']) ?>">
                <div class="dash-tx-mobile-head">
                    <div class="dash-tx-mobile-id-wrap">
                        <span>#<?= e($o['order_id']) ?></span>
                        <button type="button" class="dark-chip-copy" data-copy="<?= e($o['order_id']) ?>" title="Copy Order ID">
                            <?= icon('copy', 11) ?>
                        </button>
                    </div>
                    <div>
                        <?php if ($isSucc): ?>
                            <span class="badge-clean success"><?= icon('check', 11) ?> Success</span>
                        <?php elseif ($o['status'] === 'pending'): ?>
                            <span class="badge-clean pending"><?= icon('clock', 11) ?> Pending</span>
                        <?php else: ?>
                            <span class="badge-clean failed"><?= icon('x', 11) ?> <?= ucfirst(e($o['status'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="dash-tx-mobile-body">
                    <div class="dash-tx-mobile-main-info">
                        <div class="dash-tx-mobile-amount <?= $isSucc ? 'is-success' : '' ?>">
                            <?= money((float) $o['payable_amount']) ?>
                        </div>
                        <div class="dash-tx-mobile-cust">
                            <strong><?= e($o['customer_name'] ?: ($o['sender_name'] ?: 'Direct UPI Scan')) ?></strong>
                            <?php if (!empty($o['customer_contact'])): ?>
                                <span class="dash-tx-mobile-contact">&middot; <?= e($o['customer_contact']) ?></span>
                            <?php elseif (!empty($o['customer_email'])): ?>
                                <span class="dash-tx-mobile-contact">&middot; <?= e($o['customer_email']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <a href="<?= url('user/transaction-details.php?id=' . $o['id']) ?>" class="btn-table-view dash-tx-mobile-view-btn">
                        <span>View</span> &rarr;
                    </a>
                </div>

                <div class="dash-tx-mobile-foot">
                    <div class="dash-tx-mobile-utr">
                        <span class="dash-tx-mobile-utr-label">UTR:</span>
                        <?php if (!empty($o['utr'])): ?>
                            <button type="button" class="utr-pill-copy" data-copy="<?= e($o['utr']) ?>" title="Click to copy UTR">
                                <span><?= e($o['utr']) ?></span>
                                <?= icon('copy', 10) ?>
                            </button>
                        <?php else: ?>
                            <span class="utr-empty-dash">&mdash;</span>
                        <?php endif; ?>
                    </div>
                    <div class="dash-tx-mobile-time">
                        <?= e(time_ago($o['created_at'])) ?> &middot; <?= date('H:i', strtotime($o['created_at'])) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="dash-tx-mobile-empty" style="display:none;padding:28px 14px;text-align:center;color:#64748b;">
                <div style="font-size:13.5px;font-weight:600;color:#94a3b8;margin-bottom:4px;">No transactions found</div>
                <div style="font-size:11.5px;color:#64748b;">No recent transactions match the selected filter.</div>
            </div>
        </div>

        <div class="transactions-table-footer">
            <span class="footer-count-text">Recent <?= count($recentOrders) ?> transactions</span>
            <a href="<?= url('user/transactions.php') ?>" class="footer-view-all-link">
                View All &rarr;
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- =========================================================
     FULL-WIDTH: Webhook Activity Stream (Left to Right)
     ========================================================= -->
<div class="dash-white-card" style="padding:0;overflow:hidden;margin-bottom:24px;" data-reveal>
    <div class="panel-head-clean">
        <div class="panel-head-left">
            <h3 class="panel-head-title">
                <?= icon('send', 18, 'style="color:#8b5cf6;"') ?>
                <span>Webhook Activity</span>
            </h3>
        </div>
        <div class="panel-head-actions">
            <a href="<?= url('user/webhooks.php') ?>" class="btn btn-secondary btn-sm panel-head-action" style="display:inline-flex;align-items:center;gap:6px;">
                <?= icon('webhook', 14) ?> <span>Configure Webhooks</span>
            </a>
        </div>
    </div>

    <?php if (!$whLogs): ?>
        <div style="padding:42px 24px;text-align:center;">
            <div style="width:48px;height:48px;border-radius:14px;background:rgba(139,92,246,0.12);color:#8b5cf6;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;">
                <?= icon('send', 22) ?>
            </div>
            <p style="margin:0 0 6px 0;font-size:15px;font-weight:700;color:var(--color-ink);">No Webhook Dispatches Yet</p>
            <p style="margin:0 auto 16px;font-size:12.5px;color:var(--color-ink-mute);max-width:420px;line-height:1.5;">
                Dispatches fire automatically to your endpoints upon verified bank settlement and QR scan credits.
            </p>
            <a href="<?= url('user/webhooks.php') ?>" class="btn btn-secondary btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
                <?= icon('webhook', 14) ?> Setup Webhook Endpoints &rarr;
            </a>
        </div>
    <?php else: ?>
        <div class="clean-table-wrap">
            <table class="clean-data-table" id="dashWebhooksTable">
                <thead>
                    <tr>
                        <th style="width:130px;">HTTP Response</th>
                        <th style="width:200px;">Event &amp; Order ID</th>
                        <th>Destination Endpoint</th>
                        <th style="width:160px;">Dispatched Time</th>
                        <th style="width:130px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($whLogs as $wh): ?>
                    <?php $isOk = ($wh['status'] === 'success'); ?>
                    <tr>
                        <td>
                            <span class="badge-clean <?= $isOk ? 'success' : 'failed' ?>" style="font-family:'JetBrains Mono',monospace;font-size:11.5px;padding:4px 10px;display:inline-flex;align-items:center;gap:6px;">
                                <span class="mini-beacon <?= $isOk ? '' : 'warn' ?>"></span>
                                <?= e((string) ($wh['response_code'] ?: ($isOk ? '200 OK' : 'ERR'))) ?>
                            </span>
                        </td>
                        <td>
                            <div style="font-family:'JetBrains Mono',monospace;font-size:12.5px;font-weight:600;color:var(--color-ink);display:flex;align-items:center;gap:8px;">
                                <span style="display:inline-flex;align-items:center;gap:4px;color:#2563eb;">
                                    <?= icon('zap', 13) ?> order.paid
                                </span>
                                <span style="color:#94a3b8;">&middot;</span>
                                <span><?= e($wh['order_id']) ?></span>
                                <button type="button" class="dark-chip-copy" data-copy="<?= e($wh['order_id']) ?>" title="Copy Order ID" style="color:#64748b;">
                                    <?= icon('copy', 11) ?>
                                </button>
                            </div>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span style="font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--color-ink-secondary);max-width:540px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= e($wh['endpoint_url']) ?>">
                                    <?= e($wh['endpoint_url']) ?>
                                </span>
                                <button type="button" class="dark-chip-copy" data-copy="<?= e($wh['endpoint_url']) ?>" title="Copy Endpoint URL" style="color:#64748b;">
                                    <?= icon('copy', 11) ?>
                                </button>
                            </div>
                        </td>
                        <td>
                            <div style="font-size:12px;color:var(--color-ink);font-weight:600;">
                                <?= e(time_ago($wh['created_at'])) ?>
                            </div>
                            <div style="font-size:11px;color:var(--color-ink-mute);font-family:'JetBrains Mono',monospace;">
                                <?= date('M j, H:i:s', strtotime($wh['created_at'])) ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($isOk): ?>
                                <span class="badge-clean success" style="gap:5px;">
                                    <?= icon('check', 11) ?> Delivered
                                </span>
                            <?php else: ?>
                                <span class="badge-clean failed" style="gap:5px;">
                                    <?= icon('alert', 11) ?> Failed
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="transactions-table-footer">
            <span class="footer-count-text">Showing recent <?= count($whLogs) ?> webhook events</span>
            <a href="<?= url('user/webhooks.php') ?>" class="footer-view-all-link">
                View Full Webhook History &rarr;
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- =========================================================
     Operational Trio Grid: Bank Alert, Quick Link & Developer API
     ========================================================= -->
<div class="dash-trio-grid">
    <!-- Card 1: Bank Alert IMAP Scanner -->
    <div class="dash-white-card dash-trio-card" data-reveal>
        <div class="trio-card-header">
            <div class="trio-header-title">
                <span class="trio-icon-bubble green"><?= icon('zap', 16) ?></span>
                <div>
                    <h3>Bank Alert Scanner</h3>
                </div>
            </div>
            <div class="trio-header-status">
                <?php if ($user['imap_status'] === 'connected'): ?>
                    <span class="badge-clean success"><span class="mini-beacon"></span> Live</span>
                <?php else: ?>
                    <span class="badge-clean pending"><span class="mini-beacon warn"></span> Standby</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="trio-stat-list">
            <div class="trio-stat-row">
                <span class="trio-stat-key">Listener Status</span>
                <span class="trio-stat-val"><?= $user['imap_status'] === 'connected' ? '<span style="color:#059669;font-weight:700;">Connected &amp; Active</span>' : '<span style="color:#d97706;font-weight:700;">Disconnected</span>' ?></span>
            </div>
            <div class="trio-stat-row">
                <span class="trio-stat-key">Receiving VPA</span>
                <span class="trio-stat-val mono" style="color:#0284c7;"><?= e($user['upi_id'] ?? 'Not set') ?></span>
            </div>
            <div class="trio-stat-row">
                <span class="trio-stat-key">Last Sync Check</span>
                <span class="trio-stat-val mono" id="imapLastCheckDisplay"><?= e($lastImapCheck) ?></span>
            </div>
        </div>

        <div class="trio-card-footer" style="margin-top:auto;padding-top:14px;">
            <?php if ($user['imap_status'] === 'connected'): ?>
                <button type="button" class="btn btn-secondary btn-block" id="dashImapSyncBtn2" onclick="document.getElementById('dashImapSyncBtn').click()" style="width:100%;justify-content:center;height:38px;">
                    <?= icon('refresh', 14) ?> Trigger Instant Sync
                </button>
            <?php else: ?>
                <a href="<?= url('user/integrations.php') ?>" class="btn btn-primary btn-block" style="width:100%;justify-content:center;height:38px;">
                    <?= icon('plug', 14) ?> Connect Gmail Alerts
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Card 2: Quick Link & Dynamic QR -->
    <div class="dash-white-card dash-trio-card" id="quickPayCard" data-reveal>
        <div class="trio-card-header">
            <div class="trio-header-title">
                <span class="trio-icon-bubble blue"><?= icon('link', 16) ?></span>
                <div>
                    <h3>Quick Link &amp; QR</h3>
                </div>
            </div>
            <div class="trio-header-status">
                <span class="badge-clean info" style="font-size:10.5px;padding:2px 7px;">Instant</span>
            </div>
        </div>

        <form id="quickDashboardForm" method="post" action="<?= url('user/dashboard.php') ?>" class="trio-form">
            <input type="hidden" name="action" value="create_link">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <div class="trio-form-group">
                <label>Amount (<?= e($currency) ?>) <span aria-hidden="true" style="color:#dc2626;font-weight:700;">*</span><span class="sr-only">(required)</span></label>
                <div class="trio-input-wrap">
                    <span class="trio-input-prefix"><?= e($currency) ?></span>
                    <input type="number" id="quickInputAmount" name="amount" class="trio-input" placeholder="500.00" step="any" min="1" required>
                </div>
            </div>

            <div class="trio-form-group">
                <label>Note / Reference</label>
                <input type="text" id="quickInputNote" name="note" class="trio-input no-prefix" placeholder="e.g. Invoice #104">
            </div>

            <div class="trio-btn-grid">
                <button type="submit" class="btn btn-primary btn-sm" style="height:38px;justify-content:center;">
                    <?= icon('link', 13) ?> Create Link
                </button>
                <button type="submit" class="btn btn-secondary btn-sm quick-btn-qr" style="height:38px;justify-content:center;color:#0284c7;">
                    <?= icon('zap', 13) ?> Instant QR
                </button>
            </div>
        </form>

        <!-- Generated Link & QR Result Box -->
        <div class="quick-result-box" id="quickResultBox" style="display:none;margin-top:14px;padding:14px;background:var(--surface-sunken);border:1.5px solid var(--color-hairline);border-radius:12px;">
            <div style="font-size:12px;font-weight:700;color:#059669;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                <?= icon('check-circle', 14) ?> Payment Link Generated!
            </div>
            <div style="display:flex;align-items:center;gap:8px;background:var(--surface-card);border:1px solid var(--color-hairline);border-radius:8px;padding:6px 10px;font-family:'JetBrains Mono',monospace;font-size:11.5px;color:#0284c7;">
                <a href="#" id="quickResultLink" target="_blank" style="flex:1;color:#0284c7;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></a>
                <button type="button" class="dash-chip-copy" id="quickResultCopyBtn" data-copy="" title="Copy Link" style="background:none;border:none;cursor:pointer;color:var(--color-ink-mute);padding:4px;display:flex;align-items:center;">
                    <?= icon('copy', 13) ?>
                </button>
            </div>
            <div style="display:flex;gap:8px;margin-top:8px;">
                <a href="#" id="quickResultWhatsapp" target="_blank" class="btn btn-secondary btn-sm" style="flex:1;height:34px;font-size:11.5px;justify-content:center;color:#16a34a;border-color:rgba(22,163,74,0.3);">
                    <?= icon('message', 13) ?> WhatsApp Share
                </a>
            </div>
            <div style="display:flex;flex-direction:column;align-items:center;gap:6px;margin-top:10px;">
                <div id="quickResultQrHolder" style="padding:6px;background:#ffffff;border:1px solid var(--color-hairline);border-radius:8px;"></div>
                <span style="font-size:10.5px;color:var(--color-ink-mute);">Scan with PhonePe, Paytm, GPay</span>
            </div>
        </div>
    </div>

    <!-- Card 3: Developer API & Endpoints -->
    <div class="dash-white-card dash-trio-card" data-reveal>
        <div class="trio-card-header">
            <div class="trio-header-title">
                <span class="trio-icon-bubble purple"><?= icon('code', 16) ?></span>
                <div>
                    <h3>Developer API</h3>
                </div>
            </div>
            <div class="trio-header-status">
                <span class="badge-clean success" style="font-size:10.5px;padding:2px 7px;">REST v1</span>
            </div>
        </div>

        <div class="trio-stat-list">
            <div class="trio-stat-row">
                <span class="trio-stat-key">Active API Keys</span>
                <span class="trio-stat-val"><?= $activeKeys ?> Key<?= $activeKeys === 1 ? '' : 's' ?> active</span>
            </div>
            <div class="trio-stat-row">
                <span class="trio-stat-key">Active Payment Links</span>
                <span class="trio-stat-val"><?= $activeLinks ?> Link<?= $activeLinks === 1 ? '' : 's' ?> live</span>
            </div>
            <div class="trio-stat-row">
                <span class="trio-stat-key">API Base URL</span>
                <span class="trio-stat-val mono" style="color:#2563eb;max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= e(rtrim(BASE_URL, '/')) ?>/api">
                    <?= e(rtrim(BASE_URL, '/')) ?>/api
                </span>
            </div>
        </div>

        <div class="trio-btn-grid" style="margin-top:auto;padding-top:14px;">
            <a href="<?= url('user/api-keys.php') ?>" class="btn btn-secondary btn-sm" style="height:38px;justify-content:center;">
                <?= icon('key', 13) ?> Manage Keys
            </a>
            <a href="<?= url('user/docs.php') ?>" class="btn btn-primary btn-sm" style="height:38px;justify-content:center;">
                <?= icon('book', 13) ?> View Docs
            </a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>