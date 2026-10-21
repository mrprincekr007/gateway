<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/admin-ops.php';

$user    = require_admin();
$active  = 'merchants';
$pageKey = 'user';

$id     = (int) get('id');
$target = admin_find_user($id);

if (!$target) {
    http_response_code(404);
    $pageHeading = 'Merchant not found';
    $pageTitle   = 'Not found';
    include __DIR__ . '/includes/header.php';
    echo '<div class="empty-state"><div class="empty-icon">' . icon('alert', 26) . '</div><p class="empty-state-title">Merchant not found</p><a class="btn btn-primary mt-4" href="' . url('admin/users.php') . '">Back to merchants</a></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$error = null;

/* ---------- POST Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf_token'))) {
        $error = 'Session expired. Please try again.';
    } else {
        $action = post('action');
        $err    = null;

        if ($action === 'status') {
            $err = admin_set_status($id, post('value'), (int) $user['id']);
        } elseif ($action === 'role') {
            $err = admin_set_role($id, post('value'), (int) $user['id']);
        } elseif ($action === 'delete') {
            $err = admin_delete_user($id, (int) $user['id']);
            if (!$err) {
                flash_set('success', 'Merchant deleted.');
                redirect('admin/users.php');
            }
        } elseif ($action === 'update_profile') {
            $err = admin_update_profile($id, post('name'), post('email'), post('upi_id'), post('role'), post('status', $target['status']), (int) $user['id']);
        } elseif ($action === 'reset_password') {
            $err = admin_reset_password($id, post('password'));
            if (!$err) {
                ops_audit('user.reset_password', 'user', $id, 'Password reset for ' . $target['email']);
                flash_set('success', 'Password reset successfully for ' . $target['email']);
                redirect('admin/user.php?id=' . $id . '&tab=security');
            }
        } elseif ($action === 'create_key') {
            $label = trim(post('label')) ?: 'Admin Generated Key';
            $res = admin_create_api_key($id, $label);
            if (is_string($res)) {
                $err = $res;
            } elseif (isset($res['error'])) {
                $err = $res['error'];
            } else {
                $secretPlain = $res['secret_plain'] ?? $res['secret_key'] ?? '';
                flash_set('success', 'API key created. Secret: ' . $secretPlain . ' (Note this down now)');
                redirect('admin/user.php?id=' . $id . '&tab=keys');
            }
        } elseif ($action === 'revoke_key') {
            $keyId = (int) post('key_id');
            $err = admin_revoke_api_key($keyId);
            if (!$err) {
                flash_set('success', 'API key revoked.');
                redirect('admin/user.php?id=' . $id . '&tab=keys');
            }
        } elseif ($action === 'create_link') {
            $title = trim(post('title'));
            $amount = post('amount') !== '' ? (float) post('amount') : null;
            $desc = trim(post('description'));
            $res = admin_create_payment_link($id, $title, $amount, $desc);
            if (is_string($res)) {
                $err = $res;
            } elseif (isset($res['error'])) {
                $err = $res['error'];
            } else {
                flash_set('success', 'Payment link created: ' . $res['link']['title']);
                redirect('admin/user.php?id=' . $id . '&tab=links');
            }
        } elseif ($action === 'toggle_link') {
            $linkId = (int) post('link_id');
            $lStatus = post('status');
            $err = admin_toggle_payment_link($linkId, $lStatus);
            if (!$err) {
                flash_set('success', 'Payment link updated.');
                redirect('admin/user.php?id=' . $id . '&tab=links');
            }
        } elseif ($action === 'delete_link') {
            $linkId = (int) post('link_id');
            $err = admin_delete_payment_link($linkId);
            if (!$err) {
                flash_set('success', 'Payment link deleted.');
                redirect('admin/user.php?id=' . $id . '&tab=links');
            }
        } elseif ($action === 'reconcile_order') {
            $orderId = trim((string) post('order_id'));
            $utr = trim(post('utr'));
            $err = admin_reconcile_order($orderId, $utr, null, (int) $user['id']);
            if ($err) {
                // $err will be handled below
            } else {
                flash_set('success', 'Order marked as settled! Webhook dispatched.');
                redirect('admin/user.php?id=' . $id . '&tab=orders');
            }
        } elseif ($action === 'resend_webhook') {
            $orderId = trim((string) post('order_id'));
            if (!$orderId && post('log_id')) {
                $logStmt = db()->prepare('SELECT order_id FROM webhook_logs WHERE id = ?');
                $logStmt->execute([(int) post('log_id')]);
                $orderId = (string) $logStmt->fetchColumn();
            }
            $err = $orderId ? admin_resend_webhook($orderId) : 'Webhook order not found.';
            if (!$err) {
                flash_set('success', 'Webhook dispatched.');
                redirect('admin/user.php?id=' . $id . '&tab=webhooks');
            }
        } elseif ($action === 'update_user_info') {
            $phone           = trim((string) post('phone'));
            $businessName    = trim((string) post('business_name'));
            $businessType    = trim((string) post('business_type'));
            $businessWebsite = trim((string) post('business_website'));
            $address         = trim((string) post('address'));
            $city            = trim((string) post('city'));
            $state           = trim((string) post('state'));
            $pincode         = trim((string) post('pincode'));
            $panNumber       = strtoupper(trim((string) post('pan_number')));
            $gstNumber       = strtoupper(trim((string) post('gst_number')));

            if ($businessWebsite !== '' && !preg_match('#^https?://#i', $businessWebsite)) {
                $businessWebsite = 'https://' . $businessWebsite;
            }

            db()->prepare('UPDATE users SET 
                phone = ?, business_name = ?, business_type = ?, business_website = ?, 
                address = ?, city = ?, state = ?, pincode = ?, 
                pan_number = ?, gst_number = ? 
                WHERE id = ?')->execute([
                    $phone !== '' ? $phone : null,
                    $businessName !== '' ? $businessName : null,
                    $businessType !== '' ? $businessType : null,
                    $businessWebsite !== '' ? $businessWebsite : null,
                    $address !== '' ? $address : null,
                    $city !== '' ? $city : null,
                    $state !== '' ? $state : null,
                    $pincode !== '' ? $pincode : null,
                    $panNumber !== '' ? $panNumber : null,
                    $gstNumber !== '' ? $gstNumber : null,
                    $id
                ]);
            flash_set('success', 'Merchant business profile & contact details updated.');
            redirect('admin/user.php?id=' . $id . '&tab=userinfo');
        } elseif ($action === 'refresh_location') {
            $candidateIp = $target['last_login_ip'] ?: (function_exists('security_ip') ? security_ip() : '127.0.0.1');
            $ipLoc = lookup_ip_location($candidateIp);
            if ($ipLoc && !empty($ipLoc['lat']) && !empty($ipLoc['lng'])) {
                record_user_location($id, (float) $ipLoc['lat'], (float) $ipLoc['lng'], (float) ($ipLoc['accuracy'] ?? 5000), null, 'ip', 'admin_sync', $candidateIp);
                flash_set('success', 'Merchant location successfully queried & updated from network telemetry.');
            } else {
                flash_set('danger', 'Unable to resolve IP location for IP: ' . e($candidateIp));
            }
            redirect('admin/user.php?id=' . $id . '&tab=userinfo');
        }

        if ($err) {
            $error = $err;
        } else {
            flash_set('success', 'Merchant updated.');
            redirect('admin/user.php?id=' . $id);
        }
    }
}

// Refresh target in case updated
$target = admin_find_user($id);
$isSelf = ((int)$target['id'] === (int)$user['id']);

/* ---------- Merchant Statistics ---------- */
$st = db()->prepare('
    SELECT
        COALESCE(SUM(CASE WHEN status = "success" THEN payable_amount END), 0) AS collected,
        COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END), 0) AS success_count,
        COALESCE(SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END), 0) AS pending_count,
        COALESCE(SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END), 0) AS failed_count
    FROM orders WHERE user_id = ?');
$st->execute([$id]);
$stats = $st->fetch();

$collected   = (float) $stats['collected'];
$totalOrders = (int) $stats['total'];
$successRate = $totalOrders > 0 ? (int) round($stats['success_count'] / $totalOrders * 100) : 0;

/* ---------- Tab Data ---------- */
// 1. Orders
$orderStmt = db()->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
$orderStmt->execute([$id]);
$orders = $orderStmt->fetchAll();

