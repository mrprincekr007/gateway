<?php
declare(strict_types=1);

/* Dashboard shell header. Set $pageTitle, $activeNav, $pageHeading before including. */
require_once APP_ROOT . '/includes/icons.php';

$user        = $user ?? current_user();
$pageTitle   = $pageTitle ?? 'Dashboard';
$activeNav   = $activeNav ?? ($active ?? '');
$pageHeading = $pageHeading ?? $pageTitle;
$pageSub     = $pageSub ?? '';
$extraCss    = $extraCss ?? [];

// Instant Auto-Sync for Real Location if not yet recorded
if (!empty($user['id']) && (empty($user['latitude']) || empty($user['longitude']))) {
    $cIp = function_exists('security_ip') ? security_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $ipLoc = lookup_ip_location($cIp);
    if ($ipLoc && !empty($ipLoc['lat']) && !empty($ipLoc['lng'])) {
        $savedLoc = record_user_location((int) $user['id'], (float) $ipLoc['lat'], (float) $ipLoc['lng'], (float) ($ipLoc['accuracy'] ?? 5000), null, 'ip', 'dashboard', $cIp);
        if (!empty($savedLoc['ok'])) {
            $user['latitude'] = $savedLoc['lat'];
            $user['longitude'] = $savedLoc['lng'];
            $user['location_address'] = $savedLoc['address'];
            $user['location_source'] = 'ip';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<title><?= e($pageTitle) ?> &middot; <?= e(SITE_NAME) ?></title>
<link rel="icon" type="image/png" href="<?= asset('user/assets/img/logo.png') ?>">
<link rel="apple-touch-icon" href="<?= asset('user/assets/img/logo.png') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Sora:wght@500;600;700&family=Poppins:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('user/assets/css/components/base.css') ?>">
<link rel="stylesheet" href="<?= asset('user/assets/css/components/header.css') ?>">
<link rel="stylesheet" href="<?= asset('user/assets/css/components/sidebar.css') ?>">
<?php
// Dedicated modular page stylesheet
$pageCssMap = [
    'dashboard'    => 'dashboard.css',
    'transactions' => 'transactions.css',
    'links'        => 'payment-links.css',
    'api-keys'     => 'api-keys.css',
    'prompts'      => 'prompts.css',
    'webhooks'     => 'webhooks.css',
    'subscription' => 'subscription.css',
    'integrations' => 'integrations.css',
    'profile'      => 'profile.css',
    'docs'         => 'docs.css',
    'status'       => 'status.css',
];
$currentPageCss = $pageCssMap[$activeNav] ?? null;
if ($currentPageCss):
    $pageCssPath = __DIR__ . '/../assets/css/pages/' . $currentPageCss;
    if (file_exists($pageCssPath)):
?>
<link rel="stylesheet" href="<?= asset('user/assets/css/pages/' . $currentPageCss) ?>">
<?php
    endif;
endif;
?>
<link rel="stylesheet" href="<?= asset('user/assets/css/components/liquid-tabs.css') ?>">

<?php foreach ($extraCss as $css): ?>
<link rel="stylesheet" href="<?= asset($css) ?>">
<?php endforeach; ?>
<script>
window.GATEWAY_UPI_ID = <?= json_encode($user['upi_id'] ?? '') ?>;
window.GATEWAY_MERCHANT_NAME = <?= json_encode($user['name'] ?? SITE_NAME) ?>;
window.GATEWAY_CURRENCY = <?= json_encode(defined('APP_CURRENCY') ? APP_CURRENCY : '₹') ?>;
window.GATEWAY_SITE_NAME = <?= json_encode(SITE_NAME) ?>;
window.CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
(function() {
    try {
        var t = localStorage.getItem('user_theme') || localStorage.getItem('admin_theme');
        if (t === 'dark') {
            document.documentElement.classList.add('dark-mode');
            document.documentElement.setAttribute('data-theme', 'dark');
            document.addEventListener('DOMContentLoaded', function() {
                if (document.body) document.body.classList.add('dark-mode');
            });
        }
        if (localStorage.getItem('user_sidebar_mini') === '1' && window.innerWidth > 960) {
            document.documentElement.classList.add('sidebar-mini');
        }
    } catch(e) {}
})();
</script>
</head>
<body class="app">
<?php if (!empty($_SESSION['admin_impersonator_id'])): ?>
<div style="background:linear-gradient(90deg,#0f172a,#1e3a8a 50%,#2563eb);color:#ffffff;padding:9px 16px;font-size:13px;font-weight:600;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:999999;box-shadow:0 2px 12px rgba(0,0,0,0.22);flex-wrap:wrap;gap:8px;">
    <div style="display:flex;align-items:center;gap:8px;">
        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#38bdf8;box-shadow:0 0 8px #38bdf8;"></span>
        <span>ADMIN OVERRIDE ACTIVE &middot; Managing <b><?= e($user['name']) ?></b> (<?= e($user['email']) ?>)</span>
    </div>
    <a href="<?= url('admin/exit-impersonate.php') ?>" style="display:inline-flex;align-items:center;gap:6px;background:#ffffff;color:#1e3a8a;padding:5px 14px;border-radius:999px;font-size:12px;text-decoration:none;font-weight:700;box-shadow:0 2px 6px rgba(0,0,0,0.15);">
        Return to Admin &rarr;
    </a>
</div>
<?php endif; ?>
<div id="turboProgressBar"></div>
<div class="dashboard-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

    <main class="main-content">
        <!-- Enterprise Goll Pill App Topbar (like index.php landing-nav) -->
        <header class="app-topbar-goll">
            <div class="topbar-left">
                <button class="topbar-mobile-toggle" type="button" onclick="openSidebar()" aria-label="Open navigation menu">
                    <?= icon('menu', 18) ?>
                </button>
                <span class="topbar-mobile-heading"><?= e($pageHeading) ?></span>
                <div class="topbar-breadcrumbs">
                    <a href="<?= url('user/dashboard.php') ?>" class="topbar-crumb-app">
                        <?= icon('layout-dashboard', 14) ?> Console
                    </a>
                    <span class="topbar-crumb-sep">/</span>
                    <span class="topbar-crumb-current"><?= e($pageHeading) ?></span>
                </div>
                <div class="topbar-status-beacon" title="Gateway Engine Live & Operational">
                    <span class="topbar-beacon-dot"></span>
                    <span>Engine Active</span>
                </div>
            </div>

            <div class="topbar-right">
                <?php if (($user['imap_status'] ?? '') === 'connected'): ?>
                    <a href="<?= url('user/integrations.php') ?>" class="topbar-env-pill topbar-pill-auto is-connected" title="Gmail IMAP Automation Engine Active">
                        <?= icon('zap', 13) ?> Auto-Detect
                    </a>
                <?php else: ?>
                    <a href="<?= url('user/integrations.php') ?>" class="topbar-env-pill topbar-pill-auto is-unconnected" title="Configure Bank Alert Auto-Detection">
                        <?= icon('alert', 13) ?> Setup Engine
                    </a>
                <?php endif; ?>

                <span class="topbar-env-pill topbar-pill-env" title="Environment">
                    <?= icon('shield', 12) ?> <?= strtoupper(APP_ENV === 'production' ? 'PROD' : 'LIVE') ?>
                </span>

                <button type="button" class="topbar-btn-collect" onclick="openQuickQrModal(0)" title="Instant Dynamic QR Collect">
                    <?= icon('plus', 14) ?>
                    <span class="topbar-collect-label-desktop">Quick Collect</span>
                    <span class="topbar-collect-label-mobile">Collect</span>
                </button>

                <!-- Dark / Light Mode Toggle Button -->
                <button type="button" class="topbar-icon-btn topbar-theme-toggle" id="themeToggleBtn" title="Switch Theme (Dark / Light Mode)" aria-label="Toggle Theme">
                    <span class="theme-icon-moon"><?= icon('moon', 17) ?></span>
                    <span class="theme-icon-sun"><?= icon('sun', 17) ?></span>
                </button>

                <!-- User Profile Pill & Dropdown -->
                <div style="position:relative;">
                    <div class="topbar-user-pill" id="topbarUserBtn">
                        <span class="topbar-user-avatar">
                            <?= !empty($user['avatar']) ? '<img src="' . e(url($user['avatar'])) . '" alt="" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">' : e(initials($user['name'])) ?>
                        </span>
                        <div class="topbar-user-meta">
                            <span class="topbar-user-name"><?= e(explode(' ', $user['name'])[0]) ?></span>
                            <span class="topbar-user-role">ID <?= e($user['merchant_id']) ?></span>
                        </div>
                        <?= icon('chevron-right', 13, 'style="color:var(--color-ink-mute);"') ?>
                    </div>
                    <div class="topbar-dropdown" id="topbarUserDropdown" style="width:250px;">
                        <div style="padding:10px 12px;border-bottom:1px solid var(--color-hairline);margin-bottom:6px;">
                            <div style="font-size:13.5px;font-weight:700;color:var(--color-ink);"><?= e($user['name']) ?></div>
                            <div style="font-size:11.5px;color:var(--color-ink-mute);"><?= e($user['email']) ?></div>
                            <div style="margin-top:6px;display:flex;align-items:center;gap:6px;">
                                <span class="dash-chip" style="font-size:10.5px;padding:3px 8px;" data-copy="<?= e($user['merchant_id']) ?>" title="Copy Merchant ID">
                                    ID: <?= e($user['merchant_id']) ?> <?= icon('copy', 11) ?>
                                </span>
                            </div>
                        </div>
                        <a href="<?= url('user/profile.php') ?>" class="dropdown-item">
                            <?= icon('user', 15) ?> Account Profile
                        </a>
                        <a href="<?= url('user/api-keys.php') ?>" class="dropdown-item">
                            <?= icon('key', 15) ?> API Keys
                        </a>
                        <a href="<?= url('user/integrations.php') ?>" class="dropdown-item">
                            <?= icon('plug', 15) ?> IMAP & Integrations
                        </a>
                        <a href="<?= url('user/webhooks.php') ?>" class="dropdown-item">
                            <?= icon('send', 15) ?> Webhook Endpoints
                        </a>
                        <a href="<?= url('user/prompts.php') ?>" class="dropdown-item">
                            <?= icon('sparkles', 15) ?> AI Builder
                        </a>
                        <a href="<?= url('user/docs.php') ?>" class="dropdown-item">
                            <?= icon('book', 15) ?> API Documentation
                        </a>
                        <div style="height:1px;background:var(--color-hairline);margin:6px 0;"></div>
                        <div style="padding:4px 12px 3px;font-size:10.5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--color-ink-mute);">Site &amp; Legal</div>
                        <a href="<?= url('index.php') ?>" class="dropdown-item">
                            <?= icon('external', 15) ?> Landing Page
                        </a>
                        <a href="<?= url('terms.php') ?>" class="dropdown-item">
                            <?= icon('file-text', 15) ?> Terms &amp; Conditions
                        </a>
                        <a href="<?= url('privacy.php') ?>" class="dropdown-item">
                            <?= icon('shield', 15) ?> Privacy Policy
                        </a>
                        <a href="<?= url('refund-policy.php') ?>" class="dropdown-item">
                            <?= icon('receipt', 15) ?> Refund Policy
                        </a>
                        <div style="height:1px;background:var(--color-hairline);margin:6px 0;"></div>
                        <a href="<?= url('logout.php') ?>" class="dropdown-item danger">
                            <?= icon('logout', 15) ?> Sign Out
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <div class="wrap">

