<?php
declare(strict_types=1);

/* =========================================================
   FatherGateway — Dashboard Lazy-Loaded Boxes
   Each box below renders its own data and card markup so that
   admin/dashboard-box.php can stream a single box at a time
   (with a skeleton loader shown inside the box meanwhile).
   ========================================================= */

/** Echo the skeleton placeholder for a lazily loaded dashboard box. */
function dash_box_placeholder(string $box): void
{
    $endpoint = url('admin/dashboard-box.php');
    ?>
    <div class="dash-box is-loading" data-dash-box="<?= e($box) ?>" data-dash-endpoint="<?= e($endpoint) ?>">
        <div class="dash-sk" aria-hidden="true">
            <div class="dash-sk-head">
                <span class="dash-sk-line" style="width:38%;height:14px;"></span>
                <span class="dash-sk-line" style="width:12%;height:22px;border-radius:999px;"></span>
            </div>
            <div class="dash-sk-body">
                <?php for ($i = 0; $i < 5; $i++): ?>
                <div class="dash-sk-row">
                    <span class="dash-sk-avatar"></span>
                    <span class="dash-sk-line" style="width:46%;"></span>
                    <span class="dash-sk-line" style="width:16%;margin-left:auto;"></span>
                </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>
    <?php
}

/** Box: Real-Time Platform Transactions (full-width master card). */
function dash_render_transactions(): void
{
    $os = db()->query('
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END), 0) AS success_count,
            COALESCE(SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END), 0) AS pending_count,
            COALESCE(SUM(CASE WHEN status = "success" THEN payable_amount END), 0) AS collected
        FROM orders
    ')->fetch();

    $totalOrders  = (int) $os['total'];
    $totalSuccess = (int) $os['success_count'];
    $totalPending = (int) $os['pending_count'];
    $collected    = (float) $os['collected'];

    $recent = db()->query('
        SELECT o.id, o.order_id, o.customer_name, o.payable_amount, o.status, o.utr, o.created_at, o.paid_at, o.link_id,
               u.id AS merchant_user_id, u.name AS merchant_name, u.email AS merchant_email
        FROM orders o JOIN users u ON u.id = o.user_id
        ORDER BY o.created_at DESC LIMIT 12
    ')->fetchAll();

    $inFlightOrders = db()->query('
        SELECT o.id, o.order_id, o.payable_amount, o.created_at, o.customer_name,
               u.name AS merchant_name, u.id AS merchant_user_id
        FROM orders o JOIN users u ON u.id = o.user_id
        WHERE o.status = "pending"
        ORDER BY o.created_at DESC
        LIMIT 5
    ')->fetchAll();
    ?>
    <div class="card-header flex items-center justify-between" style="flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <h3 class="card-title flex items-center gap-2" style="font-size:16px;margin:0;">
                <span style="color:#2563eb;"><?= icon('receipt', 19) ?></span>
                Real-Time Platform Transactions
            </h3>
            <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:999px;background:rgba(16,185,129,0.12);color:#10b981;font-size:11px;font-weight:700;">
                <span style="width:6px;height:6px;border-radius:50%;background:#10b981;box-shadow:0 0 6px #10b981;"></span>
                Live Stream
            </span>
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <!-- Live Search Bar -->
            <div class="tx-search-wrap">
                <span class="tx-search-icon"><?= icon('search', 13) ?></span>
                <input type="search" id="txSearchInput" class="tx-search-input" placeholder="Search ID, UTR, Merchant, Customer..." oninput="searchTxTable(this.value)">
            </div>
            <!-- Filter Pills -->
            <div class="filter-pills-bar">
                <button type="button" class="filter-pill-btn active" onclick="filterTxTable('all', this)">All</button>
                <button type="button" class="filter-pill-btn" onclick="filterTxTable('pending', this)">Pending</button>
                <button type="button" class="filter-pill-btn" onclick="filterTxTable('success', this)">Success</button>
                <button type="button" class="filter-pill-btn" onclick="filterTxTable('expired', this)">Expired</button>
            </div>
            <!-- Export CSV -->
            <button type="button" class="btn btn-secondary btn-sm" onclick="exportTxToCSV()" title="Export Visible Orders to CSV">
                <?= icon('download', 13) ?> CSV
            </button>
            <a class="btn btn-secondary btn-sm" href="<?= url('admin/orders.php') ?>">All (<?= $totalOrders ?>) &rarr;</a>
        </div>
    </div>

    <!-- Quick Table Statistics Telemetry Ribbon -->
    <div style="padding:10px 24px;background:var(--surface-sunken,#f8fafc);border-bottom:1px solid var(--color-hairline-2);display:flex;align-items:center;justify-content:space-between;font-size:11.5px;color:var(--color-ink-mute);flex-wrap:wrap;gap:8px;">
        <div>
            <span style="font-weight:700;font-size:12.5px;color:var(--color-ink);" id="txCounterLabel">Showing <?= count($recent) ?> Recent Orders</span> &middot;
            <span>Captured: <b style="color:#10b981;"><?= $totalSuccess ?></b></span> &middot;
            <span>In-Flight: <b style="color:#f59e0b;"><?= $totalPending ?></b></span> &middot;
            <span>Platform Volume: <b style="color:#2563eb;"><?= money($collected) ?></b></span>
        </div>
        <div style="display:flex;align-items:center;gap:6px;font-size:11px;">
            <span style="width:6px;height:6px;border-radius:50%;background:#10b981;"></span>
            <span>Bank Reconciliation Active &middot; Instant IMPS / UPI Matching</span>
        </div>
    </div>

    <!-- In-Flight Urgent Reconciliation Alert Ribbon -->
    <?php if (!empty($inFlightOrders)): ?>
    <div style="margin:14px 20px 0;padding:12px 16px;background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);border-radius:10px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:#d97706;font-weight:600;">
            <?= icon('clock', 16) ?>
            <span><b><?= count($inFlightOrders) ?> In-Flight Orders</b> awaiting bank UTR confirmation.</span>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" onclick="openAdminModal('quickReconcileModal')">
            Reconcile Any &rarr;
        </button>
    </div>
    <?php endif; ?>

    <?php if (!$recent): ?>
        <div class="empty-state" style="padding:48px 20px;">
            <div class="empty-icon"><?= icon('receipt', 32) ?></div>
            <p class="empty-state-title">No transactions recorded yet</p>
            <p class="empty-state-sub">New orders created via API or payment links will appear live here in real-time.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table" id="adminRecentTxTable">
                <thead>
                    <tr>
                        <th style="padding-left:24px;">Order ID &amp; Channel</th>
                        <th>Merchant Account</th>
                        <th>Customer &amp; Date</th>
                        <th>Payable Amount</th>
                        <th>Order Status</th>
                        <th style="text-align:right;padding-right:24px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $o): ?>
                    <tr data-status="<?= e((string) $o['status']) ?>">
                        <td style="padding-left:24px;">
                            <div style="display:flex;align-items:center;gap:7px;">
                                <a href="<?= url('admin/order.php?id=' . urlencode($o['order_id'])) ?>" class="mono-plain" style="font-weight:700;color:var(--ink-brand);font-size:13px;">
                                    <?= e($o['order_id']) ?>
                                </a>
                                <?php if ($o['link_id']): ?>
                                    <span class="meta-tag" title="Created via Hosted Payment Link">Link</span>
                                <?php else: ?>
                                    <span class="meta-tag" title="Created via Direct Merchant API">API</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($o['utr']): ?>
                                <div style="font-size:11px;color:var(--color-ink-mute);display:flex;align-items:center;gap:4px;margin-top:3px;">
                                    <span>UTR:</span>
                                    <span class="mono" style="font-weight:600;"><?= e($o['utr']) ?></span>
                                    <button type="button" class="btn-icon" data-copy="<?= e($o['utr']) ?>" title="Copy UTR" style="padding:2px;cursor:pointer;">
                                        <?= icon('copy', 11) ?>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div class="avatar avatar-xs" style="width:26px;height:26px;border-radius:8px;font-size:11px;background:linear-gradient(135deg,#2563eb,#38bdf8);flex-shrink:0;overflow:hidden;display:flex;align-items:center;justify-content:center;color:#ffffff;font-weight:700;">
                                    <?= e(initials($o['merchant_name'])) ?>
                                </div>
                                <div>
                                    <a href="<?= url('admin/user.php?id=' . (int) $o['merchant_user_id']) ?>" class="table-link-merchant" style="font-weight:600;text-decoration:none;font-size:13px;">
                                        <?= e($o['merchant_name']) ?>
                                    </a>
                                    <div style="font-size:11px;color:var(--color-ink-mute);"><?= e($o['merchant_email']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div style="font-weight:600;color:var(--color-ink);font-size:13px;"><?= e($o['customer_name'] ?: 'Direct Customer') ?></div>
                            <div class="text-mute" style="font-size:11px;margin-top:1px;">
                                <?= e(time_ago($o['created_at'])) ?> &middot; <?= e(nice_date($o['created_at'])) ?>
                            </div>
                        </td>
                        <td class="tnum" style="font-weight:700;font-size:14px;color:var(--color-ink);font-family:var(--font-display);">
                            <?= money((float) $o['payable_amount']) ?>
                        </td>
                        <td>
                            <?= status_badge((string) $o['status']) ?>
                        </td>
                        <td style="text-align:right;padding-right:24px;">
                            <div style="display:inline-flex;gap:5px;">
                                <a href="<?= url('admin/order.php?id=' . urlencode($o['order_id'])) ?>" class="btn btn-secondary btn-sm" title="Inspect Full Order Telemetry">
                                    <?= icon('eye', 13) ?> Inspect
                                </a>
                                <button type="button" class="btn btn-success btn-sm" onclick="openReconcileModalWith('<?= e($o['order_id']) ?>')" title="Mark Order Settled"<?= $o['status'] === 'pending' ? '' : ' disabled aria-hidden="true" tabindex="-1"' ?>>
                                    <?= icon('check', 13) ?> Settle
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="padding:12px 24px;background:var(--surface-sunken,#f8fafc);border-top:1px solid var(--color-hairline);display:flex;align-items:center;justify-content:space-between;font-size:12px;color:var(--color-ink-mute);flex-wrap:wrap;gap:8px;">
            <span>Showing 12 most recent platform transactions &middot; Real-time database sync</span>
            <a href="<?= url('admin/orders.php') ?>" style="color:var(--color-primary);font-weight:600;text-decoration:none;">Open Full Orders Ledger (<?= $totalOrders ?>) &rarr;</a>
        </div>
    <?php endif; ?>
    <?php
}

/** Box: Top Merchants Leaderboard. */
function dash_render_leaderboard(): void
{
    $merchants = (int) db()->query('SELECT COUNT(*) FROM users WHERE role = "user"')->fetchColumn();
    $collected = (float) db()->query('SELECT COALESCE(SUM(payable_amount), 0) FROM orders WHERE status = "success"')->fetchColumn();

    $topMerchants = db()->query('
        SELECT u.id, u.name, u.email, u.status, u.avatar, u.merchant_id,
               COUNT(o.id) AS orders,
               COALESCE(SUM(CASE WHEN o.status = "success" THEN o.payable_amount END), 0) AS collected,
               COALESCE(SUM(CASE WHEN o.status = "success" THEN 1 END), 0) AS settled_count
        FROM users u LEFT JOIN orders o ON o.user_id = u.id
        WHERE u.role = "user"
        GROUP BY u.id
        ORDER BY collected DESC, orders DESC
        LIMIT 6
    ')->fetchAll();
    ?>
    <div class="card-header flex items-center justify-between">
        <h3 class="card-title flex items-center gap-2" style="font-size:15px;margin:0;">
            <span style="color:#f59e0b;"><?= icon('trending', 17) ?></span>
            Top Merchants Leaderboard
        </h3>
        <div class="flex items-center gap-2">
            <span class="badge badge-amber" style="font-size:10.5px;">Top 5 Volume</span>
            <a class="btn btn-secondary btn-xs" href="<?= url('admin/users.php') ?>">All (<?= $merchants ?>) &rarr;</a>
        </div>
    </div>

    <!-- Merchant Platform Intelligence Strip -->
    <div class="merchant-intelligence-strip">
        <div class="m-intel-col">
            <span class="m-intel-lbl">Total Registered</span>
            <span class="m-intel-val"><?= $merchants ?> Merchants</span>
        </div>
        <div class="m-intel-sep"></div>
        <div class="m-intel-col">
            <span class="m-intel-lbl">Active Processing</span>
            <span class="m-intel-val" style="color:#10b981;"><?= count(array_filter($topMerchants, fn($m) => (float)$m['collected'] > 0)) ?> Active</span>
        </div>
        <div class="m-intel-sep"></div>
        <div class="m-intel-col">
            <span class="m-intel-lbl">Captured Volume</span>
            <span class="m-intel-val" style="color:#059669;"><?= money($collected) ?></span>
        </div>
    </div>

    <div class="card-body">
        <?php if (!$topMerchants): ?>
            <p class="text-mute" style="font-size:13px;padding:12px;">No merchant accounts registered.</p>
        <?php else: ?>
            <div class="leaderboard-list">
                <?php $rank = 1; foreach (array_slice($topMerchants, 0, 5) as $m): ?>
                <div class="leaderboard-item">
                    <!-- Left: Rank & Avatar & Identity -->
                    <div class="lb-left-cell">
                        <span class="rank-badge rank-<?= $rank <= 3 ? $rank : 'default' ?>">
                            <?= $rank ?>
                        </span>
                        <div class="avatar avatar-sm lb-avatar">
                            <?php if (!empty($m['avatar'])): ?>
                                <img src="<?= e(url($m['avatar'])) ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
                            <?php else: ?>
                                <?= e(initials($m['name'])) ?>
                            <?php endif; ?>
                        </div>
                        <div class="lb-meta-cell">
                            <div class="lb-title-line">
                                <a href="<?= url('admin/user.php?id=' . (int) $m['id']) ?>" class="leaderboard-merchant-name">
                                    <?= e($m['name']) ?>
                                </a>
                                <?php if (!empty($m['merchant_id'])): ?>
                                    <span class="lb-mid-tag mono"><?= e($m['merchant_id']) ?></span>
                                <?php endif; ?>
                                <?php if ((float)$m['collected'] > 0): ?>
                                    <span class="badge badge-green" style="font-size:9.5px;padding:1px 6px;">Active</span>
                                <?php endif; ?>
                            </div>
                            <div class="lb-sub-line">
                                <span><?= (int) $m['settled_count'] ?> captured orders</span>
                                <span class="lb-dot">&middot;</span>
                                <span class="lb-email"><?= e($m['email']) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Volume & Action -->
                    <div class="lb-right-cell">
                        <div class="lb-vol-block">
                            <div class="lb-vol-num">
                                <?= money((float) $m['collected']) ?>
                            </div>
                            <div class="lb-vol-lbl">Gross Volume</div>
                        </div>
                        <div class="lb-action-block">
                            <a href="<?= url('admin/impersonate.php?id=' . (int) $m['id'] . '&csrf_token=' . urlencode(csrf_token())) ?>" class="badge badge-cyan lb-login-btn" title="Login to Merchant Dashboard">
                                <?= icon('zap', 11) ?> Login As
                            </a>
                        </div>
                    </div>
                </div>
                <?php $rank++; endforeach; ?>

                <?php if (count($topMerchants) < 5): ?>
                <!-- Quick Onboard CTA if fewer than 5 merchants -->
                <div class="leaderboard-onboard-cta" onclick="openAdminModal('newMerchantModal')">
                    <span style="display:flex;align-items:center;"><?= icon('plus-circle', 14) ?></span>
                    <span>Onboard &amp; Register New Merchant Account &rarr;</span>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="admin-bottom-card-footer">
        <span>Showing top volume contributors &middot; Real-time settlement calculation</span>
        <a href="<?= url('admin/users.php') ?>" style="color:var(--color-primary);font-weight:600;text-decoration:none;">Manage All Merchants (<?= $merchants ?>) &rarr;</a>
    </div>
    <?php
}

/** Box: Live Security & System Audit Trail. */
function dash_render_audit(): void
{
    $recentAudits = db()->query('
        SELECT a.*, COALESCE(u.name, a.actor_name, "Admin") AS actor_display
        FROM audit_logs a
        LEFT JOIN users u ON u.id = a.actor_id
        ORDER BY a.created_at DESC
        LIMIT 5
    ')->fetchAll();
    ?>
    <div class="card-header flex items-center justify-between">
        <h3 class="card-title flex items-center gap-2" style="font-size:15px;margin:0;">
            <span style="color:#6366f1;"><?= icon('shield', 17) ?></span>
            Security &amp; Audit Trail
        </h3>
        <span class="badge badge-blue" style="font-size:10px;">Live Stream</span>
    </div>

    <div class="card-body">
        <?php if (empty($recentAudits)): ?>
            <p class="text-mute" style="font-size:12px;margin:0;">No audit events recorded yet.</p>
        <?php else: ?>
            <div class="audit-feed-list">
                <?php foreach (array_slice($recentAudits, 0, 5) as $al): ?>
                <div class="audit-feed-item">
                    <div class="audit-feed-icon">
                        <?= icon('activity', 13) ?>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:6px;">
                            <div style="font-weight:700;font-size:12px;color:var(--color-ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <?= e($al['actor_display']) ?>
                            </div>
                            <span class="audit-action-chip mono"><?= e($al['action']) ?></span>
                        </div>
                        <?php if ($al['details']): ?>
                            <div style="font-size:11px;color:var(--color-ink-mute);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:2px;">
                                <?= e($al['details']) ?>
                            </div>
                        <?php endif; ?>
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:6px;font-size:10.5px;color:var(--color-ink-mute);margin-top:4px;">
                            <span><?= e(time_ago($al['created_at'])) ?></span>
                            <?php if ($al['ip']): ?>
                                <span class="mono" style="font-size:10px;background:var(--surface-sunken);padding:1px 5px;border-radius:4px;border:1px solid var(--color-hairline);"><?= e($al['ip']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="admin-bottom-card-footer">
        <span>Audit Trail &middot; Tamper-evident logging</span>
        <a href="<?= url('admin/admins.php') ?>" style="color:var(--color-primary);font-weight:600;text-decoration:none;">Admin Governance &rarr;</a>
    </div>
    <?php
}

/** Box: Live Webhook Deliveries & API Event Stream. */
function dash_render_webhooks(): void
{
    $ws = db()->query('
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END), 0) AS ok,
            COALESCE(SUM(CASE WHEN status != "success" THEN 1 ELSE 0 END), 0) AS failed
        FROM webhook_logs
    ')->fetch();
    $whTotal  = (int) $ws['total'];
    $whOk     = (int) $ws['ok'];
    $whFailed = (int) $ws['failed'];
    $whRate   = $whTotal > 0 ? (int) round($whOk / $whTotal * 100) : 100;

    $recentWebhooks = db()->query('
        SELECT l.*, COALESCE(u.name, "Platform Core") AS merchant_name, u.email AS merchant_email
        FROM webhook_logs l
        LEFT JOIN users u ON u.id = l.user_id
        ORDER BY l.created_at DESC
        LIMIT 5
    ')->fetchAll();
    ?>
    <div class="card-header flex items-center justify-between">
        <h3 class="card-title flex items-center gap-2" style="font-size:15px;margin:0;">
            <span style="color:#10b981;"><?= icon('send', 17) ?></span>
            Webhook Dispatch Stream
        </h3>
        <div class="flex items-center gap-2">
            <span class="badge <?= $whRate >= 90 ? 'badge-green' : 'badge-rose' ?>" style="font-size:10px;display:inline-flex;align-items:center;gap:4px;">
                <span class="subsystem-dot <?= $whRate >= 90 ? 'green' : 'red' ?>" style="width:5px;height:5px;"></span>
                <?= $whRate ?>% SLA <?= $whRate < 90 ? '(Degraded)' : '' ?>
            </span>
            <a class="btn btn-secondary btn-xs" href="<?= url('admin/webhooks.php') ?>">All Logs (<?= $whTotal ?>) &rarr;</a>
        </div>
    </div>

    <div class="card-body">
        <?php if (empty($recentWebhooks)): ?>
            <div style="text-align:center;padding:24px 12px;">
                <p class="text-mute" style="font-size:13px;margin-bottom:8px;">No webhook delivery logs recorded yet.</p>
                <a href="<?= url('admin/webhooks.php') ?>" class="btn btn-secondary btn-xs">Configure Webhook Endpoints</a>
            </div>
        <?php else: ?>
            <div class="wh-feed-list">
                <?php foreach ($recentWebhooks as $wh): 
                    $whPayload = json_decode($wh['payload'] ?? '{}', true);
                    $whEvent = $whPayload['payload']['event'] ?? ($whPayload['event'] ?? 'order.paid');
                    $isSuccess = ($wh['status'] === 'success' || (isset($wh['response_code']) && (int)$wh['response_code'] >= 200 && (int)$wh['response_code'] < 300));
                ?>
                <div class="wh-feed-item">
                    <!-- Col 1: Status Pill (Fixed Width) -->
                    <div class="wh-status-cell">
                        <span class="wh-status-pill <?= $isSuccess ? 'success' : 'failed' ?>">
                            <span class="subsystem-dot <?= $isSuccess ? 'green' : 'red' ?>"></span>
                            <?= !empty($wh['response_code']) ? e((string)$wh['response_code']) : ($isSuccess ? '200 OK' : 'FAILED') ?>
                        </span>
                    </div>

                    <!-- Col 2: Event, Order, Destination URL (Flex 1) -->
                    <div class="wh-main-cell">
                        <div class="wh-meta-line">
                            <span class="wh-event-chip mono"><?= e($whEvent) ?></span>
                            <a href="<?= url('admin/order.php?order_id=' . urlencode($wh['order_id'])) ?>" class="wh-order-chip mono">
                                <?= e($wh['order_id']) ?>
                            </a>
                            <span class="wh-merchant-label">&middot; <?= e($wh['merchant_name']) ?></span>
                        </div>
                        <div class="wh-url-line" title="<?= e($wh['endpoint_url']) ?>">
                            <span class="wh-url-icon"><?= icon('link', 10) ?></span>
                            <span class="wh-url-text mono"><?= e($wh['endpoint_url']) ?></span>
                        </div>
                    </div>

                    <!-- Col 3: Timestamp & Action (Fixed Width, right aligned) -->
                    <div class="wh-action-cell">
                        <span class="wh-time-chip"><?= e(time_ago($wh['created_at'])) ?></span>
                        <a href="<?= url('admin/webhooks.php?q=' . urlencode($wh['order_id'])) ?>" class="btn btn-secondary btn-xs wh-inspect-btn" title="Inspect Webhook Payload">
                            <?= icon('eye', 13) ?> Inspect
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="admin-bottom-card-footer">
        <span>Delivered: <b style="color:#10b981;"><?= $whOk ?></b> &middot; Retries / Failures: <b style="color:#ef4444;"><?= $whFailed ?></b></span>
        <span style="display:flex;align-items:center;gap:4px;"><?= icon('shield', 12) ?> HMAC-SHA256 Signed</span>
    </div>
    <?php
}