// 2. API Keys
$keyStmt = db()->prepare('SELECT * FROM api_keys WHERE user_id = ? ORDER BY created_at DESC');
$keyStmt->execute([$id]);
$apiKeys = $keyStmt->fetchAll();

// 3. Payment Links
$linkStmt = db()->prepare('SELECT * FROM payment_links WHERE user_id = ? ORDER BY created_at DESC');
$linkStmt->execute([$id]);
$paymentLinks = $linkStmt->fetchAll();

// 4. Webhooks
$wStmt = db()->prepare('SELECT * FROM webhooks WHERE user_id = ?');
$wStmt->execute([$id]);
$webhooks = $wStmt->fetchAll();

$logStmt = db()->prepare('SELECT * FROM webhook_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 25');
$logStmt->execute([$id]);
$webhookLogs = $logStmt->fetchAll();

// 5. IMAP
$gmailEmail = (string) setting_get($id, 'gmail_email', '');
$lastCheck  = (string) setting_get($id, 'imap_last_check', '');
$gmailUpi   = (string) setting_get($id, 'gmail_upi_id', '');

$activeTab = get('tab', 'orders');
if (!in_array($activeTab, ['orders', 'keys', 'links', 'webhooks', 'imap', 'userinfo', 'security'], true)) {
    $activeTab = 'orders';
}

$pageTitle   = 'Merchant 360° · ' . $target['name'];
$pageHeading = $target['name'];
$pageSub     = 'Merchant ID: ' . $target['merchant_id'] . ' · Complete administrative control center';
include __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-error mb-4"><?= icon('alert', 18) ?><span><?= e($error) ?></span></div>
<?php endif; ?>
<?php if ($f = flash_get()): ?>
    <div class="alert alert-success mb-4"><?= icon('check-circle', 18) ?><span><?= e($f['message']) ?></span></div>
<?php endif; ?>

<!-- Enterprise Merchant 360 Command Banner -->
<div class="merchant-hero-banner mb-4">
    <!-- 1. Lush Gradient & Mesh Graphic Cover -->
    <div class="merchant-banner-cover">
        <div class="merchant-banner-mesh"></div>
        <div class="merchant-banner-cover-content">
            <div class="merchant-banner-breadcrumbs">
                <a href="<?= url('admin/users.php') ?>" class="banner-crumb-link">
                    <?= icon('arrow-left', 13) ?> Merchants Directory
                </a>
                <span class="banner-crumb-sep">/</span>
                <span class="banner-crumb-current">MID: <?= e($target['merchant_id']) ?></span>
            </div>
            <div class="merchant-banner-top-tags">
                <span class="banner-chip-glass" title="System User ID">
                    <?= icon('shield', 12) ?> UID #<?= (int) $target['id'] ?>
                </span>
                <?php if (!empty($target['business_type'])): ?>
                    <span class="banner-chip-glass">
                        <?= icon('briefcase', 12) ?> <?= e($target['business_type']) ?>
                    </span>
                <?php endif; ?>
                <span class="banner-chip-glass">
                    <?= icon('wallet', 12) ?> <?= money($collected) ?> Settled
                </span>
            </div>
        </div>
    </div>

    <!-- 2. Profile Details & Action Header (Overlapping the Cover) -->
    <div class="merchant-banner-body">
        <div class="merchant-banner-main-row">
            <!-- Overlapping Avatar -->
            <div class="merchant-banner-avatar-wrap">
                <div class="merchant-banner-avatar">
                    <?php if (!empty($target['avatar'])): ?>
                        <img src="<?= e(url($target['avatar'])) ?>" alt="<?= e($target['name']) ?>">
                    <?php else: ?>
                        <span><?= strtoupper(mb_substr($target['name'] ?: 'M', 0, 1)) ?></span>
                    <?php endif; ?>
                </div>
                <?php if (is_user_online($target['last_activity_at'] ?? null)): ?>
                    <span class="merchant-avatar-live-beacon" title="Merchant is Online Now"></span>
                <?php endif; ?>
            </div>

            <!-- Identity Info -->
            <div class="merchant-banner-identity">
                <div class="merchant-name-line">
                    <h1 class="merchant-banner-title"><?= e($target['name'] ?: 'Unnamed Merchant') ?></h1>
                    
                    <div class="merchant-badges-wrap">
                        <?= $target['role'] === 'admin' 
                            ? '<span class="badge" style="background:rgba(168,85,247,0.18);color:#a855f7;border:1px solid rgba(168,85,247,0.3);font-size:11px;font-weight:700;">Admin</span>' 
                            : '<span class="badge badge-neutral" style="font-size:11px;font-weight:600;">Merchant</span>' ?>
                        <?= status_badge((string) $target['status']) ?>
                        <?= user_online_status_badge($target['last_activity_at'] ?? null, $target['last_login_at'] ?? null) ?>
                    </div>
                </div>

                <?php if (!empty($target['business_name']) || !empty($target['business_website'])): ?>
                    <div class="merchant-business-headline">
                        <?php if (!empty($target['business_name'])): ?>
                            <span class="merchant-brand-name">
                                <?= icon('briefcase', 14) ?> <?= e($target['business_name']) ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($target['business_website'])): ?>
                            <span class="merchant-brand-dot">·</span>
                            <a href="<?= e($target['business_website']) ?>" target="_blank" rel="noopener noreferrer" class="merchant-website-link">
                                <?= icon('globe', 13) ?> <?= e(preg_replace('#^https?://#i', '', $target['business_website'])) ?>
                                <?= icon('external-link', 11) ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Actions Cluster -->
            <div class="merchant-banner-actions">
                <?php if (!$isSelf): ?>
                    <!-- 1-Click Impersonate Button -->
                    <form method="post" action="<?= url('admin/impersonate.php') ?>" target="_blank" style="margin:0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="user_id" value="<?= $id ?>">
                        <button class="btn-banner-login" type="submit">
                            <?= icon('arrow-right', 14) ?>
                            <span>Login As Merchant</span>
                        </button>
                    </form>

                    <!-- Status Suspend / Activate Button -->
                    <form method="post" style="margin:0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="status">
                        <input type="hidden" name="value" value="<?= $target['status'] === 'active' ? 'suspended' : 'active' ?>">
                        <button class="btn-banner-status <?= $target['status'] === 'active' ? 'btn-banner-suspend' : 'btn-banner-activate' ?>" type="submit">
                            <?= $target['status'] === 'active' ? icon('shield', 14) : icon('check', 14) ?>
                            <span><?= $target['status'] === 'active' ? 'Suspend Account' : 'Activate Account' ?></span>
                        </button>
                    </form>
                <?php else: ?>
                    <span class="banner-badge-self"><?= icon('shield', 13) ?> Current Administrator</span>
                <?php endif; ?>

                <!-- Jump to Edit Details tab -->
                <button type="button" class="btn-banner-edit" onclick="switchAdminTab('tab-userinfo', document.querySelector('.admin-tab-btn[onclick*=\'tab-userinfo\']'))" title="Edit Business Info & Address">
                    <?= icon('settings', 14) ?>
                    <span>Edit Profile</span>
                </button>
            </div>
        </div>

        <!-- 3. Lower Meta Strip: Contact & Technical Telemetry Chips -->
        <div class="merchant-banner-meta-bar">
            <!-- Left: Communications & Payment Handles -->
            <div class="banner-meta-group">
                <a href="mailto:<?= e($target['email']) ?>" class="banner-meta-chip" title="Email Merchant">
                    <?= icon('mail', 13) ?>
                    <span><?= e($target['email']) ?></span>
                </a>

                <?php if (!empty($target['phone'])): ?>
                    <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $target['phone']) ?>" target="_blank" rel="noopener noreferrer" class="banner-meta-chip chip-whatsapp" title="WhatsApp / Direct Call">
                        <?= icon('phone', 13) ?>
                        <span class="mono"><?= e($target['phone']) ?></span>
                    </a>
                <?php endif; ?>

                <div class="banner-meta-chip chip-clickable" onclick="navigator.clipboard.writeText('<?= e($target['merchant_id']) ?>'); if (typeof toast === 'function') { toast('Merchant ID copied', 'success'); } else { alert('Copied'); }" title="Click to copy Merchant ID">
                    <?= icon('key', 13) ?>
                    <span class="mono">MID: <?= e($target['merchant_id']) ?></span>
                    <span class="chip-copy-hint"><?= icon('copy', 11) ?></span>
                </div>

                <div class="banner-meta-chip" title="Active UPI Handle">
                    <?= icon('wallet', 13) ?>
                    <span class="mono"><?= e($target['upi_id'] ?: 'No UPI configured') ?></span>
                </div>

                <?php if (!empty($target['latitude']) && !empty($target['longitude'])): ?>
                    <button type="button" class="banner-meta-chip chip-clickable mono" onclick="switchAdminTab('tab-userinfo', document.querySelector('.admin-tab-btn[onclick*=\'tab-userinfo\']'));" style="background:rgba(16,185,129,0.14);border-color:rgba(16,185,129,0.35);color:#34d399;" title="Live Location: <?= e($target['location_address'] ?: ($target['latitude'] . ', ' . $target['longitude'])) ?>">
                        <span style="width:6px;height:6px;background:#10b981;border-radius:50%;box-shadow:0 0 6px #10b981;display:inline-block;"></span>
                        <?= icon('map-pin', 12) ?> GPS: <?= e($target['location_city'] ?: 'Live Pinned') ?> (±<?= round((float)($target['location_accuracy'] ?: 5)) ?>m)
                    </button>
                <?php endif; ?>
            </div>

            <!-- Right: Security & Device Identity -->
            <div class="banner-meta-group banner-meta-tech">
                <span class="banner-meta-chip" style="opacity:0.8;" title="Member since <?= e(nice_date($target['created_at'])) ?>">
                    <?= icon('clock', 13) ?> Joined <?= e(date('M d, Y', strtotime((string)$target['created_at']))) ?>
                </span>

                <?php if (!empty($target['last_login_at'])): ?>
                    <span class="banner-meta-chip" title="Last Login: <?= e(nice_date($target['last_login_at'])) ?>">
                        <?= icon('shield', 13) ?> Login <?= e(time_ago($target['last_login_at'])) ?>
                    </span>
                <?php endif; ?>

                <?php if (!empty($target['last_login_ip'])): ?>
                    <div class="banner-meta-chip chip-clickable mono" onclick="navigator.clipboard.writeText('<?= e($target['last_login_ip']) ?>'); if (typeof toast === 'function') { toast('IP copied', 'success'); }" title="Last Login IP: <?= e($target['last_login_ip']) ?>">
                        <?= icon('wifi', 12) ?> <?= e($target['last_login_ip']) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($target['last_login_device'])): ?>
                    <span class="banner-meta-chip" title="Client Device: <?= e($target['last_login_device']) ?>">
                        <?= icon('monitor', 13) ?> <?= e($target['last_login_device']) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Merchant KPI Cards (Ambient Aura) -->
