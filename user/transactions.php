<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';

$user   = require_login();
$active = 'transactions';

/* ---------- Filters & Parameters ---------- */
$status  = get('status'); // '' | success | pending | failed | expired
$period  = get('period', 'all');
$perPage = (int) get('per_page', '25');
if (!in_array($perPage, [10, 25, 50, 100], true)) { $perPage = 25; }
$page    = max(1, (int) get('page', '1'));
$search  = trim(get('q'));

/* Base conditions for current user */
$baseWhere  = 'user_id = :uid';
$baseParams = [':uid' => $user['id']];

/* Time period filter */
$since = null;
switch ($period) {
    case 'today':     $since = date('Y-m-d 00:00:00'); break;
    case 'yesterday': $since = date('Y-m-d 00:00:00', strtotime('-1 day')); break;
    case '7d':        $since = date('Y-m-d 00:00:00', strtotime('-6 days')); break;
    case '30d':       $since = date('Y-m-d 00:00:00', strtotime('-29 days')); break;
    case 'month':     $since = date('Y-m-01 00:00:00'); break;
}
if ($since) {
    $baseWhere .= ' AND created_at >= :since';
    $baseParams[':since'] = $since;
}

/* Search filter */
if ($search !== '') {
    $baseWhere .= ' AND (order_id LIKE :q OR customer_name LIKE :q OR customer_contact LIKE :q OR utr LIKE :q OR bank_rrn LIKE :q)';
    $baseParams[':q'] = "%{$search}%";
}

/* Tab Counts (independent of the selected status tab so counts are always visible) */
$tabCountsStmt = db()->prepare("SELECT
        COUNT(*) AS total_all,
        COALESCE(SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END), 0) AS count_success,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS count_pending,
        COALESCE(SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END), 0) AS count_expired,
        COALESCE(SUM(CASE WHEN status = 'failed'  THEN 1 ELSE 0 END), 0) AS count_failed
    FROM orders WHERE {$baseWhere}");
$tabCountsStmt->execute($baseParams);
$tabCounts = $tabCountsStmt->fetch() ?: [];

/* Specific status filter applied for CSV export */
$csvWhere  = $baseWhere;
$csvParams = $baseParams;
if ($status !== '' && in_array($status, ['success', 'pending', 'failed', 'expired'], true)) {
    $csvWhere .= ' AND status = :status';
    $csvParams[':status'] = $status;
}

/* For HTML view, load all statuses for the active period/search so instant tabs work smoothly without page refresh */
$where  = $baseWhere;
$params = $baseParams;

/* Export to CSV if requested */
if (get('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="transactions_' . date('Y-m-d_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Order ID', 'Status', 'Amount (INR)', 'Payable Amount (INR)', 'UTR', 'Bank RRN', 'Customer Name', 'Customer Email', 'Customer Contact', 'Created At', 'Paid At']);
    $exportStmt = db()->prepare("SELECT * FROM orders WHERE {$csvWhere} ORDER BY created_at DESC LIMIT 5000");
    $exportStmt->execute($csvParams);
    while ($r = $exportStmt->fetch()) {
        fputcsv($out, [
            $r['order_id'],
            $r['status'],
            $r['amount'],
            $r['payable_amount'],
            $r['utr'] ?: '',
            $r['bank_rrn'] ?: '',
            $r['customer_name'] ?: '',
            $r['customer_email'] ?: '',
            $r['customer_contact'] ?: '',
            $r['created_at'],
            $r['paid_at'] ?: '',
        ]);
    }
    fclose($out);
    exit;
}

/* Financial Totals for Summary KPIs */
$sumStmt = db()->prepare("SELECT
        COALESCE(SUM(CASE WHEN status = 'success' THEN payable_amount END), 0) AS collected,
        COALESCE(SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END), 0) AS captured,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count,
        COALESCE(SUM(CASE WHEN status = 'failed'  THEN 1 ELSE 0 END), 0) AS failed_count,
        COALESCE(SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END), 0) AS expired_count,
        COUNT(*) AS total_orders
    FROM orders WHERE {$where}");
