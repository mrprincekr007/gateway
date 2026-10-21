<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';
require_once APP_ROOT . '/includes/mail.php';

$user   = require_login();
$active = 'integrations';

$error  = null;
$notice = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf(post('csrf_token'))) {
        $error = 'Session expired. Please try again.';
    } else {
        $action = post('action');

        if ($action === 'connect') {
            $email     = strtolower(trim((string) post('gmail_email')));
            $appPass   = trim((string) post('gmail_app_password'));
            $upiId     = trim((string) post('upi_id'));

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Enter a valid Gmail address.';
            } elseif (mb_strlen($appPass) < 8) {
                $error = 'Enter your 16-digit Google App Password.';
            } elseif (!str_contains($upiId, '@')) {
                $error = 'Enter a valid UPI ID (e.g. yourname@okaxis).';
            } else {
                /* ask Gmail itself whether the credentials work */
                $conn = mail_connect($email, $appPass);
                if (!$conn) {
                    $err = function_exists('imap_last_error') ? imap_last_error() : '';
                    if (function_exists('imap_errors')) { imap_errors(); }
                    $error = 'Gmail login failed. ' . ($err ?: 'Double-check the 16-character App Password and verify that IMAP is enabled in Gmail settings.');
                } else {
                    @imap_close($conn);
                    setting_set($user['id'], 'gmail_email', $email);
                    setting_set($user['id'], 'gmail_app_password', (string) encrypt_secret($appPass));
                    setting_set($user['id'], 'gmail_upi_id', $upiId);
                    setting_set($user['id'], 'imap_last_check', 'Connected ' . date('M j, Y g:i a'));
                    db()->prepare('UPDATE users SET imap_status = "connected", upi_id = ? WHERE id = ?')
                        ->execute([$upiId, $user['id']]);
                    flash_set('success', 'Gmail IMAP connection verified! Real-time payment verification is now active.');
                    redirect('user/integrations.php');
                }
            }
        }

        if ($action === 'disconnect') {
            setting_delete($user['id'], 'gmail_email');
            setting_delete($user['id'], 'gmail_app_password');
            setting_delete($user['id'], 'gmail_upi_id');
            db()->prepare('UPDATE users SET imap_status = "not_configured" WHERE id = ?')->execute([$user['id']]);
            flash_set('success', 'Gmail integration disconnected. Automatic email scanning paused.');
            redirect('user/integrations.php');
        }

        if ($action === 'verify') {
            $storedPass = (string) setting_get($user['id'], 'gmail_app_password', '');
            $plainPass  = (string) decrypt_secret($storedPass);
            $storedMail = (string) setting_get($user['id'], 'gmail_email', '');

            $conn = mail_connect($storedMail, $plainPass);
            if ($conn) {
                @imap_close($conn);
                db()->prepare('UPDATE users SET imap_status = "connected" WHERE id = ?')->execute([$user['id']]);
                $notice = 'IMAP handshake successful! Connected to imap.gmail.com:993 (SSL) — credentials are 100% valid.';
            } else {
                $err = function_exists('imap_last_error') ? imap_last_error() : '';
                if (function_exists('imap_errors')) { imap_errors(); }
                $error = 'IMAP login test failed. ' . ($err ?: 'Check your Gmail address and verify the 16-digit App Password is still active.');
            }
        }

        if ($action === 'check_now') {
            $res = mail_process_user((int) $user['id']);
            if (!$res['connected']) {
                $error = 'Payment scan failed: ' . ($res['error'] ?? 'Connection error. Please test connection.');
            } else {
                $msg = 'Scanner run finished. Emails scanned: ' . $res['seen']
                     . ', orders matched: ' . $res['matched']
                     . ', webhooks dispatched: ' . $res['webhooks'] . '.';
                if (!$res['summary']) {
                    $msg .= ' No new credit alerts detected for currently pending invoices.';
                } else {
                    $msg .= ' Confirmed payments: ' . implode(', ', $res['summary']);
                }
                setting_set($user['id'], 'imap_last_check', date('M j, Y g:i a') . ' — ' . $msg);
                $notice = $msg;
            }
        }
    }
}

