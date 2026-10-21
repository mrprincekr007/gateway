<?php
declare(strict_types=1);

/* Admin console sidebar. Expects $activeNav and an authenticated admin $user. */
$activeNav = $activeNav ?? '';
$u = $user ?? current_user();

$nav = [
    ['section' => 'Core Overview'],
    ['key' => 'overview',  'label' => 'Command Center',    'href' => 'admin/index.php',         'icon' => 'layout-dashboard'],
    ['section' => 'Merchant Control'],
    ['key' => 'merchants', 'label' => 'Merchants & Users', 'href' => 'admin/users.php',        'icon' => 'user'],
    ['key' => 'orders',    'label' => 'Transactions',      'href' => 'admin/orders.php',       'icon' => 'receipt', 'badge' => 'Live'],
    ['key' => 'links',     'label' => 'Payment Links',     'href' => 'admin/payment-links.php', 'icon' => 'link'],
    ['key' => 'keys',      'label' => 'API Keys & Secrets', 'href' => 'admin/api-keys.php',    'icon' => 'key'],
    ['section' => 'Subscriptions & Billing'],
    ['key' => 'subscriptions', 'label' => 'Subscribers & Plans', 'href' => 'admin/subscriptions.php', 'icon' => 'wallet'],
    ['key' => 'sub_settings',   'label' => 'Subscription Settings', 'href' => 'admin/subscription-settings.php', 'icon' => 'refresh', 'badge' => 'Setup'],
    ['section' => 'Compliance & Risk'],
    ['key' => 'risk',      'label' => 'Risk & Fraud',       'href' => 'admin/risk.php',         'icon' => 'shield'],
    ['section' => 'Automation & Logs'],
    ['key' => 'webhooks',  'label' => 'Webhook Deliveries', 'href' => 'admin/webhooks.php',     'icon' => 'send'],
    ['section' => 'Infrastructure'],
    ['key' => 'system',    'label' => 'Platform & Health',  'href' => 'admin/system.php',       'icon' => 'activity', 'badge' => 'Online'],
    ['key' => 'settings',  'label' => 'Platform Settings', 'href' => 'admin/settings.php',     'icon' => 'settings'],
    ['key' => 'admins',    'label' => 'Admin Team',         'href' => 'admin/admins.php',       'icon' => 'users'],
];
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-head">
        <a class="brand" href="<?= url('admin/index.php') ?>">
            <img src="<?= asset('admin/assets/img/logo.png') ?>" alt="<?= e(SITE_NAME) ?>" class="brand-logo-img">
            <span class="logo-text"><?= e(SITE_NAME) ?></span>
            <span class="admin-badge-pill">ADMIN</span>
        </a>
        <button class="sidebar-close" type="button" onclick="closeSidebar()" aria-label="Close menu" title="Close menu"><?= icon('x', 14) ?></button>
    </div>

    <nav class="sidebar-nav">
        <?php foreach ($nav as $item): ?>
            <?php if (isset($item['section'])): ?>
                <div class="nav-section"><?= e($item['section']) ?></div>
            <?php else: ?>
                <a class="nav-item <?= $activeNav === $item['key'] ? 'active' : '' ?>" href="<?= url($item['href']) ?>">
                    <span class="nav-icon"><?= icon($item['icon'], 18) ?></span>
                    <span style="flex:1;"><?= e($item['label']) ?></span>
                    <?php if (!empty($item['badge'])): ?>
                        <span class="status-pill success" style="font-size:9.5px;padding:2px 6px;background:rgba(16,185,129,0.14);color:#34d399;border:1px solid rgba(16,185,129,0.28);"><?= e($item['badge']) ?></span>
                    <?php elseif (!empty($item['dot'])): ?>
                        <span style="width:6px;height:6px;border-radius:50%;background:<?= e($item['dot']) ?>;box-shadow:0 0 6px <?= e($item['dot']) ?>;" title="Operational"></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-foot">
        <a class="nav-item sidebar-foot-switch" href="<?= url('user/dashboard.php') ?>" style="margin-bottom:6px;">
            <span class="nav-icon" style="color:#38bdf8;"><?= icon('external', 14) ?></span>
            <span class="foot-switch-text" style="flex:1;">User Dashboard</span>
            <span class="status-pill info" style="font-size:9px;padding:1px 5px;background:rgba(56,189,248,0.12);color:#38bdf8;border:1px solid rgba(56,189,248,0.25);">Switch ↗</span>
        </a>
        <div class="sidebar-user">
            <span class="avatar avatar-xs"><?= !empty($u['avatar']) ? '<img src="' . e(url($u['avatar'])) . '" alt="">' : e(initials($u['name'])) ?></span>
            <div class="sidebar-user-meta">
                <span class="sidebar-user-name"><?= e($u['name']) ?></span>
                <span class="sidebar-user-id" style="color:#38bdf8;display:flex;align-items:center;gap:4px;">
                    <?= icon('shield', 10) ?> Root Admin
                </span>
            </div>
            <a class="sidebar-logout" href="<?= url('logout.php') ?>" title="Log out"><?= icon('logout', 14) ?></a>
        </div>
    </div>
</aside>