$sumStmt->execute($params);
$sum = $sumStmt->fetch() ?: [];

$totalOrders = (int) ($sum['total_orders'] ?? 0);
$capturedCnt = (int) ($sum['captured'] ?? 0);
$successRate = $totalOrders > 0 ? round(($capturedCnt / $totalOrders) * 100, 1) : 100.0;

/* Paginated Rows */
$countStmt = db()->prepare("SELECT COUNT(*) FROM orders WHERE {$where}");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages   = max(1, (int) ceil($totalRecords / $perPage));
$page         = min($page, $totalPages);
$offset       = ($page - 1) * $perPage;

$rowsStmt = db()->prepare("
    SELECT * FROM orders WHERE {$where}
    ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
$rowsStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$rowsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $k => $v) { $rowsStmt->bindValue($k, $v); }
$rowsStmt->execute();
$rows = $rowsStmt->fetchAll();

/* Helper function to generate query strings with parameter overrides */
$qs = function (array $overrides) use ($period, $perPage, $status, $search): string {
    $p = ['status' => $status, 'period' => $period, 'per_page' => $perPage, 'q' => $search];
    foreach ($overrides as $k => $v) { $p[$k] = $v; }
    return http_build_query(array_filter($p, fn ($v) => $v !== ''));
};

$tabStatuses = [
    ''        => ['All', (int) ($tabCounts['total_all'] ?? 0), 'active-all'],
    'success' => ['Captured', (int) ($tabCounts['count_success'] ?? 0), 'active-success'],
    'pending' => ['Pending', (int) ($tabCounts['count_pending'] ?? 0), 'active-pending'],
    'expired' => ['Expired', (int) ($tabCounts['count_expired'] ?? 0), 'active-expired'],
    'failed'  => ['Failed', (int) ($tabCounts['count_failed'] ?? 0), 'active-failed'],
];

$pageTitle       = 'Transactions Feed';
$pageHeading     = 'Transactions';
$pageSub         = 'Live payment logs & settlements';
$hidePageHeading = true; // Use sleek Dark Hero Stage instead of plain topbar
include __DIR__ . '/includes/header.php';
?>

<!-- =========================================================
     DARK HERO STAGE ("Kahi Kahi Pe Dark UI")
     Matching index.php and dashboard.php Hero Aesthetics
     ========================================================= -->
<div class="dash-hero-stage-dark" data-reveal>
    <div class="hero-stage-mesh-top" aria-hidden="true"></div>
    <div class="hero-stage-mesh-bottom" aria-hidden="true"></div>

    <div class="hero-stage-header">
        <div class="hero-eyebrow">
            <span class="dot"></span>
            <span>Live Ledger &middot; Real-Time</span>
        </div>
        <div class="hero-sla-pill">
            <span class="sla-dot"></span>
            <span>Direct UPI &middot; Instant Verification</span>
        </div>
    </div>

    <div class="hero-stage-headline">
        <h1 class="dash-hero-heading">
            Payment <span class="accent">Transactions</span>
        </h1>
        <p class="dash-hero-desc">
            Live incoming UPI transfers, auto-matched UTRs &amp; status updates.
        </p>
    </div>

    <div class="hero-meta-ribbon">
        <div class="meta-ribbon-cell" title="Total settled collection">
            <div class="meta-cell-label">Settled Volume</div>
            <div class="meta-cell-value">
                <span class="val-money" data-count="<?= (float) ($sum['collected'] ?? 0) ?>" data-decimals="2" data-prefix="₹"><?= money((float) ($sum['collected'] ?? 0)) ?></span>
            </div>
        </div>

        <div class="meta-ribbon-cell" title="Overall payment success rate">
            <div class="meta-cell-label">Success Rate</div>
            <div class="meta-cell-value">
                <span class="val-accent" data-count="<?= $successRate ?>" data-suffix="% Verified"><?= $successRate ?>% Verified</span>
            </div>
        </div>

        <div class="meta-ribbon-cell" title="Settlement destination">
            <div class="meta-cell-label">Settlement Route</div>
            <div class="meta-cell-value">
                <span class="meta-badge-ok"><span class="mini-beacon"></span> Direct Bank VPA</span>
            </div>
        </div>

        <div class="meta-ribbon-cell" title="Verification latency">
            <div class="meta-cell-label">Audit Engine</div>
            <div class="meta-cell-value">
                <span class="val-rate">Instant UTR Match</span>
            </div>
        </div>
    </div>

    <div class="hero-stage-btns">
        <button type="button" class="btn-hero-primary" onclick="openQuickQrModal(0)">
            <?= icon('plus', 15) ?> Quick Collect
        </button>
        <a href="?<?= e($qs(['export' => 'csv'])) ?>" class="btn-hero-secondary" title="Export filtered rows as CSV">
            <?= icon('download', 14) ?> Export CSV
        </a>
    </div>