$imapStmt = db()->prepare('SELECT imap_status, upi_id FROM users WHERE id = ? LIMIT 1');
$imapStmt->execute([$user['id']]);
$fresh      = $imapStmt->fetch() ?: [];
$connected  = ($fresh['imap_status'] ?? $user['imap_status']) === 'connected';
$gmailEmail = (string) setting_get($user['id'], 'gmail_email', '');
$activeUpi  = (string) setting_get($user['id'], 'gmail_upi_id', $fresh['upi_id'] ?? $user['upi_id'] ?? '');
$lastCheck  = (string) setting_get($user['id'], 'imap_last_check', '');

$pageTitle       = 'Integrations';
$pageHeading     = 'Integrations';
$pageSub         = 'Automated bank email payment verification';
$hidePageHeading = true; // Use sleek Dark Hero Stage instead of plain topbar
include __DIR__ . '/includes/header.php';
?>

<!-- =========================================================
     DARK HERO STAGE ("Kahi Kahi Pe Dark UI")
     Matching index.php and dashboard.php Hero Aesthetics
     ========================================================= -->
<div class="dash-hero-stage-dark" data-reveal>
    <div class="hero-stage-mesh-top" aria-hidden="true"></div>
    <div class="hero-stage-mesh-bottom" aria-hidden="true"></div>

    <div class="hero-stage-header">
        <div class="hero-eyebrow">
            <span class="dot" style="<?= $connected ? '' : 'background:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,0.2);' ?>"></span>
            <span><?= $connected ? 'Scanner Active' : 'Scanner Standby' ?></span>
        </div>
        <div class="hero-sla-pill">
            <span class="sla-dot"></span>
            <span>IMAP SSL :993</span>
        </div>
    </div>

    <div class="hero-stage-headline">
        <h1 class="dash-hero-heading">
            Gmail &amp; UPI <span class="accent">Automations</span>
        </h1>
        <p class="dash-hero-desc">
            Bank email payment verification engine.
        </p>
    </div>

    <div class="hero-meta-ribbon">
        <div class="meta-ribbon-cell" title="Email scanner connection status">
            <div class="meta-cell-label">Scanner Status</div>
            <div class="meta-cell-value">
                <?php if ($connected): ?>
                    <span class="meta-badge-ok"><span class="mini-beacon"></span> Connected</span>
                <?php else: ?>
                    <span class="meta-badge-warn"><span class="mini-beacon warn"></span> Standby</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="meta-ribbon-cell" title="Credential storage security standard">
            <div class="meta-cell-label">Credential Vault</div>
            <div class="meta-cell-value">
                <span class="meta-badge-ok"><span class="mini-beacon"></span> AES-256-GCM</span>
            </div>
        </div>

        <div class="meta-ribbon-cell" title="Connection protocol and secure port">
            <div class="meta-cell-label">Listener Port</div>
            <div class="meta-cell-value">
                <span class="val-rate">IMAP SSL :993</span>
            </div>
        </div>

        <div class="meta-ribbon-cell" title="Auto UTR reconciliation engine">
            <div class="meta-cell-label">Reconciler</div>
            <div class="meta-cell-value">
                <span class="val-accent">Auto Regex</span>
            </div>
        </div>
    </div>

    <div class="hero-stage-btns">
        <?php if ($connected): ?>
        <form method="post" style="margin:0;display:inline-flex;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="check_now">
            <button type="submit" class="btn-hero-primary">
                <?= icon('refresh', 15) ?> Check Inbox
            </button>
        </form>
        <a href="#guideCard" class="btn-hero-secondary">
            <?= icon('book', 14) ?> Setup Guide
        </a>
        <?php else: ?>
        <a href="#setupCard" class="btn-hero-primary">
            <?= icon('plus', 15) ?> Connect Gmail
        </a>
        <a href="#guideCard" class="btn-hero-secondary">
            <?= icon('book', 14) ?> Instructions
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- =========================================================
     SYSTEM ALERTS (Modern Animated Banners)
     ========================================================= -->
<?php if ($error): ?>
    <div class="dash-alert dash-alert-error" data-reveal>
        <div class="alert-icon-wrap"><?= icon('alert', 18) ?></div>
        <div class="alert-text-wrap"><strong>Notice:</strong> <?= e($error) ?></div>
    </div>
