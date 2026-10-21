<?php
declare(strict_types=1);

/* =========================================================
   Admin — Subscription Engine & Monetization Center
   Manage plans, configure automated gateways, review
   subscribers, assign/revoke subscriptions and monitor revenue.
   ========================================================= */

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';
require_once APP_ROOT . '/includes/subscriptions.php';
require_once __DIR__ . '/includes/admin-ops.php';

$user = require_admin();
$activeNav = 'subscriptions';

// Fast AJAX Merchant Search by UID, Gmail/Email, Merchant ID, or Name
if (isset($_GET['ajax_search_merchants'])) {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim((string) get('q', ''));
    if ($q === '') {
        echo json_encode([]);
        exit;
    }

    $like = '%' . $q . '%';
    $stmt = db()->prepare(
        "SELECT id, name, email, merchant_id, status, role
         FROM users
         WHERE CAST(id AS CHAR) = :exact_id
            OR merchant_id LIKE :like_q
            OR email LIKE :like_q
            OR name LIKE :like_q
         ORDER BY
            CASE 
                WHEN email = :exact_q OR CAST(id AS CHAR) = :exact_id OR merchant_id = :exact_q THEN 0
                WHEN email LIKE :prefix_q THEN 1
                ELSE 2
            END,
            id DESC
         LIMIT 20"
    );
    $stmt->execute([
        ':exact_id' => $q,
        ':exact_q'  => $q,
        ':like_q'   => $like,
        ':prefix_q' => $q . '%',
    ]);
    $found = $stmt->fetchAll();

    echo json_encode(array_map(function($m) {
        return [
            'id' => (int) $m['id'],
            'name' => (string) ($m['name'] ?: 'Unnamed Merchant'),
            'email' => (string) ($m['email'] ?: ''),
            'merchant_id' => (string) ($m['merchant_id'] ?? ''),
            'status' => (string) ($m['status'] ?? 'active'),
        ];
    }, $found));
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf_token'))) {
        flash_set('error', 'Session expired. Please try again.');
        redirect('admin/subscriptions.php');
    }
    $action = post('action');

    // 1. Assign or Change Subscription to Merchant (Add / Change Plan)
    if ($action === 'assign_sub') {
        $targetUserId = (int) post('user_id');
        $planId       = (int) post('plan_id');
        $durationDays = max(1, (int) post('duration_days', 30));
        $customAmount = post('amount') !== '' ? max(0.0, (float) post('amount')) : null;

        $plan = sub_plan($planId);
        $targetUser = db()->prepare('SELECT id, name, email FROM users WHERE id = ? LIMIT 1');
        $targetUser->execute([$targetUserId]);
        $uRow = $targetUser->fetch();

        if (!$plan || !$uRow) {
            flash_set('error', 'Please select a valid merchant and plan.');
            redirect('admin/subscriptions.php');
        }

        // Expire any existing active subscription for this user
        db()->prepare("UPDATE user_subscriptions SET status = 'expired' WHERE user_id = ? AND status = 'active'")
            ->execute([$targetUserId]);

        $starts  = date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', strtotime('+' . $durationDays . ' days'));
        $amount  = $customAmount !== null ? $customAmount : (float) $plan['price'];

        db()->prepare(
            'INSERT INTO user_subscriptions (user_id, plan_id, status, amount, starts_at, expires_at, payment_id)
             VALUES (?, ?, "active", ?, ?, ?, NULL)'
        )->execute([$targetUserId, $planId, $amount, $starts, $expires]);

        ops_audit('subscription.assign', 'user_subscriptions', (int) db()->lastInsertId(), 'Assigned plan ' . $plan['name'] . ' to ' . $uRow['name'] . ' for ' . $durationDays . ' days');
        flash_set('success', 'Plan ' . $plan['name'] . ' assigned to ' . $uRow['name'] . ' (' . $durationDays . ' days).');
        redirect('admin/subscriptions.php');
    }

    // 7. Revoke Subscription from Merchant
    if ($action === 'revoke_sub') {
        $subId = (int) post('sub_id');
        $sRow = db()->prepare('SELECT s.*, u.name as merchant_name, p.name as plan_name FROM user_subscriptions s LEFT JOIN users u ON u.id = s.user_id LEFT JOIN subscription_plans p ON p.id = s.plan_id WHERE s.id = ? LIMIT 1');
        $sRow->execute([$subId]);
        $subData = $sRow->fetch();

        if ($subData) {
            db()->prepare("UPDATE user_subscriptions SET status = 'cancelled' WHERE id = ?")->execute([$subId]);
            ops_audit('subscription.revoke', 'user_subscriptions', $subId, 'Revoked subscription of ' . ($subData['merchant_name'] ?? 'merchant'));
            flash_set('success', 'Subscription for ' . ($subData['merchant_name'] ?? 'merchant') . ' has been revoked/cancelled.');
        } else {
            flash_set('error', 'Subscription record not found.');
        }
        redirect('admin/subscriptions.php');
    }

    // 8. Extend Subscription (+Days)
    if ($action === 'extend_sub') {
        $subId = (int) post('sub_id');
        $days  = max(1, (int) post('days', 30));
        db()->prepare("UPDATE user_subscriptions SET expires_at = DATE_ADD(GREATEST(expires_at, NOW()), INTERVAL ? DAY), status = 'active' WHERE id = ?")
            ->execute([$days, $subId]);
        ops_audit('subscription.extend', 'user_subscriptions', $subId, 'Extended subscription #' . $subId . ' by ' . $days . ' days');
        flash_set('success', 'Subscription extended by ' . $days . ' days.');
        redirect('admin/subscriptions.php');
    }
}

// ---------------- Data Queries & Telemetry ----------------
$plans = sub_plans(false);
$stats = sub_stats();

$trialDays     = (int) sub_setting('sub_trial_days', '1');
$subEnabled    = (string) sub_setting('sub_enabled', '1') === '1';

$autoEnabled   = sub_auto_enabled();
$autoProvider  = sub_auto_provider();
$autoProviders = sub_auto_providers();
$autoApiUrl    = (string) sub_setting('sub_auto_api_url', '');
$autoApiKey    = (string) sub_setting('sub_auto_api_key', '');
$autoApiSec    = (string) sub_setting('sub_auto_api_secret', '');
$autoSecret    = (string) sub_setting('sub_auto_webhook_secret', '');
$autoMerchant  = sub_gateway_merchant();
$autoReady     = sub_gateway_ready();
$webhookUrl    = sub_gateway_webhook_url();

// All active and recent subscriptions for the directory
$subsList = db()->query(
    "SELECT s.*, u.name AS merchant_name, u.email AS merchant_email, u.merchant_id,
            p.name AS plan_name, p.price AS plan_price, p.is_trial, p.badge, p.api_key_limit, p.txn_limit, p.webhook_limit
     FROM user_subscriptions s
     LEFT JOIN users u ON u.id = s.user_id
     LEFT JOIN subscription_plans p ON p.id = s.plan_id
     ORDER BY 
        CASE WHEN s.status = 'active' AND s.expires_at > NOW() THEN 0 ELSE 1 END ASC,
        s.expires_at ASC
     LIMIT 200"
)->fetchAll();

// Metrics calculations
$activePaidCount   = 0;
$activeTrialCount  = 0;
$expiringSoonCount = 0;
$nowTime = time();

