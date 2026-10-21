<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';
require_once APP_ROOT . '/includes/subscriptions.php';

$user   = require_login();
$active = 'webhooks';

$tab    = get('tab', 'endpoints'); // endpoints | logs
$error  = null;
$notice = null;

/* Fire a test delivery */
function fire_test_webhook(int $userId, $endpoint): array
{
    $payload = [
        'event'          => 'payment.success',
        'order_id'       => generate_order_id(),
        'amount'         => 99.0,
        'payable_amount' => 99.0,
        'status'         => 'success',
        'transaction_id' => 'FMP' . random_int(100000000, 999999999),
        'utr'            => (string) random_int(100000000000, 999999999999),
        'sender_name'    => 'Test Customer',
        'payment_time'   => ist_datetime(),
        'timestamp'      => time(),
    ];
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $sig  = hash_hmac('sha256', (string) $body, (string) $endpoint['secret']);
    $ch   = curl_init((string) $endpoint['endpoint_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-' . SITE_NAME . '-Signature: ' . $sig,
            'X-' . SITE_NAME . '-Event: payment.success',
            'User-Agent: ' . SITE_NAME . '-Webhook-Dispatcher/2.0',
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $response = curl_exec($ch);
    $code     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    $ok = ($err === '' && $code >= 200 && $code < 300);
    db()->prepare(
        'INSERT INTO webhook_logs (user_id, order_id, endpoint_url, response_code, status, payload)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $userId,
        $payload['order_id'],
        $endpoint['endpoint_url'],
        $code ?: null,
        $ok ? 'success' : 'failed',
        json_encode(['payload' => $payload, 'response' => $response !== false ? substr((string) $response, 0, 300) : $err, 'signature' => $sig], JSON_UNESCAPED_SLASHES),
    ]);

    return ['success' => $ok, 'code' => $code, 'error' => $err];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf(post('csrf_token'))) {
        $error = 'Session expired. Please try again.';
    } else {
        $action = post('action', 'add');
        $id     = (int) post('id');

        if ($action === 'add') {
            $label = trim((string) post('label'));
            $url   = trim((string) post('endpoint_url'));

            if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
                $error = 'Enter a valid http(s) destination URL.';
            } else {
                $gate = sub_can((int) $user['id'], 'webhooks');
                $stmt = db()->prepare('SELECT COUNT(*) FROM webhooks WHERE user_id = ?');
                $stmt->execute([$user['id']]);
                $whCount = (int) $stmt->fetchColumn();
                if (!$gate['ok']) {
                    $error = sub_reason_message((string) $gate['reason']);
                } elseif ($whCount >= 5) {
                    $error = 'You can configure up to 5 webhook endpoints.';
                } else {
                    db()->prepare(
                        'INSERT INTO webhooks (user_id, endpoint_url, label, secret, status)
                         VALUES (?, ?, ?, ?, "active")'
                    )->execute([$user['id'], $url, $label !== '' ? $label : 'Primary Endpoint', generate_token(24)]);
                    flash_set('success', 'Webhook endpoint registered successfully.');
                    redirect('user/webhooks.php');
                }
            }
        } elseif ($action === 'toggle' && $id > 0) {
            db()->prepare('UPDATE webhooks SET status = IF(status = "active", "disabled", "active") WHERE id = ? AND user_id = ?')->execute([$id, $user['id']]);
            flash_set('success', 'Endpoint status updated.');
            redirect('user/webhooks.php');
        } elseif ($action === 'test' && $id > 0) {
            $stmt = db()->prepare('SELECT * FROM webhooks WHERE id = ? AND user_id = ? LIMIT 1');
            $stmt->execute([$id, $user['id']]);
            $ep = $stmt->fetch();
            if (!$ep) {
                $error = 'Endpoint not found.';
            } else {
                $testRes = fire_test_webhook($user['id'], $ep);
                if ($testRes['success']) {
                    flash_set('success', 'Test ping dispatched! Destination responded with HTTP ' . $testRes['code'] . ' OK.');
                } else {
                    flash_set('danger', 'Test ping failed! Destination returned HTTP ' . ($testRes['code'] ?: 'Timeout/Error') . '.');
                }
                redirect('user/webhooks.php?tab=logs');
            }
        } elseif ($action === 'delete' && $id > 0) {
            db()->prepare('DELETE FROM webhooks WHERE id = ? AND user_id = ?')->execute([$id, $user['id']]);
            flash_set('success', 'Webhook endpoint removed.');
            redirect('user/webhooks.php');
        }
    }
}

