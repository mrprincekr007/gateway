<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';
require_once __DIR__ . '/includes/admin.php';

$user   = require_admin();
$active = 'orders';

$error = null;

/* ---------- POST Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf_token'))) {
        $error = 'Session expired. Please try again.';
    } else {
        $action = post('action');
        if ($action === 'reconcile') {
            $orderId = trim((string) post('order_id'));
            $utr     = trim(post('utr'));
            $err     = admin_reconcile_order($orderId, $utr, null, (int) $user['id']);
            if ($err) {
                $error = $err;
            } else {
                flash_set('success', 'Order reconciled and marked as settled! Webhook dispatched.');
                redirect('admin/orders.php' . (post('return') !== '' ? '?' . post('return') : ''));
            }
        }
    }
}

/* ---------- Filters ---------- */
$status   = get('status');
$merchant = (int) get('merchant');
$perPage  = (int) get('per_page', '25');
if (!in_array($perPage, [25, 50, 100], true)) { $perPage = 25; }
$page     = max(1, (int) get('page', '1'));
$search   = trim(get('q'));

$where  = '1=1';
$params = [];
if (in_array($status, ['success', 'pending', 'failed', 'expired'], true)) {
    $where .= ' AND o.status = :status';
    $params[':status'] = $status;
}
if ($merchant > 0) {
    $where .= ' AND o.user_id = :uid';
    $params[':uid'] = $merchant;
}
if ($search !== '') {
    $where .= ' AND (o.order_id LIKE :sq1 OR o.customer_name LIKE :sq2 OR o.customer_email LIKE :sq3 OR o.utr LIKE :sq4 OR u.email LIKE :sq5 OR u.name LIKE :sq6)';
    $like = "%{$search}%";
    for ($i = 1; $i <= 6; $i++) { $params[":sq{$i}"] = $like; }
}

