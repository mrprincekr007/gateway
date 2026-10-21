<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';
require_once __DIR__ . '/includes/admin.php';

$user   = require_admin();
$active = 'merchants';

$error = null;

/* ---------- POST Actions ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verify_csrf(post('csrf_token'))) {
        $error = 'Session expired. Please try again.';
    } else {
        $action = post('action');
        $id     = (int) post('id');
        $err    = null;

        if ($action === 'create_user') {
            $name     = post('name');
            $email    = post('email');
            $password = post('password');
            $upiId    = post('upi_id');
            $role     = post('role') === 'admin' ? 'admin' : 'user';

            $res = admin_create_user($name, $email, $password, $upiId, $role);
            if (is_string($res)) {
                $error = $res;
            } elseif (isset($res['error'])) {
                $error = $res['error'];
            } else {
                flash_set('success', 'Merchant account created successfully: ' . $email);
                redirect('admin/users.php');
            }
        } elseif ($action === 'status') {
            $err = admin_set_status($id, post('value'), (int) $user['id']);
        } elseif ($action === 'role') {
            $err = admin_set_role($id, post('value'), (int) $user['id']);
        } elseif ($action === 'delete') {
            $err = admin_delete_user($id, (int) $user['id']);
        }

        if ($err) {
            $error = $err;
        } elseif ($action !== 'create_user') {
            flash_set('success', 'Merchant record updated.');
            redirect('admin/users.php' . (post('return') !== '' ? '?' . post('return') : ''));
        }
    }
}

/* ---------- Global Metrics for Top KPI ---------- */
$mTotals = db()->query("
    SELECT 
        COUNT(*) AS total_count,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_count,
        SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) AS suspended_count,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) AS admin_count,
        SUM(CASE WHEN last_activity_at >= (NOW() - INTERVAL 5 MINUTE) THEN 1 ELSE 0 END) AS online_count
    FROM users
")->fetch();

$volTotal = (float) db()->query("SELECT COALESCE(SUM(payable_amount), 0) FROM orders WHERE status = 'success'")->fetchColumn();

/* ---------- Filters & Pagination ---------- */
$q       = trim(get('q'));
$status  = get('status');
$role    = get('role');
$perPage = 25;
$page    = max(1, (int) get('page', '1'));

$where  = '1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (u.name LIKE :sq1 OR u.email LIKE :sq2 OR u.merchant_id LIKE :sq3 OR u.upi_id LIKE :sq4 OR u.phone LIKE :sq5 OR u.business_name LIKE :sq6)';
    $like = "%{$q}%";
    for ($i = 1; $i <= 6; $i++) { $params[":sq{$i}"] = $like; }
}
if (in_array($status, ['active', 'suspended'], true)) { 
    $where .= ' AND u.status = :status'; 
    $params[':status'] = $status; 
}
if (in_array($role, ['user', 'admin'], true)) { 
    $where .= ' AND u.role = :role';     
    $params[':role'] = $role; 
}

$countStmt = db()->prepare("SELECT COUNT(*) FROM users u WHERE {$where}");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages   = max(1, (int) ceil($totalRecords / $perPage));
$page         = min($page, $totalPages);
$offset       = ($page - 1) * $perPage;

$rowsStmt = db()->prepare("
    SELECT u.*, 
           COUNT(o.id) AS total_orders,
           COALESCE(SUM(CASE WHEN o.status = 'success' THEN 1 ELSE 0 END), 0) AS success_orders,
           COALESCE(SUM(CASE WHEN o.status = 'success' THEN o.payable_amount END), 0) AS collected
    FROM users u 
    LEFT JOIN orders o ON o.user_id = u.id
    WHERE {$where}
    GROUP BY u.id
    ORDER BY u.created_at DESC
    LIMIT :limit OFFSET :offset");
$rowsStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$rowsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $k => $v) { $rowsStmt->bindValue($k, $v); }
$rowsStmt->execute();
$rows = $rowsStmt->fetchAll();

$qs = function (array $overrides = []) use ($q, $status, $role, $page): string {
    $p = ['q' => $q, 'status' => $status, 'role' => $role, 'page' => $page];
    foreach ($overrides as $k => $v) { $p[$k] = $v; }
    return http_build_query(array_filter($p, fn ($v) => $v !== '' && $v !== null));
};
$return = $qs();