</div>

<!-- =========================================================
     4 VIBRANT COLORFUL METRIC CARDS (WHITE SURFACE)
     ========================================================= -->
<div class="dash-kpi-grid-light" data-reveal>
    <!-- KPI 1: Captured Amount -->
    <div class="light-kpi-card card-theme-green">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Captured Amount</span>
            <div class="kpi-icon-glow">
                <?= icon('wallet', 18) ?>
            </div>
        </div>
        <div class="kpi-number-display" style="color:#059669;">
            <?= money((float) ($sum['collected'] ?? 0)) ?>
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill up">
                <?= icon('check', 11) ?> Verified
            </span>
            <span><?= (int) ($sum['captured'] ?? 0) ?> orders</span>
        </div>
    </div>

    <!-- KPI 2: Success Rate -->
    <div class="light-kpi-card card-theme-blue">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Success Rate</span>
            <div class="kpi-icon-glow">
                <?= icon('check-circle', 18) ?>
            </div>
        </div>
        <div class="kpi-number-display" style="color:#2563eb;">
            <?= $successRate ?>%
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill up">
                <?= icon('activity', 11) ?> Rate
            </span>
            <span><?= (int) $sum['captured'] ?>/<?= $totalOrders ?></span>
        </div>
    </div>

    <!-- KPI 3: Pending Orders -->
    <div class="light-kpi-card card-theme-amber">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Pending Orders</span>
            <div class="kpi-icon-glow">
                <?= icon('clock', 18) ?>
            </div>
        </div>
        <div class="kpi-number-display" style="color:#d97706;">
            <?= (int) ($sum['pending_count'] ?? 0) ?>
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill neutral">
                <?= icon('clock', 11) ?> Pending
            </span>
            <span>Awaiting UTR</span>
        </div>
    </div>

    <!-- KPI 4: Expired Orders -->
    <div class="light-kpi-card card-theme-purple">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Expired Orders</span>
            <div class="kpi-icon-glow">
                <?= icon('alert', 18) ?>
            </div>
        </div>
        <div class="kpi-number-display" style="color:#64748b;">
            <?= (int) ($sum['expired_count'] ?? 0) + (int) ($sum['failed_count'] ?? 0) ?>
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill down">
                <?= icon('x', 11) ?> Timed out
            </span>
            <span><?= (int) ($sum['expired_count'] ?? 0) ?> orders</span>
        </div>
    </div>
</div>

<!-- =========================================================
     SMART FILTER & SEARCH BAR
     ========================================================= -->
