<?php
declare(strict_types=1);

/* Dashboard sidebar. Expects $activeNav and an authenticated $user. */
$activeNav = $activeNav ?? '';
$u = $user ?? current_user();

$nav = [
    ['section' => 'Main'],
    ['key' => 'dashboard',    'label' => 'Dashboard',     'href' => 'user/dashboard.php',     'icon' => 'layout-dashboard'],
    ['key' => 'transactions', 'label' => 'Transactions',  'href' => 'user/transactions.php',  'icon' => 'credit-card'],
    ['key' => 'links',        'label' => 'Payment Links', 'href' => 'user/payment-links.php', 'icon' => 'link-2'],
    ['key' => 'api-keys',     'label' => 'API Keys',      'href' => 'user/api-keys.php',      'icon' => 'key'],
    ['key' => 'webhooks',     'label' => 'Webhooks',      'href' => 'user/webhooks.php',      'icon' => 'webhook'],
    ['key' => 'blacklist',    'label' => 'Blacklist',     'href' => 'user/blacklist.php',     'icon' => 'blocks'],
    ['key' => 'subscription', 'label' => 'Subscription',  'href' => 'user/subscription.php',  'icon' => 'rocket'],
    ['section' => 'Settings'],
    ['key' => 'integrations', 'label' => 'Integrations',  'href' => 'user/integrations.php',  'icon' => 'plug'],
    ['key' => 'profile',      'label' => 'Profile',       'href' => 'user/profile.php',       'icon' => 'user'],
    ['key' => 'prompts',      'label' => 'AI Builder',    'href' => 'user/prompts.php',       'icon' => 'sparkles'],
    ['key' => 'docs',         'label' => 'Documentation', 'href' => 'user/docs.php',          'icon' => 'book-open'],
    ['key' => 'status',       'label' => 'System Status', 'href' => 'user/status.php',        'icon' => 'activity'],
];

if (function_exists('is_admin') && is_admin()) {
    $nav[] = ['section' => 'Admin'];
    $nav[] = ['key' => 'admin', 'label' => 'Admin Console', 'href' => 'admin/index.php', 'icon' => 'shield'];
}
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-head">
        <a class="brand" href="<?= url('user/dashboard.php') ?>" title="<?= e(SITE_NAME) ?>">
            <img src="<?= asset('user/assets/img/logo.png') ?>" alt="<?= e(SITE_NAME) ?>" class="brand-logo-img">
            <span class="logo-text"><?= e(SITE_NAME) ?></span>
        </a>
        <div class="sidebar-head-actions">
            <button class="sidebar-dock-toggle" type="button" onclick="toggleSidebarMini()" aria-label="Toggle compact sidebar" title="Toggle compact mode" data-tooltip="Toggle Sidebar">
                <span class="dock-collapse-icon"><?= icon('chevron-left', 14) ?></span>
                <span class="dock-expand-icon"><?= icon('chevron-right', 14) ?></span>
            </button>
        </div>
    </div>

    <nav class="sidebar-nav" id="sidebarNav">
        <!-- Liquid Glider Indicator (Fluid motion between buttons) -->
        <div class="sidebar-liquid-glider" id="sidebarLiquidGlider" aria-hidden="true">
            <span class="glider-wave-sheen"></span>
        </div>

        <?php foreach ($nav as $item): ?>
            <?php if (isset($item['section'])): ?>
                <div class="nav-section" title="<?= e($item['section']) ?>"><span><?= e($item['section']) ?></span></div>
            <?php else: ?>
                <a class="nav-item <?= $activeNav === $item['key'] ? 'active' : '' ?>" href="<?= url($item['href']) ?>" data-tooltip="<?= e($item['label']) ?>">
                    <span class="nav-icon"><?= icon($item['icon'], 18) ?></span>
                    <span class="nav-label" style="flex:1;"><?= e($item['label']) ?></span>
                    <?php if ($item['key'] === 'transactions'): ?>
                        <span class="nav-badge-pill">Live</span>
                    <?php elseif ($item['key'] === 'integrations' && ($u['imap_status'] ?? '') === 'connected'): ?>
                        <span class="nav-badge-dot" title="IMAP Connected"></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-foot">
        <a class="nav-item sidebar-foot-support" href="https://wa.me/<?= e(SUPPORT_WHATSAPP) ?>" target="_blank" rel="noopener" data-tooltip="WhatsApp Support (Online)">
            <span class="nav-icon" style="color:#25d366;"><?= icon('message-circle', 18) ?></span>
            <span class="foot-support-text" style="flex:1;">Support</span>
            <span class="foot-support-pill">ONLINE</span>
        </a>
        <div class="sidebar-user" data-tooltip="<?= e($u['name']) ?> (ID: <?= e($u['merchant_id']) ?>)">
            <span class="avatar avatar-xs"><?= !empty($u['avatar']) ? '<img src="' . e(url($u['avatar'])) . '" alt="">' : e(initials($u['name'])) ?></span>
            <div class="sidebar-user-meta">
                <span class="sidebar-user-name"><?= e($u['name']) ?></span>
                <span class="sidebar-user-id" style="display:flex;align-items:center;gap:4px;">
                    ID <?= e($u['merchant_id']) ?>
                    <button type="button" class="dash-chip-copy" data-copy="<?= e($u['merchant_id']) ?>" title="Copy ID" style="background:none;border:none;padding:0;cursor:pointer;color:#38bdf8;">
                        <?= icon('copy', 11) ?>
                    </button>
                </span>
            </div>
            <a class="sidebar-logout" href="<?= url('logout.php') ?>" title="Log out" data-tooltip="Log out"><?= icon('logout', 14) ?></a>
        </div>
    </div>
</aside>
