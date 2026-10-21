<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';
require_once __DIR__ . '/includes/admin.php';

$user   = require_admin();
$active = 'keys';

$error = null;

/* ---------- POST Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf_token'))) {
        $error = 'Session expired. Please try again.';
    } else {
        $action = post('action');

        if ($action === 'create_key') {
            $merchantId = (int) post('merchant_id');
            $label      = trim(post('label')) ?: 'Admin Generated Key';

            $res = admin_create_api_key($merchantId, $label);
            if (is_string($res)) {
                $error = $res;
            } elseif (isset($res['error'])) {
                $error = $res['error'];
            } else {
                $secretPlain = $res['secret_plain'] ?? $res['secret_key'] ?? '';
                flash_set('success', 'API key generated for merchant! Secret: ' . $secretPlain . ' (Save this secret now)');
                redirect('admin/api-keys.php');
            }
        } elseif ($action === 'revoke') {
            $keyId      = (int) post('key_id');

            $err = admin_revoke_api_key($keyId);
            if ($err) {
                $error = $err;
            } else {
                flash_set('success', 'API key revoked immediately.');
                redirect('admin/api-keys.php' . (post('return') !== '' ? '?' . post('return') : ''));
            }
        }
    }
}

/* ---------- Filters & Search ---------- */
$q          = trim(get('q'));
$status     = get('status');
$merchantId = (int) get('merchant_id');
$perPage    = 25;
$page       = max(1, (int) get('page', '1'));

$where  = '1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (k.label LIKE :q OR k.public_key LIKE :q OR u.name LIKE :q OR u.email LIKE :q)';
    $params[':q'] = "%{$q}%";
}
if (in_array($status, ['active', 'revoked'], true)) {
    $where .= ' AND k.status = :status';
    $params[':status'] = $status;
}
if ($merchantId > 0) {
    $where .= ' AND k.user_id = :uid';
    $params[':uid'] = $merchantId;
}

