<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';
require_once APP_ROOT . '/includes/subscriptions.php';

$user      = require_login();
$active    = 'subscription';
$activeNav = 'subscription';
$uid       = (int) $user['id'];

sub_bootstrap($uid);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf(post('csrf_token'))) {
        flash_set('error', 'Session expired. Please try again.');
        redirect('user/subscription.php');
    }
    $action = post('action');

    if ($action === 'subscribe') {
        $res = sub_create_payment($uid, (int) post('plan_id'));
        if ($res['ok']) {
            $url = sub_payment_checkout_url($res['payment']);
            if ($url !== '') {
                redirect($url);
            }
            flash_set('error', 'Could not start the payment. Please try again.');
            redirect('user/subscription.php');
        }
        flash_set('error', (string) $res['error']);
        redirect('user/subscription.php');
    }
}

$current = sub_current($uid);
$plans   = sub_plans(true);

$metrics = [
    'transactions' => ['label' => 'Transactions', 'icon' => 'credit-card', 'tone' => 'blue',   'color' => '#2563eb'],
    'links'        => ['label' => 'Payment Links', 'icon' => 'link-2',     'tone' => 'purple', 'color' => '#7c3aed'],
    'api_keys'     => ['label' => 'API Keys',     'icon' => 'key',        'tone' => 'amber',  'color' => '#d97706'],
    'webhooks'     => ['label' => 'Webhooks',     'icon' => 'webhook',    'tone' => 'cyan',   'color' => '#0891b2'],
];
$usage = [];
foreach ($metrics as $m => $meta) {
    $usage[$m] = ['used' => sub_usage($uid, $m), 'limit' => sub_limit($uid, $m)];
}

// Clean up any expired or unpaid subscription payment attempts
sub_cleanup_unpaid_payments($uid);

$stmt = db()->prepare(
    "SELECT sp.*, p.name AS plan_name FROM subscription_payments sp
     LEFT JOIN subscription_plans p ON p.id = sp.plan_id
     WHERE sp.user_id = ? AND sp.status = 'success'
     ORDER BY sp.id DESC LIMIT 12"
);
$stmt->execute([$uid]);
$history = $stmt->fetchAll();

/* Returning from the payment gateway (?pay=REF&ret=1): verify once and report. */
if (!empty($_GET['pay'])) {
    $p = sub_payment_by_reference((string) $_GET['pay']);
    if ($p && (int) $p['user_id'] === $uid) {
        $checkoutUrl = sub_payment_checkout_url($p);

        /* Not returned yet — send the subscriber to the gateway's own page. */
        if ((string) $p['status'] === 'pending' && $checkoutUrl !== '' && empty($_GET['ret'])) {
            redirect($checkoutUrl);
        }

        if ((string) $p['status'] === 'pending') {
            sub_gateway_sync($p);
            $p = sub_payment_by_reference((string) $p['reference']) ?? $p;
        }

        if ((string) $p['status'] === 'success') {
            flash_set('success', 'Payment confirmed. Your plan is now active.');
        } elseif ((string) $p['status'] === 'expired') {
            db()->prepare("DELETE FROM subscription_payments WHERE id = ?")->execute([(int) $p['id']]);
            flash_set('error', 'This payment request expired. Please start a new one.');
        } else {
            flash_set('error', 'Payment not detected yet. If you just paid, wait a few seconds and refresh.');
        }
        redirect('user/subscription.php');
    }
}

$now = time();
$expiresAt = $current ? strtotime((string) $current['expires_at']) : 0;
$startsAt  = $current ? strtotime((string) ($current['starts_at'] ?? $current['created_at'])) : 0;
$remSec    = ($current && $expiresAt > $now) ? ($expiresAt - $now) : 0;
$totalSec  = ($current && $expiresAt > $startsAt) ? max(86400, $expiresAt - $startsAt) : 86400;