/* ---------- CSV Export ---------- */
if (get('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=transactions_export_' . date('Y-m-d_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Order ID', 'Merchant', 'Merchant Email', 'Customer', 'Customer Email', 'Amount', 'Status', 'UTR', 'Bank RRN', 'Created At', 'Paid At']);

    $exportStmt = db()->prepare("
        SELECT o.*, u.name AS merchant, u.email AS merchant_email
        FROM orders o JOIN users u ON u.id = o.user_id
        WHERE {$where}
        ORDER BY o.created_at DESC");
    $exportStmt->execute($params);
    while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $row['id'],
            $row['order_id'],
            $row['merchant'],
            $row['merchant_email'],
            $row['customer_name'] ?? '',
            $row['customer_email'] ?? '',
            $row['payable_amount'],
            $row['status'],
            $row['utr'] ?? '',
            $row['bank_rrn'] ?? '',
            $row['created_at'],
            $row['paid_at'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

/* ---------- Global KPI Totals ---------- */
$kpiStmt = db()->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN o.status = 'success' THEN o.payable_amount END), 0) AS total_collected,
        COUNT(*) AS total_orders,
        COALESCE(SUM(CASE WHEN o.status = 'success' THEN 1 ELSE 0 END), 0) AS success_count,
        COALESCE(SUM(CASE WHEN o.status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count,
        COALESCE(SUM(CASE WHEN o.status = 'failed' OR o.status = 'expired' THEN 1 ELSE 0 END), 0) AS failed_count
    FROM orders o 
    JOIN users u ON u.id = o.user_id 
    WHERE {$where}");
$kpiStmt->execute($params);
$kpi = $kpiStmt->fetch();

$totalCollected = (float) ($kpi['total_collected'] ?? 0);
$totalOrders    = (int) ($kpi['total_orders'] ?? 0);
$successCount   = (int) ($kpi['success_count'] ?? 0);
$pendingCount   = (int) ($kpi['pending_count'] ?? 0);
$failedCount    = (int) ($kpi['failed_count'] ?? 0);
$avgTicket      = $successCount > 0 ? ($totalCollected / $successCount) : 0;

/* ---------- Pagination & Rows ---------- */
$totalRecords = $totalOrders;
$totalPages   = max(1, (int) ceil($totalRecords / $perPage));
$page         = min($page, $totalPages);
$offset       = ($page - 1) * $perPage;

$rowsStmt = db()->prepare("
    SELECT o.*, u.name AS merchant, u.email AS merchant_email, u.merchant_id
    FROM orders o JOIN users u ON u.id = o.user_id
    WHERE {$where}
    ORDER BY o.created_at DESC
    LIMIT :limit OFFSET :offset");
$rowsStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$rowsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $k => $v) { $rowsStmt->bindValue($k, $v); }
$rowsStmt->execute();
$rows = $rowsStmt->fetchAll();

$merchantRow = null;
if ($merchant > 0) { $merchantRow = admin_find_user($merchant); }

// Fetch merchants list for filter dropdown
$merchantsList = db()->query("SELECT id, name, email FROM users ORDER BY name ASC")->fetchAll();

$qs = function (array $overrides = []) use ($status, $merchant, $perPage, $search, $page): string {
    $p = ['status' => $status, 'merchant' => $merchant ?: '', 'per_page' => $perPage, 'q' => $search, 'page' => $page];
    foreach ($overrides as $k => $v) { $p[$k] = $v; }
    return http_build_query(array_filter($p, fn ($v) => $v !== '' && $v !== null));
};
$return = $qs();

$pageTitle   = 'Transactions Monitor';
$pageHeading = 'Transactions Control Center';
$pageSub     = $merchantRow ? 'Filtered to merchant: ' . $merchantRow['name'] : 'Platform-wide real-time transaction ledger & manual settlement hub.';
$topbarActions = '<a class="btn btn-secondary btn-sm flex items-center gap-1" href="?' . e($qs(['export' => 'csv'])) . '">' . icon('download', 14) . ' Export CSV</a>';
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
            <span class="admin-kpi-label">Filtered Volume</span>
            <span class="admin-kpi-icon"><?= icon('wallet', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= money($totalCollected) ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub"><?= $successCount ?> settled payment(s)</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-emerald">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Settled Count</span>
            <span class="admin-kpi-icon"><?= icon('check-circle', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= $successCount ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub"><?= $totalOrders > 0 ? round(($successCount / $totalOrders) * 100) : 0 ?>% success rate</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-amber">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Pending Orders</span>
            <span class="admin-kpi-icon"><?= icon('clock', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= $pendingCount ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Awaiting customer UTR</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-rose">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Failed / Expired</span>
            <span class="admin-kpi-icon"><?= icon('alert', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= $failedCount ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Unpaid sessions</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-cyan">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Avg Ticket Size</span>
            <span class="admin-kpi-icon"><?= icon('trending', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= money($avgTicket) ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Per settled order</span>
        </div>
    </div>
</div>

<!-- Transactions Master Table Card -->
<div class="card card-pad-0">
    <div class="orders-master-filter-card" style="padding:16px 20px;border-bottom:1px solid var(--color-border);">
        <!-- Status Tabs Bar & Export Action -->
        <div class="orders-tabs-export-row" style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
            <div class="orders-status-tabs-scroll" style="display:flex;gap:8px;flex-wrap:wrap;">
                <?php
                $tabs = [
                    ''        => 'All Transactions',
                    'success' => 'Settled (' . $successCount . ')',
                    'pending' => 'Pending (' . $pendingCount . ')',
                    'expired' => 'Expired',
                    'failed'  => 'Failed'
                ];
                foreach ($tabs as $sKey => $label): ?>
                    <a class="btn btn-sm <?= $status === $sKey ? 'btn-primary' : 'btn-secondary' ?> order-tab-btn" href="?<?= e($qs(['status' => $sKey, 'page' => 1])) ?>">
                        <?= e($label) ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <a class="btn btn-secondary btn-sm flex items-center gap-1 orders-export-btn" href="?<?= e($qs(['export' => 'csv'])) ?>">
                <?= icon('download', 14) ?> Export CSV
            </a>
        </div>

        <!-- Filter Row -->
        <form method="get" class="orders-filter-form flex items-center gap-2" style="flex-wrap:wrap;">
            <input type="hidden" name="status" value="<?= e($status) ?>">

            <div class="orders-search-wrap" style="position:relative;flex:1;min-width:240px;max-width:360px;">
                <input class="form-input" type="search" name="q" placeholder="Search Order ID, Customer, UTR, Merchant..." value="<?= e($search) ?>" style="height:38px;padding-left:34px;">
                <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--color-ink-mute);pointer-events:none;">
                    <?= icon('search', 15) ?>
                </span>
            </div>

            <select class="form-select orders-merchant-select" name="merchant" style="height:38px;width:auto;min-width:180px;">
                <option value="">All Merchants</option>
                <?php foreach ($merchantsList as $m): ?>
                    <option value="<?= (int) $m['id'] ?>" <?= $merchant === (int) $m['id'] ? 'selected' : '' ?>>
                        <?= e($m['name']) ?> (<?= e($m['email']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <button class="btn btn-secondary btn-sm flex items-center gap-1 orders-submit-btn" type="submit">
                <?= icon('search', 14) ?> Filter
            </button>

            <?php if ($search !== '' || $merchant > 0 || $status !== ''): ?>
                <a class="btn btn-ghost btn-sm orders-reset-btn" href="<?= url('admin/orders.php') ?>">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (!$rows): ?>
        <div class="empty-state" style="padding:60px 20px;">
            <div class="empty-icon"><?= icon('receipt', 28) ?></div>
            <p class="empty-state-title">No transactions found</p>
            <p class="empty-state-sub">No payments match your current query or filters.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Order Identifier</th>
                        <th>Merchant</th>
                        <th>Customer</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Bank UTR</th>
                        <th>Created Date</th>
                        <th style="text-align:right;">Control Actions</th>
                    </tr>
                </thead>
                <tbody id="ordersTbody">
                    <?php foreach ($rows as $o): include __DIR__ . '/includes/order-row.php'; endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="load-more-bar" id="ordersLoadBar"
             data-next-page="<?= $page + 1 ?>"
             data-total-pages="<?= $totalPages ?>"
             data-total-records="<?= $totalRecords ?>"
             data-per-page="<?= $perPage ?>"
             data-load-url="<?= e(url('admin/orders-load.php')) ?>">
            <span class="load-more-count" id="ordersLoadCount">
                Showing <strong><?= count($rows) ?></strong> of <strong><?= $totalRecords ?></strong> transactions
            </span>
            <div class="load-more-actions">
                <select class="form-select load-more-pagesize" style="width:auto;height:34px;font-size:13px;" onchange="location=this.value" title="Rows per page">
                    <option value="?<?= e($qs(['per_page' => '25', 'page' => 1])) ?>" <?= $perPage === 25 ? 'selected' : '' ?>>25 / page</option>
                    <option value="?<?= e($qs(['per_page' => '50', 'page' => 1])) ?>" <?= $perPage === 50 ? 'selected' : '' ?>>50 / page</option>
                    <option value="?<?= e($qs(['per_page' => '100', 'page' => 1])) ?>" <?= $perPage === 100 ? 'selected' : '' ?>>100 / page</option>
                </select>
                <span class="load-more-indicator" id="ordersLoadIndicator">
                    <span class="load-more-spinner"></span>
                    Loading more transactions…
                </span>
                <span class="load-more-error" id="ordersLoadError">
                    <?= icon('alert', 13) ?> Could not load more transactions.
                </span>
                <button type="button" class="btn btn-secondary btn-sm load-more-btn" id="ordersLoadMore">Load more</button>
                <span class="load-more-end" id="ordersLoadEnd">All <?= $totalRecords ?> transactions loaded</span>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal: Quick Reconcile Order -->
<div id="modal-orders-reconcile" class="admin-modal-overlay">
    <div class="admin-modal-dialog">
        <div class="admin-modal-header">
            <div class="admin-modal-title flex items-center gap-2">
                <?= icon('check-circle', 18) ?> Force Reconcile Order
            </div>
            <button type="button" class="admin-modal-close" onclick="closeAdminModal('modal-orders-reconcile')">✕</button>
        </div>
        <form method="post">
            <div class="admin-modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reconcile">
                <input type="hidden" name="return" value="<?= e($return) ?>">
                <input type="hidden" id="orders-reconcile-id" name="order_id" value="">

                <p id="orders-reconcile-info" class="mb-3" style="font-size:13.5px;color:var(--color-ink-base);"></p>

                <div class="form-group mb-3">
                    <label class="form-label">Bank UTR / Transaction Reference Number</label>
                    <input class="form-input mono" type="text" name="utr" placeholder="e.g. 415922837103" required>
                    <p class="form-help">This UTR will be stamped on the order and sent via webhook to the merchant.</p>
                </div>
            </div>
            <div class="admin-modal-footer">
                <button type="button" class="btn btn-ghost btn-sm" onclick="closeAdminModal('modal-orders-reconcile')">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm flex items-center gap-2">
                    <?= icon('check-circle', 14) ?> Mark Settled & Dispatch Webhook
                </button>
            </div>
        </form>
    </div>
</div>


<?php include __DIR__ . '/includes/footer.php'; ?>