foreach ($subsList as $sb) {
    if ($sb['status'] === 'active' && strtotime((string)$sb['expires_at']) > $nowTime) {
        if (!empty($sb['is_trial'])) {
            $activeTrialCount++;
        } else {
            $activePaidCount++;
        }
        $diffHours = (strtotime((string)$sb['expires_at']) - $nowTime) / 3600;
        if ($diffHours <= 72) {
            $expiringSoonCount++;
        }
    }
}

// Lifetime total collected
$totalRevenue = (float) db()->query("SELECT COALESCE(SUM(amount), 0) FROM subscription_payments WHERE status = 'success'")->fetchColumn();

// Active MRR
$activeMrr = (float) db()->query("SELECT COALESCE(SUM(s.amount), 0) FROM user_subscriptions s WHERE s.status = 'active' AND s.expires_at > NOW() AND s.amount > 0")->fetchColumn();

// Plan subscriber distribution counts
$planSubCounts = [];
$planCountsRows = db()->query(
    "SELECT plan_id, COUNT(*) as cnt 
     FROM user_subscriptions 
     WHERE status = 'active' AND expires_at > NOW() 
     GROUP BY plan_id"
)->fetchAll();
foreach ($planCountsRows as $pcr) {
    $planSubCounts[(int)$pcr['plan_id']] = (int)$pcr['cnt'];
}

// Completed purchases count per plan for history filter badges
$planBuyCounts = [];
$buyCountsRows = db()->query(
    "SELECT plan_id, COUNT(*) as cnt 
     FROM subscription_payments 
     WHERE status = 'success' 
     GROUP BY plan_id"
)->fetchAll();
foreach ($buyCountsRows as $bcr) {
    $planBuyCounts[(int)$bcr['plan_id']] = (int)$bcr['cnt'];
}

// Auto-purge any stale unpaid/expired subscription attempts
sub_cleanup_unpaid_payments();

// Buy Subscription History: completed, paid subscription purchases only
$buyHistory = db()->query(
    "SELECT sp.*, u.name AS merchant_name, u.email AS merchant_email, u.merchant_id,
            p.name AS plan_name, p.badge AS plan_badge
     FROM subscription_payments sp
     LEFT JOIN users u ON u.id = sp.user_id
     LEFT JOIN subscription_plans p ON p.id = sp.plan_id
     WHERE sp.status = 'success'
     ORDER BY sp.id DESC LIMIT 100"
)->fetchAll();

$pageTitle   = 'Subscriptions';
$pageHeading = 'Subscription Engine';
$pageSub     = 'Monetization controls, plan limits, automated payment gateways and subscriber management.';
include __DIR__ . '/includes/header.php';
?>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?= $f['type'] === 'error' ? 'error' : 'success' ?> mb-4">
        <?= icon($f['type'] === 'error' ? 'alert' : 'check-circle', 18) ?><span><?= e($f['message']) ?></span>
    </div>
<?php endif; ?>

<!-- 1. Hero Stage & Quick Actions Strip -->
<div class="sub-hero-stage">
    <div class="flex items-center justify-between" style="flex-wrap:wrap;gap:16px;">
        <div style="flex:1;min-width:280px;">
            <div class="sub-hero-badge">
                <?= icon('users', 13) ?>
                <span>Merchants &amp; Subscribers Hub</span>
            </div>
            <h1 class="sub-hero-title">Subscribers &amp; Merchant Plans</h1>
            <p class="sub-hero-sub">
                Live merchant plan enrollments, limit enforcement tracking, and direct user plan management.
            </p>
            <div class="flex items-center gap-2" style="flex-wrap:wrap;">
                <span class="badge <?= $subEnabled ? 'badge-success' : 'badge-neutral' ?>">
                    <?= icon('shield', 12) ?> Enforcement: <?= $subEnabled ? 'Enforced' : 'Off (Unlimited)' ?>
                </span>
                <span class="badge badge-cyan">
                    <?= icon('users', 12) ?> <?= $activePaidCount ?> Paid Users
                </span>
                <span class="badge badge-purple">
                    <?= icon('clock', 12) ?> <?= $activeTrialCount ?> Free Trials
                </span>
            </div>
        </div>

        <!-- Hero Control Actions -->
        <div class="sub-hero-actions">
            <button type="button" class="btn btn-primary" onclick="openNewAssignModal()">
                <?= icon('plus', 14) ?> Assign Plan to User
            </button>
            <a href="<?= url('admin/subscription-settings.php#plans') ?>" class="btn btn-secondary flex items-center gap-2" title="Create, Edit & Configure Subscription Plans">
                <?= icon('blocks', 16) ?> Manage Plans (Add / Edit)
            </a>
            <a href="<?= url('admin/subscription-settings.php') ?>" class="btn btn-secondary" title="Configure Gateway, Manage Plans, & Global Rules">
                <?= icon('settings', 16) ?> Subscription Settings
            </a>
        </div>
    </div>
</div>

