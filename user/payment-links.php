<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';

$user   = require_login();
$active = 'links';

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf(post('csrf_token'))) {
        $error = 'Session expired. Please try again.';
    } else {
        $action = post('action', 'create');

        /* ---------- Create ---------- */
        if ($action === 'create') {
            require_once APP_ROOT . '/includes/subscriptions.php';
            $can = sub_can((int) $user['id'], 'links');
            if (!$can['ok']) {
                $error = sub_reason_message($can['reason']) . ' (Links used: ' . $can['used'] . '/' . ($can['limit'] > 0 ? $can['limit'] : 'unlimited') . ')';
            } else {
                $title   = trim((string) post('title'));
                $amount  = (float) post('amount');
                $expiry  = (string) post('expiry', ''); // '' | 15m | 30m | 1h | 6h | 24h
                $desc    = trim((string) post('description'));

                if ($title === '') { $title = 'Payment Request'; }
                if ($amount <= 0) {
                    $error = 'Amount must be greater than zero.';
                } else {
                    $map = [
                        '15m' => '+15 minutes',
                        '30m' => '+30 minutes',
                        '1h'  => '+1 hour',
                        '6h'  => '+6 hours',
                        '24h' => '+24 hours'
                    ];
                    $expiresAt = isset($map[$expiry]) ? date('Y-m-d H:i:s', strtotime($map[$expiry])) : null;

                    $linkId = generate_link_id();
                    $stmt = db()->prepare(
                        'INSERT INTO payment_links (user_id, link_id, title, amount, description, status, expires_at)
                         VALUES (:uid, :lid, :title, :amount, :desc, "active", :exp)'
                    );
                    $stmt->execute([
                        ':uid'   => $user['id'],
                        ':lid'   => $linkId,
                        ':title' => $title,
                        ':amount'=> $amount,
                        ':desc'  => $desc !== '' ? $desc : null,
                        ':exp'   => $expiresAt,
                    ]);
                    flash_set('success', 'Payment link created: ' . url('pay.php?link=' . $linkId));
                    redirect('user/payment-links.php');
                }
            }
        }

        /* ---------- Toggle / Reactivate / Delete ---------- */
        $linkId = (int) post('id');
        if ($linkId > 0) {
            $stmt = db()->prepare('SELECT * FROM payment_links WHERE id = ? AND user_id = ? LIMIT 1');
            $stmt->execute([$linkId, $user['id']]);
            $link = $stmt->fetch();
            if (!$link) {
                $error = 'Link not found.';
            } elseif ($action === 'toggle') {
                $newStatus = $link['status'] === 'active' ? 'disabled' : 'active';
                if ($newStatus === 'active' && $link['expires_at'] && strtotime($link['expires_at']) <= time()) {
                    db()->prepare('UPDATE payment_links SET status = "active", expires_at = NULL WHERE id = ?')->execute([$linkId]);
                } else {
                    db()->prepare('UPDATE payment_links SET status = ? WHERE id = ?')->execute([$newStatus, $linkId]);
                }
                flash_set('success', 'Link status updated to ' . $newStatus . '.');
                redirect('user/payment-links.php');
            } elseif ($action === 'reactivate') {
                db()->prepare('UPDATE payment_links SET status = "active", expires_at = NULL WHERE id = ?')->execute([$linkId]);
                flash_set('success', 'Link reactivated successfully with no expiry.');
                redirect('user/payment-links.php');
            } elseif ($action === 'delete') {
                db()->prepare('DELETE FROM payment_links WHERE id = ? AND user_id = ?')->execute([$linkId, $user['id']]);
                flash_set('success', 'Payment link deleted.');
                redirect('user/payment-links.php');
            }
        }
    }
}

/* ---------- Query Links ---------- */
$filterStatus = get('status', '');
$search       = trim(get('q', ''));

$sql = 'SELECT * FROM payment_links WHERE user_id = ?';
$params = [$user['id']];

