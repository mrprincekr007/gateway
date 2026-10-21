<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';
require_once __DIR__ . '/includes/admin.php';

$user   = require_admin();
$active = 'webhooks';

$error = null;

/* ---------- POST Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf_token'))) {
        $error = 'Session expired. Please try again.';
    } else {
        $action = post('action');
        if ($action === 'retry') {
            $logId = (int) post('log_id');
            $logStmt = db()->prepare('SELECT order_id FROM webhook_logs WHERE id = ?');
            $logStmt->execute([$logId]);
            $orderId = (string) $logStmt->fetchColumn();

            $err = $orderId ? admin_resend_webhook($orderId) : 'Webhook order not found.';
            if ($err) {
                $error = $err;
            } else {
                flash_set('success', 'Webhook re-dispatched successfully!');
                redirect('admin/webhooks.php' . (post('return') !== '' ? '?' . post('return') : ''));
            }
        }
    }
}

/* ---------- Filters ---------- */
$status  = get('status'); // '' | success | failed
$perPage = (int) get('per_page', '25');
if (!in_array($perPage, [25, 50, 100], true)) { $perPage = 25; }
$page   = max(1, (int) get('page', '1'));
$search = trim(get('q'));

$where  = '1=1';
$params = [];
if (in_array($status, ['success', 'failed'], true)) { 
    $where .= ' AND l.status = :status'; 
    $params[':status'] = $status; 
}
if ($search !== '') {
    $where .= ' AND (l.order_id LIKE :q OR l.endpoint_url LIKE :q OR u.email LIKE :q OR u.name LIKE :q)';
    $params[':q'] = "%{$search}%";
}

/* ---------- KPI Summary ---------- */
$sumStmt = db()->prepare("SELECT
        COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN l.status = 'success' THEN 1 ELSE 0 END), 0) AS ok,
        COALESCE(SUM(CASE WHEN l.status = 'failed' THEN 1 ELSE 0 END), 0) AS failed,
        COALESCE(SUM(CASE WHEN DATE(l.created_at) = CURDATE() THEN 1 ELSE 0 END), 0) AS today_count
    FROM webhook_logs l 
    JOIN users u ON u.id = l.user_id 
    WHERE {$where}");
$sumStmt->execute($params);
$sum = $sumStmt->fetch();

$totalLogs    = (int) ($sum['total'] ?? 0);
$okLogs       = (int) ($sum['ok'] ?? 0);
$failedLogs   = (int) ($sum['failed'] ?? 0);
$todayLogs    = (int) ($sum['today_count'] ?? 0);
$overallRate  = $totalLogs > 0 ? (int) round(($okLogs / $totalLogs) * 100) : 100;

/* ---------- Pagination ---------- */
$countStmt = db()->prepare("SELECT COUNT(*) FROM webhook_logs l JOIN users u ON u.id = l.user_id WHERE {$where}");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages   = max(1, (int) ceil($totalRecords / $perPage));
$page         = min($page, $totalPages);
$offset       = ($page - 1) * $perPage;

$rowsStmt = db()->prepare("
    SELECT l.*, u.name AS merchant, u.email AS merchant_email, u.merchant_id
    FROM webhook_logs l JOIN users u ON u.id = l.user_id
    WHERE {$where}
    ORDER BY l.created_at DESC
    LIMIT :limit OFFSET :offset");
$rowsStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$rowsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $k => $v) { $rowsStmt->bindValue($k, $v); }
$rowsStmt->execute();
$rows = $rowsStmt->fetchAll();

$qs = function (array $overrides = []) use ($status, $perPage, $search, $page): string {
    $p = ['status' => $status, 'per_page' => $perPage, 'q' => $search, 'page' => $page];
    foreach ($overrides as $k => $v) { $p[$k] = $v; }
    return http_build_query(array_filter($p, fn ($v) => $v !== '' && $v !== null));
};
$return = $qs();