<!-- 2. Advanced Ambient 3D KPI Grid -->
<div class="admin-kpi-grid mb-4">
    <div class="admin-kpi-card tone-indigo">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Active MRR</span>
            <span class="admin-kpi-icon"><?= icon('rupee', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= e(money($activeMrr)) ?></div>
        <div class="admin-kpi-footer">
            <span>From active paying tiers</span>
            <span class="badge badge-cyan" style="font-size:10px;padding:1px 6px;">Live Run-Rate</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-emerald">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Paid Subscribers</span>
            <span class="admin-kpi-icon"><?= icon('users', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= $activePaidCount ?></div>
        <div class="admin-kpi-footer">
            <span>Active paying merchants</span>
            <span class="status-pill success" style="font-size:10px;">Paid</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-purple">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Trial Test-Drivers</span>
            <span class="admin-kpi-icon"><?= icon('clock', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= $activeTrialCount ?></div>
        <div class="admin-kpi-footer">
            <span><?= $trialDays ?>-day free evaluation</span>
            <span class="badge badge-purple" style="font-size:10px;">Trial</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-amber">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Expiring in 72h</span>
            <span class="admin-kpi-icon"><?= icon('alert', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum" style="color:<?= $expiringSoonCount > 0 ? '#f59e0b' : 'inherit' ?>;"><?= $expiringSoonCount ?></div>
        <div class="admin-kpi-footer">
            <span>Needs renewal action</span>
            <span class="badge <?= $expiringSoonCount > 0 ? 'badge-warning' : 'badge-neutral' ?>" style="font-size:10px;"><?= $expiringSoonCount ?> Alert</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-cyan">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Lifetime Collected</span>
            <span class="admin-kpi-icon"><?= icon('trending', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= e(money($totalRevenue)) ?></div>
        <div class="admin-kpi-footer">
            <span>All completed invoices</span>
            <span class="badge badge-success" style="font-size:10px;">Settled</span>
        </div>
    </div>
</div>

<!-- 3. Dedicated Standalone Plan Filter & Search Box (Subscribers Directory Box ke bahar uper) -->
<div class="sub-filter-topbar-box mb-4">
    <div class="sub-plan-pills-scroll" role="tablist">
        <button type="button" class="sub-plan-pill-btn active" data-sub-plan="all" onclick="filterSubsByPlan('all', this)">
            <span class="pill-icon"><?= icon('blocks', 14) ?></span>
            <span class="pill-title">All Plans</span>
            <span class="pill-counter"><?= count($subsList) ?></span>
        </button>

        <?php foreach ($plans as $p): 
            $pid = (int) $p['id'];
            $pCount = $planSubCounts[$pid] ?? 0;
            $priceText = (float)$p['price'] > 0 ? money((float)$p['price']) : 'Free';
            $isTrial = !empty($p['is_trial']);
        ?>
        <button type="button" class="sub-plan-pill-btn" data-sub-plan="<?= $pid ?>" onclick="filterSubsByPlan(<?= $pid ?>, this)">
            <span class="pill-icon"><?= $isTrial ? icon('clock', 14) : icon('zap', 14) ?></span>
            <span class="pill-title"><?= e($p['name']) ?></span>
            <span class="pill-price-tag"><?= e($priceText) ?></span>
            <span class="pill-counter <?= $pCount > 0 ? 'highlight' : '' ?>">
                <?= $pCount ?>
            </span>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- Instant Search Input -->
    <div class="sub-plan-search-wrap">
        <input type="text" id="subSearchInput" class="form-input" placeholder="Search by UID, Gmail, merchant, plan..." oninput="searchSubs(this.value)" style="height:36px;width:280px;font-size:12.5px;padding:0 12px;">
    </div>
</div>

<!-- 4. Active Subscribers Master Directory -->
<div class="card card-pad-0 mb-4">
    <div class="card-header flex items-center justify-between" style="flex-wrap:wrap;gap:12px;">
        <div>
            <h3 class="card-title flex items-center gap-2">
                <span style="color:var(--color-primary);"><?= icon('users', 18) ?></span>
                Subscribers Directory
            </h3>
            <div class="card-sub">Real-time status of merchant subscriptions, expiration countdowns, and limit tracking.</div>
        </div>

        <div class="flex items-center gap-2" style="flex-wrap:wrap;">
            <span class="badge badge-cyan" id="visibleSubsCount" style="font-size:11.5px;padding:4px 10px;">
                <?= count($subsList) ?> Merchants
            </span>
            <button type="button" class="btn btn-primary btn-sm flex items-center gap-1" onclick="openNewAssignModal()">
                <?= icon('plus', 13) ?> Assign Plan to User
            </button>
        </div>
    </div>

    <?php if (!$subsList): ?>
        <div class="empty-state" style="padding:48px 24px;text-align:center;">
            <div class="empty-icon"><?= icon('users', 28) ?></div>
            <div class="empty-state-title">No subscriptions recorded yet</div>
            <div class="empty-state-sub">Merchants will appear here once they register, start a trial, or subscribe to a plan.</div>
            <div class="mt-3">
                <button type="button" class="btn btn-primary btn-sm" onclick="openNewAssignModal()">
                    <?= icon('plus', 14) ?> Assign First Subscription
                </button>
            </div>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table" id="subsTable">
                <thead>
                    <tr>
                        <th>Merchant Profile</th>
                        <th>Subscription Plan</th>
                        <th>Period &amp; Timeline</th>
                        <th>Days Left</th>
                        <th>Limits &amp; Quota</th>
                        <th>Status</th>
                        <th style="text-align:right;">Control Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr id="noSubsForPlanRow" style="display:none;">
                        <td colspan="7" class="text-center text-mute" style="padding:34px 20px;">
                            <div style="font-size:14px;font-weight:600;margin-bottom:4px;color:var(--color-ink);">No merchants currently enrolled in this plan</div>
                            <div style="font-size:12px;margin-bottom:12px;">You can assign merchants to this tier using the button below.</div>
                            <button type="button" class="btn btn-primary btn-sm" onclick="openNewAssignModal()">
                                <?= icon('plus', 13) ?> Assign Merchant to Plan
                            </button>
                        </td>
                    </tr>
                    <?php foreach ($subsList as $s): 
                        $expTimestamp = strtotime((string) $s['expires_at']);
                        $dl = (int) floor(($expTimestamp - $nowTime) / 86400);
                        $isLive = ($s['status'] === 'active' && $expTimestamp > $nowTime);
                        $filterTag = 'other';
                        if ($isLive) {
                            $filterTag = !empty($s['is_trial']) ? 'trial' : 'paid';
                            if (($expTimestamp - $nowTime) <= 259200) { // 3 days
                                $filterTag .= ' expiring';
                            }
                        }
                    ?>
                    <tr data-plan-id="<?= (int)$s['plan_id'] ?>" data-filter="<?= e($filterTag) ?>" data-search="<?= e(strtolower((string)($s['user_id'] ?? '') . ' ' . (string)($s['merchant_id'] ?? '') . ' ' . (string)($s['merchant_name'] ?? '') . ' ' . (string)($s['merchant_email'] ?? '') . ' ' . (string)($s['plan_name'] ?? ''))) ?>">
                        <td data-label="Merchant Profile">
                            <div class="flex items-center gap-3">
                                <div style="width:36px;height:36px;border-radius:10px;background:rgba(37,99,235,0.12);color:var(--color-primary);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;border:1px solid rgba(37,99,235,0.25);flex-shrink:0;">
                                    <?= strtoupper(mb_substr($s['merchant_name'] ?: 'M', 0, 1)) ?>
                                </div>
                                <div>
                                    <div style="font-weight:600;font-size:13.5px;" class="flex items-center gap-1.5">
                                        <a href="<?= url('admin/user.php?id=' . (int) $s['user_id']) ?>" class="table-link-merchant">
                                            <?= e($s['merchant_name'] ?: 'Unnamed Merchant') ?>
                                        </a>
                                        <span class="badge badge-neutral" style="font-size:10px;padding:1px 5px;" title="User ID">UID: #<?= (int) $s['user_id'] ?></span>
                                    </div>
                                    <div class="text-mute" style="font-size:11.5px;"><?= e($s['merchant_email'] ?: 'No email') ?></div>
                                </div>
                            </div>
                        </td>
                        <td data-label="Subscription Plan">
                            <div class="flex items-center gap-1">
                                <span style="font-weight:700;font-size:13px;"><?= e($s['plan_name'] ?: 'Custom Plan') ?></span>
                                <?php if (!empty($s['is_trial'])): ?>
                                    <span class="badge badge-purple" style="font-size:9.5px;padding:1px 5px;">Trial</span>
                                <?php elseif (!empty($s['badge'])): ?>
                                    <span class="badge badge-cyan" style="font-size:9.5px;padding:1px 5px;"><?= e($s['badge']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="text-mute tnum" style="font-size:11.5px;">
                                <?= (float)$s['amount'] > 0 ? money((float)$s['amount']) : 'Free' ?>
                            </div>
                        </td>
                        <td data-label="Period & Timeline">
                            <div style="font-size:12.5px;"><?= e(date('d M Y', strtotime((string)$s['starts_at']))) ?> &rarr; <b><?= e(date('d M Y', $expTimestamp)) ?></b></div>
                            <div class="text-mute" style="font-size:11px;"><?= e(date('h:i A', $expTimestamp)) ?></div>
                        </td>
                        <td data-label="Days Left">
                            <?php if (!$isLive): ?>
                                <span class="sub-days-pill danger"><?= icon('alert', 11) ?> Expired</span>
                            <?php elseif ($dl <= 2): ?>
                                <span class="sub-days-pill danger"><?= icon('clock', 11) ?> <?= max(0, $dl) ?>d remaining</span>
                            <?php elseif ($dl <= 5): ?>
                                <span class="sub-days-pill warning"><?= icon('clock', 11) ?> <?= $dl ?> days left</span>
                            <?php else: ?>
                                <span class="sub-days-pill good"><?= icon('check-circle', 11) ?> <?= $dl ?> days left</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Limits & Quota">
                            <div class="text-mute" style="font-size:11.5px;">
                                Keys: <b style="color:var(--color-ink);"><?= sub_limit_label((int)($s['api_key_limit'] ?? 0)) ?></b> &middot; 
                                Txn: <b style="color:var(--color-ink);"><?= sub_limit_label((int)($s['txn_limit'] ?? 0)) ?></b>
                            </div>
                        </td>
                        <td data-label="Status">
                            <?php if ($isLive && !empty($s['is_trial'])): ?>
                                <span class="badge badge-purple"><?= icon('clock', 11) ?> Trial Active</span>
                            <?php elseif ($isLive): ?>
                                <span class="badge badge-success"><?= icon('check', 11) ?> Active</span>
                            <?php elseif ($s['status'] === 'cancelled'): ?>
                                <span class="badge badge-neutral">Revoked</span>
                            <?php else: ?>
                                <span class="badge badge-neutral">Expired</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Control Actions" style="text-align:right;">
                            <button type="button" class="btn btn-primary btn-sm flex items-center gap-1" style="margin-left:auto;" onclick='openManageUserModal(<?= htmlspecialchars(json_encode([
                                'user_id' => (int) $s['user_id'],
                                'sub_id' => (int) $s['id'],
                                'merchant_name' => (string) ($s['merchant_name'] ?: 'Unnamed Merchant'),
                                'merchant_email' => (string) ($s['merchant_email'] ?: ''),
                                'merchant_id' => (string) ($s['merchant_id'] ?? ''),
                                'plan_id' => (int) $s['plan_id'],
                                'plan_name' => (string) ($s['plan_name'] ?: 'Custom Plan'),
                                'plan_price' => (float) $s['plan_price'],
                                'amount' => (float) $s['amount'],
                                'status' => (string) $s['status'],
                                'is_live' => $isLive,
                                'starts_at' => date('d M Y', strtotime((string)$s['starts_at'])),
                                'expires_at' => date('d M Y, h:i A', $expTimestamp),
                                'days_left' => $dl,
                            ]), ENT_QUOTES, 'UTF-8') ?>)'>
                                <?= icon('user-check', 13) ?> Manage User
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- 5. Standalone Filter Box for Buy Subscription History (Niche bala box ke bahar uper) -->
<div class="sub-filter-topbar-box mb-4">
    <div class="sub-plan-pills-scroll" role="tablist">
        <button type="button" class="sub-plan-pill-btn active" data-buy-plan="all" onclick="filterBuyHistoryByPlan('all', this)">
            <span class="pill-icon"><?= icon('blocks', 14) ?></span>
            <span class="pill-title">All Purchases</span>
            <span class="pill-counter"><?= count($buyHistory) ?></span>
        </button>

        <?php foreach ($plans as $p): 
            $pid = (int) $p['id'];
            $bCount = $planBuyCounts[$pid] ?? 0;
            $priceText = (float)$p['price'] > 0 ? money((float)$p['price']) : 'Free';
            $isTrial = !empty($p['is_trial']);
        ?>
        <button type="button" class="sub-plan-pill-btn" data-buy-plan="<?= $pid ?>" onclick="filterBuyHistoryByPlan(<?= $pid ?>, this)">
            <span class="pill-icon"><?= $isTrial ? icon('clock', 14) : icon('zap', 14) ?></span>
            <span class="pill-title"><?= e($p['name']) ?></span>
            <span class="pill-price-tag"><?= e($priceText) ?></span>
            <span class="pill-counter <?= $bCount > 0 ? 'highlight' : '' ?>">
                <?= $bCount ?>
            </span>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- Right-aligned Search Input -->
    <div class="sub-plan-search-wrap">
        <input type="text" id="buyHistorySearchInput" class="form-input" placeholder="Search reference, merchant, UTR..." oninput="searchBuyHistory(this.value)" style="height:36px;width:280px;font-size:12.5px;padding:0 12px;">
    </div>
</div>

<!-- 6. Buy Subscription History & Completed Invoices Card Box -->
<div class="card card-pad-0 mb-4" id="buy-subscription-history">
    <div class="card-header flex items-center justify-between" style="flex-wrap:wrap;gap:12px;">
        <div>
            <h3 class="card-title flex items-center gap-2">
                <span style="color:var(--color-primary);"><?= icon('receipt', 18) ?></span>
                Buy Subscription History
            </h3>
            <div class="card-sub">Real-time audit log of completed merchant subscription purchases, transaction references, and payment proofs.</div>
        </div>

        <div class="flex items-center gap-2" style="flex-wrap:wrap;">
            <span class="badge badge-success flex items-center gap-1" id="visibleBuyHistoryCount" style="font-size:11.5px;padding:4px 10px;">
                <span style="width:7px;height:7px;border-radius:50%;background:#10b981;box-shadow:0 0 6px #10b981;"></span>
                <span><?= count($buyHistory) ?> Paid Invoices</span>
            </span>
        </div>
    </div>

    <?php if (!$buyHistory): ?>
        <div class="empty-state" style="padding:48px 24px;text-align:center;">
            <div class="empty-icon" style="color:var(--color-primary);margin-bottom:12px;"><?= icon('receipt', 32) ?></div>
            <div class="empty-state-title" style="font-weight:700;font-size:15px;margin-bottom:4px;">No completed subscription purchases yet</div>
            <div class="empty-state-sub text-mute" style="font-size:13px;max-width:540px;margin:0 auto;">Only successful paid subscriptions appear here. When merchants pay via UPI QR code or gateway, their receipts will automatically record in real time.</div>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table" id="buyHistoryTable">
                <thead>
                    <tr>
                        <th>Payment Reference</th>
                        <th>Merchant Profile</th>
                        <th>Plan Purchased</th>
                        <th>Amount Paid</th>
                        <th>UTR Number</th>
                        <th>Gateway Provider</th>
                        <th>Status</th>
                        <th>Paid Date &amp; Time</th>
                    </tr>
                </thead>
                <tbody>
                    <tr id="noBuyHistoryForPlanRow" style="display:none;">
                        <td colspan="8" class="text-center text-mute" style="padding:32px 20px;">
                            No completed purchases recorded for this plan yet.
                        </td>
                    </tr>
                    <?php foreach ($buyHistory as $bh): ?>
                    <tr data-plan-id="<?= (int)$bh['plan_id'] ?>" data-search="<?= e(strtolower(($bh['reference'] ?? '') . ' ' . ($bh['merchant_name'] ?? '') . ' ' . ($bh['merchant_email'] ?? '') . ' ' . ($bh['plan_name'] ?? '') . ' ' . ($bh['utr'] ?? ''))) ?>">
                        <td data-label="Payment Reference">
                            <div class="flex items-center gap-1">
                                <code class="mono" style="font-weight:700;font-size:12px;color:var(--color-ink);"><?= e($bh['reference']) ?></code>
                                <button type="button" class="btn btn-secondary btn-sm" data-copy="<?= e($bh['reference']) ?>" title="Copy Reference" style="height:24px;width:24px;padding:0;display:inline-flex;align-items:center;justify-content:center;">
                                    <?= icon('copy', 11) ?>
                                </button>
                            </div>
                        </td>
                        <td data-label="Merchant Profile">
                            <div class="flex items-center gap-2">
                                <div style="width:32px;height:32px;border-radius:8px;background:rgba(37,99,235,0.12);color:var(--color-primary);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0;">
                                    <?= strtoupper(mb_substr($bh['merchant_name'] ?: 'M', 0, 1)) ?>
                                </div>
                                <div>
                                    <div style="font-weight:600;font-size:13px;">
                                        <a href="<?= url('admin/user.php?id=' . (int) $bh['user_id']) ?>" class="table-link-merchant">
                                            <?= e($bh['merchant_name'] ?: 'Merchant #' . $bh['user_id']) ?>
                                        </a>
                                    </div>
                                    <div class="text-mute" style="font-size:11px;"><?= e($bh['merchant_email'] ?: '') ?></div>
                                </div>
                            </div>
                        </td>
                        <td data-label="Plan Purchased">
                            <div class="flex items-center gap-1">
                                <span style="font-weight:700;font-size:13px;color:var(--color-ink);"><?= e($bh['plan_name'] ?: 'Plan #' . $bh['plan_id']) ?></span>
                                <?php if (!empty($bh['plan_badge'])): ?>
                                    <span class="badge badge-purple" style="font-size:9.5px;padding:1px 5px;"><?= e($bh['plan_badge']) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td data-label="Amount Paid">
                            <span class="tnum" style="font-weight:800;color:var(--color-primary);font-size:14px;">
                                <?= money((float) $bh['amount']) ?>
                            </span>
                        </td>
                        <td data-label="UTR Number">
                            <?php if (!empty($bh['utr'])): ?>
                                <div class="flex items-center gap-1">
                                    <code class="mono" style="font-size:11.5px;color:#10b981;font-weight:700;"><?= e($bh['utr']) ?></code>
                                    <button type="button" class="btn btn-secondary btn-sm" data-copy="<?= e($bh['utr']) ?>" title="Copy UTR" style="height:22px;width:22px;padding:0;display:inline-flex;align-items:center;justify-content:center;">
                                        <?= icon('copy', 10) ?>
                                    </button>
                                </div>
                            <?php else: ?>
                                <span class="text-mute" style="font-size:11px;">Direct Activation</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Gateway Provider">
                            <span class="badge badge-cyan" style="font-size:11px;"><?= e(strtoupper((string)($bh['gateway_provider'] ?: 'OWN UPI'))) ?></span>
                        </td>
                        <td data-label="Status">
                            <span class="status-pill success flex items-center gap-1" style="font-size:10.5px;padding:2px 8px;font-weight:700;">
                                <span style="width:6px;height:6px;border-radius:50%;background:#10b981;"></span>
                                Paid &amp; Active
                            </span>
                        </td>
                        <td data-label="Paid Date & Time">
                            <div style="font-size:12px;font-weight:600;"><?= e(date('d M Y', strtotime((string)($bh['paid_at'] ?? $bh['created_at'])))) ?></div>
                            <div class="text-mute" style="font-size:11px;"><?= e(date('h:i A', strtotime((string)($bh['paid_at'] ?? $bh['created_at'])))) ?></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>


<!-- ====================================================================
     MODAL: MANAGE USER PLAN & ENROLLMENT (#modal-manage-user)
     Allows Admin to:
     - View user's current plan, start date, expiry date, days left
     - Add / Change plan tier, set custom validity duration and custom amount
     - Quickly extend active plan (+7d, +30d, +90d, +1y)
     - Revoke / Cancel user subscription
     - Assign plan to a new / any merchant
     ==================================================================== -->
<div id="modal-manage-user" class="admin-modal-overlay">
    <div class="admin-modal-dialog" style="max-width:680px;">
        <div class="admin-modal-header">
            <div class="admin-modal-title flex items-center gap-2">
                <?= icon('user-check', 18) ?> <span id="manageModalTitle">Manage Merchant Plan</span>
            </div>
            <button type="button" class="admin-modal-close" onclick="closeAdminModal('modal-manage-user')">✕</button>
        </div>

        <div class="admin-modal-body" style="padding:22px 24px;display:flex;flex-direction:column;gap:18px;">
            <!-- Merchant Identity Banner (Shows when managing an existing user) -->
            <div id="manageMerchantCard" style="background:linear-gradient(135deg, rgba(37,99,235,0.08), rgba(99,102,241,0.04));border:1px solid rgba(59,130,246,0.2);border-radius:12px;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div class="flex items-center gap-3">
                    <div id="manageMerchantAvatar" style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg, var(--color-primary), #6366f1);color:#fff;font-weight:700;font-size:16px;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(37,99,235,0.25);">
                        U
                    </div>
                    <div>
                        <div id="manageMerchantName" style="font-weight:700;font-size:15px;color:var(--color-ink);">Merchant Name</div>
                        <div id="manageMerchantEmail" class="text-mute" style="font-size:12px;">merchant@example.com</div>
                    </div>
                </div>
                <div style="text-align:right;">
                    <div id="manageCurrentPlanBadge" class="badge badge-primary" style="font-size:12px;padding:4px 10px;">
                        Plan Name
                    </div>
                    <div id="manageCurrentPlanExpiry" class="text-mute" style="font-size:11.5px;margin-top:4px;">
                        Expires: -
                    </div>
                </div>
            </div>

            <!-- Merchant Selector (Live Search by UID or Gmail) -->
            <div id="manageMerchantSelectorGroup" class="form-group" style="display:none;">
                <label class="form-label" style="font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:space-between;">
                    <span>Select Merchant (Search UID or Gmail) <span style="color:var(--color-danger);">*</span></span>
                    <span class="text-mute" style="font-size:11.5px;font-weight:normal;">Live Database Search</span>
                </label>

                <!-- Search Input Container -->
                <div id="merchantSearchBoxWrap" style="position:relative;">
                    <div style="position:relative;display:flex;align-items:center;">
                        <span style="position:absolute;left:12px;color:var(--color-primary);pointer-events:none;display:flex;align-items:center;">
                            <?= icon('search', 16) ?>
                        </span>
                        <input type="text" id="merchantSearchInput" class="form-input" 
                               placeholder="Type User ID (UID e.g. 15), Gmail / Email, or Name..." 
                               autocomplete="off"
                               style="padding-left:38px;padding-right:75px;height:42px;font-size:13.5px;"
                               oninput="onMerchantSearchInput(this.value)">
                        <span id="merchantSearchSpinner" style="position:absolute;right:12px;display:none;color:var(--color-primary);font-size:11.5px;font-weight:600;">
                            Searching...
                        </span>
                    </div>
                    <div class="form-hint" style="margin-top:4px;">Type a User UID (e.g. 1, 25), Gmail address, or Merchant ID to search instantly from database.</div>

                    <!-- Live Dropdown Results List -->
                    <div id="merchantSearchResults" style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;max-height:260px;overflow-y:auto;background:var(--color-surface, #ffffff);border:1px solid var(--color-border);border-radius:10px;box-shadow:0 14px 34px rgba(0,0,0,0.22);z-index:99999;padding:6px;">
                    </div>
                </div>

                <!-- Selected Merchant Display Card (When chosen) -->
                <div id="merchantSelectedDisplayCard" style="display:none;background:linear-gradient(135deg, rgba(16,185,129,0.1), rgba(37,99,235,0.06));border:1px solid rgba(16,185,129,0.35);border-radius:12px;padding:12px 16px;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-top:8px;">
                    <div class="flex items-center gap-3">
                        <div id="selMerchantAvatar" style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg, #10b981, #2563eb);color:#fff;font-weight:700;font-size:15px;display:flex;align-items:center;justify-content:center;box-shadow:0 3px 8px rgba(16,185,129,0.3);flex-shrink:0;">
                            M
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <span id="selMerchantName" style="font-weight:700;font-size:14px;color:var(--color-ink);">Merchant Name</span>
                                <span id="selMerchantUidBadge" class="badge badge-cyan" style="font-size:11px;padding:2px 7px;">UID: #0</span>
                            </div>
                            <div id="selMerchantEmail" class="text-mute" style="font-size:12px;margin-top:2px;">merchant@gmail.com</div>
                        </div>
                    </div>
                    <div>
                        <button type="button" class="btn btn-secondary btn-sm flex items-center gap-1" style="font-size:12px;height:32px;padding:0 12px;" onclick="resetSelectedMerchant()">
                            <?= icon('refresh', 12) ?> Change Merchant
                        </button>
                    </div>
                </div>
            </div>

            <!-- SECTION 1: Add or Change Plan -->
            <div style="border:1px solid var(--color-border);border-radius:12px;padding:16px 18px;background:var(--color-surface);">
                <div style="font-weight:700;font-size:13.5px;margin-bottom:12px;display:flex;align-items:center;gap:6px;color:var(--color-ink);">
                    <?= icon('blocks', 15) ?> <span id="managePlanSectionTitle">Add or Change Plan</span>
                </div>
                <form method="post" id="formAssignSub" onsubmit="return validateAssignSubForm();">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="assign_sub">
                    <input type="hidden" name="user_id" id="manageUserId" value="0">

                    <div class="sub-form-grid">
                        <div class="form-group">
                            <label class="form-label">Subscription Tier <span style="color:var(--color-danger);">*</span></label>
                            <select class="form-select" name="plan_id" id="managePlanSelect" required onchange="onPlanSelectionChanged(this)">
                                <option value="">-- Select Plan --</option>
                                <?php foreach ($plans as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>" data-price="<?= (float)$p['price'] ?>" data-days="<?= (int)$p['duration_days'] ?>">
                                        <?= e($p['name']) ?> &mdash; <?= (float)$p['price'] > 0 ? money((float)$p['price']) : 'Free' ?> (<?= (int)$p['duration_days'] ?>d)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-hint">New plan will immediately activate and override any previous plan.</div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Duration (Days) <span style="color:var(--color-danger);">*</span></label>
                            <input class="form-input" type="number" min="1" max="3650" name="duration_days" id="manageDurationDays" value="30" required>
                            <div class="form-hint">How many days the user will have access from today.</div>
                        </div>

                        <div class="form-group" style="grid-column:1 / -1;">
                            <label class="form-label">Charged Amount (₹) <span class="text-mute">(Optional override)</span></label>
                            <input class="form-input" type="number" step="0.01" min="0" name="amount" id="manageAmountInput" placeholder="Leave empty for plan default">
                            <div class="form-hint">Overrides the plan price on record (enter 0 for complimentary VIP access).</div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end mt-3">
                        <button type="submit" class="btn btn-primary btn-sm flex items-center gap-2">
                            <?= icon('check-circle', 14) ?> <span id="manageSaveBtnText">Apply Plan to User</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- SECTION 2: Extend Subscription (+Days) -->
            <div id="manageExtendSection" style="border:1px solid var(--color-border);border-radius:12px;padding:16px 18px;background:var(--color-surface);">
                <div style="font-weight:700;font-size:13.5px;margin-bottom:8px;display:flex;align-items:center;gap:6px;color:var(--color-ink);">
                    <?= icon('clock', 15) ?> Quick Extend Subscription
                </div>
                <div class="text-mute" style="font-size:12px;margin-bottom:12px;">Add extra days to their existing expiration date without changing the plan tier.</div>

                <form method="post" id="formExtendSub">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="extend_sub">
                    <input type="hidden" name="sub_id" id="extendSubId" value="0">

                    <div class="flex items-center gap-2 mb-3" style="flex-wrap:wrap;">
                        <button type="button" class="btn btn-ghost btn-sm" onclick="setExtendDays(7)">+7 Days</button>
                        <button type="button" class="btn btn-ghost btn-sm" onclick="setExtendDays(30)">+30 Days (1 Mo)</button>
                        <button type="button" class="btn btn-ghost btn-sm" onclick="setExtendDays(90)">+90 Days (3 Mo)</button>
                        <button type="button" class="btn btn-ghost btn-sm" onclick="setExtendDays(365)">+1 Year</button>
                    </div>

                    <div class="flex items-center gap-2">
                        <input class="form-input" type="number" min="1" max="1000" name="days" id="extendDaysInput" value="30" style="max-width:140px;" placeholder="Days">
                        <button type="submit" class="btn btn-secondary btn-sm flex items-center gap-1">
                            <?= icon('plus', 13) ?> Extend Plan
                        </button>
                    </div>
                </form>
            </div>

            <!-- SECTION 3: Revoke Subscription -->
            <div id="manageRevokeSection" style="border:1px solid rgba(239,68,68,0.25);border-radius:12px;padding:14px 18px;background:rgba(239,68,68,0.03);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div>
                    <div style="font-weight:700;font-size:13px;color:var(--color-danger);">Revoke Active Subscription</div>
                    <div class="text-mute" style="font-size:11.5px;">Immediately cancel and remove this user's plan access.</div>
                </div>
                <form method="post" id="formRevokeSub" onsubmit="return confirm('Are you sure you want to REVOKE this user\'s subscription? They will immediately lose plan access.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="revoke_sub">
                    <input type="hidden" name="sub_id" id="revokeSubId" value="0">
                    <button type="submit" class="btn btn-danger btn-sm flex items-center gap-1">
                        <?= icon('trash', 13) ?> Revoke / Remove Plan
                    </button>
                </form>
            </div>
        </div>

        <div class="admin-modal-footer">
            <button type="button" class="btn btn-ghost btn-sm" onclick="closeAdminModal('modal-manage-user')">Close</button>
        </div>
    </div>
</div>

<script>
// Open Manage User Modal for an individual merchant
function openManageUserModal(data) {
    if (!data) return;

    var titleEl   = document.getElementById('manageModalTitle');
    var userIdEl  = document.getElementById('manageUserId');
    var cardEl    = document.getElementById('manageMerchantCard');
    var selectGrp = document.getElementById('manageMerchantSelectorGroup');
    var avatarEl  = document.getElementById('manageMerchantAvatar');
    var nameEl    = document.getElementById('manageMerchantName');
    var emailEl   = document.getElementById('manageMerchantEmail');
    var badgeEl   = document.getElementById('manageCurrentPlanBadge');
    var expiryEl  = document.getElementById('manageCurrentPlanExpiry');
    var planSecTitle = document.getElementById('managePlanSectionTitle');
    var planSelect= document.getElementById('managePlanSelect');
    var durInput  = document.getElementById('manageDurationDays');
    var amtInput  = document.getElementById('manageAmountInput');
    var btnText   = document.getElementById('manageSaveBtnText');
    var extSec    = document.getElementById('manageExtendSection');
    var extSubId  = document.getElementById('extendSubId');
    var revSec    = document.getElementById('manageRevokeSection');
    var revSubId  = document.getElementById('revokeSubId');

    if (titleEl) titleEl.textContent = 'Manage Plan: ' + (data.merchant_name || 'Merchant');
    if (userIdEl) userIdEl.value = data.user_id || 0;

    // Show card, hide dropdown
    if (cardEl) cardEl.style.display = 'flex';
    if (selectGrp) selectGrp.style.display = 'none';

    // Populate merchant details
    var initials = (data.merchant_name || 'U').trim().charAt(0).toUpperCase();
    if (avatarEl) avatarEl.textContent = initials;
    if (nameEl) nameEl.textContent = data.merchant_name || 'Unnamed Merchant';
    if (emailEl) {
        var subText = data.merchant_email || '';
        if (data.merchant_id) subText += ' • ' + data.merchant_id;
        emailEl.textContent = subText;
    }

    // Populate current plan & status
    if (badgeEl) {
        badgeEl.textContent = (data.plan_name || 'No Plan') + (data.is_live ? ' (Active)' : ' (Expired)');
        badgeEl.className = 'badge ' + (data.is_live ? 'badge-success' : 'badge-neutral');
    }
    if (expiryEl) {
        if (data.expires_at) {
            expiryEl.textContent = 'Expires: ' + data.expires_at + (data.is_live ? ' (' + (data.days_left || 0) + 'd left)' : ' (Expired)');
        } else {
            expiryEl.textContent = 'No active expiration date';
        }
    }

    if (planSecTitle) planSecTitle.textContent = 'Change / Upgrade Plan';
    if (planSelect && data.plan_id) planSelect.value = data.plan_id;
    if (durInput) durInput.value = 30;
    if (amtInput) amtInput.value = '';
    if (btnText) btnText.textContent = 'Update Plan for ' + (data.merchant_name || 'Merchant');

    // Extend and Revoke sections
    if (data.sub_id && data.is_live) {
        if (extSec) extSec.style.display = 'block';
        if (extSubId) extSubId.value = data.sub_id;
        if (revSec) revSec.style.display = 'flex';
        if (revSubId) revSubId.value = data.sub_id;
    } else {
        if (extSec) extSec.style.display = 'none';
        if (revSec) revSec.style.display = 'none';
    }

    if (typeof openAdminModal === 'function') {
        openAdminModal('modal-manage-user');
    } else {
        var m = document.getElementById('modal-manage-user');
        if (m) m.classList.add('show');
    }
}

// Open Assign Modal to enroll any merchant
function openNewAssignModal() {
    var titleEl   = document.getElementById('manageModalTitle');
    var userIdEl  = document.getElementById('manageUserId');
    var cardEl    = document.getElementById('manageMerchantCard');
    var selectGrp = document.getElementById('manageMerchantSelectorGroup');
    var planSecTitle = document.getElementById('managePlanSectionTitle');
    var planSelect= document.getElementById('managePlanSelect');
    var durInput  = document.getElementById('manageDurationDays');
    var amtInput  = document.getElementById('manageAmountInput');
    var btnText   = document.getElementById('manageSaveBtnText');
    var extSec    = document.getElementById('manageExtendSection');
    var revSec    = document.getElementById('manageRevokeSection');

    if (titleEl) titleEl.textContent = 'Assign Subscription to Merchant';
    if (userIdEl) userIdEl.value = '0';

    // Hide identity card, show live search selector
    if (cardEl) cardEl.style.display = 'none';
    if (selectGrp) selectGrp.style.display = 'block';

    resetSelectedMerchant();

    if (planSecTitle) planSecTitle.textContent = 'Select Plan & Duration';
    if (planSelect) planSelect.value = '';
    if (durInput) durInput.value = 30;
    if (amtInput) amtInput.value = '';
    if (btnText) btnText.textContent = 'Assign Plan to User';

    if (extSec) extSec.style.display = 'none';
    if (revSec) revSec.style.display = 'none';

    if (typeof openAdminModal === 'function') {
        openAdminModal('modal-manage-user');
    } else {
        var m = document.getElementById('modal-manage-user');
        if (m) m.classList.add('show');
    }

    setTimeout(function() {
        var inp = document.getElementById('merchantSearchInput');
        if (inp) inp.focus();
    }, 150);
}

// Live Merchant Search Handlers (UID & Gmail)
var merchantSearchTimer = null;

function onMerchantSearchInput(val) {
    clearTimeout(merchantSearchTimer);
    var q = (val || '').trim();
    var resBox = document.getElementById('merchantSearchResults');
    var spinner = document.getElementById('merchantSearchSpinner');

    if (!q) {
        if (resBox) {
            resBox.style.display = 'none';
            resBox.innerHTML = '';
        }
        if (spinner) spinner.style.display = 'none';
        return;
    }

    if (spinner) spinner.style.display = 'inline-block';

    merchantSearchTimer = setTimeout(function() {
        fetch('subscriptions.php?ajax_search_merchants=1&q=' + encodeURIComponent(q))
            .then(function(r) { return r.json(); })
            .then(function(users) {
                if (spinner) spinner.style.display = 'none';
                renderMerchantSearchResults(users);
            })
            .catch(function(err) {
                if (spinner) spinner.style.display = 'none';
                console.error('Merchant search error:', err);
            });
    }, 200);
}

function renderMerchantSearchResults(users) {
    var resBox = document.getElementById('merchantSearchResults');
    if (!resBox) return;

    if (!users || users.length === 0) {
        resBox.innerHTML = '<div style="padding:14px;text-align:center;color:var(--color-ink-muted);font-size:12.5px;">No merchant found with that UID or Gmail.</div>';
        resBox.style.display = 'block';
        return;
    }

    var html = '';
    users.forEach(function(u) {
        var initial = (u.name || 'M').charAt(0).toUpperCase();
        var safeUser = JSON.stringify(u).replace(/'/g, "&#39;");
        html += '<div class="merchant-search-item" onclick=\'selectMerchant(' + safeUser + ')\' ' +
                'style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 12px;border-radius:8px;cursor:pointer;margin-bottom:2px;">' +
                    '<div class="flex items-center gap-2" style="min-width:0;">' +
                        '<div style="width:32px;height:32px;border-radius:50%;background:rgba(37,99,235,0.12);color:var(--color-primary);font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">' + initial + '</div>' +
                        '<div style="min-width:0;">' +
                            '<div style="font-weight:600;font-size:13px;color:var(--color-ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(u.name) + '</div>' +
                            '<div style="font-size:11.5px;color:var(--color-ink-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(u.email) + '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div style="text-align:right;flex-shrink:0;">' +
                        '<span class="badge badge-neutral" style="font-size:10.5px;padding:2px 6px;">UID: #' + u.id + '</span>' +
                        (u.merchant_id ? '<div style="font-size:10.5px;color:var(--color-ink-muted);margin-top:2px;">' + escapeHtml(u.merchant_id) + '</div>' : '') +
                    '</div>' +
                '</div>';
    });

    resBox.innerHTML = html;
    resBox.style.display = 'block';
}

function selectMerchant(u) {
    document.getElementById('manageUserId').value = u.id;
    var searchWrap = document.getElementById('merchantSearchBoxWrap');
    var dispCard = document.getElementById('merchantSelectedDisplayCard');
    var resBox = document.getElementById('merchantSearchResults');

    if (resBox) resBox.style.display = 'none';
    if (searchWrap) searchWrap.style.display = 'none';

    if (dispCard) {
        dispCard.style.display = 'flex';
        var av = document.getElementById('selMerchantAvatar');
        var nm = document.getElementById('selMerchantName');
        var em = document.getElementById('selMerchantEmail');
        var uid = document.getElementById('selMerchantUidBadge');

        if (av) av.textContent = (u.name || 'M').charAt(0).toUpperCase();
        if (nm) nm.textContent = u.name;
        if (em) em.textContent = u.email;
        if (uid) uid.textContent = 'UID: #' + u.id + (u.merchant_id ? ' • ' + u.merchant_id : '');
    }

    var btnText = document.getElementById('manageSaveBtnText');
    if (btnText) btnText.textContent = 'Assign Plan to ' + u.name;
}

function resetSelectedMerchant() {
    document.getElementById('manageUserId').value = '0';
    var searchWrap = document.getElementById('merchantSearchBoxWrap');
    var dispCard = document.getElementById('merchantSelectedDisplayCard');
    var input = document.getElementById('merchantSearchInput');
    var resBox = document.getElementById('merchantSearchResults');

    if (dispCard) dispCard.style.display = 'none';
    if (searchWrap) searchWrap.style.display = 'block';
    if (input) {
        input.value = '';
        input.focus();
    }
    if (resBox) {
        resBox.innerHTML = '';
        resBox.style.display = 'none';
    }

    var btnText = document.getElementById('manageSaveBtnText');
    if (btnText) btnText.textContent = 'Assign Plan to User';
}

function validateAssignSubForm() {
    var uid = document.getElementById('manageUserId').value;
    if (!uid || uid === '0') {
        alert('Please search and select a merchant by UID or Gmail first.');
        var inp = document.getElementById('merchantSearchInput');
        if (inp) inp.focus();
        return false;
    }
    return true;
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

document.addEventListener('click', function(e) {
    var wrap = document.getElementById('merchantSearchBoxWrap');
    var resBox = document.getElementById('merchantSearchResults');
    if (resBox && wrap && !wrap.contains(e.target)) {
        resBox.style.display = 'none';
    }
});

// When plan is changed in the dropdown
function onPlanSelectionChanged(sel) {
    if (!sel || !sel.selectedOptions || !sel.selectedOptions[0]) return;
    var opt = sel.selectedOptions[0];
    var days = opt.getAttribute('data-days');
    var price = opt.getAttribute('data-price');

    var durInput = document.getElementById('manageDurationDays');
    var amtInput = document.getElementById('manageAmountInput');

    if (durInput && days) durInput.value = parseInt(days, 10) || 30;
    if (amtInput && price !== null) {
        var pNum = parseFloat(price);
        amtInput.placeholder = pNum > 0 ? ('Default: ₹' + pNum.toFixed(2)) : 'Default: Free';
    }
}

// Helper for quick extend buttons
function setExtendDays(days) {
    var input = document.getElementById('extendDaysInput');
    if (input) input.value = days;
}

// Live plan-based filter for subscribers directory
var currentSubPlanFilter = 'all';

function filterSubsByPlan(planId, btn) {
    currentSubPlanFilter = planId;
    document.querySelectorAll('.sub-plan-pill-btn[data-sub-plan]').forEach(function(b) {
        b.classList.remove('active');
    });
    if (btn) btn.classList.add('active');

    applySubsFilters();
}

// Instant search in subscribers directory
function searchSubs(query) {
    applySubsFilters();
}

function applySubsFilters() {
    var query = (document.getElementById('subSearchInput') ? document.getElementById('subSearchInput').value : '').toLowerCase().trim();
    var rows = document.querySelectorAll('#subsTable tbody tr:not(#noSubsForPlanRow)');
    var visible = 0;

    rows.forEach(function(r) {
        var p = r.getAttribute('data-plan-id');
        var text = (r.getAttribute('data-search') || '').toLowerCase();
        var matchPlan = (currentSubPlanFilter === 'all' || p == currentSubPlanFilter);
        var matchSearch = (query === '' || text.indexOf(query) !== -1);

        if (matchPlan && matchSearch) {
            r.style.display = '';
            visible++;
        } else {
            r.style.display = 'none';
        }
    });

    var emptyRow = document.getElementById('noSubsForPlanRow');
    if (emptyRow) {
        emptyRow.style.display = (visible === 0) ? '' : 'none';
    }

    var counterBadge = document.getElementById('visibleSubsCount');
    if (counterBadge) {
        counterBadge.textContent = visible + ' ' + (visible === 1 ? 'Merchant' : 'Merchants');
    }
}

// Plan-based filter for buy history
var currentBuyPlanFilter = 'all';

function filterBuyHistoryByPlan(planId, btn) {
    currentBuyPlanFilter = planId;
    document.querySelectorAll('.sub-plan-pill-btn[data-buy-plan]').forEach(function(b) {
        b.classList.remove('active');
    });
    if (btn) btn.classList.add('active');

    applyBuyHistoryFilters();
}

function searchBuyHistory(query) {
    applyBuyHistoryFilters();
}

function applyBuyHistoryFilters() {
    var query = (document.getElementById('buyHistorySearchInput') ? document.getElementById('buyHistorySearchInput').value : '').toLowerCase().trim();
    var rows = document.querySelectorAll('#buyHistoryTable tbody tr:not(#noBuyHistoryForPlanRow)');
    var visible = 0;

    rows.forEach(function(row) {
        var p = row.getAttribute('data-plan-id');
        var text = (row.getAttribute('data-search') || row.textContent).toLowerCase();
        var matchPlan = (currentBuyPlanFilter === 'all' || p == currentBuyPlanFilter);
        var matchSearch = (query === '' || text.indexOf(query) !== -1);

        if (matchPlan && matchSearch) {
            row.style.display = '';
            visible++;
        } else {
            row.style.display = 'none';
        }
    });

    var emptyRow = document.getElementById('noBuyHistoryForPlanRow');
    if (emptyRow) {
        emptyRow.style.display = (visible === 0) ? '' : 'none';
    }

    var countBadge = document.getElementById('visibleBuyHistoryCount');
    if (countBadge) {
        countBadge.innerHTML = '<span style="width:7px;height:7px;border-radius:50%;background:#10b981;box-shadow:0 0 6px #10b981;"></span><span>' + visible + ' Paid Invoices</span>';
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