$pageTitle   = 'Merchants Directory';
$pageHeading = 'Merchants & Users Management';
$pageSub     = 'Complete administrative control over all registered merchant accounts and credentials.';
$topbarActions = '<button class="btn btn-primary btn-sm flex items-center gap-2" onclick="openAdminModal(\'modal-create-user\')">' . icon('user', 14) . ' New Merchant</button>';
include __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-error mb-4"><?= icon('alert', 18) ?><span><?= e($error) ?></span></div>
<?php endif; ?>
<?php if ($f = flash_get()): ?>
    <div class="alert alert-success mb-4"><?= icon('check-circle', 18) ?><span><?= e($f['message']) ?></span></div>
<?php endif; ?>

<!-- 1. Ambient Hero Stage & Quick Actions Strip -->
<div class="merchants-hero-stage mb-4">
    <div class="flex items-center justify-between" style="flex-wrap:wrap;gap:16px;">
        <div style="flex:1;min-width:280px;">
            <div class="merchants-hero-badge">
                <?= icon('users', 13) ?>
                <span>MERCHANTS DIRECTORY &amp; DIRECT AUTH</span>
            </div>
            <h1 class="merchants-hero-title">Merchants &amp; Accounts Master</h1>
            <p class="merchants-hero-sub">
                Complete administrative control over all registered merchant accounts, live UPI configurations, settlements, and 1-click impersonation login.
            </p>
            <div class="flex items-center gap-2" style="flex-wrap:wrap;">
                <span class="badge badge-success">
                    <?= icon('check-circle', 12) ?> <?= (int) ($mTotals['active_count'] ?? 0) ?> Active Merchants
                </span>
                <span class="badge <?= (int) ($mTotals['suspended_count'] ?? 0) > 0 ? 'badge-rose' : 'badge-neutral' ?>">
                    <?= icon('shield', 12) ?> <?= (int) ($mTotals['suspended_count'] ?? 0) ?> Suspended
                </span>
                <span class="badge badge-purple">
                    <?= icon('key', 12) ?> <?= (int) ($mTotals['admin_count'] ?? 0) ?> Administrators
                </span>
                <span class="badge badge-cyan">
                    <?= icon('wallet', 12) ?> <?= money($volTotal) ?> Platform Volume
                </span>
            </div>
        </div>

        <div class="merchants-hero-actions">
            <button type="button" class="btn btn-primary" onclick="openAdminModal('modal-create-user')">
                <?= icon('user', 14) ?> Add New Merchant
            </button>
            <a href="<?= url('admin/subscriptions.php') ?>" class="btn btn-secondary" title="View Subscribers & Subscription Plans">
                <?= icon('users', 14) ?> Subscribers Hub
            </a>
        </div>
    </div>
</div>