$pageTitle   = 'Webhook Deliveries Audit';
$pageHeading = 'Webhook Deliveries & Payloads';
$pageSub     = 'Inspect outgoing HTTP notification payloads, response codes, and manual re-dispatch triggers.';
include __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-error mb-4"><?= icon('alert', 18) ?><span><?= e($error) ?></span></div>
<?php endif; ?>
<?php if ($f = flash_get()): ?>
    <div class="alert alert-success mb-4"><?= icon('check-circle', 18) ?><span><?= e($f['message']) ?></span></div>
<?php endif; ?>

<!-- Top Metrics Cards (Ambient Aura) -->
<div class="admin-kpi-grid mb-4">
    <div class="admin-kpi-card tone-indigo">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Total Dispatched</span>
            <span class="admin-kpi-icon"><?= icon('send', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= $totalLogs ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Across all merchant endpoints</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-emerald">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Delivered (2xx)</span>
            <span class="admin-kpi-icon"><?= icon('check-circle', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= $okLogs ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub"><?= $overallRate ?>% platform delivery rate</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-rose">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Failed Deliveries</span>
            <span class="admin-kpi-icon"><?= icon('alert', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= $failedLogs ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Non-200 or timeout errors</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-cyan">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Deliveries Today</span>
            <span class="admin-kpi-icon"><?= icon('clock', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= $todayLogs ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Since midnight UTC</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-purple">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">HMAC Protocol</span>
            <span class="admin-kpi-icon"><?= icon('shield', 18) ?></span>
        </div>
        <div class="admin-kpi-value" style="font-size:18px;">SHA-256</div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Signed signature headers</span>
        </div>
    </div>
</div>

<!-- Webhook Logs Table Card -->
<div class="card card-pad-0">
    <div class="admin-toolbar" style="padding:18px 24px;">
        <form method="get" class="flex items-center gap-2" style="flex-wrap:wrap;flex:1;">
            <div style="position:relative;flex:1;min-width:240px;max-width:380px;">
                <input class="form-input" type="search" name="q" placeholder="Search Order ID, Endpoint, Merchant..." value="<?= e($search) ?>" style="height:38px;padding-left:34px;">
                <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--color-ink-mute);pointer-events:none;">
                    <?= icon('search', 15) ?>
                </span>
            </div>

            <select class="form-select" name="status" style="height:38px;width:auto;min-width:140px;">
                <option value="">All Statuses</option>
                <option value="success" <?= $status === 'success' ? 'selected' : '' ?>>Success (2xx) Only</option>
                <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>Failed Only</option>
            </select>

            <button class="btn btn-secondary btn-sm flex items-center gap-1" type="submit">
                <?= icon('search', 14) ?> Filter
            </button>

            <?php if ($search !== '' || $status !== ''): ?>
                <a class="btn btn-ghost btn-sm" href="<?= url('admin/webhooks.php') ?>">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (!$rows): ?>
        <div class="empty-state" style="padding:60px 20px;">
            <div class="empty-icon"><?= icon('send', 28) ?></div>
            <p class="empty-state-title">No webhook logs match your criteria</p>
            <p class="empty-state-sub">Deliveries will appear here as payments are settled.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Order Identifier</th>
                        <th>Merchant Owner</th>
                        <th>Target Endpoint URL</th>
                        <th>Status</th>
                        <th>HTTP Code</th>
                        <th>Dispatched At</th>
                        <th style="text-align:right;">Control Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $i => $l): ?>
                    <tr>
                        <td data-label="Order Identifier">
                            <span class="mono font-semibold" style="font-size:13px;color:var(--color-primary);">
                                <?= e($l['order_id'] ?: 'ping_test') ?>
                            </span>
                        </td>
                        <td data-label="Merchant Owner">
                            <div style="font-weight:600;font-size:13px;">
                                <a href="<?= url('admin/user.php?id=' . (int) $l['user_id'] . '&tab=webhooks') ?>" style="color:inherit;text-decoration:none;">
                                    <?= e($l['merchant']) ?>
                                </a>
                            </div>
                            <div class="text-mute" style="font-size:11.5px;"><?= e($l['merchant_email']) ?></div>
                        </td>
                        <td data-label="Target Endpoint URL">
                            <div class="mono" style="font-size:12px;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= e($l['endpoint_url']) ?>">
                                <?= e($l['endpoint_url']) ?>
                            </div>
                        </td>
                        <td data-label="Status">
                            <?= status_badge((string) $l['status']) ?>
                        </td>
                        <td data-label="HTTP Code">
                            <span class="mono badge <?= (int)$l['response_code'] >= 200 && (int)$l['response_code'] < 300 ? 'badge-success' : 'badge-danger' ?>">
                                <?= $l['response_code'] ?: 'TIMEOUT' ?>
                            </span>
                        </td>
                        <td data-label="Dispatched At" class="text-mute" style="font-size:12px;">
                            <?= e(nice_date($l['created_at'])) ?>
                        </td>
                        <td data-label="Control Actions" style="text-align:right;">
                            <div class="flex items-center justify-end gap-1">
                                <!-- Expand Payload Button -->
                                <button class="btn btn-secondary btn-sm" type="button" onclick="var p = document.getElementById('payload-row-<?= $i ?>'); p.style.display = (p.style.display === 'none' ? 'table-row' : 'none');">
                                    Payload
                                </button>

                                <!-- Retry Dispatch Form -->
                                <form method="post" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="retry">
                                    <input type="hidden" name="log_id" value="<?= (int) $l['id'] ?>">
                                    <input type="hidden" name="return" value="<?= e($return) ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit" title="Resend HTTP POST to this endpoint">
                                        <?= icon('refresh', 11) ?> Retry
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <tr id="payload-row-<?= $i ?>" style="display:none;background:var(--color-bg-subtle);">
                        <td colspan="7" style="padding:16px 24px;border-bottom:1px solid var(--color-border);">
                            <div class="flex items-center justify-between mb-2">
                                <span style="font-weight:600;font-size:12px;color:var(--color-ink-base);">JSON Payload Sent:</span>
                                <span class="mono text-mute" style="font-size:11.5px;">Attempt ID #<?= (int) $l['id'] ?></span>
                            </div>
                            <pre class="mono" style="margin:0;padding:12px;background:var(--surface-page, #0f172a);color:#e2e8f0;border-radius:8px;font-size:11.5px;overflow:auto;max-height:220px;"><?php
                                $decoded = json_decode((string) $l['payload'], true);
                                echo e($decoded !== null ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : (string) $l['payload']);
                            ?></pre>
                            <?php if (!empty($l['response_body'])): ?>
                                <div class="mt-2" style="font-weight:600;font-size:12px;color:var(--color-ink-base);">Merchant Response Body:</div>
                                <pre class="mono mt-1" style="margin:0;padding:8px;background:rgba(0,0,0,0.1);border-radius:6px;font-size:11.5px;max-height:100px;overflow:auto;"><?= e((string) $l['response_body']) ?></pre>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-between" style="padding:16px 24px;gap:12px;flex-wrap:wrap;border-top:1px solid var(--color-border);">
            <span class="text-mute" style="font-size:12.5px;">Showing page <?= $page ?> of <?= $totalPages ?> (Total <?= $totalRecords ?> logs)</span>
            <div class="flex items-center gap-2">
                <a class="btn btn-secondary btn-sm <?= $page <= 1 ? 'hidden' : '' ?>" href="?<?= e($qs(['page' => max(1, $page - 1)])) ?>">Previous</a>
                <a class="btn btn-secondary btn-sm <?= $page >= $totalPages ? 'hidden' : '' ?>" href="?<?= e($qs(['page' => $page + 1])) ?>">Next</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