<div class="admin-kpi-grid mb-4">
    <div class="admin-kpi-card tone-indigo">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Settled Volume</span>
            <span class="admin-kpi-icon"><?= icon('wallet', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= money($collected) ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub"><?= (int) $stats['success_count'] ?> of <?= $totalOrders ?> orders settled</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-emerald">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Success Rate</span>
            <span class="admin-kpi-icon"><?= icon('trending', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= $successRate ?>%</div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub"><?= (int) $stats['pending_count'] ?> pending · <?= (int) $stats['failed_count'] ?> failed</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-cyan">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">API Keys & Links</span>
            <span class="admin-kpi-icon"><?= icon('key', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= count($apiKeys) ?> / <?= count($paymentLinks) ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub"><?= count($apiKeys) ?> Keys · <?= count($paymentLinks) ?> Payment Links</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-purple">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Bank IMAP Status</span>
            <span class="admin-kpi-icon"><?= icon('mail', 18) ?></span>
        </div>
        <div class="admin-kpi-value" style="font-size:18px;">
            <?= status_badge((string) $target['imap_status']) ?>
        </div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub"><?= $gmailEmail ? e($gmailEmail) : 'Not configured' ?></span>
        </div>
    </div>

    <div class="admin-kpi-card tone-amber">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Pending Orders</span>
            <span class="admin-kpi-icon"><?= icon('clock', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= (int) $stats['pending_count'] ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Awaiting customer UTR</span>
        </div>
    </div>
</div>

<!-- Tabs Card -->
<div class="card card-pad-0">
    <div class="admin-tab-nav">
        <button class="admin-tab-btn <?= $activeTab === 'orders' ? 'active' : '' ?>" onclick="switchAdminTab('tab-orders', this)">
            <?= icon('receipt', 15) ?> Orders & Transactions (<?= count($orders) ?>)
        </button>
        <button class="admin-tab-btn <?= $activeTab === 'keys' ? 'active' : '' ?>" onclick="switchAdminTab('tab-keys', this)">
            <?= icon('key', 15) ?> API Keys (<?= count($apiKeys) ?>)
        </button>
        <button class="admin-tab-btn <?= $activeTab === 'links' ? 'active' : '' ?>" onclick="switchAdminTab('tab-links', this)">
            <?= icon('link', 15) ?> Payment Links (<?= count($paymentLinks) ?>)
        </button>
        <button class="admin-tab-btn <?= $activeTab === 'webhooks' ? 'active' : '' ?>" onclick="switchAdminTab('tab-webhooks', this)">
            <?= icon('send', 15) ?> Webhooks & Deliveries (<?= count($webhookLogs) ?>)
        </button>
        <button class="admin-tab-btn <?= $activeTab === 'imap' ? 'active' : '' ?>" onclick="switchAdminTab('tab-imap', this)">
            <?= icon('mail', 15) ?> Bank IMAP Alert Sync
        </button>
        <button class="admin-tab-btn <?= $activeTab === 'userinfo' ? 'active' : '' ?>" onclick="switchAdminTab('tab-userinfo', this)">
            <?= icon('map-pin', 15) ?> User Info &amp; Live Location
            <?php if (!empty($target['latitude'])): ?>
                <span class="badge" style="background:#10b981;color:#ffffff;font-size:9.5px;padding:1px 5px;margin-left:4px;border-radius:4px;font-weight:700;">GPS</span>
            <?php endif; ?>
        </button>
        <button class="admin-tab-btn <?= $activeTab === 'security' ? 'active' : '' ?>" onclick="switchAdminTab('tab-security', this)">
            <?= icon('shield', 15) ?> Account & Password
        </button>
    </div>

    <div style="padding:20px 24px;">

        <!-- TAB 1: ORDERS -->
        <div id="tab-orders" class="admin-tab-pane <?= $activeTab === 'orders' ? 'active' : '' ?>">
            <div class="flex items-center justify-between mb-3" style="flex-wrap:wrap;gap:12px;">
                <div style="font-size:14px;font-weight:600;color:var(--color-ink-base);">Merchant Transactions History</div>
                <a class="btn btn-secondary btn-sm" href="<?= url('admin/orders.php?merchant=' . $id) ?>">
                    View all in platform monitor <?= icon('arrow-right', 12) ?>
                </a>
            </div>

            <?php if (!$orders): ?>
                <div class="empty-state" style="padding:40px 20px;">
                    <div class="empty-icon"><?= icon('receipt', 26) ?></div>
                    <p class="empty-state-title">No transactions recorded</p>
                    <p class="empty-state-sub">This merchant has not initiated any payments yet.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Customer</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>UTR / RRN</th>
                                <th>Date</th>
                                <th style="text-align:right;">Admin Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $o): ?>
                            <tr>
                                <td data-label="Order ID" class="mono font-semibold">
                                    <a href="<?= url('admin/order.php?id=' . (int) $o['id']) ?>"><?= e($o['order_id']) ?></a>
                                </td>
                                <td data-label="Customer"><?= e($o['customer_name'] ?: '—') ?></td>
                                <td data-label="Amount" class="tnum" style="font-weight:700;"><?= money((float) $o['payable_amount']) ?></td>
                                <td data-label="Status"><?= status_badge((string) $o['status']) ?></td>
                                <td data-label="UTR / RRN" class="mono" style="font-size:12px;"><?= e($o['utr'] ?: '—') ?></td>
                                <td data-label="Date" class="text-mute" style="font-size:12px;"><?= e(nice_date($o['created_at'])) ?></td>
                                <td data-label="Admin Actions" style="text-align:right;">
                                    <div class="flex items-center justify-end gap-1">
                                        <a class="btn btn-ghost btn-sm" href="<?= url('admin/order.php?id=' . (int) $o['id']) ?>" title="Full details">
                                            View
                                        </a>
                                        <?php if ($o['status'] === 'pending'): ?>
                                            <button class="btn btn-primary btn-sm" onclick="openOrderReconcileModal(<?= (int) $o['id'] ?>, '<?= e($o['order_id']) ?>', '<?= money((float) $o['payable_amount']) ?>')" style="padding:3px 8px;font-size:11.5px;">
                                                <?= icon('check-circle', 11) ?> Reconcile
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB 2: API KEYS -->
        <div id="tab-keys" class="admin-tab-pane <?= $activeTab === 'keys' ? 'active' : '' ?>">
            <div class="flex items-center justify-between mb-3" style="flex-wrap:wrap;gap:12px;">
                <div>
                    <div style="font-size:14px;font-weight:600;color:var(--color-ink-base);">Merchant API Keys & Credentials</div>
                    <div class="text-mute" style="font-size:12.5px;">Manage or generate integration keys on behalf of this merchant.</div>
                </div>
                <button class="btn btn-primary btn-sm flex items-center gap-1" onclick="openAdminModal('modal-create-key')">
                    <?= icon('key', 13) ?> Generate API Key
                </button>
            </div>

            <?php if (!$apiKeys): ?>
                <div class="empty-state" style="padding:40px 20px;">
                    <div class="empty-icon"><?= icon('key', 26) ?></div>
                    <p class="empty-state-title">No API keys created</p>
                    <p class="empty-state-sub">Click above to generate the first API key for this merchant.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Key Label</th>
                                <th>Public Key</th>
                                <th>Status</th>
                                <th>Last Used</th>
                                <th>Created</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($apiKeys as $k): ?>
                            <tr>
                                <td data-label="Key Label" style="font-weight:600;"><?= e($k['label']) ?></td>
                                <td data-label="Public Key" class="mono font-semibold" style="font-size:12.5px;color:var(--color-primary);"><?= e($k['public_key']) ?></td>
                                <td data-label="Status"><?= status_badge((string) $k['status']) ?></td>
                                <td data-label="Last Used" class="text-mute" style="font-size:12px;"><?= $k['last_used_at'] ? e(nice_date($k['last_used_at'])) : 'Never' ?></td>
                                <td data-label="Created" class="text-mute" style="font-size:12px;"><?= e(nice_date($k['created_at'])) ?></td>
                                <td data-label="Action" style="text-align:right;">
                                    <?php if ($k['status'] === 'active'): ?>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Revoke this API key? Merchant integrations using it will immediately fail.');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="revoke_key">
                                            <input type="hidden" name="key_id" value="<?= (int) $k['id'] ?>">
                                            <button class="btn btn-secondary btn-sm" type="submit" style="color:var(--color-danger);">
                                                Revoke
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-mute" style="font-size:12px;">Revoked</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB 3: PAYMENT LINKS -->
        <div id="tab-links" class="admin-tab-pane <?= $activeTab === 'links' ? 'active' : '' ?>">
            <div class="flex items-center justify-between mb-3" style="flex-wrap:wrap;gap:12px;">
                <div>
                    <div style="font-size:14px;font-weight:600;color:var(--color-ink-base);">Hosted Payment Links</div>
                    <div class="text-mute" style="font-size:12.5px;">Manage or generate direct payment checkout links for this merchant.</div>
                </div>
                <button class="btn btn-primary btn-sm flex items-center gap-1" onclick="openAdminModal('modal-create-link')">
                    <?= icon('link', 13) ?> Create Payment Link
                </button>
            </div>

            <?php if (!$paymentLinks): ?>
                <div class="empty-state" style="padding:40px 20px;">
                    <div class="empty-icon"><?= icon('link', 26) ?></div>
                    <p class="empty-state-title">No payment links configured</p>
                    <p class="empty-state-sub">Create a payment link for this merchant above.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Title / Description</th>
                                <th>Link Identifier</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th style="text-align:right;">Control Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paymentLinks as $l): ?>
                            <tr>
                                <td data-label="Title / Description">
                                    <div style="font-weight:600;"><?= e($l['title']) ?></div>
                                    <?php if ($l['description']): ?>
                                        <div class="text-mute" style="font-size:11.5px;"><?= e($l['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Link Identifier">
                                    <a class="mono font-semibold" href="<?= url('pay.php?link=' . urlencode($l['link_id'])) ?>" target="_blank" style="font-size:12px;">
                                        <?= e($l['link_id']) ?> ↗
                                    </a>
                                </td>
                                <td data-label="Amount" class="tnum" style="font-weight:700;">
                                    <?= $l['amount'] !== null ? money((float) $l['amount']) : '<span class="badge badge-neutral">Open / Customer choice</span>' ?>
                                </td>
                                <td data-label="Status"><?= status_badge((string) $l['status']) ?></td>
                                <td data-label="Created" class="text-mute" style="font-size:12px;"><?= e(nice_date($l['created_at'])) ?></td>
                                <td data-label="Control Actions" style="text-align:right;">
                                    <div class="flex items-center justify-end gap-1">
                                        <!-- Toggle Status -->
                                        <form method="post" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle_link">
                                            <input type="hidden" name="link_id" value="<?= (int) $l['id'] ?>">
                                            <input type="hidden" name="status" value="<?= $l['status'] === 'active' ? 'disabled' : 'active' ?>">
                                            <button class="btn btn-ghost btn-sm" type="submit">
                                                <?= $l['status'] === 'active' ? 'Disable' : 'Enable' ?>
                                            </button>
                                        </form>

                                        <!-- Delete Link -->
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this payment link permanently?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_link">
                                            <input type="hidden" name="link_id" value="<?= (int) $l['id'] ?>">
                                            <button class="btn btn-ghost btn-sm" type="submit" style="color:var(--color-danger);">
                                                <?= icon('trash', 12) ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB 4: WEBHOOKS & DELIVERIES -->
        <div id="tab-webhooks" class="admin-tab-pane <?= $activeTab === 'webhooks' ? 'active' : '' ?>">
            <div class="flex items-center justify-between mb-3" style="flex-wrap:wrap;gap:12px;">
                <div>
                    <div style="font-size:14px;font-weight:600;color:var(--color-ink-base);">Merchant Webhook Configuration & Dispatch History</div>
                    <div class="text-mute" style="font-size:12.5px;">Audit outgoing HTTP POST webhook payloads and responses.</div>
                </div>
            </div>

            <!-- Webhook Endpoints -->
            <?php if ($webhooks): ?>
                <div class="mb-4" style="background:var(--color-bg-subtle);border:1px solid var(--color-border);border-radius:10px;padding:16px;">
                    <div style="font-weight:600;font-size:13px;margin-bottom:8px;">Registered Webhook Endpoints:</div>
                    <?php foreach ($webhooks as $w): ?>
                        <div class="flex items-center justify-between py-1" style="font-size:12.5px;border-bottom:1px dashed var(--color-border);">
                            <span class="mono" style="color:var(--color-primary);"><?= e($w['url']) ?></span>
                            <span class="badge badge-neutral"><?= e($w['events'] ?: 'order.paid') ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!$webhookLogs): ?>
                <div class="empty-state" style="padding:40px 20px;">
                    <div class="empty-icon"><?= icon('send', 26) ?></div>
                    <p class="empty-state-title">No webhook dispatches logged</p>
                    <p class="empty-state-sub">Webhook requests triggered by settlements will appear here.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Endpoint URL</th>
                                <th>Status</th>
                                <th>HTTP Code</th>
                                <th>Dispatched At</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($webhookLogs as $l): ?>
                            <tr>
                                <td data-label="Order ID" class="mono font-semibold"><?= e($l['order_id'] ?: 'ping') ?></td>
                                <td data-label="Endpoint URL" class="mono" style="font-size:12px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?= e($l['endpoint_url']) ?>
                                </td>
                                <td data-label="Status"><?= status_badge((string) $l['status']) ?></td>
                                <td data-label="HTTP Code">
                                    <span class="mono badge <?= (int)$l['response_code'] >= 200 && (int)$l['response_code'] < 300 ? 'badge-success' : 'badge-danger' ?>">
                                        <?= $l['response_code'] ?: 'TIMEOUT' ?>
                                    </span>
                                </td>
                                <td data-label="Dispatched At" class="text-mute" style="font-size:12px;"><?= e(nice_date($l['created_at'])) ?></td>
                                <td data-label="Action" style="text-align:right;">
                                    <form method="post" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="resend_webhook">
                                        <input type="hidden" name="log_id" value="<?= (int) $l['id'] ?>">
                                        <button class="btn btn-secondary btn-sm" type="submit" title="Retry webhook POST call">
                                            <?= icon('refresh', 11) ?> Retry
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB 5: BANK IMAP ALERTS -->
        <div id="tab-imap" class="admin-tab-pane <?= $activeTab === 'imap' ? 'active' : '' ?>">
            <div style="font-size:14px;font-weight:600;color:var(--color-ink-base);margin-bottom:4px;">Bank IMAP Automation Health</div>
            <p class="text-mute" style="font-size:12.5px;margin-bottom:18px;">
                This merchant's automated bank credit notification sync status via Gmail IMAP.
            </p>

            <div class="dash-grid" style="grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;">
                <div style="background:var(--color-bg-subtle);border:1px solid var(--color-border);border-radius:12px;padding:20px;">
                    <div style="font-weight:600;font-size:13.5px;margin-bottom:14px;color:var(--color-ink-base);">Sync Telemetry</div>
                    
                    <div class="kv"><span class="kv-key">IMAP Status</span><span class="kv-val"><?= status_badge((string) $target['imap_status']) ?></span></div>
                    <div class="kv"><span class="kv-key">Gmail Account</span><span class="kv-val"><?= e($gmailEmail ?: 'None registered') ?></span></div>
                    <div class="kv"><span class="kv-key">Alert UPI ID</span><span class="kv-val mono"><?= e($gmailUpi ?: 'None registered') ?></span></div>
                    <div class="kv" style="border-bottom:none;"><span class="kv-key">Last Checked</span><span class="kv-val text-mute"><?= $lastCheck ? e($lastCheck) : 'Never synced' ?></span></div>
                </div>

                <div style="background:var(--color-bg-subtle);border:1px solid var(--color-border);border-radius:12px;padding:20px;">
                    <div style="font-weight:600;font-size:13.5px;margin-bottom:10px;color:var(--color-ink-base);">Automated UTR Matcher</div>
                    <p style="font-size:12.5px;color:var(--color-ink-mute);line-height:1.6;margin-bottom:16px;">
                        The background IMAP worker checks incoming bank emails for credit alerts matching pending order amounts within seconds.
                    </p>
                    <a class="btn btn-secondary btn-sm" href="<?= url('cron/imap.php') ?>" target="_blank">
                        <?= icon('refresh', 13) ?> Run Platform IMAP Cron Now ↗
                    </a>
                </div>
            </div>
        </div>

        <!-- TAB: USER & BUSINESS INFO -->
        <div id="tab-userinfo" class="admin-tab-pane <?= $activeTab === 'userinfo' ? 'active' : '' ?>">
            <?php
            $isComplete = !empty($target['phone']) && !empty($target['address']) && !empty($target['business_name']);
            $cleanPhone = preg_replace('/\D/', '', (string) ($target['phone'] ?? ''));
            $mapQuery   = urlencode(trim(($target['address'] ? $target['address'] . ', ' : '') . ($target['city'] ? $target['city'] . ' ' : '') . ($target['pincode'] ?? '')));
            ?>

            <!-- Top Compact Overview & Quick Action Strip -->
            <div class="userinfo-top-strip" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;background:var(--color-bg-subtle,#f8fafc);border:1px solid var(--color-border);padding:10px 16px;border-radius:10px;margin-bottom:16px;">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <div style="display:flex;align-items:center;gap:6px;font-weight:700;font-size:14px;color:var(--color-ink-base);">
                        <?= icon('briefcase', 16) ?>
                        <span><?= e($target['business_name'] ?: ($target['name'] . ' (Store)')) ?></span>
                    </div>

                    <?php if (!empty($target['business_type'])): ?>
                        <span class="badge badge-neutral" style="font-size:11px;padding:2px 8px;"><?= e($target['business_type']) ?></span>
                    <?php endif; ?>

                    <span class="badge <?= $isComplete ? 'badge-success' : 'badge-neutral' ?>" style="font-size:11px;padding:2px 8px;gap:4px;">
                        <?= $isComplete ? icon('check-circle', 11) . ' Profile Complete' : icon('clock', 11) . ' Pending Details' ?>
                    </span>
                </div>

                <!-- 1-Click Action Toolbar -->
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <?php if (!empty($target['phone'])): ?>
                        <a href="tel:<?= e($target['phone']) ?>" class="btn btn-secondary btn-xs" style="font-size:11px;padding:4px 9px;gap:5px;">
                            <?= icon('phone', 11) ?> Call
                        </a>
                        <?php if ($cleanPhone !== ''): ?>
                            <a href="https://wa.me/<?= e($cleanPhone) ?>" target="_blank" rel="noopener" class="btn btn-xs" style="background:rgba(16,185,129,0.15);color:#10b981;border:1px solid rgba(16,185,129,0.3);font-size:11px;padding:4px 9px;gap:5px;font-weight:600;">
                                <?= icon('message-circle', 11) ?> WhatsApp ↗
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!empty($target['business_website'])): ?>
                        <a href="<?= e($target['business_website']) ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-xs" style="font-size:11px;padding:4px 9px;gap:5px;">
                            <?= icon('globe', 11) ?> Site ↗
                        </a>
                    <?php endif; ?>

                    <?php if (!empty($target['address']) || !empty($target['city'])): ?>
                        <a href="https://maps.google.com/?q=<?= $mapQuery ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-xs" style="font-size:11px;padding:4px 9px;gap:5px;">
                            <?= icon('map-pin', 11) ?> Maps ↗
                        </a>
                    <?php endif; ?>
                </div>
            </div>



            <!-- Two Equal, Compact Columns -->
            <div class="userinfo-grid" style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px;align-items:stretch;">
                
                <!-- Column 1: Verified Dossier Details (Single Clean Card, No nested box inception!) -->
                <div class="card card-pad-sm" style="background:var(--surface-card,#ffffff);border:1px solid var(--color-border);border-radius:12px;padding:16px 18px;margin:0;display:flex;flex-direction:column;justify-content:space-between;">
                    <div>
                        <div class="flex items-center justify-between mb-3" style="border-bottom:1px solid var(--color-border);padding-bottom:10px;">
                            <div style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--color-ink-muted);letter-spacing:0.04em;display:flex;align-items:center;gap:6px;">
                                <?= icon('building', 13) ?> Merchant Operating Dossier
                            </div>
                            <span class="text-mute" style="font-size:11px;">Merchant-Provided</span>
                        </div>

                        <!-- Brand & Website -->
                        <div style="display:grid;grid-template-columns:1.2fr 0.8fr;gap:12px;margin-bottom:12px;">
                            <div>
                                <div class="text-mute" style="font-size:11px;">Store / Business Name</div>
                                <div style="font-size:13.5px;font-weight:700;color:var(--color-ink-base);margin-top:2px;">
                                    <?= e($target['business_name'] ?: 'Not provided') ?>
                                </div>
                            </div>
                            <div>
                                <div class="text-mute" style="font-size:11px;">Business Category</div>
                                <div style="font-size:12.5px;font-weight:600;color:var(--color-ink-base);margin-top:2px;">
                                    <?= e($target['business_type'] ?: 'Not categorized') ?>
                                </div>
                            </div>
                        </div>

                        <!-- Website URL -->
                        <div style="margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid var(--color-border);">
                            <div class="text-mute" style="font-size:11px;">Storefront / Portal URL</div>
                            <div style="display:flex;align-items:center;gap:8px;margin-top:3px;">
                                <?php if (!empty($target['business_website'])): ?>
                                    <a href="<?= e($target['business_website']) ?>" target="_blank" rel="noopener" class="mono" style="font-size:12px;color:var(--color-primary);text-decoration:none;display:inline-flex;align-items:center;gap:4px;word-break:break-all;">
                                        <?= icon('globe', 12) ?> <?= e($target['business_website']) ?> ↗
                                    </a>
                                    <button type="button" class="btn btn-ghost btn-xs" data-copy="<?= e($target['business_website']) ?>" title="Copy URL" style="padding:2px 6px;">
                                        <?= icon('copy', 11) ?>
                                    </button>
                                <?php else: ?>
                                    <span class="text-mute" style="font-size:12px;">No URL provided</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Contact & Email -->
                        <div style="display:grid;grid-template-columns:1.1fr 0.9fr;gap:12px;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid var(--color-border);">
                            <div>
                                <div class="text-mute" style="font-size:11px;">Customer Phone / WhatsApp</div>
                                <div style="font-size:13px;font-weight:700;color:var(--color-ink-base);margin-top:3px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                    <?php if (!empty($target['phone'])): ?>
                                        <span class="mono"><?= e($target['phone']) ?></span>
                                        <button type="button" class="btn btn-ghost btn-xs" data-copy="<?= e($target['phone']) ?>" title="Copy Phone" style="padding:1px 5px;">
                                            <?= icon('copy', 11) ?>
                                        </button>
                                    <?php else: ?>
                                        <span class="text-mute">Not provided</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <div class="text-mute" style="font-size:11px;">Account Email</div>
                                <div style="font-size:12.5px;font-weight:600;color:var(--color-ink-base);margin-top:3px;word-break:break-all;">
                                    <?= e($target['email']) ?>
                                </div>
                            </div>
                        </div>

                        <!-- Physical Address -->
                        <div style="margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid var(--color-border);">
                            <div class="text-mute" style="font-size:11px;">Physical Operating Address</div>
                            <div style="font-size:13px;color:var(--color-ink-base);margin-top:3px;line-height:1.4;">
                                <?= !empty($target['address']) ? nl2br(e($target['address'])) : '<span class="text-mute">Not provided</span>' ?>
                            </div>
                            <div style="display:flex;align-items:center;gap:12px;margin-top:6px;font-size:12px;color:var(--color-ink-secondary);flex-wrap:wrap;">
                                <span><strong>City:</strong> <?= e($target['city'] ?: '—') ?></span>
                                <span><strong>State:</strong> <?= e($target['state'] ?: '—') ?></span>
                                <span><strong>PIN:</strong> <span class="mono"><?= e($target['pincode'] ?: '—') ?></span></span>
                            </div>
                        </div>

                        <!-- Tax & Identifiers -->
                        <div>
                            <div class="text-mute" style="font-size:11px;margin-bottom:4px;">Regulatory &amp; Tax Identifiers</div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                                <div style="background:var(--color-bg-subtle,#f8fafc);padding:6px 10px;border-radius:6px;border:1px solid var(--color-border);display:flex;align-items:center;justify-content:space-between;">
                                    <div>
                                        <div class="text-mute" style="font-size:9.5px;text-transform:uppercase;">PAN</div>
                                        <div class="mono" style="font-size:12.5px;font-weight:700;color:var(--color-ink-base);">
                                            <?= e($target['pan_number'] ?: '—') ?>
                                        </div>
                                    </div>
                                    <?php if (!empty($target['pan_number'])): ?>
                                        <button type="button" class="btn btn-ghost btn-xs" data-copy="<?= e($target['pan_number']) ?>" title="Copy PAN" style="padding:2px 4px;">
                                            <?= icon('copy', 11) ?>
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <div style="background:var(--color-bg-subtle,#f8fafc);padding:6px 10px;border-radius:6px;border:1px solid var(--color-border);display:flex;align-items:center;justify-content:space-between;">
                                    <div>
                                        <div class="text-mute" style="font-size:9.5px;text-transform:uppercase;">GSTIN</div>
                                        <div class="mono" style="font-size:12.5px;font-weight:700;color:var(--color-ink-base);">
                                            <?= e($target['gst_number'] ?: '—') ?>
                                        </div>
                                    </div>
                                    <?php if (!empty($target['gst_number'])): ?>
                                        <button type="button" class="btn btn-ghost btn-xs" data-copy="<?= e($target['gst_number']) ?>" title="Copy GSTIN" style="padding:2px 4px;">
                                            <?= icon('copy', 11) ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Live Session, IP & Device Security Dossier -->
                        <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--color-border);">
                            <div class="text-mute" style="font-size:11px;margin-bottom:6px;display:flex;align-items:center;justify-content:space-between;">
                                <span style="font-weight:600;display:flex;align-items:center;gap:4px;">
                                    <?= icon('shield', 12) ?> Security &amp; Device Identity
                                </span>
                                <?= user_online_status_badge($target['last_activity_at'] ?? null, $target['last_login_at'] ?? null) ?>
                            </div>

                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                                <div style="background:var(--color-bg-subtle,#f8fafc);padding:6px 10px;border-radius:6px;border:1px solid var(--color-border);">
                                    <div class="text-mute" style="font-size:9.5px;text-transform:uppercase;">Last Login IP</div>
                                    <div style="display:flex;align-items:center;justify-content:space-between;gap:4px;margin-top:2px;">
                                        <span class="mono" style="font-size:12px;font-weight:700;color:var(--color-ink-base);">
                                            <?= e($target['last_login_ip'] ?: '—') ?>
                                        </span>
                                        <?php if (!empty($target['last_login_ip'])): ?>
                                            <button type="button" class="btn btn-ghost btn-xs" data-copy="<?= e($target['last_login_ip']) ?>" title="Copy IP" style="padding:1px 4px;">
                                                <?= icon('copy', 11) ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div style="background:var(--color-bg-subtle,#f8fafc);padding:6px 10px;border-radius:6px;border:1px solid var(--color-border);">
                                    <div class="text-mute" style="font-size:9.5px;text-transform:uppercase;">Last Authentication</div>
                                    <div style="font-size:12px;font-weight:600;color:var(--color-ink-base);margin-top:2px;" title="<?= e($target['last_login_at'] ?? 'Never') ?>">
                                        <?= !empty($target['last_login_at']) ? e(time_ago($target['last_login_at'])) : 'Never logged in' ?>
                                    </div>
                                </div>
                            </div>

                            <div style="background:var(--color-bg-subtle,#f8fafc);padding:6px 10px;border-radius:6px;border:1px solid var(--color-border);margin-top:8px;display:flex;align-items:center;justify-content:space-between;gap:8px;">
                                <div style="display:flex;align-items:center;gap:6px;overflow:hidden;">
                                    <?php
                                    $devParsed = parse_user_agent($target['last_login_device'] ?? '');
                                    ?>
                                    <span style="color:var(--color-ink-mute);display:flex;align-items:center;">
                                        <?= icon($devParsed['icon'] ?? 'monitor', 13) ?>
                                    </span>
                                    <span style="font-size:11.5px;font-weight:600;color:var(--color-ink-base);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                        <?= e($target['last_login_device'] ?: 'No device recorded') ?>
                                    </span>
                                </div>
                                <?php if (!empty($target['last_activity_at'])): ?>
                                    <span class="text-mute" style="font-size:10.5px;white-space:nowrap;" title="Last Active: <?= e($target['last_activity_at']) ?>">
                                        Active <?= e(time_ago($target['last_activity_at'])) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($target['latitude']) && !empty($target['longitude'])): ?>
                                <div style="background:var(--color-bg-subtle,#f8fafc);padding:6px 10px;border-radius:6px;border:1px solid var(--color-border);margin-top:8px;display:flex;align-items:center;justify-content:space-between;gap:8px;">
                                    <div style="display:flex;align-items:center;gap:6px;font-size:11.5px;">
                                        <span style="color:#10b981;"><?= icon('map-pin', 12) ?></span>
                                        <span style="font-weight:600;color:var(--color-ink-base);">Real GPS:</span>
                                        <span class="mono" style="color:var(--color-primary);font-weight:600;"><?= number_format((float)$target['latitude'], 5) ?>, <?= number_format((float)$target['longitude'], 5) ?></span>
                                        <?php if (!empty($target['location_accuracy'])): ?>
                                            <span class="text-mute" style="font-size:10px;">(±<?= round((float)$target['location_accuracy']) ?>m)</span>
                                        <?php endif; ?>
                                    </div>
                                    <a href="https://www.google.com/maps?q=<?= $target['latitude'] ?>,<?= $target['longitude'] ?>&t=k" target="_blank" rel="noopener noreferrer" class="btn btn-ghost btn-xs" style="color:#2563eb;font-size:10.5px;padding:2px 6px;gap:3px;text-decoration:none;">
                                        <?= icon('external-link', 10) ?> Maps ↗
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Column 2: Administrator Edit Form (Matching Single Card) -->
                <div class="card card-pad-sm" style="background:var(--surface-card,#ffffff);border:1px solid var(--color-border);border-radius:12px;padding:16px 18px;margin:0;display:flex;flex-direction:column;justify-content:space-between;">
                    <div>
                        <div class="flex items-center justify-between mb-3" style="border-bottom:1px solid var(--color-border);padding-bottom:10px;">
                            <div style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--color-ink-muted);letter-spacing:0.04em;display:flex;align-items:center;gap:6px;">
                                <?= icon('settings', 13) ?> Edit Merchant Information
                            </div>
                            <span class="badge badge-neutral" style="font-size:10.5px;padding:2px 6px;">Admin Override</span>
                        </div>

                        <form method="post" id="adminUserInfoForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_user_info">

                            <div style="display:grid;grid-template-columns:1.2fr 0.8fr;gap:10px;" class="mb-2">
                                <div>
                                    <label class="form-label" style="font-size:11.5px;margin-bottom:3px;">Business / Brand Name</label>
                                    <input class="form-input" type="text" name="business_name" value="<?= e($target['business_name'] ?? '') ?>" placeholder="e.g. Apex Store Pvt Ltd" style="height:36px;font-size:12.5px;padding:0 10px;">
                                </div>
                                <div>
                                    <label class="form-label" style="font-size:11.5px;margin-bottom:3px;">Category</label>
                                    <select class="form-select" name="business_type" style="height:36px;font-size:12.5px;padding:0 8px;">
                                        <?php
                                        $bTypes = [
                                            '' => '— Select —',
                                            'E-Commerce & Retail Store' => 'E-Commerce & Retail',
                                            'Digital Products & SaaS' => 'Digital SaaS',
                                            'IT Services & Software' => 'IT & Software',
                                            'Freelancer & Consulting' => 'Consulting',
                                            'Education & EdTech' => 'Education',
                                            'Gaming & Entertainment' => 'Gaming',
                                            'Hospitality & Travel' => 'Hospitality',
                                            'Other' => 'Other Enterprise'
                                        ];
                                        $curAdminType = trim((string)($target['business_type'] ?? ''));
                                        foreach ($bTypes as $val => $lbl):
                                            $isSel = ($curAdminType === $val) 
                                                || ($val !== '' && str_starts_with($curAdminType, $val))
                                                || ($curAdminType !== '' && str_starts_with($val, $curAdminType));
                                        ?>
                                            <option value="<?= e($val) ?>" <?= $isSel ? 'selected' : '' ?>><?= e($lbl) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;" class="mb-2">
                                <div>
                                    <label class="form-label" style="font-size:11.5px;margin-bottom:3px;">Website / Storefront URL</label>
                                    <input class="form-input" type="text" name="business_website" value="<?= e($target['business_website'] ?? '') ?>" placeholder="https://domain.com" style="height:36px;font-size:12.5px;padding:0 10px;">
                                </div>
                                <div>
                                    <label class="form-label" style="font-size:11.5px;margin-bottom:3px;">Contact Phone / WhatsApp</label>
                                    <input class="form-input" type="text" name="phone" value="<?= e($target['phone'] ?? '') ?>" placeholder="+91 98765 43210" style="height:36px;font-size:12.5px;padding:0 10px;">
                                </div>
                            </div>

                            <div class="mb-2">
                                <label class="form-label" style="font-size:11.5px;margin-bottom:3px;">Street Address / Operating Office</label>
                                <textarea class="form-input form-textarea" name="address" rows="2" placeholder="Suite / Office, Street, Area" style="width:100% !important;min-height:56px;height:56px;font-size:12.5px;line-height:1.4;padding:6px 10px;box-sizing:border-box;resize:vertical;"><?= e($target['address'] ?? '') ?></textarea>
                            </div>

                            <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:8px;" class="mb-2">
                                <div>
                                    <label class="form-label" style="font-size:11px;margin-bottom:3px;">City</label>
                                    <input class="form-input" type="text" name="city" value="<?= e($target['city'] ?? '') ?>" placeholder="City" style="height:36px;font-size:12.5px;padding:0 8px;">
                                </div>
                                <div>
                                    <label class="form-label" style="font-size:11px;margin-bottom:3px;">State</label>
                                    <input class="form-input" type="text" name="state" value="<?= e($target['state'] ?? '') ?>" placeholder="State" style="height:36px;font-size:12.5px;padding:0 8px;">
                                </div>
                                <div>
                                    <label class="form-label" style="font-size:11px;margin-bottom:3px;">PIN Code</label>
                                    <input class="form-input mono" type="text" name="pincode" value="<?= e($target['pincode'] ?? '') ?>" placeholder="PIN" style="height:36px;font-size:12.5px;padding:0 8px;">
                                </div>
                            </div>

                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;" class="mb-3">
                                <div>
                                    <label class="form-label" style="font-size:11px;margin-bottom:3px;">PAN Number</label>
                                    <input class="form-input mono" type="text" name="pan_number" value="<?= e($target['pan_number'] ?? '') ?>" placeholder="ABCDE1234F" maxlength="10" style="height:36px;font-size:12.5px;padding:0 10px;text-transform:uppercase;">
                                </div>
                                <div>
                                    <label class="form-label" style="font-size:11px;margin-bottom:3px;">GSTIN Number</label>
                                    <input class="form-input mono" type="text" name="gst_number" value="<?= e($target['gst_number'] ?? '') ?>" placeholder="27ABCDE1234F1Z5" maxlength="15" style="height:36px;font-size:12.5px;padding:0 10px;text-transform:uppercase;">
                                </div>
                            </div>

                            <button class="btn btn-primary" type="submit" style="height:38px;padding:0 18px;font-size:12.5px;font-weight:600;display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#3b82f6,#2563eb);box-shadow:0 3px 10px rgba(37,99,235,0.25);border:none;">
                                <?= icon('check-circle', 13) ?> Save Business &amp; Contact Info
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 6: SECURITY & PROFILE -->
        <div id="tab-security" class="admin-tab-pane <?= $activeTab === 'security' ? 'active' : '' ?>">
            <div class="dash-grid" style="grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:24px;">
                <!-- Edit Merchant Profile -->
                <div>
                    <div style="font-weight:600;font-size:14px;margin-bottom:12px;color:var(--color-ink-base);">Edit Merchant Credentials</div>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_profile">

                        <div class="form-group mb-3">
                            <label class="form-label">Full Name / Company</label>
                            <input class="form-input" type="text" name="name" value="<?= e($target['name']) ?>" required>
                        </div>

                        <div class="form-group mb-3">
                            <label class="form-label">Email Address</label>
                            <input class="form-input" type="email" name="email" value="<?= e($target['email']) ?>" required>
                        </div>

                        <div class="form-group mb-3">
                            <label class="form-label">Default UPI ID (VPA)</label>
                            <input class="form-input mono" type="text" name="upi_id" value="<?= e($target['upi_id'] ?? '') ?>">
                        </div>

                        <div class="form-group mb-4">
                            <label class="form-label">Account Role</label>
                            <select class="form-select" name="role" <?= $isSelf ? 'disabled' : '' ?>>
                                <option value="user" <?= $target['role'] === 'user' ? 'selected' : '' ?>>Merchant (Regular)</option>
                                <option value="admin" <?= $target['role'] === 'admin' ? 'selected' : '' ?>>Administrator</option>
                            </select>
                        </div>

                        <button class="btn btn-primary btn-sm flex items-center gap-1" type="submit">
                            <?= icon('check-circle', 14) ?> Save Profile Changes
                        </button>
                    </form>
                </div>

                <!-- Force Password Reset & Danger Zone -->
                <div>
                    <!-- Merchant Active Password Card -->
                    <div style="background:var(--color-surface, #ffffff);border:1px solid var(--color-border);border-radius:12px;padding:16px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
                        <div class="flex items-center gap-2">
                            <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:8px;background:rgba(99,102,241,0.1);color:var(--brand-primary, #6366f1);">
                                <?= icon('shield', 15) ?>
                            </span>
                            <div>
                                <div style="font-weight:700;font-size:13.5px;color:var(--color-ink-base);">Password Security</div>
                                <div style="font-size:11px;color:var(--color-mute, #64748b);">Passwords are stored as one-way bcrypt hashes and can never be viewed.</div>
                            </div>
                        </div>
                    </div>

                    <div style="font-weight:600;font-size:14px;margin-bottom:12px;color:var(--color-ink-base);">Direct Password Reset</div>
                    <form method="post" class="mb-4" onsubmit="return confirm('Immediately reset this merchant\'s password?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reset_password">

                        <div class="form-group mb-3">
                            <label class="form-label">New Password</label>
                            <input class="form-input" type="password" name="password" placeholder="Minimum 8 characters" required minlength="8">
                            <p class="form-help">Overwrites current password immediately without requiring merchant confirmation.</p>
                        </div>

                        <button class="btn btn-secondary btn-sm flex items-center gap-1" type="submit">
                            <?= icon('key', 14) ?> Force Set Password
                        </button>
                    </form>

                    <?php if (!$isSelf): ?>
                        <div style="border-top:1px solid var(--color-border);padding-top:20px;margin-top:20px;">
                            <div style="font-weight:600;font-size:13px;color:var(--color-danger);margin-bottom:8px;">Danger Zone</div>
                            <p class="text-mute" style="font-size:12px;margin-bottom:12px;">Permanently delete this merchant, including all API keys, orders, and payment links.</p>
                            <form method="post" onsubmit="return confirm('DANGER: Delete this merchant permanently? All data will be destroyed and cannot be recovered.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <button class="btn btn-sm" type="submit" style="background:var(--danger-bg);color:var(--danger-text);">
                                    <?= icon('trash', 14) ?> Delete Merchant Account
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Modal: Generate API Key for Merchant -->
<div id="modal-create-key" class="admin-modal-overlay">
    <div class="admin-modal-dialog">
        <div class="admin-modal-header">
            <div class="admin-modal-title flex items-center gap-2">
                <?= icon('key', 18) ?> Generate API Key for Merchant
            </div>
            <button type="button" class="admin-modal-close" onclick="closeAdminModal('modal-create-key')">✕</button>
        </div>
        <form method="post">
            <div class="admin-modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_key">

                <div class="form-group mb-3">
                    <label class="form-label">Key Label / Environment</label>
                    <input class="form-input" type="text" name="label" placeholder="e.g. Production Backend, Android App" required>
                </div>
            </div>
            <div class="admin-modal-footer">
                <button type="button" class="btn btn-ghost btn-sm" onclick="closeAdminModal('modal-create-key')">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm flex items-center gap-2">
                    <?= icon('check-circle', 14) ?> Generate Key
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Create Payment Link for Merchant -->
<div id="modal-create-link" class="admin-modal-overlay">
    <div class="admin-modal-dialog">
        <div class="admin-modal-header">
            <div class="admin-modal-title flex items-center gap-2">
                <?= icon('link', 18) ?> Create Payment Link for Merchant
            </div>
            <button type="button" class="admin-modal-close" onclick="closeAdminModal('modal-create-link')">✕</button>
        </div>
        <form method="post">
            <div class="admin-modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_link">

                <div class="form-group mb-3">
                    <label class="form-label">Link Title</label>
                    <input class="form-input" type="text" name="title" placeholder="e.g. VIP Subscription Annual" required>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Fixed Amount (₹)</label>
                    <input class="form-input tnum" type="number" step="0.01" min="1" name="amount" placeholder="Leave empty for open amount">
                    <p class="form-help">Leave blank to allow customer to enter any custom payment amount.</p>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Description / Purpose</label>
                    <textarea class="form-textarea" name="description" rows="2" placeholder="Optional notes shown on checkout page"></textarea>
                </div>
            </div>
            <div class="admin-modal-footer">
                <button type="button" class="btn btn-ghost btn-sm" onclick="closeAdminModal('modal-create-link')">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm flex items-center gap-2">
                    <?= icon('check-circle', 14) ?> Create Link
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Quick Reconcile Order -->
<div id="modal-order-reconcile" class="admin-modal-overlay">
    <div class="admin-modal-dialog">
        <div class="admin-modal-header">
            <div class="admin-modal-title flex items-center gap-2">
                <?= icon('check-circle', 18) ?> Force Reconcile Order
            </div>
            <button type="button" class="admin-modal-close" onclick="closeAdminModal('modal-order-reconcile')">✕</button>
        </div>
        <form method="post">
            <div class="admin-modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reconcile_order">
                <input type="hidden" id="reconcile-order-id" name="order_id" value="">

                <p id="reconcile-order-info" class="mb-3" style="font-size:13.5px;color:var(--color-ink-base);"></p>

                <div class="form-group mb-3">
                    <label class="form-label">Bank UTR / Transaction Reference Number</label>
                    <input class="form-input mono" type="text" name="utr" placeholder="e.g. 415922837103" required>
                    <p class="form-help">Setting this marks order as settled and immediately dispatches merchant webhooks.</p>
                </div>
            </div>
            <div class="admin-modal-footer">
                <button type="button" class="btn btn-ghost btn-sm" onclick="closeAdminModal('modal-order-reconcile')">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm flex items-center gap-2">
                    <?= icon('check-circle', 14) ?> Mark Settled & Dispatch
                </button>
            </div>
        </form>
    </div>
</div>


<?php if (!empty($target['latitude']) && !empty($target['longitude'])): ?>
<script>
(function() {
    var lat = <?= (float) $target['latitude'] ?>;
    var lng = <?= (float) $target['longitude'] ?>;
    var acc = <?= (float) ($target['location_accuracy'] ?: 10) ?>;
    var userLiveMapInstance = null;

    function initUserLiveMap() {
        var mapEl = document.getElementById('userLiveMap');
        if (!mapEl || userLiveMapInstance) return;
        if (typeof L === 'undefined') {
            setTimeout(initUserLiveMap, 200);
            return;
        }

        userLiveMapInstance = L.map('userLiveMap', {
            center: [lat, lng],
            zoom: 17,
            zoomControl: true,
            scrollWheelZoom: false
        });

        // 1. OpenStreetMap Standard Street Layer
        var streetLayer = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap'
        });

        // 2. High-Res Satellite Imagery Layer (ESRI World Imagery - Public Free High-Res Aerial Roofs)
        var satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 19,
            attribution: '© Esri & Satellite Community'
        });

        // Default to Satellite Imagery for maximum house/roof visibility!
        satelliteLayer.addTo(userLiveMapInstance);

        L.control.layers({
            "🛰️ Satellite Aerial View": satelliteLayer,
            "🗺️ Street Map": streetLayer
        }, null, { position: 'topleft' }).addTo(userLiveMapInstance);

        // Accuracy Circle (House / Building Radius)
        L.circle([lat, lng], {
            radius: Math.max(acc, 5),
            color: '#3b82f6',
            fillColor: '#60a5fa',
            fillOpacity: 0.18,
            weight: 1.5
        }).addTo(userLiveMapInstance);

        // Pulsing Radar Marker at Exact Pin
        var customIcon = L.divIcon({
            className: 'custom-radar-pin',
            html: '<div style="position:relative;width:26px;height:26px;">' +
                  '<div style="position:absolute;width:100%;height:100%;background:#ef4444;border-radius:50%;opacity:0.4;animation:pulse-radar 1.8s infinite;"></div>' +
                  '<div style="position:absolute;top:5px;left:5px;width:16px;height:16px;background:#ef4444;border:2.5px solid #ffffff;border-radius:50%;box-shadow:0 2px 8px rgba(0,0,0,0.5);"></div>' +
                  '</div>',
            iconSize: [26, 26],
            iconAnchor: [13, 13]
        });

        var marker = L.marker([lat, lng], { icon: customIcon }).addTo(userLiveMapInstance);
        var popupContent = '<div style="padding:4px 2px;min-width:180px;">' +
            '<div style="font-weight:700;font-size:13px;color:#0f172a;"><?= addslashes(e($target['name'])) ?></div>' +
            '<div style="font-size:11px;color:#64748b;margin:3px 0;line-height:1.35;"><?= addslashes(e($target['location_address'] ?: '')) ?></div>' +
            '<div style="font-size:10.5px;color:#10b981;font-weight:600;">GPS Accuracy: ±' + Math.round(acc) + ' meters</div>' +
            '</div>';
        marker.bindPopup(popupContent).openPopup();
    }

    window.invalidateUserMap = function() {
        if (!userLiveMapInstance) {
            initUserLiveMap();
        } else {
            setTimeout(function() {
                userLiveMapInstance.invalidateSize();
            }, 100);
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initUserLiveMap);
    } else {
        initUserLiveMap();
    }
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