<div class="dash-white-card tx-filter-card" id="txFilterCard" data-reveal>
    <!-- Row 1: Status Tabs -->
    <div class="tx-filter-top-row">
        <div class="tx-status-tabs" data-liquid-tabs id="txStatusTabs">
            <div class="tx-liquid-glider" aria-hidden="true">
                <span class="tx-glider-wave"></span>
            </div>
            <?php foreach ($tabStatuses as $sKey => [$label, $count, $activeClass]): ?>
                <?php $isActive = ($status === $sKey); ?>
                <button type="button" class="tx-status-tab <?= $isActive ? 'active ' . $activeClass : '' ?>" data-status-filter="<?= e($sKey) ?>" data-url="?<?= e($qs(['status' => $sKey, 'page' => 1])) ?>">
                    <span class="tx-tab-dot dot-<?= $sKey ?: 'all' ?>"></span>
                    <span class="tx-tab-text"><?= e($label) ?></span>
                    <span class="tx-tab-badge badge-<?= $sKey ?: 'all' ?>"><?= (int) $count ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Row 2: Horizon Segment Strip & Export Action -->
    <div class="tx-horizon-strip">
        <div class="tx-horizon-pills" data-liquid-tabs id="txHorizonTabs">
            <div class="tx-liquid-glider" aria-hidden="true">
                <span class="tx-glider-wave"></span>
            </div>
            <button type="button" class="tx-horizon-pill <?= $period === 'all' ? 'active' : '' ?>" data-period-filter="all" data-url="?<?= e($qs(['period' => 'all', 'page' => 1])) ?>">All Time</button>
            <button type="button" class="tx-horizon-pill <?= $period === 'today' ? 'active' : '' ?>" data-period-filter="today" data-url="?<?= e($qs(['period' => 'today', 'page' => 1])) ?>">Today</button>
            <button type="button" class="tx-horizon-pill <?= $period === 'yesterday' ? 'active' : '' ?>" data-period-filter="yesterday" data-url="?<?= e($qs(['period' => 'yesterday', 'page' => 1])) ?>">Yesterday</button>
            <button type="button" class="tx-horizon-pill <?= $period === '7d' ? 'active' : '' ?>" data-period-filter="7d" data-url="?<?= e($qs(['period' => '7d', 'page' => 1])) ?>">Last 7 Days</button>
            <button type="button" class="tx-horizon-pill <?= $period === '30d' ? 'active' : '' ?>" data-period-filter="30d" data-url="?<?= e($qs(['period' => '30d', 'page' => 1])) ?>">Last 30 Days</button>
            <button type="button" class="tx-horizon-pill <?= $period === 'month' ? 'active' : '' ?>" data-period-filter="month" data-url="?<?= e($qs(['period' => 'month', 'page' => 1])) ?>">This Month</button>
        </div>

        <div>
            <a href="?<?= e($qs(['export' => 'csv'])) ?>" class="btn-tx-export" title="Download Excel/CSV report">
                <?= icon('download', 14) ?> Export CSV
            </a>
        </div>
    </div>

    <!-- Row 3: Instant Search Form & Per-Page Controls -->
    <div class="tx-search-controls">
        <form method="get" class="tx-search-form">
            <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
            <?php if ($period !== 'all'): ?><input type="hidden" name="period" value="<?= e($period) ?>"><?php endif; ?>
            <?php if ($perPage !== 25): ?><input type="hidden" name="per_page" value="<?= (int) $perPage ?>"><?php endif; ?>

            <span class="tx-search-icon"><?= icon('search', 15) ?></span>
            <input class="tx-search-input" type="search" name="q" placeholder="Search Order ID, UTR, customer..." value="<?= e($search) ?>" autocomplete="off">
            <?php if ($search !== ''): ?>
                <a href="?<?= e($qs(['q' => ''])) ?>" class="tx-search-clear" title="Clear search">&times;</a>
            <?php endif; ?>
        </form>

        <div class="tx-perpage-row">
            <span class="tx-perpage-label">Rows:</span>
            <div class="tx-perpage-buttons" id="txPerPageButtons">
                <?php foreach ([10, 25, 50, 100] as $opt): ?>
                    <button type="button" class="tx-perpage-pill <?= $perPage === $opt ? 'active' : '' ?>" data-perpage="<?= $opt ?>" data-url="?<?= e($qs(['per_page' => $opt, 'page' => 1])) ?>">
                        <?= $opt ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <select class="tx-perpage-select" style="display:none;" aria-hidden="true">
                <option value="?<?= e($qs(['per_page' => '10', 'page' => 1])) ?>" <?= $perPage === 10 ? 'selected' : '' ?>>10 items</option>
                <option value="?<?= e($qs(['per_page' => '25', 'page' => 1])) ?>" <?= $perPage === 25 ? 'selected' : '' ?>>25 items</option>
                <option value="?<?= e($qs(['per_page' => '50', 'page' => 1])) ?>" <?= $perPage === 50 ? 'selected' : '' ?>>50 items</option>
                <option value="?<?= e($qs(['per_page' => '100', 'page' => 1])) ?>" <?= $perPage === 100 ? 'selected' : '' ?>>100 items</option>
            </select>
        </div>
    </div>
