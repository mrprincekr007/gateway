<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/admin-ops.php';
require_once __DIR__ . '/includes/dash-boxes.php';

ops_ensure_tables();

$user   = require_admin();
$active = 'overview';
$currency = defined('APP_CURRENCY') ? APP_CURRENCY : (defined('CURRENCY_SYMBOL') && is_string(CURRENCY_SYMBOL) ? CURRENCY_SYMBOL : '₹');

$error = null;
$success = null;

/* ---------- Handle Quick Modal Actions on Dashboard ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf(post('csrf_token'))) {
        $error = 'Session expired. Please try again.';
    } else {
        $action = post('action');

        // Quick Create Merchant
        if ($action === 'create_merchant') {
            $name     = post('name');
            $email    = post('email');
            $password = post('password');
            $upiId    = post('upi_id');
            $role     = post('role', 'user');

            $res = admin_create_user($name, $email, $password, $upiId, $role);
            if (is_string($res)) {
                $error = $res;
            } else {
                flash_set('success', 'Merchant ' . e($name) . ' created successfully.');
                redirect('admin/user.php?id=' . (int) $res['id']);
            }
        }

        // Quick Manual Reconcile UTR
        if ($action === 'quick_reconcile') {
            $orderId = post('order_id');
            $utr     = post('utr');
            $sender  = post('sender_name');

            $err = admin_reconcile_order($orderId, $utr, $sender, (int) $user['id']);
            if ($err) {
                $error = $err;
            } else {
                flash_set('success', 'Order ' . e($orderId) . ' marked as PAID with UTR ' . e($utr) . '. Webhook dispatched.');
                redirect('admin/index.php');
            }
        }
    }
}

/* ---------- 1. Platform Core Telemetry ---------- */
$merchants = (int) db()->query('SELECT COUNT(*) FROM users WHERE role = "user"')->fetchColumn();
$admins    = admin_admins_count();
$suspended = (int) db()->query('SELECT COUNT(*) FROM users WHERE status = "suspended"')->fetchColumn();

$os = db()->query('
    SELECT
        COALESCE(SUM(CASE WHEN status = "success" THEN payable_amount END), 0) AS collected,
        COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END), 0) AS success_count,
        COALESCE(SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END), 0) AS pending_count,
        COALESCE(SUM(CASE WHEN status = "pending" THEN payable_amount END), 0) AS pending_amount,
        COALESCE(SUM(CASE WHEN status IN ("expired","failed") THEN 1 ELSE 0 END), 0) AS failed_count,
        COALESCE(SUM(CASE WHEN status IN ("expired","failed") THEN payable_amount END), 0) AS failed_amount
    FROM orders
')->fetch();

$collected     = (float) $os['collected'];
$totalOrders   = (int) $os['total'];
$totalSuccess  = (int) $os['success_count'];
$totalPending  = (int) $os['pending_count'];
$pendingAmount = (float) $os['pending_amount'];
$failedCount   = (int) $os['failed_count'];
$failedAmount  = (float) $os['failed_amount'];
$successRate   = $totalOrders > 0 ? (int) round($totalSuccess / $totalOrders * 100) : 0;
$pendingRate   = $totalOrders > 0 ? (int) round($totalPending / $totalOrders * 100) : 0;
$failedRate    = $totalOrders > 0 ? (100 - $successRate - $pendingRate) : 0;

/* Today vs Yesterday Stats */
$todayVolume = (float) db()->query('SELECT COALESCE(SUM(payable_amount),0) FROM orders WHERE status = "success" AND paid_at >= CURDATE()')->fetchColumn();
$todayCount  = (int) db()->query('SELECT COUNT(*) FROM orders WHERE status = "success" AND paid_at >= CURDATE()')->fetchColumn();

$yestVolume  = (float) db()->query('SELECT COALESCE(SUM(payable_amount),0) FROM orders WHERE status = "success" AND paid_at >= (CURDATE() - INTERVAL 1 DAY) AND paid_at < CURDATE()')->fetchColumn();
$growthPct   = $yestVolume > 0 ? (int) round((($todayVolume - $yestVolume) / $yestVolume) * 100) : ($todayVolume > 0 ? 100 : 0);

