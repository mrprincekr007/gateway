<?php
/* Merchant directory table row. Expects $u (row), $user (current admin), $return (query string). */
$isSelf = ((int)$u['id'] === (int)$user['id']);
$initials = strtoupper(mb_substr($u['name'] ?: 'M', 0, 1));
$searchPayload = strtolower(implode(' ', [
    (string)$u['id'],
    (string)$u['name'],
    (string)$u['email'],
    (string)$u['merchant_id'],
    (string)($u['upi_id'] ?? ''),
    (string)($u['business_name'] ?? ''),
    (string)($u['phone'] ?? ''),
    (string)$u['status'],
    (string)$u['role'],
]));
?>
<tr data-status="<?= e((string)$u['status']) ?>" data-role="<?= e((string)$u['role']) ?>" data-online="<?= is_user_online($u['last_activity_at'] ?? null) ? '1' : '0' ?>" data-search="<?= e($searchPayload) ?>">
    <td data-label="Merchant">
        <div class="flex items-center gap-3">
            <div class="merchant-avatar-wrap" style="position:relative;">
                <?php if (!empty($u['avatar'])): ?>
                    <img src="<?= e(url($u['avatar'])) ?>" alt="">
                <?php else: ?>
                    <span><?= e($initials) ?></span>
                <?php endif; ?>
                <?php if (is_user_online($u['last_activity_at'] ?? null)): ?>
                    <span class="avatar-online-dot" title="Online Now" style="position:absolute;bottom:-1px;right:-1px;width:10px;height:10px;border-radius:50%;background:#10b981;border:2px solid var(--surface-card,#ffffff);box-shadow:0 0 6px #10b981;display:inline-block;"></span>
                <?php endif; ?>
            </div>
            <div>
                <div class="flex items-center gap-1.5" style="flex-wrap:wrap;">
                    <a href="<?= url('admin/user.php?id=' . (int) $u['id']) ?>" class="table-link-merchant" style="font-weight:700;font-size:13.5px;">
                        <?= e($u['name'] ?: 'Unnamed Merchant') ?>
                    </a>
                    <span class="badge badge-neutral" style="font-size:10px;padding:1px 6px;" title="User ID">UID: #<?= (int) $u['id'] ?></span>
                    <?php if ($u['role'] === 'admin'): ?>
                        <span class="badge badge-purple" style="font-size:9.5px;padding:1px 5px;">Admin</span>
                    <?php endif; ?>
                </div>
                <div class="text-mute" style="font-size:12px;margin-top:2px;">
                    <?= e($u['email']) ?>
                </div>
            </div>
        </div>
    </td>
    <td data-label="IDs & UPI">
        <div style="display:flex;flex-direction:column;gap:3px;align-items:flex-start;">
            <div class="merchant-copy-chip" onclick="copyMerchantId('<?= e($u['merchant_id']) ?>', this)" title="Click to copy Merchant ID">
                <span class="chip-text mono"><?= e($u['merchant_id']) ?></span>
                <span class="chip-copy-icon"><?= icon('copy', 11) ?></span>
            </div>
            <div class="upi-handle-chip">
                <?php if (!empty($u['upi_id'])): ?>
                    <span class="upi-text mono"><?= e($u['upi_id']) ?></span>
                <?php else: ?>
                    <span class="upi-empty-text">No UPI set</span>
                <?php endif; ?>
            </div>
        </div>
    </td>
    <td data-label="Volume">
        <div class="tnum" style="font-weight:800;font-size:14px;color:var(--color-success, #10b981);">
            <?= money((float) $u['collected']) ?>
        </div>
        <div class="text-mute tnum" style="font-size:11.5px;margin-top:2px;">
            <?= (int) $u['total_orders'] ?> orders &middot; <?= (int) $u['success_orders'] ?> settled
        </div>
    </td>
    <td data-label="Status">
        <?php if ($u['status'] === 'active'): ?>
            <span class="status-pill success flex items-center gap-1.5" style="display:inline-flex;">
                <span class="status-dot-pulse"></span> Active
            </span>
        <?php else: ?>
            <span class="status-pill danger flex items-center gap-1.5" style="display:inline-flex;">
                <?= icon('alert', 11) ?> Suspended
            </span>
        <?php endif; ?>
    </td>
    <td data-label="Access">
        <?= $u['role'] === 'admin' 
            ? '<span class="badge badge-purple" style="font-size:11px;font-weight:700;padding:3px 9px;">Admin</span>' 
            : '<span class="badge badge-neutral" style="font-size:11px;font-weight:600;padding:3px 9px;">Merchant</span>' ?>
    </td>
    <td data-label="Registered">
        <div style="font-size:12.5px;"><?= e(date('d M Y', strtotime((string)$u['created_at']))) ?></div>
        <div class="text-mute" style="font-size:11px;"><?= e(date('h:i A', strtotime((string)$u['created_at']))) ?></div>
    </td>
    <td data-label="Actions" style="text-align:right;" class="action-cell">
        <div class="merchant-dots-menu-wrap">
            <button type="button" class="btn-dots-action" onclick="toggleMerchantActionMenu(event, <?= (int) $u['id'] ?>, this)" title="Merchant Actions" aria-label="Merchant Actions">
                <?= icon('dots-vertical', 16) ?>
            </button>
            <div class="merchant-action-dropdown" id="merchantActionMenu_<?= (int) $u['id'] ?>">
                <div class="action-dropdown-header">
                    <span class="action-dropdown-title">Merchant #<?= (int) $u['id'] ?></span>
                    <span class="action-dropdown-badge <?= $u['status'] === 'active' ? 'badge-success' : 'badge-rose' ?>">
                        <?= ucfirst($u['status']) ?>
                    </span>
                </div>

                <!-- 1. Inspect Merchant Profile -->
                <a href="<?= url('admin/user.php?id=' . (int) $u['id']) ?>" class="action-dropdown-item">
                    <span class="action-item-icon tone-blue"><?= icon('eye', 14) ?></span>
                    <div class="action-item-content">
                        <span class="action-item-name">Inspect Merchant</span>
                        <span class="action-item-hint">360° Profile, UPI, Keys</span>
                    </div>
                </a>

                <?php if (!$isSelf): ?>
                    <!-- 2. Impersonate Login Form -->
                    <form method="post" action="<?= url('admin/impersonate.php') ?>" target="_blank" style="margin:0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                        <button type="submit" class="action-dropdown-item" style="width:100%;text-align:left;background:none;border:none;">
                            <span class="action-item-icon tone-cyan"><?= icon('arrow-right', 14) ?></span>
                            <div class="action-item-content">
                                <span class="action-item-name">Login As Merchant</span>
                                <span class="action-item-hint">Open Merchant Dashboard</span>
                            </div>
                        </button>
                    </form>

                    <div class="action-dropdown-divider"></div>

                    <!-- 3. Quick Status Toggle -->
                    <form method="post" style="margin:0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="status">
                        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                        <input type="hidden" name="value" value="<?= $u['status'] === 'active' ? 'suspended' : 'active' ?>">
                        <input type="hidden" name="return" value="<?= e($return) ?>">
                        <button type="submit" class="action-dropdown-item <?= $u['status'] === 'active' ? 'danger' : 'success' ?>" style="width:100%;text-align:left;background:none;border:none;">
                            <span class="action-item-icon <?= $u['status'] === 'active' ? 'tone-rose' : 'tone-emerald' ?>">
                                <?= $u['status'] === 'active' ? icon('shield', 14) : icon('check', 14) ?>
                            </span>
                            <div class="action-item-content">
                                <span class="action-item-name"><?= $u['status'] === 'active' ? 'Suspend Merchant' : 'Activate Merchant' ?></span>
                                <span class="action-item-hint"><?= $u['status'] === 'active' ? 'Block gateway & payments' : 'Restore active processing' ?></span>
                            </div>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </td>
</tr>
