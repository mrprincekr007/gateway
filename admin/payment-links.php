<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';
require_once __DIR__ . '/includes/admin.php';

$user   = require_admin();
$active = 'links';

$error = null;

/* ---------- POST Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf_token'))) {
        $error = 'Session expired. Please try again.';
    } else {
        $action = post('action');

        if ($action === 'create_link') {
            $merchantId = (int) post('merchant_id');
            $title      = trim(post('title'));
            $amount     = post('amount') !== '' ? (float) post('amount') : null;
            $desc       = trim(post('description'));

            $res = admin_create_payment_link($merchantId, $title, $amount, $desc);
            if (isset($res['error'])) {
                $error = $res['error'];
            } else {
                flash_set('success', 'Payment link created successfully for merchant.');
                redirect('admin/payment-links.php');
            }
        } elseif ($action === 'toggle') {
            $linkId     = (int) post('link_id');
            $newStatus  = post('status');

            $err = admin_toggle_payment_link($linkId, $newStatus);
            if ($err) {
                $error = $err;
            } else {
                flash_set('success', 'Payment link status updated.');
                redirect('admin/payment-links.php' . (post('return') !== '' ? '?' . post('return') : ''));
            }
        } elseif ($action === 'delete') {
            $linkId     = (int) post('link_id');

            $err = admin_delete_payment_link($linkId);
            if ($err) {
                $error = $err;
            } else {
                flash_set('success', 'Payment link deleted permanently.');
                redirect('admin/payment-links.php' . (post('return') !== '' ? '?' . post('return') : ''));
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
    $where .= ' AND (p.title LIKE :q OR p.link_id LIKE :q OR u.name LIKE :q OR u.email LIKE :q)';
    $params[':q'] = "%{$q}%";
}
if (in_array($status, ['active', 'disabled', 'expired'], true)) {
    $where .= ' AND p.status = :status';
    $params[':status'] = $status;
}
if ($merchantId > 0) {
    $where .= ' AND p.user_id = :uid';
    $params[':uid'] = $merchantId;
}

/* ---------- KPI Metrics ---------- */
$kpiTotals = db()->query("
    SELECT 
        COUNT(*) AS total_links,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_links,
        SUM(CASE WHEN status = 'disabled' THEN 1 ELSE 0 END) AS disabled_links
    FROM payment_links
")->fetch();

$totalLinks    = (int) ($kpiTotals['total_links'] ?? 0);
$activeLinks   = (int) ($kpiTotals['active_links'] ?? 0);
$disabledLinks = (int) ($kpiTotals['disabled_links'] ?? 0);

/* ---------- Pagination & Rows ---------- */
$countStmt = db()->prepare("
    SELECT COUNT(*) 
    FROM payment_links p 
    JOIN users u ON u.id = p.user_id 
    WHERE {$where}");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages   = max(1, (int) ceil($totalRecords / $perPage));
$page         = min($page, $totalPages);
$offset       = ($page - 1) * $perPage;

$rowsStmt = db()->prepare("
    SELECT p.*, u.name AS merchant_name, u.email AS merchant_email, u.merchant_id
    FROM payment_links p 
    JOIN users u ON u.id = p.user_id
    WHERE {$where}
    ORDER BY p.created_at DESC
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

$pageTitle   = 'Payment Links Management';
$pageHeading = 'Payment Links Control';
$pageSub     = 'Inspect, manage, toggle, and generate hosted checkout payment links across all merchants.';
$topbarActions = '<button class="btn btn-primary btn-sm flex items-center gap-1" onclick="openAdminModal(\'modal-create-link\')">' . icon('link', 14) . ' Create Payment Link</button>';
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
            <span class="admin-kpi-label">Total Payment Links</span>
            <span class="admin-kpi-icon"><?= icon('link', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= $totalLinks ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Across all registered merchants</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-emerald">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Active Links</span>
            <span class="admin-kpi-icon"><?= icon('check-circle', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= $activeLinks ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Live and accepting payments</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-rose">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Disabled Links</span>
            <span class="admin-kpi-icon"><?= icon('shield', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= $disabledLinks ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Suspended checkouts</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-purple">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Merchant Coverage</span>
            <span class="admin-kpi-icon"><?= icon('user', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= count($merchantsList) ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Total merchant businesses</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-cyan">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Filtered Results</span>
            <span class="admin-kpi-icon"><?= icon('receipt', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= $totalRecords ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Matching current query</span>
        </div>
    </div>
</div>

<!-- Links Table Card -->
<div class="card card-pad-0">
    <div class="admin-toolbar" style="padding:18px 24px;">
        <form method="get" class="flex items-center gap-2" style="flex-wrap:wrap;flex:1;">
            <div style="position:relative;flex:1;min-width:240px;max-width:360px;">
                <input class="form-input" type="search" name="q" placeholder="Search Title, Link ID, Merchant..." value="<?= e($q) ?>" style="height:38px;padding-left:34px;">
                <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--color-ink-mute);pointer-events:none;">
                    <?= icon('search', 15) ?>
                </span>
            </div>

            <select class="form-select" name="status" style="height:38px;width:auto;min-width:140px;">
                <option value="">All Statuses</option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active Only</option>
                <option value="disabled" <?= $status === 'disabled' ? 'selected' : '' ?>>Disabled Only</option>
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
                <a class="btn btn-ghost btn-sm" href="<?= url('admin/payment-links.php') ?>">Reset</a>
            <?php endif; ?>
        </form>

        <button class="btn btn-primary btn-sm flex items-center gap-1" onclick="openAdminModal('modal-create-link')">
            <?= icon('link', 14) ?> New Link
        </button>
    </div>

    <?php if (!$rows): ?>
        <div class="empty-state" style="padding:60px 20px;">
            <div class="empty-icon"><?= icon('link', 28) ?></div>
            <p class="empty-state-title">No payment links found</p>
            <p class="empty-state-sub">Create a new payment link for any merchant using the button above.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Title & Description</th>
                        <th>Merchant Owner</th>
                        <th>Link Identifier</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Created Date</th>
                        <th style="text-align:right;">Control Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $l): ?>
                    <tr>
                        <td data-label="Title & Description">
                            <div style="font-weight:600;font-size:13.5px;color:var(--color-ink-base);"><?= e($l['title']) ?></div>
                            <?php if ($l['description']): ?>
                                <div class="text-mute" style="font-size:12px;max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                    <?= e($l['description']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Merchant Owner">
                            <div style="font-weight:600;font-size:13px;">
                                <a href="<?= url('admin/user.php?id=' . (int) $l['user_id']) ?>" style="color:inherit;text-decoration:none;">
                                    <?= e($l['merchant_name']) ?>
                                </a>
                            </div>
                            <div class="text-mute" style="font-size:11.5px;"><?= e($l['merchant_email']) ?></div>
                        </td>
                        <td data-label="Link Identifier">
                            <a class="mono font-semibold" href="<?= url('pay.php?link=' . urlencode($l['link_id'])) ?>" target="_blank" style="color:var(--color-primary);font-size:12.5px;">
                                <?= e($l['link_id']) ?> ↗
                            </a>
                        </td>
                        <td data-label="Amount">
                            <div class="tnum" style="font-weight:700;font-size:13.5px;">
                                <?= $l['amount'] !== null ? money((float) $l['amount']) : '<span class="badge badge-neutral">Open Amount</span>' ?>
                            </div>
                        </td>
                        <td data-label="Status">
                            <?= status_badge((string) $l['status']) ?>
                        </td>
                        <td data-label="Created Date" class="text-mute" style="font-size:12px;">
                            <?= e(nice_date($l['created_at'])) ?>
                        </td>
                        <td data-label="Control Actions" style="text-align:right;">
                            <div class="flex items-center justify-end gap-1">
                                <!-- Checkout Preview -->
                                <a class="btn btn-secondary btn-sm" href="<?= url('pay.php?link=' . urlencode($l['link_id'])) ?>" target="_blank" title="Open Checkout Page">
                                    Preview
                                </a>

                                <!-- Toggle Status -->
                                <form method="post" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="link_id" value="<?= (int) $l['id'] ?>">
                                    <input type="hidden" name="merchant_id" value="<?= (int) $l['user_id'] ?>">
                                    <input type="hidden" name="status" value="<?= $l['status'] === 'active' ? 'disabled' : 'active' ?>">
                                    <input type="hidden" name="return" value="<?= e($return) ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit">
                                        <?= $l['status'] === 'active' ? 'Disable' : 'Enable' ?>
                                    </button>
                                </form>

                                <!-- Delete Link -->
                                <form method="post" style="display:inline;" onsubmit="return confirm('DANGER: Delete this payment link permanently?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="link_id" value="<?= (int) $l['id'] ?>">
                                    <input type="hidden" name="merchant_id" value="<?= (int) $l['user_id'] ?>">
                                    <input type="hidden" name="return" value="<?= e($return) ?>">
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

        <div class="flex items-center justify-between" style="padding:16px 24px;gap:12px;flex-wrap:wrap;border-top:1px solid var(--color-border);">
            <span class="text-mute" style="font-size:12.5px;">Showing page <?= $page ?> of <?= $totalPages ?> (Total <?= $totalRecords ?> links)</span>
            <div class="flex items-center gap-2">
                <a class="btn btn-secondary btn-sm <?= $page <= 1 ? 'hidden' : '' ?>" href="?<?= e($qs(['page' => max(1, $page - 1)])) ?>">Previous</a>
                <a class="btn btn-secondary btn-sm <?= $page >= $totalPages ? 'hidden' : '' ?>" href="?<?= e($qs(['page' => $page + 1])) ?>">Next</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal: Create Payment Link for Any Merchant -->
<div id="modal-create-link" class="admin-modal-overlay">
    <div class="admin-modal-dialog">
        <div class="admin-modal-header">
            <div class="admin-modal-title flex items-center gap-2">
                <?= icon('link', 18) ?> Create Platform Payment Link
            </div>
            <button type="button" class="admin-modal-close" onclick="closeAdminModal('modal-create-link')">✕</button>
        </div>
        <form method="post">
            <div class="admin-modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_link">

                <div class="form-group mb-3">
                    <label class="form-label">Assign to Merchant Account</label>
                    <select class="form-select" name="merchant_id" required>
                        <option value="">Choose Merchant...</option>
                        <?php foreach ($merchantsList as $m): ?>
                            <option value="<?= (int) $m['id'] ?>">
                                <?= e($m['name']) ?> (<?= e($m['email']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Link Title</label>
                    <input class="form-input" type="text" name="title" placeholder="e.g. VIP Subscription Annual" required>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Fixed Amount (₹)</label>
                    <input class="form-input tnum" type="number" step="0.01" min="1" name="amount" placeholder="Leave empty for open amount">
                    <p class="form-help">Leave empty if customer should specify custom payment amount.</p>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Description / Purpose</label>
                    <textarea class="form-textarea" name="description" rows="2" placeholder="Shown to the customer on checkout"></textarea>
                </div>
            </div>
            <div class="admin-modal-footer">
                <button type="button" class="btn btn-ghost btn-sm" onclick="closeAdminModal('modal-create-link')">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm flex items-center gap-2">
                    <?= icon('check-circle', 14) ?> Generate Link
                </button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
