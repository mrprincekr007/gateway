<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/admin-ops.php';

$user     = require_admin();
$active   = 'admins';
$actorId  = (int) $user['id'];
$error    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf_token'))) {
        $error = 'Session expired. Please try again.';
    } else {
        $action = post('action');
        $id     = (int) post('id');
        $err    = null;
        $okMsg  = 'Administrator record updated.';

        if ($action === 'role') {
            $err   = admin_set_role($id, post('value') === 'admin' ? 'admin' : 'user', $actorId);
            $okMsg = 'Administrator role updated.';
        } elseif ($action === 'status') {
            $err   = admin_set_status($id, post('value'), $actorId);
            $okMsg = 'Administrator access status updated.';
        } elseif ($action === 'reset') {
            $err   = admin_reset_password($id, post('password'));
            $okMsg = 'Password reset successfully for this account.';
        } elseif ($action === 'delete') {
            $err   = admin_delete_user($id, $actorId);
            $okMsg = 'Administrator account removed from the team.';
        } elseif ($action === 'promote') {
            $err   = admin_set_role($id, 'admin', $actorId);
            $okMsg = 'Merchant promoted to administrator.';
        } else {
            $err = 'Unknown action.';
        }

        if ($err) {
            $error = $err;
        } else {
            ops_audit('admin.' . $action, 'user', $id, $okMsg);
            flash_set('success', $okMsg);
            redirect('admin/admins.php');
        }
    }
}

$admins = db()->query(
    "SELECT * FROM users WHERE role = 'admin' ORDER BY status = 'active' DESC, name ASC"
)->fetchAll();

$stats = db()->query(
    "SELECT COUNT(*) AS total_admins,
            COALESCE(SUM(status = 'active'), 0)   AS active_admins,
            COALESCE(SUM(status = 'suspended'), 0) AS suspended_admins
       FROM users WHERE role = 'admin'"
)->fetch();

$totalAdmins    = (int) ($stats['total_admins'] ?? 0);
$activeAdmins   = (int) ($stats['active_admins'] ?? 0);
$suspendedAdmins = (int) ($stats['suspended_admins'] ?? 0);
$adminCount     = admin_admins_count();

$merchantCount = (int) db()->query(
    "SELECT COUNT(*) FROM users WHERE role = 'user' AND status = 'active'"
)->fetchColumn();

$merchants = db()->query(
    "SELECT id, name, email, merchant_id FROM users
      WHERE role = 'user' AND status = 'active'
      ORDER BY name ASC LIMIT 300"
)->fetchAll();

$recent = db()->query(
    'SELECT actor_name, action, details, created_at FROM audit_logs ORDER BY id DESC LIMIT 8'
)->fetchAll();

$pageTitle   = 'Admin Team';
$pageHeading = 'Admin Team & Access Control';
$pageSub     = 'Privileged console accounts, promotion flow and the security action trail.';
include __DIR__ . '/includes/header.php';
?>


<?php if ($error): ?>
    <div class="alert alert-error mb-4"><?= icon('alert', 18) ?><span><?= e($error) ?></span></div>
<?php endif; ?>
<?php if ($f = flash_get()): ?>
    <div class="alert alert-success mb-4"><?= icon('check-circle', 18) ?><span><?= e($f['message']) ?></span></div>
<?php endif; ?>