if ($search !== '') {
    $sql .= ' AND (title LIKE ? OR link_id LIKE ? OR description LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$sql .= ' ORDER BY created_at DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rawLinks = $stmt->fetchAll();

/* Compute Counts & Summary Metrics */
$now = time();
$totalCount = count($rawLinks);
$activeCount = 0;
$activeVolume = 0.0;
$inactiveCount = 0;

$filteredLinks = [];
foreach ($rawLinks as $l) {
    $isExp = !empty($l['expires_at']) && strtotime($l['expires_at']) <= $now;
    $isLive = ($l['status'] === 'active' && !$isExp);

    if ($isLive) {
        $activeCount++;
        $activeVolume += (float) $l['amount'];
    } else {
        $inactiveCount++;
    }
}
$allDisplayLinks = $rawLinks;


$pageTitle       = 'Payment Links';
$pageHeading     = 'Links';
$pageSub         = 'Create and manage checkout URLs to share with your customers.';
$hidePageHeading = true; // Use sleek Dark Hero Stage instead of plain topbar
include __DIR__ . '/includes/header.php';
?>

<!-- =========================================================
     DARK HERO STAGE ("Kahi Kahi Pe Dark UI")
     Matching index.php, dashboard.php & transactions.php
     ========================================================= -->
<div class="dash-hero-stage-dark" data-reveal>
    <div class="hero-stage-mesh-top" aria-hidden="true"></div>
    <div class="hero-stage-mesh-bottom" aria-hidden="true"></div>

    <div class="hero-stage-header">
        <div class="hero-eyebrow">
            <span class="dot"></span>
            <span>Checkout Engine &middot; Direct UPI</span>
        </div>
        <div class="hero-sla-pill">
            <span class="sla-dot"></span>
            <span>Dynamic QR &middot; Deep Intent</span>
        </div>
    </div>

    <div class="hero-stage-headline">
        <h1 class="dash-hero-heading">
            Payment <span class="accent">Links</span>
        </h1>
        <p class="dash-hero-desc">
            Shareable links &amp; hosted checkouts with real-time UTR match.
        </p>
    </div>

    <div class="hero-meta-ribbon">
        <div class="meta-ribbon-cell" title="Number of active payment links">
            <div class="meta-cell-label">Active Links</div>
            <div class="meta-cell-value">
                <span class="val-accent" data-count="<?= $activeCount ?>" data-suffix=" Live Links"><?= $activeCount ?> Live Links</span>
            </div>
        </div>

        <div class="meta-ribbon-cell" title="Total settled revenue through links">
            <div class="meta-cell-label">Live Volume</div>
            <div class="meta-cell-value">
                <span class="val-money" data-count="<?= (float) $activeVolume ?>" data-decimals="2" data-prefix="₹"><?= money($activeVolume) ?></span>
            </div>
        </div>

        <div class="meta-ribbon-cell" title="Direct settlement destination">
            <div class="meta-cell-label">Destination VPA</div>
            <div class="meta-cell-value">
                <span class="meta-badge-ok"><span class="mini-beacon"></span> Linked Merchant UPI</span>
            </div>
        </div>

        <div class="meta-ribbon-cell" title="Customer checkout flow">
            <div class="meta-cell-label">Customer Flow</div>
            <div class="meta-cell-value">
                <span class="val-rate">Instant Intent / QR</span>
            </div>
        </div>
    </div>

    <div class="hero-stage-btns">
        <button type="button" class="btn-hero-primary" onclick="document.getElementById('createCard').scrollIntoView({behavior:'smooth'});document.getElementById('linkTitleInput').focus();">
            <?= icon('plus', 15) ?> Create Payment Link
        </button>
        <button type="button" class="btn-hero-secondary" onclick="openQuickQrModal(0)">
            <?= icon('scan-line', 14) ?> Quick QR Collect
        </button>
    </div>
</div>

<!-- =========================================================
     SYSTEM ALERTS
     ========================================================= -->
<?php if ($error): ?>
    <div class="dash-alert dash-alert-error" data-reveal>
        <div class="alert-icon-wrap"><?= icon('alert', 18) ?></div>
        <div class="alert-text-wrap"><strong>Notice:</strong> <?= e($error) ?></div>
    </div>
<?php endif; ?>
<?php if ($f = flash_get()): ?>
    <div class="dash-alert dash-alert-success" data-reveal>
        <div class="alert-icon-wrap"><?= icon('check-circle', 18) ?></div>
        <div class="alert-text-wrap"><strong>Success:</strong> <?= e($f['message']) ?></div>
    </div>
<?php endif; ?>

<!-- =========================================================
     4 VIBRANT COLORFUL METRIC CARDS
     ========================================================= -->
<div class="dash-kpi-grid-light" data-reveal>
    <!-- KPI 1: Active Live Links -->
    <div class="light-kpi-card" style="--kpi-top-bar:#10b981;--kpi-bubble-bg:rgba(16,185,129,0.12);--kpi-bubble-color:#059669;--kpi-bubble-glow:rgba(16,185,129,0.25);">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Active Links</span>
            <span class="kpi-icon-glow"><?= icon('link', 18) ?></span>
        </div>
        <div class="kpi-number-display" style="color:#059669;">
            <?= $activeCount ?>
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill up">
                <?= icon('check', 11) ?> Active
            </span>
            <span>Ready</span>
        </div>
    </div>

    <!-- KPI 2: Live Volume Offered -->
    <div class="light-kpi-card" style="--kpi-top-bar:#0284c7;--kpi-bubble-bg:rgba(2,132,199,0.12);--kpi-bubble-color:#0284c7;--kpi-bubble-glow:rgba(2,132,199,0.25);">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Offered Volume</span>
            <span class="kpi-icon-glow"><?= icon('wallet', 18) ?></span>
        </div>
        <div class="kpi-number-display" style="color:#0284c7;">
            <?= money($activeVolume) ?>
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill up">
                <?= icon('activity', 11) ?> Open
            </span>
            <span>Live checkouts</span>
        </div>
    </div>

    <!-- KPI 3: Total Links Generated -->
    <div class="light-kpi-card" style="--kpi-top-bar:#8b5cf6;--kpi-bubble-bg:rgba(139,92,246,0.12);--kpi-bubble-color:#7c3aed;--kpi-bubble-glow:rgba(139,92,246,0.25);">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Total Links</span>
            <span class="kpi-icon-glow"><?= icon('zap', 18) ?></span>
        </div>
        <div class="kpi-number-display" style="color:#7c3aed;">
            <?= $totalCount ?>
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill neutral">
                <?= icon('layers', 11) ?> All
            </span>
            <span>Lifetime</span>
        </div>
    </div>

    <!-- KPI 4: Inactive / Expired -->
    <div class="light-kpi-card" style="--kpi-top-bar:#f59e0b;--kpi-bubble-bg:rgba(245,158,11,0.12);--kpi-bubble-color:#d97706;--kpi-bubble-glow:rgba(245,158,11,0.25);">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Inactive Links</span>
            <span class="kpi-icon-glow"><?= icon('clock', 18) ?></span>
        </div>
        <div class="kpi-number-display" style="color:#d97706;">
            <?= $inactiveCount ?>
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill down">
                <?= icon('x', 11) ?> Closed
            </span>
            <span>Expired</span>
        </div>
    </div>
</div>

<!-- =========================================================
     INTERACTIVE "GENERATE PAYMENT LINK" CREATOR CARD
     ========================================================= -->
<div class="dash-white-card pl-create-card" id="createCard" data-reveal>
    <div class="white-card-header" style="margin-bottom:18px;">
        <div>
            <h3 class="white-card-title" style="display:flex;align-items:center;gap:8px;">
                <span style="color:#2563eb;"><?= icon('link', 17) ?></span>
                Create Payment Link
            </h3>
            <p class="white-card-sub">Dedicated checkout URL with auto-expiry.</p>
        </div>
    </div>

    <form method="post" action="<?= url('user/payment-links.php') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">

        <div class="pl-create-form-grid">
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="display:flex;align-items:center;gap:5px;">
                    <?= icon('receipt', 13) ?> Title / Purpose
                </label>
                <input class="form-input" id="linkTitleInput" name="title" placeholder="e.g. Website design invoice #104" value="<?= e(post('title')) ?>" required>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" style="display:flex;align-items:center;gap:5px;">
                    <?= icon('rupee', 13) ?> Amount (<?= e(defined('APP_CURRENCY') ? APP_CURRENCY : '₹') ?>)
                </label>
                <input class="form-input" id="linkAmountInput" name="amount" type="number" step="0.01" min="0.01" placeholder="500.00" required>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" style="display:flex;align-items:center;gap:5px;">
                    <?= icon('clock', 13) ?> Auto-Expiry
                </label>
                <select class="form-select" name="expiry">
                    <option value="">Never (Permanent)</option>
                    <option value="15m">15 Minutes</option>
                    <option value="30m">30 Minutes</option>
                    <option value="1h">1 Hour</option>
                    <option value="6h">6 Hours</option>
                    <option value="24h">24 Hours</option>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" style="display:flex;align-items:center;gap:5px;">
                    <?= icon('book', 13) ?> Note / Customer Ref
                </label>
                <input class="form-input" name="description" placeholder="Optional notes for customer">
            </div>

            <div>
                <button class="btn btn-primary" type="submit" style="width:100%;height:48px;font-weight:700;display:flex;align-items:center;justify-content:center;gap:6px;font-size:14px;border-radius:12px;">
                    <?= icon('plus', 15) ?> Create Link
                </button>
            </div>
        </div>

        <!-- Quick Amount Presets -->
        <div style="margin-top:10px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
            <span style="font-size:11.5px;color:#64748b;font-weight:600;">Presets:</span>
            <div class="pl-quick-amounts">
                <span class="pl-quick-amount-chip" onclick="document.getElementById('linkAmountInput').value='100'">₹100</span>
                <span class="pl-quick-amount-chip" onclick="document.getElementById('linkAmountInput').value='250'">₹250</span>
                <span class="pl-quick-amount-chip" onclick="document.getElementById('linkAmountInput').value='500'">₹500</span>
                <span class="pl-quick-amount-chip" onclick="document.getElementById('linkAmountInput').value='1000'">₹1,000</span>
                <span class="pl-quick-amount-chip" onclick="document.getElementById('linkAmountInput').value='2500'">₹2,500</span>
                <span class="pl-quick-amount-chip" onclick="document.getElementById('linkAmountInput').value='5000'">₹5,000</span>
            </div>
        </div>
    </form>
</div>

<!-- =========================================================
     LIST & FILTER CARD
     ========================================================= -->
<div class="dash-white-card tx-filter-card" id="plFilterCard" data-reveal style="margin-bottom:16px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <!-- Status Tabs -->
        <div class="tx-status-tabs" data-liquid-tabs id="plStatusTabs">
            <div class="tx-liquid-glider" aria-hidden="true">
                <span class="tx-glider-wave"></span>
            </div>
            <button type="button" class="tx-status-tab <?= $filterStatus === '' ? 'active active-all' : '' ?>" data-status-filter="" data-url="?status=&q=<?= urlencode($search) ?>">
                <span class="tx-tab-dot dot-all"></span>
                <span class="tx-tab-text">All Links</span>
                <span class="tx-tab-badge badge-all"><?= $totalCount ?></span>
            </button>
            <button type="button" class="tx-status-tab <?= $filterStatus === 'active' ? 'active active-success' : '' ?>" data-status-filter="active" data-url="?status=active&q=<?= urlencode($search) ?>">
                <span class="tx-tab-dot dot-success"></span>
                <span class="tx-tab-text">Active Live</span>
                <span class="tx-tab-badge badge-success"><?= $activeCount ?></span>
            </button>
            <button type="button" class="tx-status-tab <?= $filterStatus === 'inactive' ? 'active active-expired' : '' ?>" data-status-filter="inactive" data-url="?status=inactive&q=<?= urlencode($search) ?>">
                <span class="tx-tab-dot dot-failed"></span>
                <span class="tx-tab-text">Inactive / Expired</span>
                <span class="tx-tab-badge badge-failed"><?= $inactiveCount ?></span>
            </button>
        </div>

        <!-- Search Form -->
        <form method="get" class="pl-search-form" style="position:relative;flex:1;max-width:320px;min-width:200px;">
            <?php if ($filterStatus !== ''): ?><input type="hidden" name="status" value="<?= e($filterStatus) ?>"><?php endif; ?>
            <span class="tx-search-icon"><?= icon('search', 15) ?></span>
            <input class="tx-search-input" type="search" name="q" placeholder="Search by title or link ID..." value="<?= e($search) ?>" style="height:46px;font-size:13.5px;">
            <?php if ($search !== ''): ?>
                <a href="?status=<?= urlencode($filterStatus) ?>" class="tx-search-clear" title="Clear search">&times;</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- =========================================================
     PAYMENT LINKS PRESENTATION (DESKTOP TABLE + MOBILE CARDS)
     ========================================================= -->
<div class="dash-white-card" id="plPresentationCard" style="padding:0;overflow:hidden;" data-reveal>
    <?php if (!$allDisplayLinks): ?>
        <div class="empty-state" style="padding:32px 16px;">
            <div class="empty-icon" style="background:rgba(37,99,235,0.08);color:#2563eb;width:44px;height:44px;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px;">
                <?= icon('link', 22) ?>
            </div>
            <h3 class="empty-state-title" style="font-size:14.5px;font-weight:700;color:#0f172a;margin-bottom:3px;">No links found</h3>
            <p class="empty-state-sub" style="font-size:12px;color:#64748b;max-width:340px;margin:0 auto 12px;">
                <?= $search !== '' ? 'No links matched "' . e($search) . '".' : 'Create a checkout link using the form above.' ?>
            </p>
            <?php if ($search !== '' || $filterStatus !== ''): ?>
                <a class="btn btn-secondary btn-sm" href="<?= url('user/payment-links.php') ?>">Reset Filters</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <!-- Desktop Table View (Hidden on mobile <= 768px) -->
        <div class="pl-desktop-table-wrap">
            <table class="tx-table">
                <thead>
                    <tr>
                        <th>Link Instance</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Expires</th>
                        <th style="text-align:right;">Actions &amp; Sharing</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allDisplayLinks as $l): ?>
                    <?php
                        $isExp = !empty($l['expires_at']) && strtotime($l['expires_at']) <= $now;
                        $isLive = ($l['status'] === 'active' && !$isExp);
                        $linkUrl = url('pay.php?link=' . $l['link_id']);
                        $waText = rawurlencode("Hello, please pay " . (defined('APP_CURRENCY') ? APP_CURRENCY : '₹') . number_format((float)$l['amount'], 2) . " for " . ($l['title'] ?: 'Payment') . " via UPI here: " . $linkUrl);
                        $waUrl  = "https://api.whatsapp.com/send?text=" . $waText;
                    ?>
                    <tr class="pl-table-row" data-status="<?= $isLive ? 'active' : 'inactive' ?>">
                        <td>
                            <div class="tx-order-pill">
                                <span>/pay/<?= e($l['link_id']) ?></span>
                                <button type="button" class="dark-chip-copy" data-copy="<?= e($linkUrl) ?>" title="Copy Full URL" style="background:none;border:none;padding:0;cursor:pointer;color:#64748b;">
                                    <?= icon('copy', 12) ?>
                                </button>
                            </div>
                            <div class="pl-link-title">
                                <?= e($l['title']) ?>
                            </div>
                            <?php if (!empty($l['description'])): ?>
                                <div class="pl-link-desc">
                                    <?= e($l['description']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="tx-amount-display <?= $isLive ? 'is-success' : '' ?>">
                                <?= money((float) $l['amount']) ?>
                            </div>
                        </td>
                        <td>
                            <?php
                                if ($isExp) {
                                    echo status_badge('expired');
                                } else {
                                    echo status_badge((string) $l['status']);
                                }
                            ?>
                        </td>
                        <td>
                            <div class="pl-date-cell">
                                <?= e(date('M j, g:i a', strtotime($l['created_at']))) ?>
                            </div>
                        </td>
                        <td>
                            <div class="pl-expires-cell">
                                <?= $l['expires_at'] ? e(date('M j, g:i a', strtotime($l['expires_at']))) : '<span class="pl-perm-badge">Permanent</span>' ?>
                            </div>
                        </td>
                        <td style="text-align:right;">
                            <div style="display:inline-flex;align-items:center;gap:6px;">
                                <!-- Copy Link -->
                                <button type="button" class="btn btn-ghost btn-sm" data-copy="<?= e($linkUrl) ?>" title="Copy Checkout URL">
                                    <?= icon('copy', 14) ?>
                                </button>

                                <!-- WhatsApp Share -->
                                <a href="<?= e($waUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-ghost btn-sm" title="Share on WhatsApp" style="color:#16a34a;">
                                    <?= icon('message', 14) ?>
                                </a>

                                <!-- Open Checkout in new tab -->
                                <a href="<?= e($linkUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm" title="Open Checkout Page">
                                    <?= icon('external', 13) ?>
                                </a>

                                <!-- Toggle Status or Reactivate -->
                                <?php if ($isExp): ?>
                                    <form method="post" style="display:inline;margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reactivate">
                                        <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                                        <button class="btn btn-primary btn-sm" type="submit" title="Reactivate with no expiry">Reactivate</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" style="display:inline;margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                                        <button class="btn btn-ghost btn-sm" type="submit" title="<?= $l['status'] === 'active' ? 'Disable Link' : 'Enable Link' ?>">
                                            <?= $l['status'] === 'active' ? 'Disable' : 'Enable' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <!-- Delete Link -->
                                <form method="post" onsubmit="return confirm('Delete this payment link permanently?');" style="display:inline;margin:0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit" title="Delete Link" style="color:#ef4444;">
                                        <?= icon('trash', 14) ?>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Cards List View (Visible on mobile <= 768px, eliminates all horizontal blowout) -->
        <div class="pl-mobile-list">
            <?php foreach ($allDisplayLinks as $l): ?>
            <?php
                $isExp = !empty($l['expires_at']) && strtotime($l['expires_at']) <= $now;
                $isLive = ($l['status'] === 'active' && !$isExp);
                $linkUrl = url('pay.php?link=' . $l['link_id']);
                $waText = rawurlencode("Hello, please pay " . (defined('APP_CURRENCY') ? APP_CURRENCY : '₹') . number_format((float)$l['amount'], 2) . " for " . ($l['title'] ?: 'Payment') . " via UPI here: " . $linkUrl);
                $waUrl  = "https://api.whatsapp.com/send?text=" . $waText;
            ?>
            <div class="pl-card-mobile" data-status="<?= $isLive ? 'active' : 'inactive' ?>">
                <div class="pl-card-mobile-top">
                    <div class="tx-order-pill">
                        <span>/pay/<?= e($l['link_id']) ?></span>
                        <button type="button" class="dark-chip-copy" data-copy="<?= e($linkUrl) ?>" title="Copy URL" style="background:none;border:none;padding:0;cursor:pointer;color:#64748b;">
                            <?= icon('copy', 12) ?>
                        </button>
                    </div>
                    <div>
                        <?php
                            if ($isExp) {
                                echo status_badge('expired');
                            } else {
                                echo status_badge((string) $l['status']);
                            }
                        ?>
                    </div>
                </div>

                <div class="pl-card-mobile-mid">
                    <div>
                        <div class="pl-card-mobile-amt <?= $isLive ? 'is-success' : '' ?>">
                            <?= money((float) $l['amount']) ?>
                        </div>
                        <div class="pl-card-mobile-title"><?= e($l['title']) ?></div>
                        <?php if (!empty($l['description'])): ?>
                            <div class="pl-card-mobile-desc"><?= e($l['description']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div style="text-align:right;font-size:11.5px;color:#64748b;">
                        <div><?= e(date('M j, g:i a', strtotime($l['created_at']))) ?></div>
                        <div style="margin-top:2px;color:<?= $l['expires_at'] ? '#64748b' : '#10b981' ?>;">
                            <?= $l['expires_at'] ? 'Exp: ' . e(date('M j', strtotime($l['expires_at']))) : 'Permanent' ?>
                        </div>
                    </div>
                </div>

                <!-- 4 Action Buttons Grid (Designed specifically for mobile touch) -->
                <div class="pl-actions-grid">
                    <button type="button" class="pl-btn-action btn-copy" data-copy="<?= e($linkUrl) ?>" title="Copy URL">
                        <?= icon('copy', 13) ?> Copy
                    </button>

                    <a href="<?= e($waUrl) ?>" target="_blank" rel="noopener noreferrer" class="pl-btn-action btn-whatsapp" title="Share via WhatsApp">
                        <?= icon('message', 13) ?> WhatsApp
                    </a>

                    <a href="<?= e($linkUrl) ?>" target="_blank" rel="noopener noreferrer" class="pl-btn-action" title="Open Checkout Page">
                        <?= icon('external', 13) ?> Open
                    </a>

                    <?php if ($isExp): ?>
                        <form method="post" style="margin:0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reactivate">
                            <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                            <button class="pl-btn-action" type="submit" style="width:100%;background:#eff6ff;color:#2563eb;border-color:#bfdbfe;">Reactivate</button>
                        </form>
                    <?php else: ?>
                        <form method="post" style="margin:0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                            <button class="pl-btn-action" type="submit" style="width:100%;">
                                <?= $l['status'] === 'active' ? 'Disable' : 'Enable' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>