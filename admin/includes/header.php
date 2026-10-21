<?php
declare(strict_types=1);

/* Admin console shell header. Set $pageTitle, $activeNav, $pageHeading before including. */
require_once APP_ROOT . '/includes/icons.php';

$user        = $user ?? current_user();
$pageTitle   = $pageTitle ?? 'Admin';
$activeNav   = $activeNav ?? ($active ?? '');
$pageHeading = $pageHeading ?? $pageTitle;
$pageSub     = $pageSub ?? '';
$extraCss    = $extraCss ?? [];

// Quick telemetry counts for topbar notifications
$topPendingOrders = (int) db()->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
$topActiveMerchants = (int) db()->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<title><?= e($pageTitle) ?> &middot; <?= e(SITE_NAME) ?> Admin</title>
<link rel="icon" type="image/png" href="<?= asset('admin/assets/img/logo.png') ?>">
<link rel="apple-touch-icon" href="<?= asset('admin/assets/img/logo.png') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Sora:wght@500;600;700&family=Poppins:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<?php
// Shared component stylesheets (loaded in cascade order)
$adminComponents = ['base', 'header', 'sidebar', 'load-more'];
foreach ($adminComponents as $component):
?>
<link rel="stylesheet" href="<?= asset('admin/assets/css/components/' . $component . '.css') ?>">
<?php endforeach; ?>
<?php
// Dedicated modular page stylesheet
$pageKey = $pageKey ?? $activeNav;
$pageCssMap = [
    'overview'    => 'index.css',
    'merchants'   => 'users.css',
    'user'        => 'user.css',
    'orders'      => 'orders.css',
    'order'       => 'order.css',
    'links'       => 'payment-links.css',
    'keys'        => 'api-keys.css',
    'sub_settings' => 'subscription-settings.css',
    'subscriptions' => 'subscriptions.css',
    'risk'        => 'risk.css',
    'webhooks'    => 'webhooks.css',
    'system'      => 'system.css',
    'settings'    => 'settings.css',
    'admins'      => 'admins.css',
];
$currentPageCss = $pageCssMap[$pageKey] ?? null;
if ($currentPageCss):
    $pageCssPath = __DIR__ . '/../assets/css/pages/' . $currentPageCss;
    if (file_exists($pageCssPath)):
?>
<link rel="stylesheet" id="adminPageCss" href="<?= asset('admin/assets/css/pages/' . $currentPageCss) ?>">
<?php
    endif;