</div>

<!-- =========================================================
     TRANSACTIONS PRESENTATION (DESKTOP TABLE + MOBILE CARDS)
     ========================================================= -->
<div class="dash-white-card" id="txPresentationCard" style="padding:0;overflow:hidden;" data-reveal>
    <?php if (!$rows): ?>
        <div class="empty-state" style="padding:40px 18px;">
            <div class="empty-icon" style="background:rgba(37,99,235,0.08);color:#2563eb;width:48px;height:48px;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;">
                <?= icon('receipt', 24) ?>
            </div>
            <h3 class="empty-state-title" style="font-size:15px;font-weight:700;color:#0f172a;margin-bottom:4px;">No transactions found</h3>
            <p class="empty-state-sub" style="font-size:12px;color:#64748b;max-width:380px;margin:0 auto 14px;">
                <?= $search !== '' ? 'No orders matched "' . e($search) . '".' : 'No records found for current filter.' ?>
            </p>
            <?php if ($search !== '' || $status !== '' || $period !== 'all'): ?>
                <a class="btn btn-secondary btn-sm" href="<?= url('user/transactions.php') ?>" style="display:inline-flex;align-items:center;gap:6px;">
                    <?= icon('refresh', 12) ?> Reset filters
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <!-- Desktop Table View (Hidden on mobile <= 768px) -->
        <div class="tx-desktop-table-wrap">
            <table class="tx-table">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Settled Amount</th>
                        <th>Status</th>
                        <th>Bank UTR / RRN</th>
                        <th>Customer Detail</th>
                        <th>Created Timestamp</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $o): ?>
                    <?php $isSucc = ($o['status'] === 'success'); ?>
                    <tr class="tx-table-row" data-status="<?= e($o['status']) ?>">
                        <td>
                            <div class="tx-order-pill" title="Order ID">
                                <span><?= e($o['order_id']) ?></span>
                                <button type="button" class="dark-chip-copy" data-copy="<?= e($o['order_id']) ?>" title="Copy Order ID">
                                    <?= icon('copy', 12) ?>
                                </button>
                            </div>
                        </td>
                        <td>
                            <div class="tx-amount-display <?= $isSucc ? 'is-success' : '' ?>">
                                <?= money((float) $o['payable_amount']) ?>
                            </div>
                            <?php if ((float) $o['payable_amount'] !== (float) $o['amount']): ?>
                                <div class="tx-base-amt">
                                    Base: <?= money((float) $o['amount']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= status_badge((string) $o['status']) ?>
                        </td>
                        <td>
                            <?php $utrVal = (string) ($o['utr'] ?: ($o['bank_rrn'] ?: '')); ?>
                            <?php if ($utrVal !== ''): ?>
                                <div class="tx-utr-pill">
                                    <span><?= e($utrVal) ?></span>
                                    <button type="button" class="dark-chip-copy" data-copy="<?= e($utrVal) ?>" title="Copy UTR">
                                        <?= icon('copy', 11) ?>
                                    </button>
                                </div>
                            <?php else: ?>
                                <span class="tx-utr-empty">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="tx-cust-name">
                                <?= e($o['customer_name'] ?: 'Direct Payment') ?>
                            </div>
                            <?php if (!empty($o['customer_contact'])): ?>
                                <div class="tx-cust-contact">
                                    <?= e($o['customer_contact']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="tx-date-cell">
                                <?= e(nice_date($o['created_at'])) ?>
                            </div>
                            <?php if (!empty($o['paid_at'])): ?>
                                <div class="tx-paid-badge">
                                    <?= icon('check', 11) ?> Paid <?= e(date('g:i a', strtotime($o['paid_at']))) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <a class="tx-btn-view" href="<?= url('user/transaction-details.php?id=' . (int) $o['id']) ?>">
                                <span>View</span>
                                <?= icon('arrow-right', 12) ?>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Card List View (Visible on mobile <= 768px, eliminates all horizontal blowout) -->
        <div class="tx-mobile-list">
            <?php foreach ($rows as $o): ?>
            <?php $isSucc = ($o['status'] === 'success'); ?>
            <div class="tx-card-mobile" data-status="<?= e($o['status']) ?>">
                <div class="tx-card-mobile-top">
                    <div class="tx-order-pill">
                        <span>#<?= e($o['order_id']) ?></span>
                        <button type="button" class="dark-chip-copy" data-copy="<?= e($o['order_id']) ?>" title="Copy Order ID" style="background:none;border:none;padding:0;cursor:pointer;color:#64748b;">
                            <?= icon('copy', 12) ?>
                        </button>
                    </div>
                    <div>
                        <?= status_badge((string) $o['status']) ?>
                    </div>
                </div>

                <div class="tx-card-mobile-mid">
                    <div>
                        <div class="tx-card-mobile-amt <?= $isSucc ? 'is-success' : '' ?>">
                            <?= money((float) $o['payable_amount']) ?>
                        </div>
                        <div class="tx-card-mobile-cust">
                            <strong><?= e($o['customer_name'] ?: 'Direct Payment') ?></strong>
                            <?php if (!empty($o['customer_contact'])): ?>
                                &middot; <?= e($o['customer_contact']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <a class="tx-btn-view" href="<?= url('user/transaction-details.php?id=' . (int) $o['id']) ?>">
                        <span>Details</span>
                        <?= icon('arrow-right', 12) ?>
                    </a>
                </div>

                <div class="tx-card-mobile-bot">
                    <div style="display:flex;align-items:center;gap:5px;">
                        <span style="color:#64748b;">UTR:</span>
                        <?php $mUtr = (string) ($o['utr'] ?: ($o['bank_rrn'] ?: '')); ?>
                        <?php if ($mUtr !== ''): ?>
                            <span class="mono tx-mobile-utr-badge"><?= e($mUtr) ?></span>
                            <button type="button" class="dark-chip-copy" data-copy="<?= e($mUtr) ?>" title="Copy UTR" style="background:none;border:none;padding:0;cursor:pointer;color:#64748b;">
                                <?= icon('copy', 12) ?>
                            </button>
                        <?php else: ?>
                            <span style="color:#94a3b8;font-style:italic;">Pending verify</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?= e(nice_date($o['created_at'])) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination Footer -->
        <div class="tx-pagination-footer">
            <div class="tx-pagination-info">
                Showing <strong><?= min($totalRecords, $offset + 1) ?></strong> &ndash; <strong><?= min($totalRecords, $offset + count($rows)) ?></strong> of <strong><?= $totalRecords ?></strong> records &middot; Page <?= $page ?>/<?= $totalPages ?>
            </div>
            <div class="flex items-center gap-2">
                <a class="btn btn-secondary btn-sm tx-page-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $page > 1 ? '?' . e($qs(['page' => $page - 1])) : 'javascript:void(0)' ?>" style="<?= $page <= 1 ? 'opacity:0.4;pointer-events:none;' : '' ?>">
                    &larr; Prev
                </a>
                <a class="btn btn-secondary btn-sm tx-page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= $page < $totalPages ? '?' . e($qs(['page' => $page + 1])) : 'javascript:void(0)' ?>" style="<?= $page >= $totalPages ? 'opacity:0.4;pointer-events:none;' : '' ?>">
                    Next &rarr;
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>