/* ---------- Query Data ---------- */
$epsStmt = db()->prepare('SELECT * FROM webhooks WHERE user_id = ? ORDER BY created_at ASC');
$epsStmt->execute([$user['id']]);
$endpoints = $epsStmt->fetchAll();

$logFilter  = get('logs', 'all');
$logStmt = db()->prepare('SELECT * FROM webhook_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 100');
$logStmt->execute([$user['id']]);
$logs = $logStmt->fetchAll();

/* Summary metrics */
$allLogsStmt = db()->prepare('SELECT status FROM webhook_logs WHERE user_id = ?');
$allLogsStmt->execute([$user['id']]);
$allLogs = $allLogsStmt->fetchAll();
$totalLogsCount = count($allLogs);
$succLogsCount  = count(array_filter($allLogs, fn($l) => $l['status'] === 'success'));
$deliveryRate   = $totalLogsCount > 0 ? round(($succLogsCount / $totalLogsCount) * 100, 1) : 100.0;

$pageTitle       = 'Webhooks';
$pageHeading     = 'Webhooks';
$pageSub         = 'Receive real-time HTTP POST notifications whenever customer payments succeed.';
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
            <span>Event Bus &middot; Signed Webhooks</span>
        </div>
        <div class="hero-sla-pill">
            <span class="sla-dot"></span>
            <span>HMAC SHA-256 &middot; Instant Dispatch</span>
        </div>
    </div>

    <div class="hero-stage-headline">
        <h1 class="dash-hero-heading">
            Real-Time <span class="accent">Webhooks</span>
        </h1>
        <p class="dash-hero-desc">
            Instant cryptographic callbacks on payment &amp; settlement events.
        </p>
    </div>

    <div class="hero-meta-ribbon">
        <div class="meta-ribbon-cell" title="Configured webhook listener endpoints">
            <div class="meta-cell-label">Active Slots</div>
            <div class="meta-cell-value">
                <span class="val-accent" data-auto-fit="12"><?= count($endpoints) ?> / 5 Configured</span>
            </div>
        </div>

        <div class="meta-ribbon-cell" title="Total event dispatches recorded">
            <div class="meta-cell-label">Total Dispatches</div>
            <div class="meta-cell-value">
                <span class="val-money" data-auto-fit="12"><?= $totalLogsCount ?> Events</span>
            </div>
        </div>

        <div class="meta-ribbon-cell" title="Verification signature header: X-<?= e(SITE_NAME) ?>-Signature">
            <div class="meta-cell-label">Signature Header</div>
            <div class="meta-cell-value">
                <span class="val-rate" data-auto-fit="11" title="X-<?= e(SITE_NAME) ?>-Signature">X-Signature</span>
            </div>
        </div>

        <div class="meta-ribbon-cell" title="Retry and timeout policy: Exponential Backoff">
            <div class="meta-cell-label">Retry Policy</div>
            <div class="meta-cell-value">
                <span class="meta-badge-ok" data-auto-fit="11"><span class="mini-beacon"></span> Exponential</span>
            </div>
        </div>
    </div>

    <div class="hero-stage-btns">
        <button type="button" class="btn-hero-primary" onclick="document.getElementById('addEndpointCard').scrollIntoView({behavior:'smooth'});document.getElementById('endpointUrlInput').focus();">
            <?= icon('plus', 15) ?> Add Webhook Endpoint
        </button>
        <a href="<?= url('user/docs.php') ?>" class="btn-hero-secondary">
            <?= icon('book', 14) ?> Webhook Docs
        </a>
    </div>