/* ---------- KPI Metrics ---------- */
$kpiTotals = db()->query("
    SELECT 
        COUNT(*) AS total_keys,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_keys,
        SUM(CASE WHEN status = 'revoked' THEN 1 ELSE 0 END) AS revoked_keys,
        COUNT(DISTINCT user_id) AS merchants_with_keys
    FROM api_keys
")->fetch();

$totalKeys    = (int) ($kpiTotals['total_keys'] ?? 0);
$activeKeys   = (int) ($kpiTotals['active_keys'] ?? 0);
$revokedKeys  = (int) ($kpiTotals['revoked_keys'] ?? 0);
$merchantsCov = (int) ($kpiTotals['merchants_with_keys'] ?? 0);

/* ---------- Pagination & Rows ---------- */
$countStmt = db()->prepare("
    SELECT COUNT(*) 
    FROM api_keys k 
    JOIN users u ON u.id = k.user_id 
    WHERE {$where}");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages   = max(1, (int) ceil($totalRecords / $perPage));
$page         = min($page, $totalPages);
$offset       = ($page - 1) * $perPage;

$rowsStmt = db()->prepare("
    SELECT k.*, u.name AS merchant_name, u.email AS merchant_email, u.merchant_id
    FROM api_keys k 
    JOIN users u ON u.id = k.user_id
    WHERE {$where}
    ORDER BY k.created_at DESC
    LIMIT :limit OFFSET :offset");
$rowsStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$rowsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $k => $v) { $rowsStmt->bindValue($k, $v); }
$rowsStmt->execute();
$rows = $rowsStmt->fetchAll();

$merchantsList = db()->query("SELECT id, name, email FROM users ORDER BY name ASC")->fetchAll();

$qs = function (array $overrides = []) use ($q, $status, $merchantId, $page): string {
    $p = ['q' => $q, 'status' => $status, 'merchant_id' => $merchantId ?: '', 'page' => $page];
    foreach ($overrides as $k => $v) { $p[$k] = $v; }
    return http_build_query(array_filter($p, fn ($v) => $v !== '' && $v !== null));
};
$return = $qs();

$pageTitle   = 'API Keys Audit';
$pageHeading = 'API Keys & Secrets Control';
$pageSub     = 'Complete security audit of integration credentials, live keys, and access tokens across all merchant stores.';
$topbarActions = '<button class="btn btn-primary btn-sm flex items-center gap-1" onclick="openAdminModal(\'modal-create-key\')">' . icon('key', 14) . ' Generate API Key</button>';
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
            <span class="admin-kpi-label">Total API Keys</span>
            <span class="admin-kpi-icon"><?= icon('key', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= $totalKeys ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Platform total credentials</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-emerald">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Active Credentials</span>
            <span class="admin-kpi-icon"><?= icon('check-circle', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= $activeKeys ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Currently authorized</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-rose">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Revoked Keys</span>
            <span class="admin-kpi-icon"><?= icon('shield', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= $revokedKeys ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Disabled security keys</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-purple">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Active Merchants</span>
            <span class="admin-kpi-icon"><?= icon('user', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= $merchantsCov ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">With active integrations</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-cyan">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Filtered Results</span>
            <span class="admin-kpi-icon"><?= icon('receipt', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= $totalRecords ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Matching search criteria</span>
        </div>
    </div>
</div>

<!-- API Keys Table Card -->
<div class="card card-pad-0">
    <div class="admin-toolbar" style="padding:18px 24px;">
        <form method="get" class="flex items-center gap-2" style="flex-wrap:wrap;flex:1;">
            <div style="position:relative;flex:1;min-width:240px;max-width:360px;">
                <input class="form-input" type="search" name="q" placeholder="Search Label, Public Key, Merchant..." value="<?= e($q) ?>" style="height:38px;padding-left:34px;">
                <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--color-ink-mute);pointer-events:none;">
                    <?= icon('search', 15) ?>
                </span>
            </div>

            <select class="form-select" name="status" style="height:38px;width:auto;min-width:140px;">
                <option value="">All Statuses</option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active Only</option>
                <option value="revoked" <?= $status === 'revoked' ? 'selected' : '' ?>>Revoked Only</option>
            </select>

            <select class="form-select" name="merchant_id" style="height:38px;width:auto;min-width:180px;">
                <option value="">All Merchants</option>
                <?php foreach ($merchantsList as $m): ?>
                    <option value="<?= (int) $m['id'] ?>" <?= $merchantId === (int) $m['id'] ? 'selected' : '' ?>>
                        <?= e($m['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button class="btn btn-secondary btn-sm flex items-center gap-1" type="submit">
                <?= icon('search', 14) ?> Filter
            </button>

            <?php if ($q !== '' || $status !== '' || $merchantId > 0): ?>
                <a class="btn btn-ghost btn-sm" href="<?= url('admin/api-keys.php') ?>">Reset</a>
            <?php endif; ?>
        </form>

        <button class="btn btn-primary btn-sm flex items-center gap-1" onclick="openAdminModal('modal-create-key')">
            <?= icon('key', 14) ?> New Key
        </button>
    </div>

    <?php if (!$rows): ?>
        <div class="empty-state" style="padding:60px 20px;">
            <div class="empty-icon"><?= icon('key', 28) ?></div>
            <p class="empty-state-title">No API keys found</p>
            <p class="empty-state-sub">No integration credentials match your query.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Key Label & Scope</th>
                        <th>Merchant Owner</th>
                        <th>Public Key Token</th>
                        <th>Status</th>
                        <th>Last Used</th>
                        <th>Issued Date</th>
                        <th style="text-align:right;">Control Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $k): ?>
                    <tr>
                        <td data-label="Key Label & Scope">
                            <div style="font-weight:600;font-size:13.5px;color:var(--color-ink-base);"><?= e($k['label']) ?></div>
                            <div class="text-mute" style="font-size:11.5px;">API v1 Engine</div>
                        </td>
                        <td data-label="Merchant Owner">
                            <div style="font-weight:600;font-size:13px;">
                                <a href="<?= url('admin/user.php?id=' . (int) $k['user_id']) ?>" style="color:inherit;text-decoration:none;">
                                    <?= e($k['merchant_name']) ?>
                                </a>
                            </div>
                            <div class="text-mute" style="font-size:11.5px;"><?= e($k['merchant_email']) ?></div>
                        </td>
                        <td data-label="Public Key Token">
                            <div class="mono font-semibold" style="color:var(--color-primary);font-size:12.5px;">
                                <?= e($k['public_key']) ?>
                            </div>
                        </td>
                        <td data-label="Status">
                            <?= status_badge((string) $k['status']) ?>
                        </td>
                        <td data-label="Last Used" class="text-mute" style="font-size:12px;">
                            <?= $k['last_used_at'] ? e(nice_date($k['last_used_at'])) : 'Never called' ?>
                        </td>
                        <td data-label="Issued Date" class="text-mute" style="font-size:12px;">
                            <?= e(nice_date($k['created_at'])) ?>
                        </td>
                        <td data-label="Control Actions" style="text-align:right;">
                            <div class="flex items-center justify-end gap-1">
                                <a class="btn btn-secondary btn-sm" href="<?= url('admin/user.php?id=' . (int) $k['user_id'] . '&tab=keys') ?>">
                                    Inspect Merchant
                                </a>

                                <?php if ($k['status'] === 'active'): ?>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Revoke this API key immediately? Merchant requests with this key will fail.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="revoke">
                                        <input type="hidden" name="key_id" value="<?= (int) $k['id'] ?>">
                                        <input type="hidden" name="merchant_id" value="<?= (int) $k['user_id'] ?>">
                                        <input type="hidden" name="return" value="<?= e($return) ?>">
                                        <button class="btn btn-ghost btn-sm" type="submit" style="color:var(--color-danger);">
                                            Revoke Key
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-mute" style="font-size:12px;padding:0 8px;">Revoked</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-between" style="padding:16px 24px;gap:12px;flex-wrap:wrap;border-top:1px solid var(--color-border);">
            <span class="text-mute" style="font-size:12.5px;">Showing page <?= $page ?> of <?= $totalPages ?> (Total <?= $totalRecords ?> keys)</span>
            <div class="flex items-center gap-2">
                <a class="btn btn-secondary btn-sm <?= $page <= 1 ? 'hidden' : '' ?>" href="?<?= e($qs(['page' => max(1, $page - 1)])) ?>">Previous</a>
                <a class="btn btn-secondary btn-sm <?= $page >= $totalPages ? 'hidden' : '' ?>" href="?<?= e($qs(['page' => $page + 1])) ?>">Next</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal: Generate API Key for Any Merchant -->
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
                    <label class="form-label">Target Merchant Account</label>
                    <select class="form-select" name="merchant_id" required>
                        <option value="">Select Merchant...</option>
                        <?php foreach ($merchantsList as $m): ?>
                            <option value="<?= (int) $m['id'] ?>">
                                <?= e($m['name']) ?> (<?= e($m['email']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Key Label / Integration Name</label>
                    <input class="form-input" type="text" name="label" placeholder="e.g. Production Mobile App" required>
                </div>
            </div>
            <div class="admin-modal-footer">
                <button type="button" class="btn btn-ghost btn-sm" onclick="closeAdminModal('modal-create-key')">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm flex items-center gap-2">
                    <?= icon('check-circle', 14) ?> Generate Credentials
                </button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