if ($current && $remSec > 0) {
    $cyclePct = max(1, min(100, (int) round($remSec / $totalSec * 100)));
    if ($remSec >= 86400) {
        $ringNum   = (int) ceil($remSec / 86400);
        $ringLabel = $ringNum === 1 ? 'day left' : 'days left';
        $subLabel  = $ringNum . ' ' . $ringLabel . ' remaining';
    } else {
        $ringNum   = (int) ceil($remSec / 3600);
        $ringLabel = $ringNum === 1 ? 'hr left' : 'hrs left';
        $subLabel  = $ringNum . ' ' . ($ringNum === 1 ? 'hour' : 'hours') . ' left today';
    }
} else {
    $cyclePct  = 0;
    $ringNum   = 0;
    $ringLabel = 'expired';
    $subLabel  = 'Cycle ended &middot; Renew plan';
}

$pageTitle   = 'Subscription';
$pageHeading = 'Subscription & Billing';
$pageSub     = 'Manage your plan, track usage and upgrade for unlimited access.';

include __DIR__ . '/includes/header.php';
?>

<div class="subx-wrap">

    <?php $fl = flash_get(); if ($fl): ?>
        <div class="dash-alert dash-alert-<?= $fl['type'] === 'success' ? 'success' : 'error' ?>">
            <div class="alert-icon-wrap"><?= icon($fl['type'] === 'success' ? 'check-circle' : 'shield', 18) ?></div>
            <div class="alert-text-wrap"><?= e($fl['message']) ?></div>
        </div>
    <?php endif; ?>

    <!-- =========================================================
         DARK HERO STAGE & INTEGRATED META RIBBON
         Universal matching dashboard.php, transactions.php, webhooks.php
         ========================================================= -->
    <div class="dash-hero-stage-dark" data-reveal>
        <div class="hero-stage-mesh-top" aria-hidden="true"></div>
        <div class="hero-stage-mesh-bottom" aria-hidden="true"></div>

        <div class="hero-stage-layout">
            <!-- Left Column: Details, Headline, Meta Ribbon & Action Buttons -->
            <div class="hero-stage-main">
                <div class="hero-stage-header">
                    <div class="hero-eyebrow">
                        <span class="dot"></span>
                        <span><?= $current ? 'Active Subscription &middot; Direct UPI' : 'No Active Plan &middot; Upgrade Required' ?></span>
                    </div>
                    <div class="hero-sla-pill">
                        <span class="sla-dot"></span>
                        <span><?= $current ? ($current['is_trial'] ? 'Trial Period Active' : 'Commercial Merchant Tier') : 'Instant UPI Activation' ?></span>
                    </div>
                </div>

                <div class="hero-stage-headline">
                    <h1 class="dash-hero-heading">
                        Subscription &amp; <span class="accent">Billing</span>
                        <span class="merchant-verified-badge" title="Verified Subscription Engine"><?= icon('check-circle', 20) ?></span>
                    </h1>
                    <p class="dash-hero-desc">
                        <?= $current 
                            ? 'Running on <strong style="color:#ffffff;">' . e($current['plan_name']) . '</strong> with dynamic QR, webhooks &amp; auto UTR match.'
                            : 'Upgrade plan for unlimited transactions, webhooks and priority support.'
                        ?>
                    </p>
                </div>

                <div class="hero-meta-ribbon">
                    <div class="meta-ribbon-cell" title="Current Active Plan">
                        <div class="meta-cell-label">Current Plan</div>
                        <div class="meta-cell-value">
                            <?php if ($current): ?>
                                <span class="val-accent"><?= e($current['plan_name']) ?></span>
                            <?php else: ?>
                                <span class="meta-badge-warn"><span class="mini-beacon warn"></span> None</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="meta-ribbon-cell" title="Plan Price & Billing Tier">
                        <div class="meta-cell-label">Billing Tier</div>
                        <div class="meta-cell-value">
                            <?php if ($current): ?>
                                <span class="val-money"><?= (float) $current['plan_price'] > 0 ? e(money((float) $current['plan_price'])) . '<small style="font-size:11px;color:#94a3b8;font-weight:500;">/' . ($totalDays >= 90 ? '3mo' : 'mo') . '</small>' : 'Free Included' ?></span>
                            <?php else: ?>
                                <span class="val-money">₹0.00</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="meta-ribbon-cell" title="Gateway Activation Status">
                        <div class="meta-cell-label">Account Status</div>
                        <div class="meta-cell-value">
                            <?php if ($current): ?>
                                <span class="meta-badge-ok"><span class="mini-beacon"></span> <?= $current['is_trial'] ? 'Trial Active' : 'Active &middot; Protected' ?></span>
                            <?php else: ?>
                                <span class="meta-badge-warn"><span class="mini-beacon warn"></span> Expired</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="meta-ribbon-cell" title="Subscription expiration date">
                        <div class="meta-cell-label">Valid Until</div>
                        <div class="meta-cell-value">
                            <?php if ($current): ?>
                                <span class="val-rate"><?= date('d M Y', strtotime((string) $current['expires_at'])) ?></span>
                            <?php else: ?>
                                <span class="val-rate">Expired</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="hero-stage-btns">
                    <a href="#plans" class="btn-hero-primary">
                        <?= icon('rocket', 15) ?> <?= $current ? 'Upgrade or Renew Plan' : 'Choose a Plan' ?>
                    </a>
                    <a href="#history" class="btn-hero-secondary">
                        <?= icon('receipt', 14) ?> Billing History
                    </a>
                </div>
            </div>

            <!-- Right Column: Seamless Integrated Circular Countdown HUD -->
            <div class="hero-stage-countdown">
                <div class="hud-ring-wrap">
                    <svg viewBox="0 0 120 120" class="hud-ring-svg" aria-hidden="true">
                        <defs>
                            <linearGradient id="hudRingGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#38bdf8" />
                                <stop offset="50%" stop-color="#60a5fa" />
                                <stop offset="100%" stop-color="#818cf8" />
                            </linearGradient>
                        </defs>
                        <circle class="hud-ring-track" cx="60" cy="60" r="50"/>
                        <?php if ($cyclePct > 0): ?>
                            <circle class="hud-ring-fill active" cx="60" cy="60" r="50" style="stroke-dasharray:314.16px;stroke-dashoffset:<?= round(314.16 * (1 - $cyclePct / 100), 2) ?>px;"/>
                        <?php else: ?>
                            <circle class="hud-ring-fill expired" cx="60" cy="60" r="50" style="stroke-dasharray:8 6;stroke-dashoffset:0;"/>
                        <?php endif; ?>
                    </svg>
                    <div class="hud-ring-center">
                        <span class="hud-ring-number tnum"><?= $ringNum ?></span>
                        <span class="hud-ring-label"><?= $ringLabel ?></span>
                    </div>
                </div>
                <div class="hud-details-col">
                    <div class="hud-status-badge">
                        <span class="hud-dot <?= $cyclePct <= 0 ? 'warn' : 'green' ?>"></span>
                        <span><?= $cyclePct ?>% cycle remaining</span>
                    </div>
                    <div class="hud-sub-label">
                        <?= $subLabel ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================
         4 VIBRANT COLORFUL METRIC CARDS (SAME AS ALL OTHER PAGES)
         ========================================================= -->
    <div class="dash-kpi-grid-light" data-reveal>
        <?php foreach ($metrics as $m => $meta):
            $u = $usage[$m]['used'];
            $l = $usage[$m]['limit'];
            $pct = ($l > 0) ? max(0, min(100, (int) round($u / $l * 100))) : 0;
            $unlimited = $l <= 0;
            $color = $meta['color'];
        ?>
        <div class="light-kpi-card card-theme-<?= e($meta['tone']) ?>">
            <div class="kpi-head-row">
                <span class="kpi-label-text"><?= e($meta['label']) ?></span>
                <div class="kpi-icon-glow">
                    <?= icon($meta['icon'], 18) ?>
                </div>
            </div>
            <div class="kpi-number-display" style="color: <?= e($color) ?>;">
                <?= e(number_format($u)) ?>
                <span style="font-size:14px;color:#94a3b8;font-weight:500;">/ <?= $unlimited ? '∞' : e(number_format($l)) ?></span>
            </div>
            <div class="kpi-footer-row">
                <span class="trend-pill <?= ($pct >= 100 && !$unlimited) ? 'down' : 'up' ?>">
                    <?= icon(($pct >= 100 && !$unlimited) ? 'alert' : 'check', 11) ?> <?= $unlimited ? 'Unlimited' : $pct . '% used' ?>
                </span>
                <span><?= $unlimited ? 'Active' : ($l - $u > 0 ? number_format($l - $u) . ' remaining' : 'Limit reached') ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="subx-section-head" id="plans">
        <div class="subx-section-head-left">
            <span class="subx-pill-eyebrow"><?= icon('sparkles', 12) ?> Transparent Pricing</span>
            <h3>Choose the Right Plan</h3>
            <p>Upgrade anytime &middot; Instant UPI activation &middot; Cancel or switch plans seamlessly</p>
        </div>
    </div>

    <div class="subx-plans">
        <?php foreach ($plans as $plan):
            $isCurrent = $current && (int) $current['plan_id'] === (int) $plan['id'];
            $isFree = (float) $plan['price'] <= 0;
            $isFeatured = !$isFree && !empty($plan['badge']);
            $months = max(1, (int) round((int) $plan['duration_days'] / 30));
            $perMonth = $isFree ? 0 : (float) $plan['price'] / $months;

            $badgeLower = strtolower(trim((string) $plan['badge']));
            if (strpos($badgeLower, 'best') !== false || strpos($badgeLower, 'value') !== false || $months >= 3) {
                $planTheme = 'theme-gold';
                $btnClass  = 'btn-gold-glow';
                $flagClass = 'flag-gold';
                $flagIcon  = 'sparkles';
            } elseif (strpos($badgeLower, 'pop') !== false || (float) $plan['price'] >= 90) {
                $planTheme = 'theme-ruby';
                $btnClass  = 'btn-ruby-glow';
                $flagClass = 'flag-ruby';
                $flagIcon  = 'zap';
            } elseif ($isFree) {
                $planTheme = 'theme-green';
                $btnClass  = 'btn-ghost-disabled';
                $flagClass = 'flag-free';
                $flagIcon  = 'check-circle';
            } else {
                $planTheme = 'theme-blue';
                $btnClass  = 'btn-outline-glow';
                $flagClass = 'flag-deal';
                $flagIcon  = 'zap';
            }
        ?>
        <div class="subx-plan <?= $isCurrent ? 'is-current' : '' ?> <?= $isFeatured ? 'is-featured' : '' ?> <?= $planTheme ?>" data-reveal>
            <?php if ($isCurrent): ?>
                <span class="subx-plan-flag flag-active"><?= icon('check-circle', 12) ?> Current Plan</span>
            <?php elseif (!empty($plan['badge'])): ?>
                <span class="subx-plan-flag <?= $flagClass ?>"><?= icon($flagIcon, 11) ?> <?= e($plan['badge']) ?></span>
            <?php endif; ?>

            <div class="subx-plan-header">
                <div class="subx-plan-name"><?= e($plan['name']) ?></div>
                <div class="subx-plan-tag"><?= e($plan['tagline'] ?? '') ?></div>
            </div>

            <div class="subx-plan-price-wrap">
                <div class="subx-plan-price">
                    <?php if ($isFree): ?>
                        <span class="subx-plan-amount">Free</span>
                        <span class="subx-plan-per">/ trial</span>
                    <?php else: ?>
                        <span class="subx-plan-cur"><?= e(defined('APP_CURRENCY') ? APP_CURRENCY : '₹') ?></span>
                        <span class="subx-plan-amount tnum"><?= e(number_format((float) $plan['price'], 0)) ?></span>
                        <span class="subx-plan-per">/ <?= $months >= 3 ? '3 months' : 'month' ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!$isFree && $months > 1): ?>
                    <div class="subx-plan-save"><?= e(defined('APP_CURRENCY') ? APP_CURRENCY : '₹') ?><?= e(number_format($perMonth, 0)) ?> /mo equivalent</div>
                <?php endif; ?>
            </div>

            <div class="subx-plan-divider"></div>

            <ul class="subx-plan-features">
                <?php foreach (array_slice(array_filter(array_map('trim', explode("\n", (string) $plan['features']))), 0, 7) as $f): ?>
                    <li>
                        <span class="subx-feat-ico"><?= icon('check-circle', 14) ?></span>
                        <span class="subx-feat-text"><?= e($f) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="subx-plan-action">
                <?php if ($isCurrent): ?>
                    <button class="btn btn-current-plan btn-block" type="button" disabled>
                        <?= icon('check-circle', 15) ?> Current Plan
                    </button>
                <?php elseif ($isFree): ?>
                    <button class="btn btn-ghost-disabled btn-block" type="button" disabled>Free Included</button>
                <?php else: ?>
                    <form method="post" class="subx-plan-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="subscribe">
                        <input type="hidden" name="plan_id" value="<?= (int) $plan['id'] ?>">
                        <button class="btn <?= $btnClass ?> btn-block subx-plan-btn" type="submit">
                            <?= icon('zap', 15) ?> <span>Subscribe</span>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="subx-section-head" id="history">
        <div class="subx-section-head-left">
            <span class="subx-pill-eyebrow"><?= icon('receipt', 12) ?> Billing History</span>
            <h3>Payment Records</h3>
            <p>Recent transactions and subscription activations</p>
        </div>
    </div>

    <div class="dash-white-card subx-card" style="padding: 0; overflow: hidden;">
        <?php if (!$history): ?>
            <div class="subx-empty">
                <div class="subx-empty-icon"><?= icon('receipt', 28) ?></div>
                <div class="subx-empty-title">No subscription payments yet</div>
                <p>When you subscribe to a plan, your invoices and receipts will appear here.</p>
            </div>
        <?php else: ?>
        <div class="tx-desktop-table-wrap" style="border: none !important; border-radius: 0 !important; box-shadow: none !important; margin: 0 !important; background: transparent !important;">
            <table class="tx-table subx-table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Plan</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Date &amp; Time</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($history as $h): ?>
                    <tr>
                        <td>
                            <div class="tx-order-pill subx-ref-cell">
                                <code class="mono"><?= e($h['reference']) ?></code>
                                <button type="button" class="dark-chip-copy dash-chip-copy" data-copy="<?= e($h['reference']) ?>" title="Copy Reference"><?= icon('copy', 12) ?></button>
                            </div>
                        </td>
                        <td>
                            <span class="subx-plan-chip"><?= icon('zap', 13) ?> <?= e($h['plan_name'] ?? 'Custom Plan') ?></span>
                        </td>
                        <td class="tnum subx-amount-cell"><?= e(money((float) $h['amount'])) ?></td>
                        <td>
                            <?php if ($h['status'] === 'success'): ?>
                                <span class="subx-paystatus is-ok"><span class="subx-paystatus-dot"></span>Paid</span>
                            <?php elseif ($h['status'] === 'pending'): ?>
                                <span class="subx-paystatus is-pending"><span class="subx-paystatus-dot"></span>Pending</span>
                            <?php else: ?>
                                <span class="subx-paystatus is-muted"><span class="subx-paystatus-dot"></span><?= e(ucfirst((string) $h['status'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-mute tnum"><?= e(date('d M Y, h:i A', strtotime((string) $h['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