</div>

<!-- =========================================================
     SYSTEM ALERTS
     ========================================================= -->
<?php if ($error): ?>
    <div class="dash-alert dash-alert-error" data-reveal style="margin-bottom:22px !important;">
        <div class="alert-icon-wrap"><?= icon('alert', 18) ?></div>
        <div class="alert-text-wrap"><strong>Notice:</strong> <?= e($error) ?></div>
    </div>
<?php endif; ?>

<?php if ($f = flash_get()): ?>
    <div class="dash-alert dash-alert-success" data-reveal style="margin-bottom:22px !important;">
        <div class="alert-icon-wrap"><?= icon('check-circle', 18) ?></div>
        <div class="alert-text-wrap"><strong>Success:</strong> <?= e($f['message']) ?></div>
    </div>
<?php endif; ?>

<!-- =========================================================
     4 VIBRANT COLORFUL METRIC CARDS
     ========================================================= -->
<div class="dash-kpi-grid-light" data-reveal>
    <!-- KPI 1: Active Endpoints -->
    <div class="light-kpi-card" style="--kpi-top-bar:#10b981;--kpi-bubble-bg:rgba(16,185,129,0.12);--kpi-bubble-color:#059669;--kpi-bubble-glow:rgba(16,185,129,0.25);">
        <div class="kpi-head-row">
            <span class="kpi-label-text" data-auto-fit="11">Endpoints</span>
            <span class="kpi-icon-glow"><?= icon('send', 18) ?></span>
        </div>
        <div class="kpi-number-display" style="color:#059669;" data-auto-fit="16">
            <?= count($endpoints) ?> <span style="font-size:14px;color:#94a3b8;font-weight:500;">/ 5</span>
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill up">
                <?= icon('check', 11) ?> Active
            </span>
        </div>
    </div>

    <!-- KPI 2: Total Dispatches -->
    <div class="light-kpi-card" style="--kpi-top-bar:#0284c7;--kpi-bubble-bg:rgba(2,132,199,0.12);--kpi-bubble-color:#0284c7;--kpi-bubble-glow:rgba(2,132,199,0.25);">
        <div class="kpi-head-row">
            <span class="kpi-label-text" data-auto-fit="11">Dispatches</span>
            <span class="kpi-icon-glow"><?= icon('zap', 18) ?></span>
        </div>
        <div class="kpi-number-display" style="color:#0284c7;" data-auto-fit="16">
            <?= $totalLogsCount ?>
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill up">
                <?= icon('activity', 11) ?> Total
            </span>
        </div>
    </div>

    <!-- KPI 3: Delivery Success Rate -->
    <div class="light-kpi-card" style="--kpi-top-bar:#8b5cf6;--kpi-bubble-bg:rgba(139,92,246,0.12);--kpi-bubble-color:#7c3aed;--kpi-bubble-glow:rgba(139,92,246,0.25);">
        <div class="kpi-head-row">
            <span class="kpi-label-text" data-auto-fit="11">Delivery Rate</span>
            <span class="kpi-icon-glow"><?= icon('check-circle', 18) ?></span>
        </div>
        <div class="kpi-number-display" style="color:#7c3aed;" data-auto-fit="16">
            <?= $deliveryRate ?>%
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill up">
                <?= icon('check', 11) ?> Healthy
            </span>
        </div>
    </div>

    <!-- KPI 4: Cryptographic Security -->
    <div class="light-kpi-card" style="--kpi-top-bar:#2563eb;--kpi-bubble-bg:rgba(37,99,235,0.12);--kpi-bubble-color:#2563eb;--kpi-bubble-glow:rgba(37,99,235,0.25);">
        <div class="kpi-head-row">
            <span class="kpi-label-text" data-auto-fit="11">Signature</span>
            <span class="kpi-icon-glow"><?= icon('shield', 18) ?></span>
        </div>
        <div class="kpi-number-display" style="color:#2563eb;font-size:17px;" data-auto-fit="13">
            HMAC-SHA256
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill up">
                <?= icon('lock', 11) ?> Signed
            </span>
        </div>
    </div>