<!-- 2. Top Merchant Stats Cards (Ambient Aura) -->
<div class="admin-kpi-grid mb-4">
    <div class="admin-kpi-card tone-indigo">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Total Accounts</span>
            <span class="admin-kpi-icon"><?= icon('user', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= (int) ($mTotals['total_count'] ?? 0) ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub"><?= (int) ($mTotals['active_count'] ?? 0) ?> Active · <?= (int) ($mTotals['suspended_count'] ?? 0) ?> Suspended</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-emerald">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Active Merchants</span>
            <span class="admin-kpi-icon"><?= icon('check-circle', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= (int) ($mTotals['active_count'] ?? 0) ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Ready to process UPI</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-rose">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Suspended</span>
            <span class="admin-kpi-icon"><?= icon('shield', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= (int) ($mTotals['suspended_count'] ?? 0) ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Blocked from payments</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-purple">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Administrators</span>
            <span class="admin-kpi-icon"><?= icon('key', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= (int) ($mTotals['admin_count'] ?? 0) ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Console root access</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-cyan">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Platform Volume</span>
            <span class="admin-kpi-icon"><?= icon('wallet', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= money($volTotal) ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Combined settlements</span>
        </div>
    </div>
</div>

<!-- 3. Dedicated Standalone Filter Box (Buttons Left, Search Box Right) -->
<div class="sub-filter-topbar-box mb-4">
    <div class="sub-plan-pills-scroll" role="tablist">
        <button type="button" class="sub-plan-pill-btn active" data-filter-status="all" onclick="filterMerchantsByStatus('all', this)">
            <span class="pill-icon"><?= icon('users', 14) ?></span>
            <span class="pill-title">All Merchants</span>
            <span class="pill-counter"><?= (int) ($mTotals['total_count'] ?? 0) ?></span>
        </button>

        <button type="button" class="sub-plan-pill-btn" data-filter-status="active" onclick="filterMerchantsByStatus('active', this)">
            <span class="pill-icon" style="color:#10b981;"><?= icon('check-circle', 14) ?></span>
            <span class="pill-title">Active</span>
            <span class="pill-counter highlight"><?= (int) ($mTotals['active_count'] ?? 0) ?></span>
        </button>

        <button type="button" class="sub-plan-pill-btn" data-filter-status="online" onclick="filterMerchantsByStatus('online', this)">
            <span class="pill-icon" style="color:#10b981;"><?= icon('activity', 14) ?></span>
            <span class="pill-title">Online Now</span>
            <span class="pill-counter" style="background:rgba(16,185,129,0.15);color:#10b981;font-weight:700;"><?= (int) ($mTotals['online_count'] ?? 0) ?></span>
        </button>

        <button type="button" class="sub-plan-pill-btn" data-filter-status="suspended" onclick="filterMerchantsByStatus('suspended', this)">
            <span class="pill-icon" style="color:#f43f5e;"><?= icon('shield', 14) ?></span>
            <span class="pill-title">Suspended</span>
            <span class="pill-counter"><?= (int) ($mTotals['suspended_count'] ?? 0) ?></span>
        </button>

        <button type="button" class="sub-plan-pill-btn" data-filter-status="admin" onclick="filterMerchantsByStatus('admin', this)">
            <span class="pill-icon" style="color:#c084fc;"><?= icon('key', 14) ?></span>
            <span class="pill-title">Admins</span>
            <span class="pill-counter"><?= (int) ($mTotals['admin_count'] ?? 0) ?></span>
        </button>
    </div>

    <!-- Right-aligned Search Form -->
    <div class="sub-plan-search-wrap">
        <form method="get" class="flex items-center gap-2" style="margin:0;">
            <div style="position:relative;display:flex;align-items:center;">
                <input type="text" id="merchantSearchInput" name="q" class="form-input" placeholder="Search by UID, Name, Email, UPI, ID..." value="<?= e($q) ?>" oninput="liveSearchMerchants(this.value)" style="height:38px;width:290px;font-size:12.5px;padding-left:34px;padding-right:12px;">
                <span style="position:absolute;left:11px;color:var(--color-ink-mute);pointer-events:none;display:flex;align-items:center;">
                    <?= icon('search', 14) ?>
                </span>
            </div>
            <?php if ($q !== '' || $status !== '' || $role !== ''): ?>
                <a href="<?= url('admin/users.php') ?>" class="btn btn-ghost btn-sm" title="Clear all filters">Reset</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- 4. Main Merchant Directory Card -->
<div class="card card-pad-0 mb-4">
    <div class="card-header flex items-center justify-between" style="flex-wrap:wrap;gap:12px;">
        <div>
            <h3 class="card-title flex items-center gap-2">
                <span style="color:var(--color-primary);"><?= icon('users', 18) ?></span>
                Registered Merchants
            </h3>
            <div class="card-sub">Real-time status of merchant profiles, VPA routing, orders settled, and account privileges.</div>
        </div>

        <div class="flex items-center gap-2" style="flex-wrap:wrap;">
            <span class="badge badge-cyan" id="visibleMerchantsCount" style="font-size:11.5px;padding:4px 10px;">
                <?= count($rows) ?> Merchants
            </span>
            <button type="button" class="btn btn-primary btn-sm flex items-center gap-1" onclick="openAdminModal('modal-create-user')">
                <?= icon('user', 14) ?> Add Merchant
            </button>
        </div>
    </div>

    <?php if (!$rows): ?>
        <div class="empty-state" style="padding:60px 20px;">
            <div class="empty-icon"><?= icon('user', 28) ?></div>
            <p class="empty-state-title">No merchants match your criteria</p>
            <p class="empty-state-sub">Try adjusting search filters or register a new merchant.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table" id="merchantsTable">
                <thead>
                    <tr>
                        <th>Merchant Profile</th>
                        <th>Identifier &amp; UPI</th>
                        <th>Volume &amp; Orders</th>
                        <th>Status</th>
                        <th>Access</th>
                        <th>Registered</th>
                        <th style="text-align:right;width:64px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="merchantsTbody">
                    <?php foreach ($rows as $u): include __DIR__ . '/includes/merchant-row.php'; endforeach; ?>
                    <tr id="noMerchantsMatchRow" style="display:none;">
                        <td colspan="7" style="padding:40px 20px;text-align:center;">
                            <div class="text-mute" style="font-size:14px;">No merchants match your instant search filter.</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="merchant-load-bar" id="merchantLoadBar"
             data-next-page="<?= $page + 1 ?>"
             data-total-pages="<?= $totalPages ?>"
             data-total-records="<?= $totalRecords ?>"
             data-per-page="<?= $perPage ?>"
             data-load-url="<?= e(url('admin/users-load.php')) ?>">
            <span class="merchant-load-count" id="merchantLoadCount">
                Showing <strong><?= count($rows) ?></strong> of <strong><?= $totalRecords ?></strong> merchants
            </span>
            <div class="merchant-load-actions">
                <span class="merchant-load-indicator" id="merchantLoadIndicator">
                    <span class="merchant-load-spinner"></span>
                    Loading more merchants…
                </span>
                <span class="merchant-load-error" id="merchantLoadError">
                    <?= icon('alert', 13) ?> Could not load more merchants.
                </span>
                <button type="button" class="btn btn-secondary btn-sm merchant-load-more" id="merchantLoadMore">Load more</button>
                <span class="merchant-load-end" id="merchantLoadEnd">All <?= $totalRecords ?> merchants loaded</span>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// Live Status and Instant Search Filter
var currentMerchantStatusFilter = 'all';

function filterMerchantsByStatus(status, btn) {
    currentMerchantStatusFilter = status;
    document.querySelectorAll('.sub-plan-pill-btn[data-filter-status]').forEach(function(b) {
        b.classList.remove('active');
    });
    if (btn) btn.classList.add('active');
    applyMerchantFilters();
}

function liveSearchMerchants(query) {
    applyMerchantFilters();
}

function applyMerchantFilters() {
    var query = (document.getElementById('merchantSearchInput') ? document.getElementById('merchantSearchInput').value : '').toLowerCase().trim();
    var rows = document.querySelectorAll('#merchantsTable tbody tr:not(#noMerchantsMatchRow)');
    var visible = 0;

    rows.forEach(function(r) {
        var status = (r.getAttribute('data-status') || '').toLowerCase();
        var role = (r.getAttribute('data-role') || '').toLowerCase();
        var text = (r.getAttribute('data-search') || r.textContent).toLowerCase();

        var matchStatus = true;
        if (currentMerchantStatusFilter === 'active') {
            matchStatus = (status === 'active');
        } else if (currentMerchantStatusFilter === 'online') {
            matchStatus = (r.getAttribute('data-online') === '1');
        } else if (currentMerchantStatusFilter === 'suspended') {
            matchStatus = (status === 'suspended');
        } else if (currentMerchantStatusFilter === 'admin') {
            matchStatus = (role === 'admin');
        }

        var matchSearch = (query === '' || text.indexOf(query) !== -1);

        if (matchStatus && matchSearch) {
            r.style.display = '';
            visible++;
        } else {
            r.style.display = 'none';
        }
    });

    var emptyRow = document.getElementById('noMerchantsMatchRow');
    if (emptyRow) {
        emptyRow.style.display = (visible === 0) ? '' : 'none';
    }

    var countBadge = document.getElementById('visibleMerchantsCount');
    if (countBadge) {
        countBadge.textContent = visible + ' ' + (visible === 1 ? 'Merchant' : 'Merchants');
    }
}

// 1-Click Clipboard Copy
function copyMerchantId(text, el) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text);
    } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    }
    var original = el.innerHTML;
    el.innerHTML = '<span class="chip-text mono" style="color:#10b981;">Copied!</span>';
    setTimeout(function() {
        el.innerHTML = original;
    }, 1400);
}

// 3-Dot Action Menu Logic
window.toggleMerchantActionMenu = function(e, id, btn) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    var menu = document.getElementById('merchantActionMenu_' + id);
    if (!menu) return;

    var wasOpen = menu.classList.contains('is-open');
    window.closeAllMerchantMenus();

    if (!wasOpen) {
        menu.classList.add('is-open');
        if (btn) {
            btn.classList.add('is-active');
            var tr = btn.closest('tr');
            var td = btn.closest('td');
            var wrap = btn.closest('.merchant-dots-menu-wrap');
            if (tr) tr.classList.add('menu-open');
            if (td) td.classList.add('menu-open');
            if (wrap) wrap.classList.add('is-open');

            // Dynamic direction: check if bottom of screen is near
            var rect = btn.getBoundingClientRect();
            var spaceBelow = window.innerHeight - rect.bottom;
            if (spaceBelow < 230 && rect.top > 230) {
                menu.classList.add('open-upward');
            } else {
                menu.classList.remove('open-upward');
            }
        }
    }
};