<div class="admin-kpi-grid mb-4">
    <div class="admin-kpi-card tone-indigo">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Total Admins</span>
            <span class="admin-kpi-icon"><?= icon('shield', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= $totalAdmins ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Privileged console accounts</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-emerald">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Active Admins</span>
            <span class="admin-kpi-icon"><?= icon('check-circle', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= $activeAdmins ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Full console authority live</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-rose">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Suspended</span>
            <span class="admin-kpi-icon"><?= icon('lock', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= $suspendedAdmins ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Blocked from signing in</span>
        </div>
    </div>

    <div class="admin-kpi-card tone-cyan">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Merchant Pool</span>
            <span class="admin-kpi-icon"><?= icon('user', 18) ?></span>
        </div>
        <div class="admin-kpi-value tnum"><?= $merchantCount ?></div>
        <div class="admin-kpi-footer">
            <span class="admin-kpi-sub">Active accounts ready to promote</span>
        </div>
    </div>
</div>

<div class="adm-grid">
    <!-- Administrators directory -->
    <div class="card card-pad-0">
        <div class="admin-toolbar" style="padding:18px 24px;">
            <div class="card-title flex items-center gap-2"><?= icon('users', 18) ?> Administrators</div>
            <button class="btn btn-primary btn-sm flex items-center gap-1" type="button"
                    onclick="document.getElementById('adm-promote-card').scrollIntoView({behavior:'smooth',block:'center'});setTimeout(function(){var s=document.getElementById('adm-promote-select');if(s)s.focus();},420);">
                <?= icon('plus', 14) ?> Promote Merchant
            </button>
        </div>

        <?php if (!$admins): ?>
            <div class="empty-state" style="padding:56px 20px;">
                <div class="empty-icon"><?= icon('shield', 28) ?></div>
                <p class="empty-state-title">No administrator accounts found</p>
                <p class="empty-state-sub">Promote an active merchant to restore console access.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table adm-table">
                    <thead>
                        <tr>
                            <th>Administrator</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Account Timeline</th>
                            <th style="text-align:right;">Control Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($admins as $a): ?>
                            <?php
                                $isSelf = ((int) $a['id'] === $actorId);
                                $av     = initials((string) $a['name']);
                            ?>
                            <tr>
                                <td data-label="Administrator">
                                    <div class="flex items-center gap-3">
                                        <div class="adm-avatar <?= $isSelf ? 'you' : '' ?>"><?= e($av) ?></div>
                                        <div style="min-width:0;">
                                            <div class="adm-name">
                                                <?= e($a['name']) ?>
                                                <?php if ($isSelf): ?><span class="adm-pill" style="margin-left:6px;">You</span><?php endif; ?>
                                            </div>
                                            <div class="adm-mail"><?= e($a['email']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Role">
                                    <span class="badge" style="background:rgba(168,85,247,.12);color:#c084fc;border:1px solid rgba(168,85,247,.3);">Administrator</span>
                                </td>
                                <td data-label="Status"><?= status_badge((string) $a['status']) ?></td>
                                <td data-label="Account Timeline">
                                    <div style="font-size:12.5px;font-weight:600;">Updated <?= e(nice_date((string) $a['updated_at'])) ?></div>
                                    <div class="text-mute" style="font-size:11.5px;">Joined <?= e(nice_date((string) $a['created_at'])) ?></div>
                                </td>
                                <td data-label="Control Actions" style="text-align:right;">
                                    <?php if ($isSelf): ?>
                                        <span class="badge badge-info" style="font-size:10.5px;">Your Account</span>
                                    <?php else: ?>
                                        <div class="adm-actions">
                                            <?php if ($a['role'] === 'admin'): ?>
                                                <form method="post" style="display:inline;">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="role">
                                                    <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                                    <input type="hidden" name="value" value="user">
                                                    <button class="btn btn-ghost btn-sm" type="submit" title="Remove administrator rights">Demote</button>
                                                </form>
                                            <?php endif; ?>

                                            <form method="post" style="display:inline;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="status">
                                                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                                <input type="hidden" name="value" value="<?= $a['status'] === 'active' ? 'suspended' : 'active' ?>">
                                                <button class="btn btn-ghost btn-sm" type="submit" title="<?= $a['status'] === 'active' ? 'Suspend this admin' : 'Reactivate this admin' ?>">
                                                    <?= $a['status'] === 'active' ? 'Suspend' : 'Activate' ?>
                                                </button>
                                            </form>

                                            <button class="btn btn-secondary btn-sm" type="button" title="Reset login password"
                                                    onclick="admOpenReset(<?= (int) $a['id'] ?>, <?= e(json_encode((string) $a['name'])) ?>)">
                                                <?= icon('lock', 13) ?> Reset
                                            </button>

                                            <form method="post" style="display:inline;"
                                                  onsubmit="return confirm('Remove this administrator account permanently? This cannot be undone.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                                <button class="btn btn-ghost btn-sm" type="submit" style="color:#dc2626;" title="Delete this account">
                                                    <?= icon('trash', 13) ?> Delete
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between" style="padding:14px 24px;border-top:1px solid var(--color-border);gap:12px;flex-wrap:wrap;">
                <span class="text-mute" style="font-size:12.5px;"><?= $totalAdmins ?> administrator account(s) · <?= $adminCount ?> privileged role holder(s)</span>
                <span class="text-mute" style="font-size:12.5px;">Guards: self-lockout and last-admin removal are blocked</span>
            </div>
        <?php endif; ?>
    </div>

    <div class="adm-side">
        <!-- Promote merchant -->
        <div class="card" id="adm-promote-card" style="padding: 18px 22px 22px;">
            <div class="card-header" style="padding: 0 0 14px 0; border-bottom: 1px solid var(--color-hairline-2);">
                <div class="card-title flex items-center gap-2"><?= icon('user', 18) ?> Promote Merchant</div>
            </div>

            <?php if (!$merchants): ?>
                <div class="empty-state" style="padding:34px 10px;">
                    <div class="empty-icon"><?= icon('user', 24) ?></div>
                    <p class="empty-state-title">No active merchants</p>
                    <p class="empty-state-sub">Create a merchant account first, then promote it here.</p>
                </div>
            <?php else: ?>
                <form method="post" style="margin-top:14px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="promote">
                    <div class="form-group mb-3">
                        <label class="form-label">Select Merchant</label>
                        <select class="form-select adm-select" name="id" id="adm-promote-select" required>
                            <?php foreach ($merchants as $m): ?>
                                <option value="<?= (int) $m['id'] ?>"><?= e($m['name']) ?> · <?= e($m['email']) ?> · <?= e($m['merchant_id']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn-primary btn-block flex items-center justify-center gap-2" type="submit">
                        <?= icon('shield', 14) ?> Promote to Administrator
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Recent admin actions -->
        <div class="card" style="padding: 18px 22px 22px;">
            <div class="card-header" style="padding: 0 0 14px 0; border-bottom: 1px solid var(--color-hairline-2);">
                <div class="card-title flex items-center gap-2"><?= icon('file-text', 18) ?> Recent Admin Actions</div>
            </div>

            <?php if (!$recent): ?>
                <div class="empty-state" style="padding:34px 10px;">
                    <div class="empty-icon"><?= icon('activity', 24) ?></div>
                    <p class="empty-state-title">No recorded activity</p>
                    <p class="empty-state-sub">Administrative changes will appear here automatically.</p>
                </div>
            <?php else: ?>
                <div class="adm-feed" style="margin-top:10px;">
                    <?php foreach ($recent as $r): ?>
                        <div class="adm-feed-item">
                            <div class="adm-feed-dot"><?= icon('activity', 13) ?></div>
                            <div class="adm-feed-body">
                                <div class="adm-feed-line">
                                    <strong><?= e((string) ($r['actor_name'] ?? 'System')) ?></strong>
                                    performed <span class="adm-feed-code"><?= e((string) $r['action']) ?></span>
                                </div>
                                <?php if (!empty($r['details'])): ?>
                                    <div class="adm-feed-time"><?= e((string) $r['details']) ?></div>
                                <?php endif; ?>
                                <div class="adm-feed-time"><?= e(nice_date((string) $r['created_at'])) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal: Reset Password -->
<div id="adm-reset-modal" class="admin-modal-overlay">
    <div class="admin-modal-dialog">
        <div class="admin-modal-header">
            <div class="admin-modal-title flex items-center gap-2">
                <?= icon('lock', 18) ?> Reset Login Password
            </div>
            <button type="button" class="admin-modal-close" onclick="closeAdminModal('adm-reset-modal')" aria-label="Close"><?= icon('x', 16) ?></button>
        </div>
        <form method="post">
            <div class="admin-modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset">
                <input type="hidden" name="id" id="adm-reset-id" value="">

                <div class="form-group mb-3">
                    <label class="form-label">Target Account</label>
                    <input class="form-input" type="text" id="adm-reset-name" value="" readonly
                           style="background:var(--surface-sunken);color:var(--color-ink-mute);">
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">New Password</label>
                    <input class="form-input" type="password" name="password" placeholder="At least 8 characters"
                           required minlength="8" autocomplete="new-password">
                </div>
            </div>
            <div class="admin-modal-footer">
                <button type="button" class="btn btn-ghost btn-sm" onclick="closeAdminModal('adm-reset-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm flex items-center gap-2">
                    <?= icon('check-circle', 14) ?> Reset Password
                </button>
            </div>
        </form>
    </div>
</div>


<?php include __DIR__ . '/includes/footer.php'; ?>