</div>

<!-- =========================================================
     ADD ENDPOINT CREATOR CARD
     ========================================================= -->
<div class="dash-white-card wh-create-card" id="addEndpointCard" data-reveal>
    <div class="white-card-header" style="display:flex;align-items:center;justify-content:space-between;width:100%;margin-bottom:16px;">
        <h3 class="white-card-title" style="margin:0;">
            <span style="color:#2563eb;"><?= icon('plus', 18) ?></span>
            Register Webhook
        </h3>
        <div style="margin-left:auto;text-align:right;">
            <span class="wh-slot-chip">
                <?= 5 - count($endpoints) ?> of 5 slots
            </span>
        </div>
    </div>

    <form method="post" action="<?= url('user/webhooks.php') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">

        <div class="wh-create-grid">
            <div class="form-group">
                <label class="form-label">
                    <?= icon('tag', 13) ?> Endpoint Label
                </label>
                <input class="form-input" name="label" placeholder="e.g. Production Backend" value="<?= e(post('label')) ?>">
            </div>

            <div class="form-group">
                <label class="form-label">
                    <?= icon('link', 13) ?> Destination URL (HTTPS)
                </label>
                <input class="form-input" id="endpointUrlInput" name="endpoint_url" type="url" placeholder="https://api.yourdomain.com/webhooks/payment" required value="<?= e(post('endpoint_url')) ?>">
            </div>

            <div>
                <button class="wh-submit-btn" type="submit">
                    <?= icon('send', 14) ?> Save Endpoint
                </button>
            </div>
        </div>
        <p class="form-hint">
            Dispatches include <code>X-<?= e(SITE_NAME) ?>-Signature</code> for HMAC verification.
        </p>
    </form>
</div>

<!-- =========================================================
     SAMPLE JSON PAYLOAD PREVIEW (DARK TERMINAL)
     ========================================================= -->