<?php endif; ?>
<?php if ($notice): ?>
    <div class="dash-alert dash-alert-info" data-reveal>
        <div class="alert-icon-wrap"><?= icon('check-circle', 18) ?></div>
        <div class="alert-text-wrap"><strong>Notice:</strong> <?= e($notice) ?></div>
    </div>
<?php endif; ?>
<?php if ($f = flash_get()): ?>
    <div class="dash-alert dash-alert-success" data-reveal>
        <div class="alert-icon-wrap"><?= icon('check-circle', 18) ?></div>
        <div class="alert-text-wrap"><strong>Success:</strong> <?= e($f['message']) ?></div>
    </div>
<?php endif; ?>

<!-- =========================================================
     SPLIT 2-COLUMN WORKSPACE: CONSOLE ON LEFT, SETUP & SECURITY ON RIGHT
     ========================================================= -->
<div class="dash-split-grid" data-reveal>

    <!-- LEFT COLUMN: Gmail IMAP Automated Verification Console (or Connect Form) -->
    <div class="dash-col-main">
        <?php if ($connected): ?>
        <div class="dash-white-card" id="setupCard" style="margin-bottom:0;">
            <div class="white-card-header" style="margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div>
                    <h3 class="white-card-title" style="display:flex;align-items:center;gap:8px;">
                        <span style="color:#2563eb;"><?= icon('plug', 18) ?></span>
                        Gmail Verification Console
                    </h3>
                    <p class="white-card-sub">
                        Auto-reconciles bank credit alert emails.
                    </p>
                </div>
                <div>
                    <span class="badge-clean success" style="padding:6px 14px;font-weight:700;font-size:12px;gap:6px;">
                        <span class="mini-beacon"></span> Port 993 SSL
                    </span>
                </div>
            </div>

            <!-- Live Pulse Banner -->
            <div class="integ-status-banner is-connected" style="margin-bottom:18px;padding:16px 20px;">
                <div class="integ-status-icon" style="width:44px;height:44px;border-radius:12px;">
                    <?= icon('check-circle', 22) ?>
                </div>
                <div style="flex:1;">
                    <div class="integ-status-title" style="font-size:15px;">Active &amp; Monitoring</div>
                    <p class="integ-status-subtitle" style="font-size:12.5px;">
                        Scanning incoming bank alerts in real-time.
                    </p>
                </div>
                <div style="margin-left:auto;text-align:right;">
                    <span style="font-size:11px;color:#047857;font-weight:600;display:inline-flex;align-items:center;gap:4px;">
                        <span class="mini-beacon"></span> Ready
                    </span>
                </div>
            </div>

            <!-- 2x2 Structured Inset Depth Grid -->
            <div class="integ-grid-2x2">
                <!-- Cell 1: Gmail Address -->
                <div class="integ-grid-cell">
                    <div class="integ-cell-label">
                        <?= icon('mail', 13) ?> Monitored Gmail
                    </div>
                    <div class="integ-cell-value">
                        <span class="integ-cell-value-text" title="<?= e($gmailEmail) ?>"><?= e($gmailEmail) ?></span>
                        <button type="button" class="copy-chip-btn" data-copy="<?= e($gmailEmail) ?>" title="Copy Gmail Address">
                            <?= icon('copy', 13) ?>
                        </button>
                    </div>
                </div>

                <!-- Cell 2: Receiving UPI VPA -->
                <div class="integ-grid-cell">
                    <div class="integ-cell-label">
                        <?= icon('wallet', 13) ?> Receiving UPI
                    </div>
                    <div class="integ-cell-value">
                        <span class="integ-cell-value-text" style="color:#2563eb;font-family:'JetBrains Mono',monospace;" title="<?= e($activeUpi) ?>"><?= e($activeUpi ?: 'Not configured') ?></span>
                        <?php if ($activeUpi): ?>
                        <button type="button" class="copy-chip-btn" data-copy="<?= e($activeUpi) ?>" title="Copy UPI ID">
                            <?= icon('copy', 13) ?>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Cell 3: Credential Security -->
                <div class="integ-grid-cell">
                    <div class="integ-cell-label">
                        <?= icon('lock', 13) ?> Credential Vault
                    </div>
                    <div class="integ-cell-value">
                        <span class="integ-cell-value-text" style="color:#059669;display:inline-flex;align-items:center;gap:6px;">
                            <?= icon('shield', 14) ?> AES-256-GCM
                        </span>
                        <span class="badge-clean success" style="font-size:10px;padding:2px 6px;">Encrypted</span>
                    </div>
                </div>

                <!-- Cell 4: Last Scanner Run -->
                <div class="integ-grid-cell">
                    <div class="integ-cell-label">
                        <?= icon('clock', 13) ?> Latest Scanner Pulse
                    </div>
                    <div class="integ-cell-value">
                        <span class="integ-cell-value-text" style="font-size:12.5px;font-weight:600;color:var(--color-ink-secondary);" title="<?= e($lastCheck) ?>">
                            <?= e($lastCheck ?: 'Ready for payments') ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- 3D Action Button Cluster -->
            <div class="integ-actions-cluster">
                <div class="integ-btn-row-primary">
                    <form method="post" style="margin:0;width:100%;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="check_now">
                        <button class="btn btn-primary" type="submit" style="width:100%;height:44px;font-size:13.5px;font-weight:700;display:flex;align-items:center;justify-content:center;gap:8px;">
                            <?= icon('refresh', 16) ?> Check Payments Now
                        </button>
                    </form>
                </div>

                <div class="integ-btn-row-secondary">
                    <form method="post" style="margin:0;width:100%;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="verify">
                        <button class="btn btn-secondary" type="submit" style="width:100%;height:40px;font-size:13px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:6px;">
                            <?= icon('activity', 15) ?> Test Connection
                        </button>
                    </form>

                    <form method="post" onsubmit="return confirm('Are you sure you want to disconnect Gmail integration? Automatic payment verification will be paused until reconnected.');" style="margin:0;width:100%;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="disconnect">
                        <button class="btn btn-danger" type="submit" style="width:100%;height:40px;font-size:13px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:6px;">
                            <?= icon('trash', 15) ?> Disconnect
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <?php else: ?>
        <div class="dash-white-card" id="setupCard" style="margin-bottom:0;">
            <div class="white-card-header" style="margin-bottom:16px;">
                <div>
                    <h3 class="white-card-title" style="display:flex;align-items:center;gap:8px;">
                        <span style="color:#2563eb;"><?= icon('plug', 18) ?></span>
                        Connect Gmail &amp; UPI
                    </h3>
                    <p class="white-card-sub">
                        Auto-verify bank payments via IMAP.
                    </p>
                </div>
            </div>

            <div class="integ-status-banner is-disconnected" style="margin-bottom:18px;">
                <div class="integ-status-icon">
                    <?= icon('alert', 22) ?>
                </div>
                <div style="flex:1;min-width:0;">
                    <div class="integ-status-title">Gmail Not Connected</div>
                    <p class="integ-status-subtitle">
                        Connect Gmail &amp; UPI to auto-verify.
                    </p>
                </div>
                <div style="margin-left:auto;text-align:right;flex-shrink:0;">
                    <span style="font-size:11px;color:#d97706;font-weight:600;display:inline-flex;align-items:center;gap:4px;">
                        <span class="mini-beacon warn"></span> Standby
                    </span>
                </div>
            </div>

            <form method="post" action="<?= url('user/integrations.php') ?>" style="display:flex;flex-direction:column;gap:18px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="connect">

                <div class="form-group" style="margin:0;">
                    <label class="form-label" style="display:flex;align-items:center;gap:6px;">
                        <?= icon('mail', 14) ?> Gmail Address
                    </label>
                    <div class="input-icon-group">
                        <span class="input-icon-prefix"><?= icon('mail', 16) ?></span>
                        <input class="form-input input-with-icon" type="email" name="gmail_email" placeholder="e.g. yourname@gmail.com" required value="<?= e($gmailEmail) ?>">
                    </div>
                    <p class="form-hint">The personal or business Gmail where your bank credit SMS/emails arrive.</p>
                </div>

                <div class="form-group" style="margin:0;">
                    <label class="form-label" style="display:flex;align-items:center;gap:6px;">
                        <?= icon('wallet', 14) ?> Receiving UPI ID
                    </label>
                    <div class="input-icon-group">
                        <span class="input-icon-prefix"><?= icon('wallet', 16) ?></span>
                        <input class="form-input input-with-icon" type="text" name="upi_id" placeholder="e.g. username@okaxis / username@paytm" required value="<?= e($activeUpi) ?>">
                    </div>
                    <p class="form-hint">Transactions sent to this UPI handle will be scanned and verified.</p>
                </div>

                <div class="form-group" style="margin:0;">
                    <label class="form-label" style="display:flex;align-items:center;gap:6px;">
                        <?= icon('lock', 14) ?> Google App Password (16-Digit)
                        <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener noreferrer" class="app-pass-generate-btn"><?= icon('external', 12) ?> Generate</a>
                    </label>
                    <div class="input-icon-group">
                        <span class="input-icon-prefix"><?= icon('lock', 16) ?></span>
                        <input id="appPasswordInput" class="form-input input-with-icon" type="password" name="gmail_app_password" placeholder="xxxx xxxx xxxx xxxx" required autocomplete="off" style="letter-spacing:1px;font-family:'JetBrains Mono',monospace;">
                        <button type="button" class="input-toggle-btn" id="toggleAppPassBtn" title="Show/Hide Password" aria-label="Toggle password visibility">
                            <span id="eyeOpenIcon"><?= icon('eye', 16) ?></span>
                            <span id="eyeClosedIcon" style="display:none;"><?= icon('eye-off', 16) ?></span>
                        </button>
                    </div>
                    <p class="form-hint">
                        Created under Google Account &rarr; Security &rarr; 2-Step Verification &rarr; App Passwords.
                    </p>
                </div>

                <button class="btn btn-primary" type="submit" style="height:46px;font-size:14px;font-weight:700;display:flex;align-items:center;justify-content:center;gap:8px;margin-top:6px;">
                    <?= icon('plug', 16) ?> Verify Credentials &amp; Connect
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <!-- RIGHT COLUMN: Exactly Matching the Photo ("App Password Setup") -->
    <div class="dash-col-side">
        <!-- App Password Setup (Exact photo styling) -->
        <div class="dash-white-card" id="guideCard" style="margin-bottom:0;">
            <div class="white-card-header" style="margin-bottom:16px;">
                <div>
                    <h3 class="white-card-title" style="display:flex;align-items:center;gap:8px;font-size:15px;font-weight:700;">
                        <span class="integ-photo-card-icon"><?= icon('terminal', 15) ?></span>
                        App Password Setup
                    </h3>
                    <p class="white-card-sub" style="font-size:12px;color:var(--color-ink-mute);margin:3px 0 0;">
                        Quick 4-step setup.
                    </p>
                </div>
            </div>

            <!-- Inset Steps (Matching Photo 1:1) -->
            <div class="integ-step-card">
                <div class="integ-step-badge">1</div>
                <div class="integ-step-body">
                    <div class="integ-step-title">Turn on 2-Step Verification</div>
                    <div class="integ-step-desc">Google Account &rarr; Security</div>
                </div>
            </div>

            <div class="integ-step-card">
                <div class="integ-step-badge">2</div>
                <div class="integ-step-body">
                    <div class="integ-step-title">Enable IMAP in Settings</div>
                    <div class="integ-step-desc">Forwarding &amp; POP/IMAP &rarr; Enable</div>
                </div>
            </div>

            <div class="integ-step-card">
                <div class="integ-step-badge">3</div>
                <div class="integ-step-body">
                    <div class="integ-step-title">Create App Password</div>
                    <div class="integ-step-desc">Security &rarr; App passwords &rarr; Mail</div>
                </div>
            </div>

            <div class="integ-step-card">
                <div class="integ-step-badge">4</div>
                <div class="integ-step-body">
                    <div class="integ-step-title">Paste Password &amp; Connect</div>
                    <div class="integ-step-desc">Enter 16 digits in connection form</div>
                </div>
            </div>

            <div style="margin-top:16px;padding-top:12px;border-top:1px solid rgba(148,163,184,0.18);">
                <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm" style="width:100%;height:38px;justify-content:center;display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:#2563eb;border-color:rgba(37,99,235,0.35);">
                    <?= icon('external', 14) ?> Open Google Passwords &nearr;
                </a>
            </div>
        </div>
    </div>