window.closeAllMerchantMenus = function() {
    document.querySelectorAll('.merchant-action-dropdown.is-open').forEach(function(m) {
        m.classList.remove('is-open');
        m.classList.remove('open-upward');
    });
    document.querySelectorAll('.btn-dots-action.is-active').forEach(function(b) {
        b.classList.remove('is-active');
    });
    document.querySelectorAll('tr.menu-open, td.menu-open, .merchant-dots-menu-wrap.is-open').forEach(function(el) {
        el.classList.remove('menu-open');
        el.classList.remove('is-open');
    });
};

// Close when clicking outside the entire wrapper or clicking an action item
document.addEventListener('click', function(e) {
    if (e.target.closest('.action-dropdown-item')) {
        setTimeout(window.closeAllMerchantMenus, 100);
        return;
    }
    if (!e.target.closest('.merchant-dots-menu-wrap')) {
        window.closeAllMerchantMenus();
    }
});

// ESC key closes any open dropdown
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        window.closeAllMerchantMenus();
    }
});
</script>

<!-- Modal: Create New Merchant Account -->
<div id="modal-create-user" class="admin-modal-overlay">
    <div class="admin-modal-dialog">
        <div class="admin-modal-header">
            <div class="admin-modal-title flex items-center gap-2">
                <?= icon('user', 18) ?> Register New Merchant
            </div>
            <button type="button" class="admin-modal-close" onclick="closeAdminModal('modal-create-user')">✕</button>
        </div>
        <form method="post">
            <div class="admin-modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_user">

                <div class="form-group mb-3">
                    <label class="form-label">Full Name / Business Name</label>
                    <input class="form-input" type="text" name="name" placeholder="e.g. Acme Tech Solutions" required>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Business Email Address</label>
                    <input class="form-input" type="email" name="email" placeholder="merchant@acme.com" required>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Password</label>
                    <input class="form-input" type="password" name="password" placeholder="At least 8 characters" required minlength="8">
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Default UPI ID (VPA)</label>
                    <input class="form-input mono" type="text" name="upi_id" placeholder="acme@okaxis">
                    <p class="form-help">Payments for this merchant will be routed to this VPA by default.</p>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Account Role</label>
                    <select class="form-select" name="role">
                        <option value="user" selected>Merchant (Standard Dashboard)</option>
                        <option value="admin">Administrator (Full Admin Access)</option>
                    </select>
                </div>
            </div>
            <div class="admin-modal-footer">
                <button type="button" class="btn btn-ghost btn-sm" onclick="closeAdminModal('modal-create-user')">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm flex items-center gap-2">
                    <?= icon('check-circle', 14) ?> Create Account
                </button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