<div class="wh-code-box" data-reveal>
    <div class="wh-code-header">
        <div class="wh-code-dots">
            <span class="wh-dot dot-red"></span>
            <span class="wh-dot dot-yellow"></span>
            <span class="wh-dot dot-green"></span>
            <span class="wh-code-title">Sample Payload</span>
        </div>
        <?php
            $sampleJson = json_encode([
                'event'          => 'payment.success',
                'order_id'       => 'fg_9F2C81B4',
                'amount'         => 500.00,
                'payable_amount' => 500.00,
                'status'         => 'success',
                'transaction_id' => 'FMP829104812',
                'utr'            => '303872321241',
                'sender_name'    => 'Rohan Sharma',
                'payment_time'   => date('Y-m-d H:i:s'),
                'timestamp'      => time()
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        ?>
        <button type="button" class="wh-code-copy-btn" data-copy="<?= e($sampleJson) ?>">
            <?= icon('copy', 13) ?> Copy
        </button>
    </div>
    <pre class="wh-code-pre"><code><span class="wh-code-syntax"><?= e($sampleJson) ?></span></code></pre>
</div>

<!-- =========================================================
     TABBED NAVIGATION (ENDPOINTS VS DELIVERY LOGS)
     ========================================================= -->
<div class="dash-white-card tx-filter-card" data-reveal>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;width:100%;">
        <div class="tx-status-tabs" data-liquid-tabs id="whStatusTabs">
            <div class="tx-liquid-glider" aria-hidden="true">
                <span class="tx-glider-wave"></span>
            </div>
            <button type="button" class="tx-status-tab <?= $tab === 'endpoints' ? 'active active-all' : '' ?>" data-wh-tab="endpoints">
                <span class="tx-tab-dot dot-all"></span>
                <span class="tx-tab-text">Configured Endpoints</span>
                <span class="tx-tab-badge badge-all"><?= count($endpoints) ?></span>
            </button>
            <button type="button" class="tx-status-tab <?= $tab === 'logs' ? 'active active-all' : '' ?>" data-wh-tab="logs">
                <span class="tx-tab-dot dot-success"></span>
                <span class="tx-tab-text">Delivery Logs</span>
                <span class="tx-tab-badge badge-success"><?= $totalLogsCount ?></span>
            </button>
        </div>

        <div class="tx-horizon-pills" id="whLogsFilterStrip" style="<?= $tab === 'logs' ? '' : 'display:none;' ?>" data-liquid-tabs>
            <div class="tx-liquid-glider" aria-hidden="true">
                <span class="tx-glider-wave"></span>
            </div>
            <button type="button" class="tx-horizon-pill is-active active" data-wh-log-filter="all">All Logs</button>
            <button type="button" class="tx-horizon-pill" data-wh-log-filter="success">Success (2xx)</button>
            <button type="button" class="tx-horizon-pill" data-wh-log-filter="failed">Failed</button>
        </div>
    </div>
</div>

<!-- =========================================================
     TAB 1: ENDPOINTS CONTENT
     ========================================================= -->
<div class="dash-white-card wh-tab-panel <?= $tab === 'endpoints' ? 'is-active' : '' ?>" id="whPanelEndpoints" data-wh-panel="endpoints" style="<?= $tab === 'endpoints' ? '' : 'display:none;' ?>padding:0;overflow:hidden;" data-reveal>
        <?php if (!$endpoints): ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <?= icon('send', 24) ?>
                </div>
                <h3 class="empty-state-title">No Endpoints Configured</h3>
                <p class="empty-state-sub">
                    Add your listener URL above to receive real-time POST alerts on payment.
                </p>
                <button type="button" class="wh-submit-btn" onclick="document.getElementById('addEndpointCard').scrollIntoView({behavior:'smooth'});document.getElementById('endpointUrlInput').focus();" style="width:auto;min-width:160px;height:40px;gap:6px;">
                    <?= icon('plus', 14) ?> Add Endpoint
                </button>
            </div>
        <?php else: ?>
            <!-- Desktop Table View -->
            <div class="tx-desktop-table-wrap">
                <table class="tx-table">
                    <thead>
                        <tr>
                            <th>Endpoint Label</th>
                            <th>Destination URL</th>
                            <th>Signing Secret</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($endpoints as $ep): ?>
                        <tr>
                            <td>
                                <div style="font-weight:700;color:var(--color-ink);font-size:13.5px;display:flex;align-items:center;gap:6px;">
                                    <span style="color:#2563eb;"><?= icon('send', 15) ?></span>
                                    <?= e($ep['label']) ?>
                                </div>
                            </td>
                            <td>
                                <div style="display:inline-flex;align-items:center;gap:6px;background:var(--surface-sunken);padding:4px 8px;border-radius:8px;border:1px solid var(--color-hairline);font-family:'JetBrains Mono',monospace;font-size:12px;">
                                    <span style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($ep['endpoint_url']) ?></span>
                                    <button type="button" class="dark-chip-copy" data-copy="<?= e($ep['endpoint_url']) ?>" title="Copy URL" style="background:none;border:none;padding:0;cursor:pointer;color:#64748b;">
                                        <?= icon('copy', 12) ?>
                                    </button>
                                </div>
                            </td>
                            <td>
                                <div style="display:inline-flex;align-items:center;gap:6px;font-family:'JetBrains Mono',monospace;font-size:12px;color:#64748b;">
                                    <span><?= substr((string)$ep['secret'], 0, 8) ?>••••••••</span>
                                    <button type="button" class="dark-chip-copy" data-copy="<?= e($ep['secret']) ?>" title="Copy Full Secret" style="background:none;border:none;padding:0;cursor:pointer;color:#64748b;">
                                        <?= icon('copy', 12) ?>
                                    </button>
                                </div>
                            </td>
                            <td>
                                <?= status_badge((string) $ep['status']) ?>
                            </td>
                            <td>
                                <span style="font-size:12.5px;color:#64748b;"><?= e(date('M j, Y', strtotime($ep['created_at']))) ?></span>
                            </td>
                            <td style="text-align:right;">
                                <div style="display:inline-flex;align-items:center;gap:6px;">
                                    <!-- Send Test Event -->
                                    <form method="post" style="display:inline;margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="test">
                                        <input type="hidden" name="id" value="<?= (int) $ep['id'] ?>">
                                        <button class="btn-wh-ping" type="submit" title="Send test webhook">
                                            <?= icon('zap', 13) ?> Test Ping
                                        </button>
                                    </form>

                                    <!-- Enable / Disable -->
                                    <form method="post" style="display:inline;margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int) $ep['id'] ?>">
                                        <button class="btn-wh-toggle" type="submit">
                                            <?= $ep['status'] === 'active' ? 'Disable' : 'Enable' ?>
                                        </button>
                                    </form>

                                    <!-- Delete -->
                                    <form method="post" onsubmit="return confirm('Delete this webhook endpoint?');" style="display:inline;margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $ep['id'] ?>">
                                        <button class="btn-wh-delete" type="submit" title="Delete Endpoint">
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

            <!-- Mobile Cards View (Hidden on desktop > 768px, zero overflow on phone) -->
            <div class="tx-mobile-list" style="padding:14px;">
                <?php foreach ($endpoints as $ep): ?>
                <div class="wh-card-mobile">
                    <div class="wh-card-mobile-top">
                        <div style="font-weight:700;color:#0f172a;font-size:14px;display:flex;align-items:center;gap:6px;">
                            <span style="color:#2563eb;"><?= icon('send', 15) ?></span>
                            <?= e($ep['label']) ?>
                        </div>
                        <div>
                            <?= status_badge((string) $ep['status']) ?>
                        </div>
                    </div>

                    <div class="wh-url-box">
                        <span style="overflow:hidden;text-overflow:ellipsis;"><?= e($ep['endpoint_url']) ?></span>
                        <button type="button" class="dark-chip-copy" data-copy="<?= e($ep['endpoint_url']) ?>" title="Copy URL" style="background:none;border:none;padding:0;cursor:pointer;color:#64748b;flex-shrink:0;">
                            <?= icon('copy', 13) ?>
                        </button>
                    </div>

                    <div style="display:flex;align-items:center;justify-content:space-between;font-size:12px;color:#64748b;margin-bottom:12px;">
                        <span>Secret: <code class="mono"><?= substr((string)$ep['secret'], 0, 6) ?>••••</code></span>
                        <button type="button" class="btn btn-ghost btn-sm" data-copy="<?= e($ep['secret']) ?>" style="padding:2px 6px;height:auto;font-size:11px;">
                            <?= icon('copy', 11) ?> Copy Secret
                        </button>
                    </div>

                    <div class="wh-actions-grid">
                        <form method="post" style="margin:0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="test">
                            <input type="hidden" name="id" value="<?= (int) $ep['id'] ?>">
                            <button class="pl-btn-action pl-btn-test" type="submit">
                                <?= icon('zap', 13) ?> Send Test
                            </button>
                        </form>

                        <form method="post" style="margin:0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int) $ep['id'] ?>">
                            <button class="pl-btn-action" type="submit">
                                <?= $ep['status'] === 'active' ? 'Disable' : 'Enable' ?>
                            </button>
                        </form>

                        <form method="post" onsubmit="return confirm('Delete this webhook endpoint?');" style="margin:0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $ep['id'] ?>">
                            <button class="pl-btn-action btn-danger-soft" type="submit">
                                <?= icon('trash', 13) ?> Delete
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

<!-- =========================================================
     TAB 2: DELIVERY LOGS CONTENT
     ========================================================= -->
<div class="dash-white-card wh-tab-panel <?= $tab === 'logs' ? 'is-active' : '' ?>" id="whPanelLogs" data-wh-panel="logs" style="<?= $tab === 'logs' ? '' : 'display:none;' ?>padding:0;overflow:hidden;" data-reveal>
    <?php if (!$logs): ?>
        <div class="empty-state">
            <div class="empty-icon">
                <?= icon('clock', 24) ?>
            </div>
            <h3 class="empty-state-title">No Delivery Logs</h3>
            <p class="empty-state-sub">
                Dispatched deliveries will be logged here with HTTP codes &amp; payloads.
            </p>
        </div>
    <?php else: ?>
        <div id="whLogEmptyFilterNotice" class="empty-state" style="display:none;">
            <div class="empty-icon" style="background:rgba(100,116,139,0.08);color:#64748b;border-color:rgba(100,116,139,0.2);">
                <?= icon('search', 24) ?>
            </div>
            <h3 class="empty-state-title">No Logs Matching Filter</h3>
            <p class="empty-state-sub">
                Try selecting "All Logs" above to see the complete history.
            </p>
        </div>

        <!-- Desktop Table View -->
        <div class="tx-desktop-table-wrap">
            <table class="tx-table">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Destination Endpoint</th>
                        <th>HTTP Response</th>
                        <th>Status</th>
                        <th>Dispatched At</th>
                    </tr>
                </thead>
                <tbody id="whLogTableBody">
                    <?php foreach ($logs as $log): ?>
                    <?php $isSucc = ($log['status'] === 'success'); ?>
                    <tr class="wh-log-row" data-log-status="<?= e($log['status']) ?>">
                        <td>
                            <div class="tx-order-pill">
                                <span><?= e($log['order_id'] ?: 'test_event') ?></span>
                            </div>
                        </td>
                        <td>
                            <div class="mono" style="font-size:12px;color:#334155;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <?= e($log['endpoint_url']) ?>
                            </div>
                        </td>
                        <td>
                            <span class="mono" style="padding:3px 8px;border-radius:6px;font-size:12px;font-weight:700;background:<?= $isSucc ? 'rgba(16,185,129,0.1)' : 'rgba(239,68,68,0.1)' ?>;color:<?= $isSucc ? '#059669' : '#dc2626' ?>;">
                                HTTP <?= $log['response_code'] ?: 'Fail' ?>
                            </span>
                        </td>
                        <td>
                            <?= status_badge((string) $log['status']) ?>
                        </td>
                        <td>
                            <div style="font-size:12px;color:#64748b;">
                                <?= e(nice_date($log['created_at'])) ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Logs Card View -->
        <div class="tx-mobile-list" id="whLogMobileList" style="padding:14px;">
            <?php foreach ($logs as $log): ?>
            <?php $isSucc = ($log['status'] === 'success'); ?>
            <div class="wh-log-card-mobile wh-log-item" data-log-status="<?= e($log['status']) ?>">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <span class="tx-order-pill" style="font-size:11.5px;">
                        #<?= e($log['order_id'] ?: 'test') ?>
                    </span>
                    <span class="mono" style="padding:2px 6px;border-radius:4px;font-size:11.5px;font-weight:700;background:<?= $isSucc ? 'rgba(16,185,129,0.1)' : 'rgba(239,68,68,0.1)' ?>;color:<?= $isSucc ? '#059669' : '#dc2626' ?>;">
                        HTTP <?= $log['response_code'] ?: 'ERR' ?>
                    </span>
                </div>
                <div class="mono" style="font-size:11.5px;color:#475569;word-break:break-all;margin-bottom:8px;">
                    <?= e($log['endpoint_url']) ?>
                </div>
                <div style="display:flex;align-items:center;justify-content:space-between;font-size:11px;color:#94a3b8;">
                    <span><?= status_badge((string) $log['status']) ?></span>
                    <span><?= e(nice_date($log['created_at'])) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>