</div>

<!-- =========================================================
     BOTTOM ROW: 3 COMPANION BOXES (ALL IN ONE HORIZONTAL LINE)
     ========================================================= -->
<div class="integ-bottom-trio-grid" data-reveal>
    <!-- Box 1: Mailbox Security -->
    <div class="dash-white-card integ-feature-card">
        <div>
            <div class="integ-card-header">
                <div class="integ-card-icon-bubble green">
                    <?= icon('shield', 18) ?>
                </div>
                <div>
                    <h3 class="integ-card-title">Mailbox Security</h3>
                    <p class="integ-card-sub">Read-only mode &amp; zero storage</p>
                </div>
            </div>

            <div class="integ-feature-list">
                <div class="integ-feature-item">
                    <div class="integ-item-left">
                        <div class="integ-item-icon green"><?= icon('check-circle', 15) ?></div>
                        <div class="integ-item-text">
                            <div class="integ-item-title">Zero Email Storage</div>
                            <div class="integ-item-desc">Parsed in RAM &amp; discarded</div>
                        </div>
                    </div>
                    <span class="badge-clean success integ-item-badge">Zero-Save</span>
                </div>

                <div class="integ-feature-item">
                    <div class="integ-item-left">
                        <div class="integ-item-icon green"><?= icon('lock', 15) ?></div>
                        <div class="integ-item-text">
                            <div class="integ-item-title">AES-256-GCM Vault</div>
                            <div class="integ-item-desc">Credentials encrypted at rest</div>
                        </div>
                    </div>
                    <span class="badge-clean info integ-item-badge">Vaulted</span>
                </div>

                <div class="integ-feature-item">
                    <div class="integ-item-left">
                        <div class="integ-item-icon green"><?= icon('shield', 15) ?></div>
                        <div class="integ-item-text">
                            <div class="integ-item-title">Strict Read-Only Mode</div>
                            <div class="integ-item-desc">Zero debit or send access</div>
                        </div>
                    </div>
                    <span class="badge-clean success integ-item-badge">Read-Only</span>
                </div>

                <div class="integ-feature-item">
                    <div class="integ-item-left">
                        <div class="integ-item-icon green"><?= icon('activity', 15) ?></div>
                        <div class="integ-item-text">
                            <div class="integ-item-title">Encrypted TLS 1.3</div>
                            <div class="integ-item-desc">Direct SSL on Port 993</div>
                        </div>
                    </div>
                    <span class="badge-clean info integ-item-badge">SSL :993</span>
                </div>
            </div>
        </div>

        <div class="integ-card-footer">
            <span style="font-size:11.5px;color:var(--color-ink-mute);display:flex;align-items:center;gap:6px;justify-content:center;">
                <span class="mini-beacon"></span> Hardware vault active
            </span>
        </div>
    </div>

    <!-- Box 2: UTR Auto-Reconciler -->
    <div class="dash-white-card integ-feature-card">
        <div>
            <div class="integ-card-header">
                <div class="integ-card-icon-bubble purple">
                    <?= icon('activity', 18) ?>
                </div>
                <div>
                    <h3 class="integ-card-title">UTR Auto-Reconciler</h3>
                    <p class="integ-card-sub">Sub-second regex extraction</p>
                </div>
            </div>

            <div class="integ-feature-list">
                <div class="integ-feature-item">
                    <div class="integ-item-left">
                        <div class="integ-item-icon purple"><?= icon('zap', 15) ?></div>
                        <div class="integ-item-text">
                            <div class="integ-item-title">12-Digit UTR Extraction</div>
                            <div class="integ-item-desc">UPI &amp; IMPS reference regex</div>
                        </div>
                    </div>
                    <span class="badge-clean info integ-item-badge">Regex</span>
                </div>

                <div class="integ-feature-item">
                    <div class="integ-item-left">
                        <div class="integ-item-icon purple"><?= icon('check', 15) ?></div>
                        <div class="integ-item-text">
                            <div class="integ-item-title">Amount Cross-Check</div>
                            <div class="integ-item-desc">Prevents false credit matches</div>
                        </div>
                    </div>
                    <span class="badge-clean success integ-item-badge">Strict</span>
                </div>

                <div class="integ-feature-item">
                    <div class="integ-item-left">
                        <div class="integ-item-icon purple"><?= icon('clock', 15) ?></div>
                        <div class="integ-item-text">
                            <div class="integ-item-title">Sub-Second Processing</div>
                            <div class="integ-item-desc">Matched in &lt; 350ms</div>
                        </div>
                    </div>
                    <span class="badge-clean info integ-item-badge">Fast</span>
                </div>

                <div class="integ-feature-item">
                    <div class="integ-item-left">
                        <div class="integ-item-icon purple"><?= icon('send', 15) ?></div>
                        <div class="integ-item-text">
                            <div class="integ-item-title">Instant Webhooks</div>
                            <div class="integ-item-desc">Fires order.paid event live</div>
                        </div>
                    </div>
                    <span class="badge-clean success integ-item-badge">Live</span>
                </div>
            </div>
        </div>

        <div class="integ-card-footer">
            <a href="<?= url('user/webhooks.php') ?>" class="btn btn-secondary btn-sm" style="width:100%;justify-content:center;height:38px;gap:6px;">
                <?= icon('webhook', 13) ?> View Webhooks &rarr;
            </a>
        </div>
    </div>

    <!-- Box 3: Supported Bank Formats -->
    <div class="dash-white-card integ-feature-card">
        <div>
            <div class="integ-card-header">
                <div class="integ-card-icon-bubble blue">
                    <?= icon('wallet', 18) ?>
                </div>
                <div>
                    <h3 class="integ-card-title">Supported Bank Formats</h3>
                    <p class="integ-card-sub">Pre-trained instant regex</p>
                </div>
            </div>

            <div class="integ-feature-list">
                <div class="integ-feature-item">
                    <div class="integ-item-left">
                        <div class="integ-item-icon blue"><?= icon('check-circle', 15) ?></div>
                        <div class="integ-item-text">
                            <div class="integ-item-title">HDFC &amp; ICICI Bank</div>
                            <div class="integ-item-desc">Credit alert notifications</div>
                        </div>
                    </div>
                    <span class="badge-clean success integ-item-badge">Supported</span>
                </div>

                <div class="integ-feature-item">
                    <div class="integ-item-left">
                        <div class="integ-item-icon blue"><?= icon('check-circle', 15) ?></div>
                        <div class="integ-item-text">
                            <div class="integ-item-title">State Bank of India (SBI)</div>
                            <div class="integ-item-desc">UPI Ref &amp; IMPS alerts</div>
                        </div>
                    </div>
                    <span class="badge-clean success integ-item-badge">Supported</span>
                </div>

                <div class="integ-feature-item">
                    <div class="integ-item-left">
                        <div class="integ-item-icon blue"><?= icon('check-circle', 15) ?></div>
                        <div class="integ-item-text">
                            <div class="integ-item-title">Axis Bank &amp; Kotak</div>
                            <div class="integ-item-desc">Credit transaction emails</div>
                        </div>
                    </div>
                    <span class="badge-clean success integ-item-badge">Supported</span>
                </div>

                <div class="integ-feature-item">
                    <div class="integ-item-left">
                        <div class="integ-item-icon blue"><?= icon('check-circle', 15) ?></div>
                        <div class="integ-item-text">
                            <div class="integ-item-title">Paytm, PhonePe &amp; GPay</div>
                            <div class="integ-item-desc">Soundbox &amp; VPA alerts</div>
                        </div>
                    </div>
                    <span class="badge-clean success integ-item-badge">Supported</span>
                </div>
            </div>
        </div>

        <div class="integ-card-footer">
            <span style="font-size:11.5px;color:var(--color-ink-mute);display:flex;align-items:center;gap:6px;justify-content:center;">
                <span class="mini-beacon"></span> Multi-bank regex active
            </span>
        </div>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function () {
    var toggleBtn = document.getElementById('toggleAppPassBtn');
    var input = document.getElementById('appPasswordInput');
    var eyeOpen = document.getElementById('eyeOpenIcon');
    var eyeClosed = document.getElementById('eyeClosedIcon');

    if (toggleBtn && input) {
        toggleBtn.addEventListener('click', function () {
            if (input.type === 'password') {
                input.type = 'text';
                if (eyeOpen) eyeOpen.style.display = 'none';
                if (eyeClosed) eyeClosed.style.display = 'inline-flex';
            } else {
                input.type = 'password';
                if (eyeOpen) eyeOpen.style.display = 'inline-flex';
                if (eyeClosed) eyeClosed.style.display = 'none';
            }
        });
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>