endif;
?>
<?php foreach ($extraCss as $css): ?>
<link rel="stylesheet" href="<?= asset($css) ?>">
<?php endforeach; ?>
<script>
(function() {
    try {
        var t = localStorage.getItem('admin_theme');
        if (t === 'dark') {
            document.documentElement.classList.add('dark-mode');
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    } catch(e) {}
})();
</script>
</head>
<body class="app">
<div class="dashboard-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

    <main class="main-content">
        <!-- Enterprise Goll Pill Admin Topbar (User-Panel Styled Floating Header) -->
        <header class="app-topbar-goll">
            <div class="topbar-left">
                <button class="topbar-mobile-toggle" type="button" onclick="openSidebar()" aria-label="Open navigation menu" aria-controls="sidebar" aria-expanded="false">
                    <?= icon('menu', 18) ?>
                </button>
                <a href="<?= url('admin/index.php') ?>" class="topbar-mobile-brand">
                    <img src="<?= asset('admin/assets/img/logo.png') ?>" alt="<?= e(SITE_NAME) ?>" class="brand-logo-img" style="width:24px;height:24px;">
                    <span class="topbar-mobile-heading"><?= e($pageHeading) ?></span>
                </a>
                <div class="topbar-breadcrumbs">
                    <a href="<?= url('admin/index.php') ?>" class="topbar-crumb-app">
                        <?= icon('shield', 14) ?> Admin Console
                    </a>
                    <span class="topbar-crumb-sep">/</span>
                    <span class="topbar-crumb-current"><?= e($pageHeading) ?></span>
                </div>
                <div class="topbar-status-beacon" title="Admin Root Authority Active & Secure">
                    <span class="topbar-beacon-dot"></span>
                    <span>Root Active</span>
                </div>
            </div>

            <div class="topbar-center">
                <div class="topbar-search-box" onclick="this.querySelector('input').focus()">
                    <?= icon('search', 16) ?>
                    <input class="topbar-search-input" placeholder="Search orders, UTR, merchants..." aria-label="Search orders, UTR or merchants" type="text" onkeydown="if(event.key==='Enter'){window.location.href='<?= url('admin/orders.php') ?>?q='+encodeURIComponent(this.value)}">
                    <kbd class="topbar-search-kbd" title="Press Enter to search">Enter</kbd>
                </div>
            </div>

            <div class="topbar-right">
                <span class="topbar-env-pill topbar-env-admin">
                    <?= icon('zap', 12) ?> ROOT ADMIN
                </span>

                <button type="button" class="topbar-btn-collect" onclick="openAdminModal('modal-header-new-merchant')" title="Register New Merchant Account">
                    <?= icon('plus', 14) ?>
                    <span class="topbar-collect-label-desktop">New Merchant</span>
                    <span class="topbar-collect-label-mobile">Add</span>
                </button>

                <!-- Dark / Light Mode Toggle Button -->
                <button type="button" class="topbar-icon-btn topbar-theme-toggle" id="themeToggleBtn" title="Switch Theme (Dark / Light Mode)" aria-label="Toggle Theme">
                    <span class="theme-icon-moon"><?= icon('moon', 17) ?></span>
                    <span class="theme-icon-sun"><?= icon('sun', 17) ?></span>
                </button>

                <!-- Admin Profile Pill & Dropdown -->
                <div style="position:relative;">
                    <div class="topbar-user-pill" id="topbarUserBtn" role="button" tabindex="0" aria-haspopup="true" aria-expanded="false" aria-label="Account menu">
                        <span class="topbar-user-avatar">
                            <?= strtoupper(mb_substr($user['name'] ?: 'A', 0, 1)) ?>
                        </span>
                        <div class="topbar-user-meta">
                            <span class="topbar-user-name"><?= e(explode(' ', $user['name'])[0]) ?></span>
                            <span class="topbar-user-role">ROOT ADMIN</span>
                        </div>
                        <?= icon('chevron-right', 13, 'style="color:#64748b;"') ?>
                    </div>
                    <div class="topbar-dropdown" id="topbarUserDropdown">
                        <div class="dropdown-user-header">
                            <div class="dropdown-user-name"><?= e($user['name']) ?></div>
                            <div class="dropdown-user-email"><?= e($user['email']) ?></div>
                            <div class="dropdown-user-badge">
                                <span class="badge badge-purple" style="font-size:10px;padding:2px 8px;">Full Console Authority</span>
                            </div>
                        </div>
                        <a href="<?= url('user/dashboard.php') ?>" class="dropdown-item" target="_blank">
                            <?= icon('arrow-right', 15) ?> Switch to User Panel ↗
                        </a>
                        <a href="<?= url('admin/system.php') ?>" class="dropdown-item">
                            <?= icon('activity', 15) ?> System Diagnostics
                        </a>
                        <a href="<?= url('admin/api-keys.php') ?>" class="dropdown-item">
                            <?= icon('key', 15) ?> API Keys & Audit
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="<?= url('logout.php') ?>" class="dropdown-item danger">
                            <?= icon('logout', 15) ?> Sign Out
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <div class="wrap" id="adminMainWrap">


<!-- Universal Header Modal: Register New Merchant -->
<div id="modal-header-new-merchant" class="admin-modal-overlay">
    <div class="admin-modal-dialog">
        <div class="admin-modal-header">
            <div class="admin-modal-title flex items-center gap-2">
                <?= icon('user', 18) ?> Register New Merchant
            </div>
            <button type="button" class="admin-modal-close" onclick="closeAdminModal('modal-header-new-merchant')">✕</button>
        </div>
        <form method="post" action="<?= url('admin/users.php') ?>">
            <div class="admin-modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_user">

                <div class="form-group mb-3">
                    <label class="form-label">Full Name / Merchant Business</label>
                    <input class="form-input" type="text" name="name" placeholder="e.g. Apex Commerce" required>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Email Address</label>
                    <input class="form-input" type="email" name="email" placeholder="merchant@business.com" required>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Password</label>
                    <input class="form-input" type="password" name="password" placeholder="At least 8 characters" required minlength="8">
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Default UPI ID (VPA)</label>
                    <input class="form-input mono" type="text" name="upi_id" placeholder="merchant@okaxis">
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Account Role</label>
                    <select class="form-select" name="role">
                        <option value="user" selected>Merchant (Standard Dashboard)</option>
                        <option value="admin">Administrator (Console Access)</option>
                    </select>
                </div>
            </div>
            <div class="admin-modal-footer">
                <button type="button" class="btn btn-ghost btn-sm" onclick="closeAdminModal('modal-header-new-merchant')">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm flex items-center gap-2">
                    <?= icon('check-circle', 14) ?> Create Account
                </button>
            </div>
        </form>
    </div>
</div>