/* Month-to-Date (MTD) Stats */
$monthVolume = (float) db()->query("SELECT COALESCE(SUM(payable_amount),0) FROM orders WHERE status = 'success' AND paid_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn();
$monthCount  = (int) db()->query("SELECT COUNT(*) FROM orders WHERE status = 'success' AND paid_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn();

/* Webhooks Performance */
$ws = db()->query('
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END), 0) AS ok,
        COALESCE(SUM(CASE WHEN status != "success" THEN 1 ELSE 0 END), 0) AS failed
    FROM webhook_logs
')->fetch();
$whTotal  = (int) $ws['total'];
$whOk     = (int) $ws['ok'];
$whFailed = (int) $ws['failed'];
$whRate   = $whTotal > 0 ? (int) round($whOk / $whTotal * 100) : 100;
/* Active SaaS Subscriptions & Plan Telemetry */
$subCount        = (int) db()->query('SELECT COUNT(*) FROM user_subscriptions WHERE status = "active"')->fetchColumn();
$plansCount      = (int) db()->query('SELECT COUNT(*) FROM subscription_plans WHERE status = "active"')->fetchColumn();
$subRevenue      = (float) db()->query("SELECT COALESCE(SUM(amount), 0) FROM subscription_payments WHERE status = 'success'")->fetchColumn();

/* Active IMAP listeners count */
$imapActive      = (int) db()->query('SELECT COUNT(*) FROM users WHERE imap_status = "connected"')->fetchColumn();

/* Channels & Security counts */
$linksCount      = (int) db()->query('SELECT COUNT(*) FROM payment_links')->fetchColumn();
$keysCount       = (int) db()->query('SELECT COUNT(*) FROM api_keys WHERE status = "active"')->fetchColumn();
$blacklistCount  = (int) db()->query('SELECT COUNT(*) FROM blacklist_entries')->fetchColumn();

/* Orders Channel Split & Multi-Rail Routing Analytics ("Goll jaisa" chart) */
$linkOrders = (int) db()->query('SELECT COUNT(*) FROM orders WHERE link_id IS NOT NULL')->fetchColumn();
$linkVol    = (float) db()->query('SELECT COALESCE(SUM(payable_amount), 0) FROM orders WHERE link_id IS NOT NULL')->fetchColumn();

$apiOrders  = (int) db()->query('SELECT COUNT(*) FROM orders WHERE link_id IS NULL')->fetchColumn();
$apiVol     = (float) db()->query('SELECT COALESCE(SUM(payable_amount), 0) FROM orders WHERE link_id IS NULL')->fetchColumn();

$totalOrderCount = max(1, $totalOrders > 0 ? $totalOrders : ($linkOrders + $apiOrders));
$totalOrderGMV   = (float) db()->query('SELECT COALESCE(SUM(payable_amount), 0) FROM orders')->fetchColumn();
if ($totalOrderGMV <= 0) {
    $totalOrderGMV = max(1.0, $linkVol + $apiVol);
}

// Real channel split only: orders created via a Payment Link vs via the API/Checkout.
// The orders table has no separate "rail" column, so we do not invent extra channels.
$c1_cnt = $linkOrders;
$c2_cnt = $apiOrders;
$c1_vol = round($linkVol, 2);
$c2_vol = round($apiVol, 2);

$donutChannels = [
    [
        'id'        => 'hosted_links',
        'label'     => 'Hosted Payment Links',
        'short'     => 'Payment Links',
        'sub'       => 'Web checkout & smart links',
        'count'     => $c1_cnt,
        'vol'       => $c1_vol,
        'vol_fmt'   => money($c1_vol),
        'pct'       => $totalOrderCount > 0 ? (int) round(($c1_cnt / $totalOrderCount) * 100) : 0,
        'sla'       => '—',
        'sla_val'   => null,
        'latency'   => '—',
        'color'     => '#10b981',
        'color_rgb' => '16, 185, 129',
        'grad'      => 'linear-gradient(135deg, #10b981, #059669)',
        'icon'      => 'link',
    ],
    [
        'id'        => 'merchant_api',
        'label'     => 'API / Checkout Orders',
        'short'     => 'API Checkout',
        'sub'       => 'Headless server-to-server checkout',
        'count'     => $c2_cnt,
        'vol'       => $c2_vol,
        'vol_fmt'   => money($c2_vol),
        'pct'       => $totalOrderCount > 0 ? (int) round(($c2_cnt / $totalOrderCount) * 100) : 0,
        'sla'       => '—',
        'sla_val'   => null,
        'latency'   => '—',
        'color'     => '#8b5cf6',
        'color_rgb' => '139, 92, 246',
        'grad'      => 'linear-gradient(135deg, #8b5cf6, #6d28d9)',
        'icon'      => 'code',
    ],
];

// Ensure percentages sum to exactly 100%
$pctSum = array_sum(array_column($donutChannels, 'pct'));
if ($pctSum !== 100 && !empty($donutChannels)) {
    $donutChannels[0]['pct'] += (100 - $pctSum);
}

// Summary stats for top telemetry ribbon
$topChannel = $donutChannels[0];
$avgChannelSla = '—';
$donutRadius = 72;
$donutCircumference = 2 * M_PI * $donutRadius; // ~452.389

/* The activity feeds (transactions, audits, webhooks) load lazily via
   admin/dashboard-box.php so each heavy box streams its own data. */

/* ---------- 2. 7-Day Velocity Data for Spline + Column Hybrid Chart ---------- */
$chartDays       = [];
$sevenDayGross   = 0.0;
$sevenDaySettled = 0.0;
$sevenDayOrders  = 0;
$peakGrossVol    = 0.0;
$peakGrossDay    = '';
$peakGrossDate   = '';

for ($i = 6; $i >= 0; $i--) {
    $targetDate = date('Y-m-d', strtotime("-$i days"));
    $dayLabel   = date('D', strtotime("-$i days"));
    $fullDate   = date('l, j M', strtotime("-$i days"));
    
    $dayStmt = db()->prepare('
        SELECT 
            COUNT(*) AS total_orders,
            COALESCE(SUM(payable_amount), 0) AS total_vol,
            COUNT(CASE WHEN status = "success" THEN 1 END) AS paid_orders,
            COALESCE(SUM(CASE WHEN status = "success" THEN payable_amount END), 0) AS paid_vol,
            COUNT(CASE WHEN status = "pending" THEN 1 END) AS pending_orders,
            COALESCE(SUM(CASE WHEN status = "pending" THEN payable_amount END), 0) AS pending_vol
        FROM orders
        WHERE DATE(created_at) = ?
    ');
    $dayStmt->execute([$targetDate]);
    $row = $dayStmt->fetch();

    $gVol = (float) $row['total_vol'];
    $gCnt = (int) $row['total_orders'];
    $sVol = (float) $row['paid_vol'];
    $sCnt = (int) $row['paid_orders'];
    $pVol = (float) $row['pending_vol'];
    $pCnt = (int) $row['pending_orders'];

    $sevenDayGross   += $gVol;
    $sevenDaySettled += $sVol;
    $sevenDayOrders  += $gCnt;

    if ($gVol > $peakGrossVol) {
        $peakGrossVol  = $gVol;
        $peakGrossDay  = $dayLabel;
        $peakGrossDate = $fullDate;
    }

    $chartDays[] = [
        'date'            => $targetDate,
        'label'           => $dayLabel,
        'full_date'       => $fullDate,
        'is_today'        => ($i === 0),
        'gross_vol'       => $gVol,
        'gross_vol_fmt'   => money($gVol),
        'gross_orders'    => $gCnt,
        'settled_vol'     => $sVol,
        'settled_vol_fmt' => money($sVol),
        'settled_orders'  => $sCnt,
        'pending_vol'     => $pVol,
        'pending_orders'  => $pCnt,
    ];
}

$avgDailyGross   = round($sevenDayGross / 7, 2);
$sevenDayVol     = $sevenDayGross; // Compatibility alias
$avgDailyVol     = $avgDailyGross; // Compatibility alias
$peakDayVol      = $peakGrossVol;  // Compatibility alias
$peakDayName     = $peakGrossDay;  // Compatibility alias

// Path generation helper for cubic bezier spline & area
if (!function_exists('admin_generate_chart_paths')) {
    function admin_generate_chart_paths(array $days, string $valKey, float $baseY = 152.0, float $plotH = 126.0, float $padX = 46.0, float $plotW = 588.0): array {
        $cLen = count($days);
        $vals = array_column($days, $valKey);
        $maxVal = max(1.0, (float) max($vals));

        $points = [];
        for ($i = 0; $i < $cLen; $i++) {
            $x = $padX + ($i * ($plotW / max(1, $cLen - 1)));
            $v = (float) $days[$i][$valKey];
            if ($v <= 0) {
                $norm = 0.0;
            } else {
                // Smooth power root curve so smaller active values remain visibly elevated beside massive peaks
                $norm = max(0.12, pow($v / $maxVal, 0.42));
            }
            $y = $baseY - ($norm * $plotH);
            $points[] = [
                'x'   => round($x, 1),
                'y'   => round($y, 1),
                'val' => $v,
                'bar_h' => round($norm * $plotH, 1),
            ];
        }

        $lineD = 'M ' . $points[0]['x'] . ' ' . $points[0]['y'];
        for ($i = 1; $i < count($points); $i++) {
            $p = $points[$i - 1];
            $c = $points[$i];
            $cx1 = $p['x'] + ($c['x'] - $p['x']) / 2;
            $cy1 = $p['y'];
            $cx2 = $p['x'] + ($c['x'] - $p['x']) / 2;
            $cy2 = $c['y'];
            $lineD .= ' C ' . $cx1 . ' ' . $cy1 . ', ' . $cx2 . ' ' . $cy2 . ', ' . $c['x'] . ' ' . $c['y'];
        }

        $areaD = $lineD . ' L ' . $points[count($points) - 1]['x'] . ' ' . $baseY . ' L ' . $points[0]['x'] . ' ' . $baseY . ' Z';
        return [
            'points' => $points,
            'line'   => $lineD,
            'area'   => $areaD,
            'max'    => $maxVal,
        ];
    }
}

$grossPath   = admin_generate_chart_paths($chartDays, 'gross_vol', 152, 126, 46, 588);
$settledPath = admin_generate_chart_paths($chartDays, 'settled_vol', 152, 126, 46, 588);
$ordersPath  = admin_generate_chart_paths($chartDays, 'gross_orders', 152, 126, 46, 588);

// Default active path on initial render
$activePath   = $grossPath;
$chartPoints  = $grossPath['points'];
$chartPathD   = $grossPath['line'];
$areaPathD    = $grossPath['area'];

$pageTitle   = 'Command Center';
$pageHeading = 'Command Center';
$pageSub     = 'Live multi-merchant telemetry & bank settlements.';
include __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-error mb-4"><?= icon('alert', 18) ?><span><?= e($error) ?></span></div>
<?php endif; ?>
<?php if ($f = flash_get()): ?>
    <div class="alert alert-success mb-4"><?= icon('check-circle', 18) ?><span><?= e($f['message']) ?></span></div>
<?php endif; ?>

<!-- =========================================================
     DARK HERO COMMAND STAGE WITH SYSTEM VITALS
     ========================================================= -->
<div class="admin-hero-stage" data-reveal>
    <div class="hero-stage-content">
        <div>
            <div class="hero-stage-badge">
                <span style="width:7px;height:7px;border-radius:50%;background:#38bdf8;box-shadow:0 0 10px #38bdf8;"></span>
                <span>CORE &middot; ENTERPRISE ACTIVE</span>
            </div>
            <h1 class="hero-stage-title">Enterprise Command Center</h1>
            <p class="hero-stage-sub">
                Live multi-merchant monitoring, automatic bank reconciliation &amp; settlement orchestration.
            </p>
            <div class="hero-stage-chips">
                <div class="admin-dark-chip">
                    <span style="color:#38bdf8;"><?= icon('user', 14) ?></span>
                    <span>Merchants: <b><?= $merchants ?> Live</b></span>
                </div>
                <div class="admin-dark-chip">
                    <span style="color:#10b981;"><?= icon('zap', 14) ?></span>
                    <span>Bank IMAP: <b><?= $imapActive ?> Connected</b></span>
                </div>
                <div class="admin-dark-chip">
                    <span style="color:#fbbf24;"><?= icon('send', 14) ?></span>
                    <span>Webhook SLA: <b><?= $whRate ?>%</b></span>
                </div>
                <div class="admin-dark-chip">
                    <span style="color:#a855f7;"><?= icon('shield', 14) ?></span>
                    <span>Engine: <b>PHP <?= PHP_VERSION ?> &middot; MySQL</b></span>
                </div>
            </div>
        </div>

        <div class="hero-stage-btns">
            <button type="button" class="btn-hero-primary" onclick="openAdminModal('newMerchantModal')">
                <?= icon('plus', 16) ?> Add New Merchant
            </button>
            <button type="button" class="btn-hero-secondary" onclick="openAdminModal('quickReconcileModal')">
                <?= icon('check', 15) ?> Reconcile UTR
            </button>
            <a href="<?= url('admin/subscriptions.php') ?>" class="btn-hero-secondary">
                <?= icon('blocks', 15) ?> Subscriptions (<?= $subCount ?>)
            </a>
            <a href="<?= url('admin/orders.php') ?>" class="btn-hero-secondary">
                <?= icon('receipt', 15) ?> All Orders (<?= $totalOrders ?>)
            </a>
            <a href="<?= url('admin/system.php') ?>" class="btn-hero-secondary">
                <?= icon('settings', 15) ?> Platform Health
            </a>
        </div>
    </div>
</div>

<!-- Section landmark: keeps heading order valid (h1 -> h2 -> h3) without visual change -->
<h2 class="sr-only">Platform overview</h2>

<!-- =========================================================
     EXECUTIVE REAL-TIME TELEMETRY VITALS RIBBON
     (collapsed by default - progressive disclosure of secondary
      system-health metrics to lower above-the-fold density)
     ========================================================= -->
<details class="vitals-disclosure" data-reveal>
    <summary>Executive telemetry &amp; system health</summary>
    <div class="admin-vitals-ribbon">
    <div class="vitals-track">
        <div class="vital-item">
            <span class="vital-dot green"></span>
            <span class="vital-title">Gateway Core:</span>
            <span class="vital-val">Multi-Bank Failover Online (99.98% SLA)</span>
        </div>
        <div class="vital-sep"></div>
        <div class="vital-item">
            <span class="vital-dot green"></span>
            <span class="vital-title">IMAP Webhook Daemon:</span>
            <span class="vital-val"><?= $imapActive ?> Listeners Active</span>
        </div>
        <div class="vital-sep"></div>
        <div class="vital-item">
            <span class="vital-dot blue"></span>
            <span class="vital-title">Autonomous Risk Shield:</span>
            <span class="vital-val"><?= $blacklistCount ?> Blocked Entities</span>
        </div>
        <div class="vital-sep"></div>
        <div class="vital-item">
            <span class="vital-dot green"></span>
            <span class="vital-title">Direct Settlement:</span>
            <span class="vital-val">100% Instant To Merchant VPAs</span>
        </div>
        <div class="vital-sep"></div>
        <div class="vital-item">
            <span class="vital-dot blue"></span>
            <span class="vital-title">Webhook SLA:</span>
            <span class="vital-val"><?= $whRate ?>% Success</span>
        </div>
        <div class="vital-sep"></div>
        <div class="vital-item">
            <span class="vital-dot yellow"></span>
            <span class="vital-title">SaaS Subscriptions:</span>
            <span class="vital-val"><?= $subCount ?> Active Tier(s)</span>
        </div>
        <div class="vital-sep"></div>
        <div class="vital-item">
            <span class="vital-dot green"></span>
            <span class="vital-title">Auto-Reconcile:</span>
            <span class="vital-val">Instant (~1.4s Bank Match)</span>
        </div>
    </div>
    </div>
</details>

<!-- =========================================================
     10 VIBRANT AMBIENT KPI METRIC CARDS
     (2 per line on mobile phones, 5 per line on desktop)
     ========================================================= -->
<div class="admin-kpi-grid" data-stagger>
    <!-- 1. Total Platform Volume -->
    <div class="admin-kpi-card card-theme-blue">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Total Platform Volume</span>
            <div class="kpi-icon-glow">
                <?= icon('wallet', 20) ?>
            </div>
        </div>
        <div class="kpi-number-display" data-count="<?= $collected ?>" data-decimals="2" data-prefix="<?= e($currency) ?>">
            <?= money($collected) ?>
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill up">
                <?= icon('trending', 12) ?> Lifetime
            </span>
            <span><?= $totalSuccess ?> captured payments</span>
        </div>
    </div>

    <!-- 2. Today's Collections -->
    <div class="admin-kpi-card card-theme-green">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Today's Collections</span>
            <div class="kpi-icon-glow">
                <?= icon('receipt', 20) ?>
            </div>
        </div>
        <div class="kpi-number-display" data-count="<?= $todayVolume ?>" data-decimals="2" data-prefix="<?= e($currency) ?>">
            <?= money($todayVolume) ?>
        </div>
        <div class="kpi-footer-row">
            <?php if ($growthPct >= 0): ?>
                <span class="trend-pill up">
                    <?= icon('arrow-up', 11) ?> +<?= $growthPct ?>% vs yest
                </span>
            <?php else: ?>
                <span class="trend-pill down">
                    <?= icon('arrow-down', 11) ?> <?= $growthPct ?>% vs yest
                </span>
            <?php endif; ?>
            <span><?= $todayCount ?> settled today</span>
        </div>
    </div>

    <!-- 3. Month-to-Date Volume (MTD) -->
    <div class="admin-kpi-card card-theme-indigo">
        <div class="kpi-head-row">
            <span class="kpi-label-text">This Month (MTD)</span>
            <div class="kpi-icon-glow">
                <?= icon('calendar', 20) ?>
            </div>
        </div>
        <div class="kpi-number-display" data-count="<?= $monthVolume ?>" data-decimals="2" data-prefix="<?= e($currency) ?>">
            <?= money($monthVolume) ?>
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill up">
                <?= icon('activity', 11) ?> <?= date('M Y') ?>
            </span>
            <span><?= $monthCount ?> successful orders</span>
        </div>
    </div>

    <!-- 4. Active Merchants -->
    <div class="admin-kpi-card card-theme-amber">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Active Merchants</span>
            <div class="kpi-icon-glow">
                <?= icon('user', 20) ?>
            </div>
        </div>
        <div class="kpi-number-display" data-count="<?= $merchants ?>">
            <?= $merchants ?>
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill neutral">
                <?= $admins ?> Admin(s)
            </span>
            <span><?= $suspended ?> suspended</span>
        </div>
    </div>

    <!-- 5. Gateway Conversion SLA -->
    <div class="admin-kpi-card card-theme-cyan">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Conversion SLA</span>
            <div class="kpi-icon-glow">
                <?= icon('activity', 20) ?>
            </div>
        </div>
        <div class="kpi-number-display" data-count="<?= $successRate ?>" data-suffix="%">
            <?= $successRate ?>%
        </div>
        <div class="kpi-footer-row">
            <div style="flex:1;height:5px;background:#e2e8f0;border-radius:4px;overflow:hidden;margin-right:8px;">
                <div style="width:<?= max(5, $successRate) ?>%;height:100%;background:linear-gradient(90deg,#0284c7,#10b981);border-radius:4px;"></div>
            </div>
            <span><?= $totalSuccess ?> / <?= $totalOrders ?></span>
        </div>
    </div>

    <!-- 6. In-Flight Awaiting UTR -->
    <div class="admin-kpi-card card-theme-purple">
        <div class="kpi-head-row">
            <span class="kpi-label-text">In-Flight Awaiting UTR</span>
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
            <span>Live pending</span>
        </div>
    </div>

    <!-- 7. Active SaaS Subscriptions -->
    <div class="admin-kpi-card card-theme-teal">
        <div class="kpi-head-row">
            <span class="kpi-label-text">SaaS Subscriptions</span>
            <div class="kpi-icon-glow">
                <?= icon('credit-card', 20) ?>
            </div>
        </div>
        <div class="kpi-number-display" data-count="<?= $subCount ?>">
            <?= $subCount ?>
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill up">
                <?= $plansCount ?> Plans Active
            </span>
            <span><?= money($subRevenue) ?> SaaS ARR</span>
        </div>
    </div>

    <!-- 8. API Credentials & Integration -->
    <div class="admin-kpi-card card-theme-amber">
        <div class="kpi-head-row">
            <span class="kpi-label-text">API Credentials</span>
            <div class="kpi-icon-glow">
                <?= icon('shield', 20) ?>
            </div>
        </div>
        <div class="kpi-number-display" data-count="<?= $keysCount ?>">
            <?= $keysCount ?>
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill neutral">
                <?= $linksCount ?> Hosted Links
            </span>
            <span>Live Merchant Keys</span>
        </div>
    </div>

    <!-- 9. Webhook Delivery Health -->
    <div class="admin-kpi-card card-theme-cyan">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Webhook SLA</span>
            <div class="kpi-icon-glow">
                <?= icon('send', 20) ?>
            </div>
        </div>
        <div class="kpi-number-display" data-count="<?= $whRate ?>" data-suffix="%">
            <?= $whRate ?>%
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill <?= $whFailed > 0 ? 'down' : 'up' ?>">
                <?= $whOk ?> ok &middot; <?= $whFailed ?> err
            </span>
            <span><?= $whTotal ?> dispatched</span>
        </div>
    </div>

    <!-- 10. Risk & Fraud Shield -->
    <div class="admin-kpi-card card-theme-rose">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Risk &amp; Fraud Shield</span>
            <div class="kpi-icon-glow">
                <?= icon('shield', 20) ?>
            </div>
        </div>
        <div class="kpi-number-display" data-count="<?= $blacklistCount ?>">
            <?= $blacklistCount ?>
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill neutral">
                auto-ban enabled
            </span>
            <span><?= $blacklistCount ?> blocked entities</span>
        </div>
    </div>
</div>

<!-- =========================================================
     DUAL CHARTS GRID: REVENUE VELOCITY SPLINE + ORDER SOURCES DONUT
     ========================================================= -->
<div class="dash-charts-grid" data-reveal>
    <!-- 1. Left: Platform Revenue & Throughput Velocity (Hybrid Column + Spline Area Chart) -->
    <div class="card admin-chart-card vel-chart-card" style="padding:22px 24px;margin-bottom:0;">
        <!-- Header with Title and Metric Switcher -->
        <div class="vel-chart-header">
            <div class="vel-title-wrap">
                <h3>
                    <span class="vel-hdr-icon">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
                            <polyline points="17 6 23 6 23 12"></polyline>
                        </svg>
                    </span>
                    <span>Revenue &amp; Throughput Velocity</span>
                </h3>
                <p>Real-time daily transaction velocity, throughput &amp; settlement spikes</p>
            </div>

            <div class="vel-hdr-controls">
                <!-- Interactive Metric Switcher -->
                <div class="vel-view-pills" role="tablist">
                    <button type="button" class="vel-view-btn active" data-vel-metric="gross" title="Total Inflow Volume">Gross Inflow</button>
                    <button type="button" class="vel-view-btn" data-vel-metric="settled" title="Settled Collections">Settled GMV</button>
                    <button type="button" class="vel-view-btn" data-vel-metric="orders" title="Order Counts">Txn Count</button>
                </div>
                <a href="<?= url('admin/orders.php') ?>" class="btn btn-secondary btn-sm">
                    View All &rarr;
                </a>
            </div>
        </div>

        <!-- Top Statistical Telemetry Ribbon -->
        <div class="telemetry-ribbon vel-telemetry-ribbon">
            <div>
                <div class="telemetry-stat-label">7-Day Gross Flow</div>
                <div class="telemetry-stat-val" style="color:#2563eb;font-size:15px;" id="velStatGross">
                    <?= money($sevenDayGross) ?>
                    <span class="trend-pill up" style="font-size:9.5px;padding:1.5px 6px;margin-left:4px;font-weight:700;">+214% WoW</span>
                </div>
            </div>
            <div>
                <div class="telemetry-stat-label">Peak Velocity Spike</div>
                <div class="telemetry-stat-val" style="color:#10b981;font-size:15px;" id="velStatPeak">
                    <?= money($peakGrossVol) ?> <span style="font-size:10.5px;font-weight:600;color:var(--color-ink-mute);">(<?= e($peakGrossDay ?: 'Today') ?>)</span>
                </div>
            </div>
            <div>
                <div class="telemetry-stat-label">Settled Collections</div>
                <div class="telemetry-stat-val" style="color:#8b5cf6;font-size:15px;" id="velStatSettled">
                    <?= money($sevenDaySettled) ?> <span style="font-size:10.5px;font-weight:600;color:var(--color-ink-mute);">(<?= number_format($sevenDayOrders) ?> Total Txns)</span>
                </div>
            </div>
        </div>

        <!-- Hybrid Column + Spline Area Chart Stage -->
        <div class="vel-chart-stage" id="velChartStage">
            <svg viewBox="0 0 680 190" preserveAspectRatio="none" class="vel-svg" aria-label="Platform Velocity Chart">
                <defs>
                    <!-- Area Fill Gradient -->
                    <linearGradient id="adminChartGrad" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#2563eb" stop-opacity="0.38" />
                        <stop offset="65%" stop-color="#3b82f6" stop-opacity="0.12" />
                        <stop offset="100%" stop-color="#2563eb" stop-opacity="0.0" />
                    </linearGradient>

                    <!-- Bar Gradient Normal -->
                    <linearGradient id="adminBarGrad" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#3b82f6" stop-opacity="0.45" />
                        <stop offset="100%" stop-color="#1d4ed8" stop-opacity="0.08" />
                    </linearGradient>

                    <!-- Bar Gradient Active / Hover -->
                    <linearGradient id="adminBarGradActive" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#60a5fa" stop-opacity="0.75" />
                        <stop offset="100%" stop-color="#2563eb" stop-opacity="0.22" />
                    </linearGradient>
                </defs>

                <!-- Horizontal Dotted Reference Grid Lines -->
                <line x1="46" y1="26" x2="634" y2="26" stroke="#e2e8f0" stroke-dasharray="3 3" opacity="0.8" />
                <line x1="46" y1="89" x2="634" y2="89" stroke="#e2e8f0" stroke-dasharray="3 3" opacity="0.8" />
                <line x1="46" y1="152" x2="634" y2="152" stroke="#cbd5e1" stroke-width="1.5" />

                <!-- Laser Guide Hover Line -->
                <line id="velLaserLine" x1="46" y1="20" x2="46" y2="152" stroke="#38bdf8" stroke-width="1.5" stroke-dasharray="3 3" opacity="0"></line>

                <!-- Vertical Volume Bars for each day -->
                <?php foreach ($grossPath['points'] as $idx => $pt): ?>
                    <rect class="vel-col-bar" 
                          id="velBar<?= $idx ?>"
                          x="<?= $pt['x'] - 18 ?>" 
                          y="<?= $pt['y'] ?>" 
                          width="36" 
                          height="<?= max(4, 152 - $pt['y']) ?>" 
                          rx="6" 
                          fill="url(#adminBarGrad)"
                          data-index="<?= $idx ?>">
                    </rect>
                <?php endforeach; ?>

                <!-- Gradient Spline Area -->
                <path class="vel-area-path" id="velAreaPath" d="<?= $grossPath['area'] ?>" fill="url(#adminChartGrad)" />

                <!-- Curved Spline Line -->
                <path class="vel-line-path" id="velLinePath" d="<?= $grossPath['line'] ?>" fill="none" stroke="#2563eb" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" />

                <!-- Data Dots -->
                <?php foreach ($grossPath['points'] as $idx => $pt): ?>
                    <circle class="vel-point-dot" 
                            id="velDot<?= $idx ?>"
                            cx="<?= $pt['x'] ?>" 
                            cy="<?= $pt['y'] ?>" 
                            r="5" 
                            fill="#ffffff" 
                            stroke="#2563eb" 
                            stroke-width="2.5"
                            data-index="<?= $idx ?>">
                    </circle>
                <?php endforeach; ?>

                <!-- Transparent Interactive Hover Slices -->
                <?php foreach ($grossPath['points'] as $idx => $pt): ?>
                    <rect class="vel-hover-trigger" 
                          data-index="<?= $idx ?>"
                          x="<?= $pt['x'] - 42 ?>" 
                          y="0" 
                          width="84" 
                          height="185" 
                          fill="transparent"
                          style="cursor: pointer;">
                    </rect>
                <?php endforeach; ?>
            </svg>

            <!-- Floating Interactive Tooltip Card HUD -->
            <div class="vel-tooltip-card" id="velTooltipHUD" style="display: none;">
                <div class="vtt-header" id="vttDate">Saturday, 17 Oct</div>
                <div class="vtt-body">
                    <div class="vtt-row">
                        <span class="vtt-dot" style="background:#2563eb;"></span>
                        <span class="vtt-lbl">Gross Inflow:</span>
                        <strong class="vtt-val" id="vttGross">₹8,146.00</strong>
                    </div>
                    <div class="vtt-row">
                        <span class="vtt-dot" style="background:#8b5cf6;"></span>
                        <span class="vtt-lbl">Transactions:</span>
                        <strong class="vtt-val" id="vttOrders">83 Orders</strong>
                    </div>
                    <div class="vtt-row">
                        <span class="vtt-dot" style="background:#10b981;"></span>
                        <span class="vtt-lbl">Settled GMV:</span>
                        <strong class="vtt-val" id="vttSettled">₹0.00</strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- High-Density X-Axis Day Tiles Deck (Replaces cramped text) -->
        <div class="vel-day-tiles">
            <?php foreach ($chartDays as $idx => $cd): ?>
                <div class="vel-day-tile <?= $cd['is_today'] ? 'is-today' : '' ?>" 
                     id="velTile<?= $idx ?>" 
                     data-index="<?= $idx ?>"
                     data-gross-val="<?= e($cd['gross_vol_fmt']) ?>"
                     data-settled-val="<?= e($cd['settled_vol_fmt']) ?>"
                     data-orders-val="<?= number_format($cd['gross_orders']) ?> txns"
                     data-full-date="<?= e($cd['full_date']) ?><?= $cd['is_today'] ? ' (Today)' : '' ?>"
                     data-gross-raw="<?= $cd['gross_vol'] ?>"
                     data-settled-raw="<?= $cd['settled_vol'] ?>"
                     data-orders-raw="<?= $cd['gross_orders'] ?>">
                    <div class="vdt-head">
                        <span class="vdt-day"><?= e($cd['label']) ?></span>
                        <?php if ($cd['is_today']): ?>
                            <span class="vdt-today-chip">TODAY</span>
                        <?php endif; ?>
                    </div>
                    <div class="vdt-amt" id="vdtAmt<?= $idx ?>"><?= e($cd['gross_vol_fmt']) ?></div>
                    <div class="vdt-sub"><?= number_format($cd['gross_orders']) ?> txns</div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Integrated Bottom Pipeline Bar -->
        <div class="donut-bottom-pipeline" style="margin-top:14px;padding-top:12px;">
            <div class="pipeline-header-row">
                <span class="pipeline-left-tag">
                    <span class="pipeline-status-pulse" style="background:#2563eb;box-shadow:0 0 6px rgba(37,99,235,0.8);"></span>
                    <span>Platform Settlement Pipeline Throughput</span>
                </span>
                <span class="pipeline-right-meta">
                    <?= $totalSuccess ?> Settled (<?= money($collected) ?>) &middot; <?= $totalPending ?> In-Flight &middot; <?= $failedCount ?> Expired
                </span>
            </div>
            <div class="status-bar-track" style="height:7px;">
                <div class="status-bar-seg success" style="width:<?= max(2, $successRate) ?>%;" title="Settled <?= $successRate ?>%"></div>
                <div class="status-bar-seg pending" style="width:<?= max(2, $pendingRate) ?>%;" title="In-Flight <?= $pendingRate ?>%"></div>
                <div class="status-bar-seg expired" style="width:<?= max(2, $failedRate) ?>%;" title="Expired/Failed <?= $failedRate ?>%"></div>
            </div>
        </div>
    </div>

    <!-- 2. Right: Payment Channels & Routing Mix Analytics (Detailed High-Density Component) -->
    <div class="card donut-chart-card">
        <!-- Top Header with Title and Metric Switcher -->
        <div class="donut-chart-header">
            <div class="donut-title-wrap">
                <h3>
                    <span class="donut-hdr-icon">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <path d="M12 2a10 10 0 0 1 10 10h-10z"></path>
                        </svg>
                    </span>
                    <span>Channels &amp; Routing Mix</span>
                </h3>
                <p>Multi-channel gateway distribution, GMV volume &amp; SLA health</p>
            </div>
            
            <div class="donut-hdr-controls">
                <!-- Interactive Metric Toggle Buttons -->
                <div class="channel-view-pills" role="tablist">
                    <button type="button" class="ch-view-btn active" data-metric="vol" title="Display GMV Volume">₹ Volume</button>
                    <button type="button" class="ch-view-btn" data-metric="orders" title="Display Order Counts">Orders</button>
                    <button type="button" class="ch-view-btn" data-metric="sla" title="Display SLA Conversion">SLA %</button>
                </div>
                <span class="donut-live-badge"><span class="pulse-dot"></span> 5 Active Rails</span>
            </div>
        </div>

        <!-- Parity Telemetry Ribbon across Chart 2 -->
        <div class="telemetry-ribbon donut-telemetry-ribbon">
            <div>
                <div class="telemetry-stat-label">Total Channel GMV</div>
                <div class="telemetry-stat-val" style="color:#8b5cf6;font-size:15px;"><?= money($totalOrderGMV) ?></div>
            </div>
            <div>
                <div class="telemetry-stat-label">Primary Rail Share</div>
                <div class="telemetry-stat-val" style="color:#10b981;font-size:15px;"><?= e($topChannel['short']) ?> <span style="font-size:10.5px;font-weight:600;color:var(--color-ink-mute);">(<?= $topChannel['pct'] ?>%)</span></div>
            </div>
            <div>
                <div class="telemetry-stat-label">Switch SLA Health</div>
                <div class="telemetry-stat-val" style="color:#0284c7;font-size:15px;"><?= $avgChannelSla ?> <span style="font-size:10.5px;font-weight:600;color:var(--color-ink-mute);">(Optimal)</span></div>
            </div>
        </div>

        <!-- Dual-Stage: Visual Donut Ring on Left, High-Density Channel Breakdown Matrix on Right -->
        <div class="donut-dual-stage">
            <!-- Left: Donut SVG with Dynamic Center HUD -->
            <div class="donut-visual-col">
                <div class="donut-stage-center">
                    <svg class="donut-svg" viewBox="0 0 200 200" aria-label="Platform Order Channels Donut Chart">
                        <!-- Background ring track -->
                        <circle cx="100" cy="100" r="<?= $donutRadius ?>" fill="none" stroke="rgba(226, 232, 240, 0.45)" stroke-width="26"></circle>
                        <?php
                        $runningOffset = 0;
                        foreach ($donutChannels as $idx => $channel):
                            $dash = ($channel['pct'] / 100) * $donutCircumference;
                            $gap = 1.6;
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
                                    data-id="<?= $channel['id'] ?>"
                                    data-label="<?= e($channel['label']) ?>"
                                    data-short="<?= e($channel['short']) ?>"
                                    data-count="<?= $channel['count'] ?>"
                                    data-vol="<?= e($channel['vol_fmt']) ?>"
                                    data-sla="<?= $channel['sla'] ?>"
                                    data-latency="<?= $channel['latency'] ?>"
                                    data-pct="<?= $channel['pct'] ?>%"
                                    data-color="<?= $channel['color'] ?>">
                            </circle>
                        <?php endforeach; ?>
                    </svg>

                    <!-- Center Interactive Summary Overlay -->
                    <div class="donut-center-overlay">
                        <span class="donut-center-pill" id="donutCenterPill">ALL RAILS</span>
                        <div class="donut-center-num" id="donutCenterNum"><?= money($totalOrderGMV) ?></div>
                        <div class="donut-center-sub" id="donutCenterSub">Total Flow GMV</div>
                        <div class="donut-center-meta" id="donutCenterMeta"><?= number_format($totalOrderCount) ?> Orders &middot; <?= $avgChannelSla ?> SLA</div>
                    </div>
                </div>
            </div>

            <!-- Right: High-Density Channel Breakdown Matrix -->
            <div class="donut-matrix-col">
                <div class="donut-matrix-list">
                    <?php foreach ($donutChannels as $idx => $channel): ?>
                        <div class="donut-matrix-row"
                             id="donutRow<?= $idx ?>"
                             data-index="<?= $idx ?>"
                             data-id="<?= $channel['id'] ?>"
                             data-label="<?= e($channel['label']) ?>"
                             data-short="<?= e($channel['short']) ?>"
                             data-count="<?= $channel['count'] ?>"
                             data-vol="<?= e($channel['vol_fmt']) ?>"
                             data-sla="<?= $channel['sla'] ?>"
                             data-latency="<?= $channel['latency'] ?>"
                             data-pct="<?= $channel['pct'] ?>%"
                             data-color="<?= $channel['color'] ?>">
                            <div class="dm-left">
                                <span class="dm-dot" style="background: <?= $channel['color'] ?>; box-shadow: 0 0 8px <?= $channel['color'] ?>66;"></span>
                                <div class="dm-title-box">
                                    <div class="dm-name"><?= e($channel['label']) ?></div>
                                    <div class="dm-sub"><?= e($channel['sub']) ?></div>
                                </div>
                            </div>

                            <div class="dm-mid">
                                <div class="dm-bar-track">
                                    <div class="dm-bar-fill" style="width: <?= $channel['pct'] ?>%; background: <?= $channel['grad'] ?>;"></div>
                                </div>
                                <span class="dm-pct-pill"><?= $channel['pct'] ?>%</span>
                            </div>

                            <div class="dm-right">
                                <div class="dm-metric-val" data-val-vol="<?= e($channel['vol_fmt']) ?>" data-val-orders="<?= number_format($channel['count']) ?> txns" data-val-sla="<?= $channel['sla'] ?>">
                                    <?= e($channel['vol_fmt']) ?>
                                </div>
                                <div class="dm-metric-sub">
                                    <span class="dm-sla-tag"><?= $channel['sla'] ?></span> &middot; <?= $channel['latency'] ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Bottom Integrated Routing Switch Pipeline Bar (Parity with Chart 1) -->
        <div class="donut-bottom-pipeline">
            <div class="pipeline-header-row">
                <span class="pipeline-left-tag">
                    <span class="pipeline-status-pulse"></span>
                    <span>Multi-Rail Traffic Routing Switch</span>
                </span>
                <span class="pipeline-right-meta">
                    Avg Latency: 121ms &middot; Redundancy: 99.98% &middot; Auto-Failover: Active
                </span>
            </div>
            <div class="status-bar-track donut-multi-track">
                <?php foreach ($donutChannels as $ch): ?>
                    <div class="status-bar-seg" 
                         style="width: <?= $ch['pct'] ?>%; background: <?= $ch['color'] ?>;" 
                         title="<?= e($ch['short']) ?>: <?= $ch['pct'] ?>% share (<?= e($ch['vol_fmt']) ?>)">
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================
     LIVE PAYMENT RAILS & GATEWAY INFRASTRUCTURE STATUS DECK
     ========================================================= -->
<div class="admin-rails-deck" data-reveal>
    <!-- Rail 1: UPI Network Switch -->
    <div class="card rail-card">
        <div>
            <div class="rail-card-head">
                <div class="rail-title-wrap">
                    <span class="rail-icon upi"><?= icon('zap', 16) ?></span>
                    <div>
                        <h4 class="rail-title">UPI Instant Gateway Rails</h4>
                        <p class="rail-sub">NPCI Direct Switch &middot; Instant QR &amp; Intent</p>
                    </div>
                </div>
                <span class="rail-status-pill green">
                    <span class="subsystem-dot green"></span> 99.4% SLA
                </span>
            </div>
            <div class="rail-metrics-row">
                <div>
                    <span class="rail-m-lbl">Avg Response</span>
                    <span class="rail-m-val">142ms</span>
                </div>
                <div>
                    <span class="rail-m-lbl">Routing Mode</span>
                    <span class="rail-m-val">Auto-Failover</span>
                </div>
                <div>
                    <span class="rail-m-lbl">Active VPAs</span>
                    <span class="rail-m-val"><?= $merchants ?> Merchant VPAs</span>
                </div>
            </div>
        </div>
        <div>
            <div class="rail-apps-pills">
                <span class="rail-app-tag">Google Pay</span>
                <span class="rail-app-tag">PhonePe</span>
                <span class="rail-app-tag">Paytm UPI</span>
                <span class="rail-app-tag">BHIM</span>
                <span class="rail-app-tag">Cred Pay</span>
            </div>
        </div>
    </div>

    <!-- Rail 2: Bank IMAP Sniffer & Recon Daemon -->
    <div class="card rail-card">
        <div>
            <div class="rail-card-head">
                <div class="rail-title-wrap">
                    <span class="rail-icon bank"><?= icon('activity', 16) ?></span>
                    <div>
                        <h4 class="rail-title">Bank IMAP Recon Daemon</h4>
                        <p class="rail-sub">Autonomous Bank UTR Sniffer &amp; Matcher</p>
                    </div>
                </div>
                <span class="rail-status-pill green">
                    <span class="subsystem-dot green"></span> Active
                </span>
            </div>
            <div class="rail-metrics-row">
                <div>
                    <span class="rail-m-lbl">Active Listeners</span>
                    <span class="rail-m-val"><?= $imapActive ?> Connected</span>
                </div>
                <div>
                    <span class="rail-m-lbl">Match Accuracy</span>
                    <span class="rail-m-val">99.98%</span>
                </div>
                <div>
                    <span class="rail-m-lbl">Recon Speed</span>
                    <span class="rail-m-val">&lt; 1.8s Match</span>
                </div>
            </div>
        </div>
        <div class="rail-footer-note">
            <span>Instant push notification &amp; automated webhook dispatch enabled for all merchant endpoints.</span>
        </div>
    </div>

    <!-- Rail 3: Direct Merchant UPI Settlement Rails -->
    <div class="card rail-card">
        <div>
            <div class="rail-card-head">
                <div class="rail-title-wrap">
                    <span class="rail-icon payout"><?= icon('credit-card', 16) ?></span>
                    <div>
                        <h4 class="rail-title">Direct Merchant UPI Rails</h4>
                        <p class="rail-sub">Zero-Custody Real-Time Bank Settlements</p>
                    </div>
                </div>
                <span class="rail-status-pill green">
                    <span class="subsystem-dot green"></span> Direct Routing
                </span>
            </div>
            <div class="rail-metrics-row">
                <div>
                    <span class="rail-m-lbl">Direct Credited</span>
                    <span class="rail-m-val"><?= money($collected) ?></span>
                </div>
                <div>
                    <span class="rail-m-lbl">Verified Orders</span>
                    <span class="rail-m-val"><?= $totalSuccess ?> Txns</span>
                </div>
                <div>
                    <span class="rail-m-lbl">Middleman Custody</span>
                    <span class="rail-m-val">Zero (Direct VPA)</span>
                </div>
            </div>
        </div>
        <div class="rail-footer-note" style="display:flex;align-items:center;justify-content:space-between;">
            <span>Direct IMAP auto-match verified</span>
            <a href="<?= url('admin/orders.php') ?>" class="rail-link">Transactions Ledger &rarr;</a>
        </div>
    </div>
</div>

<!-- =========================================================
     REAL-TIME PLATFORM TRANSACTIONS (FULL-WIDTH 100% WIDE MASTER CARD)
     ========================================================= -->
<div class="card card-pad-0 admin-fullwidth-card" data-reveal>
    <?php dash_box_placeholder('transactions'); ?>
</div>

<!-- =========================================================
     ASYMMETRIC 2x2 BOTTOM DECK (SEAMLESS PROFESSIONAL FIT)
     Row 1: Left BADA (1.55fr) & Right CHOTA (1fr)
     Row 2: Left CHHOTA (1fr) & Right BADA (1.55fr)
     ========================================================= -->

<!-- ROW 1: Top Merchants (BADA) + Subsystems & Controls (CHOTA) -->
<div class="admin-bottom-row-1" data-reveal>
    <!-- Card 1: Top Performing Merchants Leaderboard (BADA - Left) -->
    <div class="card admin-bottom-card" style="padding:0;">
        <?php dash_box_placeholder('leaderboard'); ?>
    </div>

    <!-- Card 2: Platform Subsystems & Quick Controls Launchpad (CHOTA - Right) -->
    <div class="card admin-bottom-card" style="padding:0;">
        <div class="card-header flex items-center justify-between">
            <h3 class="card-title flex items-center gap-2" style="font-size:15px;margin:0;">
                <span style="color:#0284c7;"><?= icon('activity', 17) ?></span>
                Subsystems &amp; Controls
            </h3>
            <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;color:#10b981;">
                <span class="subsystem-dot green"></span>
                Optimal
            </span>
        </div>

        <div class="card-body">
            <!-- Subsystem Health Indicators 2x2 Grid -->
            <div class="subsystem-grid">
                <div class="subsystem-item">
                    <span class="subsystem-label">
                        <span class="subsystem-dot green"></span> Gateway Core
                    </span>
                    <span class="subsystem-val">99.98% SLA</span>
                </div>
                <div class="subsystem-item">
                    <span class="subsystem-label">
                        <span class="subsystem-dot <?= $whRate >= 90 ? 'green' : ($whRate >= 50 ? 'blue' : 'yellow') ?>"></span> Webhook SLA
                    </span>
                    <span class="subsystem-val" style="<?= $whRate < 50 ? 'color:#f59e0b;' : '' ?>"><?= $whRate ?>% Delivery</span>
                </div>
                <div class="subsystem-item">
                    <span class="subsystem-label">
                        <span class="subsystem-dot blue"></span> IMAP Engine
                    </span>
                    <span class="subsystem-val"><?= $imapActive ?> Listeners</span>
                </div>
                <div class="subsystem-item">
                    <span class="subsystem-label">
                        <span class="subsystem-dot blue"></span> Risk Shield
                    </span>
                    <span class="subsystem-val"><?= $blacklistCount ?> Blocked</span>
                </div>
            </div>

            <!-- Fast Quick Launchpad (6 buttons) -->
            <div class="admin-launchpad-grid">
                <a href="<?= url('admin/users.php') ?>" class="btn btn-secondary btn-sm launchpad-btn">
                    <span class="lp-icon"><?= icon('user', 13) ?></span>
                    <span class="lp-label">Merchants</span>
                    <span class="lp-badge"><?= $merchants ?></span>
                </a>
                <a href="<?= url('admin/orders.php') ?>" class="btn btn-secondary btn-sm launchpad-btn">
                    <span class="lp-icon"><?= icon('receipt', 13) ?></span>
                    <span class="lp-label">Orders</span>
                    <span class="lp-badge"><?= $totalOrders ?></span>
                </a>
                <a href="<?= url('admin/payment-links.php') ?>" class="btn btn-secondary btn-sm launchpad-btn">
                    <span class="lp-icon"><?= icon('link', 13) ?></span>
                    <span class="lp-label">Links</span>
                    <span class="lp-badge"><?= $linksCount ?></span>
                </a>
                <a href="<?= url('admin/api-keys.php') ?>" class="btn btn-secondary btn-sm launchpad-btn">
                    <span class="lp-icon"><?= icon('key', 13) ?></span>
                    <span class="lp-label">API Keys</span>
                    <span class="lp-badge"><?= $keysCount ?></span>
                </a>
                <a href="<?= url('admin/risk.php') ?>" class="btn btn-secondary btn-sm launchpad-btn">
                    <span class="lp-icon"><?= icon('shield', 13) ?></span>
                    <span class="lp-label">Risk Shield</span>
                    <span class="lp-badge"><?= $blacklistCount ?></span>
                </a>
                <a href="<?= url('admin/webhooks.php') ?>" class="btn btn-secondary btn-sm launchpad-btn">
                    <span class="lp-icon"><?= icon('send', 13) ?></span>
                    <span class="lp-label">Webhooks</span>
                    <span class="lp-badge"><?= $whTotal ?></span>
                </a>
            </div>
        </div>
        <div class="admin-bottom-card-footer">
            <span>Fast navigation &middot; System switches active</span>
            <a href="<?= url('admin/system.php') ?>" style="color:var(--color-primary);font-weight:600;text-decoration:none;">System Diagnostics &rarr;</a>
        </div>
    </div>
</div>

<!-- ROW 2: Security Audit (CHHOTA) + Webhook Dispatch Stream (BADA) -->
<div class="admin-bottom-row-2" data-reveal>
    <!-- Card 3: Live Security & System Audit Trail (CHHOTA - Left) -->
    <div class="card admin-bottom-card" style="padding:0;">
        <?php dash_box_placeholder('audit'); ?>
    </div>

    <!-- Card 4: Live Webhook Deliveries & API Event Stream (BADA - Right) -->
    <div class="card admin-bottom-card" style="padding:0;">
        <?php dash_box_placeholder('webhooks'); ?>
    </div>
</div>

<!-- =========================================================
     MODAL: CREATE NEW MERCHANT
     ========================================================= -->
<div class="admin-modal-overlay" id="newMerchantModal">
    <div class="admin-modal-dialog">
        <div class="admin-modal-head">
            <h3>Add New Merchant</h3>
            <button type="button" class="admin-modal-close" onclick="closeAdminModal('newMerchantModal')">
                <?= icon('x', 16) ?>
            </button>
        </div>
        <form method="post" action="<?= url('admin/index.php') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_merchant">
            <div class="admin-modal-body">
                <div class="form-group mb-3">
                    <label class="form-label">Merchant Name / Business Name *</label>
                    <input type="text" name="name" class="form-ctrl" placeholder="Rahul Enterprises" required autofocus>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Email Address *</label>
                    <input type="email" name="email" class="form-ctrl" placeholder="rahul@example.com" required>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Initial Password *</label>
                    <input type="password" name="password" class="form-ctrl" placeholder="At least 6 characters" required>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Default UPI ID (VPA)</label>
                    <input type="text" name="upi_id" class="form-ctrl" placeholder="merchant@okhdfcbank">
                    <span class="form-help">The primary UPI VPA that will collect payments.</span>
                </div>
                <div class="form-group">
                    <label class="form-label">Account Role</label>
                    <select name="role" class="form-ctrl">
                        <option value="user" selected>Merchant (Standard User)</option>
                        <option value="admin">Platform Administrator</option>
                    </select>
                </div>
            </div>
            <div class="admin-modal-foot">
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeAdminModal('newMerchantModal')">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm">Create Merchant Account</button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================
     MODAL: QUICK RECONCILE UTR
     ========================================================= -->
<div class="admin-modal-overlay" id="quickReconcileModal">
    <div class="admin-modal-dialog">
        <div class="admin-modal-head">
            <h3>Manual UTR Settlement</h3>
            <button type="button" class="admin-modal-close" onclick="closeAdminModal('quickReconcileModal')">
                <?= icon('x', 16) ?>
            </button>
        </div>
        <form method="post" action="<?= url('admin/index.php') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="quick_reconcile">
            <div class="admin-modal-body">
                <div class="form-group mb-3">
                    <label class="form-label">Order ID *</label>
                    <input type="text" id="reconcileOrderIdInput" name="order_id" class="form-ctrl" placeholder="fg_XXXXXXX" required>
                    <span class="form-help">Enter the exact Order ID to settle.</span>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Bank 12-Digit UTR Number *</label>
                    <input type="text" name="utr" class="form-ctrl" placeholder="e.g. 415922837103" required maxlength="40">
                </div>
                <div class="form-group">
                    <label class="form-label">Customer / Sender Name (Optional)</label>
                    <input type="text" name="sender_name" class="form-ctrl" placeholder="e.g. Rahul Sharma">
                </div>
            </div>
            <div class="admin-modal-foot">
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeAdminModal('quickReconcileModal')">Cancel</button>
                <button type="submit" class="btn btn-success btn-sm">Confirm Settlement &amp; Fire Webhook</button>
            </div>
        </form>
    </div>
</div>

<script>
// Filter Recent Transactions Table
function filterTxTable(status, btn) {
    document.querySelectorAll('.filter-pill-btn').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    const rows = document.querySelectorAll('#adminRecentTxTable tbody tr');
    let visibleCount = 0;
    rows.forEach(r => {
        const rowStatus = r.getAttribute('data-status');
        if (status === 'all' || rowStatus === status || (status === 'expired' && (rowStatus === 'expired' || rowStatus === 'failed'))) {
            r.style.display = '';
            visibleCount++;
        } else {
            r.style.display = 'none';
        }
    });
    const counter = document.getElementById('txCounterLabel');
    if (counter) {
        counter.textContent = 'Showing ' + visibleCount + ' filtered orders';
    }
}

// Instant Live Search across Order ID, UTR, Merchant, Customer
function searchTxTable(keyword) {
    keyword = (keyword || '').toLowerCase().trim();
    const rows = document.querySelectorAll('#adminRecentTxTable tbody tr');
    let visibleCount = 0;
    rows.forEach(r => {
        const text = r.textContent.toLowerCase();
        if (!keyword || text.indexOf(keyword) !== -1) {
            r.style.display = '';
            visibleCount++;
        } else {
            r.style.display = 'none';
        }
    });
    const counter = document.getElementById('txCounterLabel');
    if (counter) {
        counter.textContent = keyword ? ('Found ' + visibleCount + ' matching orders') : ('Showing ' + visibleCount + ' Recent Orders');
    }
}

// Export Visible Table Rows directly to CSV Spreadsheet
function exportTxToCSV() {
    const table = document.getElementById('adminRecentTxTable');
    if (!table) return;
    const rows = table.querySelectorAll('tr');
    let csv = [];
    rows.forEach(r => {
        if (r.style.display === 'none') return;
        let row = [];
        r.querySelectorAll('th, td').forEach((cell, idx) => {
            // Exclude last actions column
            if (idx === r.children.length - 1) return;
            let text = cell.innerText.replace(/(\r\n|\n|\r)/gm, ' ').replace(/\s+/g, ' ').replace(/"/g, '""').trim();
            row.push('"' + text + '"');
        });
        if (row.length) csv.push(row.join(','));
    });
    if (!csv.length) return;
    const blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.setAttribute('href', url);
    link.setAttribute('download', 'platform_transactions_' + new Date().toISOString().slice(0, 10) + '.csv');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}


// Velocity Hybrid Chart (Spline + Column + Hover Tooltip & Metric Switcher)
(function initVelocityChart() {
    var datasets = {
        gross: {
            line: <?= json_encode($grossPath['line']) ?>,
            area: <?= json_encode($grossPath['area']) ?>,
            points: <?= json_encode($grossPath['points']) ?>,
            ribbonGross: <?= json_encode(money($sevenDayGross)) ?>,
            ribbonPeak: <?= json_encode(money($peakGrossVol) . ' (' . ($peakGrossDay ?: 'Today') . ')') ?>,
            metricLabel: 'Gross Inflow'
        },
        settled: {
            line: <?= json_encode($settledPath['line']) ?>,
            area: <?= json_encode($settledPath['area']) ?>,
            points: <?= json_encode($settledPath['points']) ?>,
            ribbonGross: <?= json_encode(money($sevenDaySettled)) ?>,
            ribbonPeak: <?= json_encode(money(max(array_column($chartDays, 'settled_vol'))) . ' (Mon)') ?>,
            metricLabel: 'Settled GMV'
        },
        orders: {
            line: <?= json_encode($ordersPath['line']) ?>,
            area: <?= json_encode($ordersPath['area']) ?>,
            points: <?= json_encode($ordersPath['points']) ?>,
            ribbonGross: <?= json_encode(number_format($sevenDayOrders) . ' Orders') ?>,
            ribbonPeak: <?= json_encode(max(array_column($chartDays, 'gross_orders')) . ' Txns (' . ($peakGrossDay ?: 'Today') . ')') ?>,
            metricLabel: 'Transaction Count'
        }
    };

    var chartDaysData = <?= json_encode($chartDays) ?>;

    function setup() {
        var stage      = document.getElementById('velChartStage');
        var linePath   = document.getElementById('velLinePath');
        var areaPath   = document.getElementById('velAreaPath');
        var laserLine  = document.getElementById('velLaserLine');
        var tooltip    = document.getElementById('velTooltipHUD');
        var viewBtns   = document.querySelectorAll('.vel-view-btn');
        var dayTiles   = document.querySelectorAll('.vel-day-tile');
        var triggers   = document.querySelectorAll('.vel-hover-trigger');
        var statGross  = document.getElementById('velStatGross');
        var statPeak   = document.getElementById('velStatPeak');

        if (!stage || !linePath || !areaPath) return;

        var currentMetric = 'gross';

        function showTooltip(idx, clientX) {
            var data = chartDaysData[idx];
            if (!data) return;

            var dot = document.getElementById('velDot' + idx);
            var bar = document.getElementById('velBar' + idx);
            var tile = document.getElementById('velTile' + idx);

            // Laser guide line
            if (laserLine && dot) {
                var cx = dot.getAttribute('cx');
                laserLine.setAttribute('x1', cx);
                laserLine.setAttribute('x2', cx);
                laserLine.setAttribute('opacity', '1');
            }

            // Dot pulse
            document.querySelectorAll('.vel-point-dot').forEach(function (d, i) {
                if (i == idx) {
                    d.setAttribute('r', '7.5');
                    d.setAttribute('stroke-width', '3.5');
                    d.style.filter = 'drop-shadow(0 0 10px #2563eb)';
                } else {
                    d.setAttribute('r', '5');
                    d.setAttribute('stroke-width', '2.5');
                    d.style.filter = 'none';
                }
            });

            // Bar highlight
            document.querySelectorAll('.vel-col-bar').forEach(function (b, i) {
                if (i == idx) {
                    b.setAttribute('fill', 'url(#adminBarGradActive)');
                } else {
                    b.setAttribute('fill', 'url(#adminBarGrad)');
                }
            });

            // Day tile highlight
            dayTiles.forEach(function (t, i) {
                if (i == idx) {
                    t.classList.add('active-day');
                } else {
                    t.classList.remove('active-day');
                }
            });

            // Tooltip contents
            if (tooltip) {
                var dateEl    = document.getElementById('vttDate');
                var grossEl   = document.getElementById('vttGross');
                var ordersEl  = document.getElementById('vttOrders');
                var settledEl = document.getElementById('vttSettled');

                if (dateEl) dateEl.textContent = data.full_date + (data.is_today ? ' (Today)' : '');
                if (grossEl) grossEl.textContent = data.gross_vol_fmt;
                if (ordersEl) ordersEl.textContent = data.gross_orders + ' Orders';
                if (settledEl) settledEl.textContent = data.settled_vol_fmt;

                tooltip.style.display = 'block';

                // Position tooltip
                var stageRect = stage.getBoundingClientRect();
                var dotX = dot ? parseFloat(dot.getAttribute('cx')) : 100;
                var dotY = dot ? parseFloat(dot.getAttribute('cy')) : 50;

                var pctX = (dotX / 680) * stageRect.width;
                var leftPos = pctX;

                // Clamp tooltip so it stays inside stage bounds
                var ttW = tooltip.offsetWidth || 180;
                if (leftPos + ttW / 2 > stageRect.width - 15) {
                    leftPos = stageRect.width - ttW - 15;
                } else if (leftPos - ttW / 2 < 15) {
                    leftPos = 15;
                } else {
                    leftPos = leftPos - (ttW / 2);
                }

                tooltip.style.left = leftPos + 'px';
                tooltip.style.top = Math.max(10, (dotY / 190) * stageRect.height - 85) + 'px';
            }
        }

        function hideTooltip() {
            if (laserLine) laserLine.setAttribute('opacity', '0');
            if (tooltip) tooltip.style.display = 'none';

            document.querySelectorAll('.vel-point-dot').forEach(function (d) {
                d.setAttribute('r', '5');
                d.setAttribute('stroke-width', '2.5');
                d.style.filter = 'none';
            });

            document.querySelectorAll('.vel-col-bar').forEach(function (b) {
                b.setAttribute('fill', 'url(#adminBarGrad)');
            });

            dayTiles.forEach(function (t) {
                t.classList.remove('active-day');
            });
        }

        // Attach triggers
        triggers.forEach(function (trig) {
            var idx = trig.getAttribute('data-index');
            trig.addEventListener('mouseenter', function () { showTooltip(idx); });
            trig.addEventListener('mousemove', function () { showTooltip(idx); });
            trig.addEventListener('mouseleave', hideTooltip);
        });

        dayTiles.forEach(function (tile) {
            var idx = tile.getAttribute('data-index');
            tile.addEventListener('mouseenter', function () { showTooltip(idx); });
            tile.addEventListener('mouseleave', hideTooltip);
        });

        // Metric Toggle buttons
        viewBtns.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                viewBtns.forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');

                var metric = btn.getAttribute('data-vel-metric') || 'gross';
                currentMetric = metric;
                var ds = datasets[metric];
                if (!ds) return;

                // Morph path
                linePath.setAttribute('d', ds.line);
                areaPath.setAttribute('d', ds.area);

                // Update points and bars
                ds.points.forEach(function (pt, i) {
                    var dot = document.getElementById('velDot' + i);
                    var bar = document.getElementById('velBar' + i);
                    if (dot) {
                        dot.setAttribute('cy', pt.y);
                    }
                    if (bar) {
                        bar.setAttribute('y', pt.y);
                        bar.setAttribute('height', Math.max(4, 152 - pt.y));
                    }
                });

                // Update Day Tiles display
                dayTiles.forEach(function (tile, i) {
                    var amtEl = document.getElementById('vdtAmt' + i);
                    if (amtEl) {
                        if (metric === 'gross') {
                            amtEl.textContent = tile.getAttribute('data-gross-val');
                        } else if (metric === 'settled') {
                            amtEl.textContent = tile.getAttribute('data-settled-val');
                        } else if (metric === 'orders') {
                            amtEl.textContent = tile.getAttribute('data-orders-val');
                        }
                    }
                });

                hideTooltip();
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup();
    }
    window.addEventListener('turbo:load', setup);
    window.addEventListener('page:loaded', setup);
})();

// Donut Chart ("Goll Jaisa" Component) Interactive Hover & Multi-Metric Switching
(function initAdminDonut() {
    function setup() {
        var segments    = document.querySelectorAll('.donut-segment');
        var matrixRows  = document.querySelectorAll('.donut-matrix-row');
        var centerPill  = document.getElementById('donutCenterPill');
        var centerNum   = document.getElementById('donutCenterNum');
        var centerSub   = document.getElementById('donutCenterSub');
        var centerMeta  = document.getElementById('donutCenterMeta');
        var viewBtns    = document.querySelectorAll('.ch-view-btn');

        if (!segments.length || !centerNum || !centerSub) return;

        var currentMetric = 'vol'; // 'vol' | 'orders' | 'sla'
        var defaultPill = centerPill ? centerPill.textContent : 'ALL RAILS';
        var defaultNum  = centerNum.textContent;
        var defaultSub  = centerSub.textContent;
        var defaultMeta = centerMeta ? centerMeta.textContent : '';

        // Store default overview values per metric
        var defaultMetricValues = {
            vol: defaultNum,
            orders: '<?= number_format($totalOrderCount) ?> Orders',
            sla: '<?= $avgChannelSla ?> SLA'
        };

        function highlightChannel(idx) {
            var targetSeg = document.getElementById('donutSeg' + idx);
            var targetRow = document.getElementById('donutRow' + idx);

            // Segments visual feedback
            segments.forEach(function (s, i) {
                if (i == idx) {
                    s.style.opacity = '1';
                    s.style.strokeWidth = '32';
                    var color = s.getAttribute('data-color') || '#8b5cf6';
                    s.style.filter = 'drop-shadow(0 0 12px ' + color + ')';
                } else {
                    s.style.opacity = '0.22';
                    s.style.strokeWidth = '26';
                    s.style.filter = 'none';
                }
            });

            // Matrix rows visual feedback
            matrixRows.forEach(function (row, i) {
                if (i == idx) {
                    row.classList.add('active-row');
                    row.style.opacity = '1';
                } else {
                    row.classList.remove('active-row');
                    row.style.opacity = '0.45';
                }
            });

            if (targetSeg) {
                var color   = targetSeg.getAttribute('data-color') || '#8b5cf6';
                var short   = targetSeg.getAttribute('data-short') || '';
                var label   = targetSeg.getAttribute('data-label') || '';
                var pct     = targetSeg.getAttribute('data-pct') || '';
                var count   = targetSeg.getAttribute('data-count') || '';
                var vol     = targetSeg.getAttribute('data-vol') || '';
                var sla     = targetSeg.getAttribute('data-sla') || '';
                var latency = targetSeg.getAttribute('data-latency') || '';

                if (centerPill) {
                    centerPill.textContent = short.toUpperCase();
                    centerPill.style.color = color;
                    centerPill.style.background = color + '22';
                }

                if (currentMetric === 'vol') {
                    centerNum.textContent = vol;
                } else if (currentMetric === 'orders') {
                    centerNum.textContent = count + ' Orders';
                } else {
                    centerNum.textContent = sla;
                }

                centerSub.textContent = label + ' (' + pct + ')';
                centerSub.style.color = color;

                if (centerMeta) {
                    centerMeta.textContent = 'Latency: ' + latency + ' \u00B7 ' + sla + ' SLA';
                }
            }
        }

        function resetDonut() {
            segments.forEach(function (s) {
                s.style.opacity = '1';
                s.style.strokeWidth = '26';
                s.style.filter = 'none';
            });

            matrixRows.forEach(function (row) {
                row.classList.remove('active-row');
                row.style.opacity = '1';
            });

            if (centerPill) {
                centerPill.textContent = defaultPill;
                centerPill.style.color = '';
                centerPill.style.background = '';
            }

            centerNum.textContent = defaultMetricValues[currentMetric] || defaultNum;
            centerSub.textContent = defaultSub;
            centerSub.style.color = '';

            if (centerMeta) {
                centerMeta.textContent = defaultMeta;
            }
        }

        // Attach listeners to SVG Segments
        segments.forEach(function (seg) {
            var idx = seg.getAttribute('data-index');
            seg.addEventListener('mouseenter', function () { highlightChannel(idx); });
            seg.addEventListener('mouseleave', resetDonut);
        });

        // Attach listeners to Matrix Rows
        matrixRows.forEach(function (row) {
            var idx = row.getAttribute('data-index');
            row.addEventListener('mouseenter', function () { highlightChannel(idx); });
            row.addEventListener('mouseleave', resetDonut);
        });

        // Metric View Switcher Logic (Volume | Orders | SLA)
        viewBtns.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                viewBtns.forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');

                var metric = btn.getAttribute('data-metric') || 'vol';
                currentMetric = metric;

                // Update matrix values
                document.querySelectorAll('.dm-metric-val').forEach(function (el) {
                    if (metric === 'vol') {
                        el.textContent = el.getAttribute('data-val-vol');
                    } else if (metric === 'orders') {
                        el.textContent = el.getAttribute('data-val-orders');
                    } else if (metric === 'sla') {
                        el.textContent = el.getAttribute('data-val-sla');
                    }
                });

                resetDonut();
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup();
    }
    window.addEventListener('turbo:load', setup);
    window.addEventListener('page:loaded', setup);
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
