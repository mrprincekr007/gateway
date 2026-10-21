<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/admin-ops.php';
require_once APP_ROOT . '/includes/smtp.php';

$user   = require_admin();
$active = 'settings';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf_token'))) {
        $error = 'Session expired. Please try again.';
    } else {
        $action = (string) post('action');

        // Card 1: General Platform Settings
        if ($action === 'save_general') {
            $generalKeys = [
                'platform_name', 'support_email', 'support_phone',
                'signup_enabled', 'email_otp_enabled', 'maintenance_mode',
            ];
            foreach ($generalKeys as $k) {
                if ($k === 'maintenance_mode' || $k === 'signup_enabled' || $k === 'email_otp_enabled') {
                    ops_setting_set($k, post($k) === '1' ? '1' : '0');
                } else {
                    ops_setting_set($k, post($k));
                }
            }
            ops_audit('settings.general.update', 'app_settings', null, 'Updated general platform settings');
            flash_set('success', 'General platform settings saved successfully.');
            redirect('admin/settings.php');

        // Card 2: Rate Limits & Anti-Spam Controls
        } elseif ($action === 'save_rate_limits') {
            $rateKeys = [
                'rate_limiting_enabled',
                'rate_login_attempts', 'rate_login_lockout_minutes',
                'rate_register_attempts', 'rate_register_window_minutes',
                'rate_reset_attempts', 'rate_reset_window_minutes',
                'otp_resend_cooldown_seconds', 'otp_max_wrong_attempts', 'otp_validity_minutes',
                'rate_global_ip_per_minute', 'rate_order_create_attempts', 'rate_utr_submit_attempts',
            ];
            foreach ($rateKeys as $k) {
                if ($k === 'rate_limiting_enabled') {
                    ops_setting_set($k, post($k) === '1' ? '1' : '0');
                } else {
                    ops_setting_set($k, post($k));
                }
            }
            if (post('rate_limiting_enabled') === '0') {
                security_clear_all_limits();
            }
            ops_audit('settings.rate_limits.update', 'app_settings', null, 'Updated rate limits & anti-spam controls');
            flash_set('success', 'Rate limits & anti-spam controls saved successfully.');
            redirect('admin/settings.php');

        // Card 3: Outbound Email & OTP Delivery (SMTP)
        } elseif ($action === 'save_smtp') {
            $smtpEmail = trim((string) post('smtp_email'));
            $smtpPass  = trim((string) post('smtp_password'));
            $smtpHost  = trim((string) post('smtp_host', 'smtp.gmail.com'));
            $smtpPort  = (int) post('smtp_port', 587);

            ops_setting_set('smtp_email', $smtpEmail);
            ops_setting_set('smtp_host', $smtpHost !== '' ? $smtpHost : 'smtp.gmail.com');
            ops_setting_set('smtp_port', (string) ($smtpPort > 0 ? $smtpPort : 587));

            $cleanPass = (string) preg_replace('/\s+/', '', $smtpPass);
            if ($cleanPass !== '') {
                ops_setting_set('smtp_password', encrypt_secret($cleanPass));
                try {
                    setting_set(1, 'gmail_email', $smtpEmail);
                    setting_set(1, 'gmail_app_password', (string) encrypt_secret($cleanPass));
                } catch (Throwable $e) {}
            } else {
                try {
                    setting_set(1, 'gmail_email', $smtpEmail);
                } catch (Throwable $e) {}
            }
            ops_audit('settings.smtp.update', 'app_settings', null, "Updated outbound SMTP email to {$smtpEmail}");
            flash_set('success', "Outbound Gmail SMTP configured successfully! OTPs will now be sent from {$smtpEmail}.");
            redirect('admin/settings.php#card-smtp');

        // Test Outbound SMTP Delivery
        } elseif ($action === 'test_smtp') {
            $targetEmail = trim((string) post('test_email', $user['email']));
            if (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
                flash_set('error', 'Please enter a valid recipient email for the test delivery.');
            } else {
                $testCode = (string) random_int(100000, 999999);
                $testHtml = smtp_render_otp_template(
                    $testCode,
                    'SMTP Live Connection Test',
                    'This is a live test transmission from your <b>' . htmlspecialchars(SITE_NAME) . '</b> admin panel. If you are reading this email in your Primary Inbox, your Gmail SMTP configuration is 100% operational and ready for live user OTP verification!'
                );
                $res = smtp_send(
                    $targetEmail,
                    "{$testCode} is your " . SITE_NAME . " verification code",
                    $testHtml
                );
                if ($res['ok']) {
                    ops_audit('settings.smtp.test', 'smtp', null, "Sent test email to {$targetEmail}");
                    flash_set('success', "Test OTP ({$testCode}) delivered successfully to {$targetEmail}! Check your inbox.");
                } else {
                    flash_set('error', "SMTP Delivery failed: " . ($res['error'] ?? 'Unknown network error'));
                }
            }
            redirect('admin/settings.php#card-smtp');

        // Emergency Clear Rate Limit Lockouts
        } elseif ($action === 'clear_all_rate_limits') {
            $cleared = security_clear_all_limits();
            ops_audit('security.limits.cleared', 'rate_limits', null, "Cleared {$cleared} active rate-limit locks");
            flash_set('success', "Cleared {$cleared} active rate-limit lockouts! All blocked IPs and users are now unblocked.");
            redirect('admin/settings.php');
        }
    }
}

$settings = ops_settings_all();
$smtpConfig = smtp_get_config();

/* Environment diagnostics */
$env = [
    'PHP Version'      => PHP_VERSION,
    'App Environment'  => APP_ENV,
    'Database'         => DB_NAME . ' @ ' . DB_HOST,
    'Encryption Key'   => (APP_KEY && APP_KEY !== 'CHANGE_ME_to_a_long_random_secret_string_1234567890') ? 'Configured' : 'DEFAULT (change!)',
    'Image (GD)'       => extension_loaded('gd') ? 'Loaded' : 'Not loaded',
    'OpenSSL'          => extension_loaded('openssl') ? 'Loaded' : 'Not loaded',
    'IMAP'             => extension_loaded('imap') ? 'Loaded' : 'Not loaded',
    'Server Time'      => ist_datetime(),
];

$pageTitle   = 'Platform Settings';
$pageHeading = 'Platform & Gateway Settings';
$pageSub     = 'Card-by-card controls for platform, rate limits, Gmail OTP, and webhooks.';
include __DIR__ . '/includes/header.php';
?>

<h1 class="sr-only"><?= e($pageHeading) ?></h1>

<?php if ($error): ?>
    <div class="alert alert-error mb-4"><?= icon('alert', 18) ?><span><?= e($error) ?></span></div>
<?php endif; ?>
<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?= $f['type'] === 'error' ? 'error' : 'success' ?> mb-4"><?= icon($f['type'] === 'error' ? 'alert' : 'check-circle', 18) ?><span><?= e($f['message']) ?></span></div>
<?php endif; ?>

<div class="dash-grid" style="grid-template-columns: 1.65fr 1fr;gap:20px;align-items:start;">
    <div>
        <!-- CARD 1: GENERAL SETTINGS -->
        <form method="post" action="<?= url('admin/settings.php') ?>" class="card" id="card-general" style="padding:22px 24px;margin-bottom:20px;display:block;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_general">

            <div class="settings-card-head">
                <h2 class="card-title settings-card-title flex items-center gap-2">
                    <span style="color:var(--color-primary);"><?= icon('settings', 18) ?></span> General Settings
                </h2>
            </div>

            <div class="form-group mb-3">
                <label class="form-label">Platform Name</label>
                <input class="form-input" type="text" name="platform_name" value="<?= e($settings['platform_name'] ?? SITE_NAME) ?>">
            </div>
            <div class="dash-grid" style="grid-template-columns:1fr 1fr;gap:14px;">
                <div class="form-group">
                    <label class="form-label">Support Email</label>
                    <input class="form-input" type="email" name="support_email" value="<?= e($settings['support_email'] ?? SITE_EMAIL) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Support Phone</label>
                    <input class="form-input" type="text" name="support_phone" value="<?= e($settings['support_phone'] ?? '') ?>">
                </div>
            </div>
            <div class="dash-grid" style="grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:14px;margin-top:14px;">
                <div class="form-group">
                    <label class="form-label">Merchant Sign-ups</label>
                    <select class="form-select" name="signup_enabled">
                        <option value="1" <?= ($settings['signup_enabled'] ?? '1') === '1' ? 'selected' : '' ?>>Enabled</option>
                        <option value="0" <?= ($settings['signup_enabled'] ?? '1') === '0' ? 'selected' : '' ?>>Disabled</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Email OTP Verification</label>
                    <select class="form-select" name="email_otp_enabled">
                        <option value="1" <?= ($settings['email_otp_enabled'] ?? '1') === '1' ? 'selected' : '' ?>>Enabled (Mandatory)</option>
                        <option value="0" <?= ($settings['email_otp_enabled'] ?? '1') === '0' ? 'selected' : '' ?>>Disabled</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Maintenance Mode</label>
                    <select class="form-select" name="maintenance_mode">
                        <option value="0" <?= ($settings['maintenance_mode'] ?? '0') === '0' ? 'selected' : '' ?>>Off (Live)</option>
                        <option value="1" <?= ($settings['maintenance_mode'] ?? '0') === '1' ? 'selected' : '' ?>>On (Read-only)</option>
                    </select>
                </div>
            </div>

            <div class="settings-save-row">
                <button class="btn btn-primary btn-sm flex items-center gap-2" type="submit">
                    <?= icon('check-circle', 14) ?> Save General Settings
                </button>
            </div>
        </form>

        <!-- Subscription & Plan Settings Link Banner -->
        <div class="card" style="padding:22px 24px;margin-bottom:20px;background:linear-gradient(135deg,rgba(37,99,235,0.08),rgba(14,165,233,0.04));border:1px solid rgba(37,99,235,0.22);">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
                <div style="display:flex;align-items:center;gap:14px;">
                    <div style="width:44px;height:44px;border-radius:var(--r-md);background:rgba(37,99,235,0.15);color:var(--color-primary);display:flex;align-items:center;justify-content:center;">
                        <?= icon('wallet', 22) ?>
                    </div>
                    <div>
                        <div style="font-weight:700;font-size:var(--fs-lg);color:var(--ink-1);">
                            Subscription &amp; Monetization Settings
                        </div>
                    </div>
                </div>
                <a href="<?= url('admin/subscription-settings.php') ?>" class="btn btn-primary btn-sm flex items-center gap-2">
                    Subscription Settings
                    <?= icon('arrow-right', 14) ?>
                </a>
            </div>
        </div>

        <!-- CARD 2: RATE LIMITS & ANTI-SPAM CONTROLS -->
        <form method="post" action="<?= url('admin/settings.php') ?>" class="card" id="card-rate-limits" style="padding:22px 24px;margin-bottom:20px;display:block;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_rate_limits">

            <div class="settings-card-head">
                <div class="flex items-center gap-2">
                    <h2 class="card-title settings-card-title flex items-center gap-2">
                        <span style="color:#f59e0b;"><?= icon('shield', 18) ?></span> Rate Limits &amp; Anti-Spam Controls
                    </h2>
                    <span class="badge badge-neutral">
                        <?= security_count_active_limits() ?> Active Throttles
                    </span>
                </div>
            </div>

            <!-- MASTER RATE LIMIT ON/OFF BANNER -->
            <?php $rateLimiterActive = ($settings['rate_limiting_enabled'] ?? '1') === '1'; ?>
            <div style="margin:0 -24px 24px;padding:16px 24px;background:<?= $rateLimiterActive ? 'rgba(34,197,94,0.08)' : 'rgba(239,68,68,0.09)' ?>;border:1.5px solid <?= $rateLimiterActive ? 'rgba(34,197,94,0.35)' : 'rgba(239,68,68,0.35)' ?>;border-radius:var(--r-md);display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap;">
                <div style="flex:1;min-width:260px;">
                    <div style="font-weight:700;font-size:14px;color:<?= $rateLimiterActive ? '#16a34a' : '#ef4444' ?>;display:flex;align-items:center;gap:8px;">
                        <?= icon($rateLimiterActive ? 'shield' : 'alert', 18) ?>
                        <span>Rate Limiting Master Switch: <b><?= $rateLimiterActive ? 'ON (Protection Active)' : 'OFF (Testing Mode — All Limits Bypassed)' ?></b></span>
                    </div>
                </div>
                <div>
                    <select class="form-select" name="rate_limiting_enabled" style="font-weight:700;font-size:var(--fs-md);height:42px;padding:0 14px;border-radius:var(--r-sm);background:var(--color-bg-base);border-color:<?= $rateLimiterActive ? '#22c55e' : '#ef4444' ?>;color:<?= $rateLimiterActive ? '#16a34a' : '#ef4444' ?>;">
                        <option value="1" <?= $rateLimiterActive ? 'selected' : '' ?>>ON (Protection Live)</option>
                        <option value="0" <?= !$rateLimiterActive ? 'selected' : '' ?>>OFF (Bypass for Testing)</option>
                    </select>
                </div>
            </div>

            <!-- 1. Login Lockout -->
            <div class="rate-section-label">
                <?= icon('lock', 14) ?> Login &amp; Brute-Force Lockout
            </div>
            <div class="dash-grid" style="grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px;">
                <div class="form-group">
                    <label class="form-label">Max Failed Login Attempts</label>
                    <input class="form-input" type="number" min="1" max="50" name="rate_login_attempts" value="<?= e($settings['rate_login_attempts'] ?? '5') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Lockout Duration (Minutes)</label>
                    <input class="form-input" type="number" min="1" max="180" name="rate_login_lockout_minutes" value="<?= e($settings['rate_login_lockout_minutes'] ?? '5') ?>" required>
                </div>
            </div>

            <!-- 2. Registration -->
            <div class="rate-section-label">
                <?= icon('user', 14) ?> Registration &amp; Account Sign-ups
            </div>
            <div class="dash-grid" style="grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px;">
                <div class="form-group">
                    <label class="form-label">Max Registrations per IP</label>
                    <input class="form-input" type="number" min="1" max="100" name="rate_register_attempts" value="<?= e($settings['rate_register_attempts'] ?? '10') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Registration Window (Minutes)</label>
                    <input class="form-input" type="number" min="1" max="360" name="rate_register_window_minutes" value="<?= e($settings['rate_register_window_minutes'] ?? '15') ?>" required>
                </div>
            </div>

            <!-- 3. Password Reset -->
            <div class="rate-section-label">
                <?= icon('key', 14) ?> Password Reset (Forgot Password)
            </div>
            <div class="dash-grid" style="grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px;">
                <div class="form-group">
                    <label class="form-label">Max Reset Requests</label>
                    <input class="form-input" type="number" min="1" max="50" name="rate_reset_attempts" value="<?= e($settings['rate_reset_attempts'] ?? '5') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Reset Window (Minutes)</label>
                    <input class="form-input" type="number" min="1" max="180" name="rate_reset_window_minutes" value="<?= e($settings['rate_reset_window_minutes'] ?? '10') ?>" required>
                </div>
            </div>

            <!-- 4. Gmail OTP -->
            <div class="rate-section-label">
                <?= icon('mail', 14) ?> Gmail OTP Verification &amp; Expiry
            </div>
            <div class="dash-grid" style="grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px;">
                <div class="form-group">
                    <label class="form-label">OTP Resend Cooldown (Seconds)</label>
                    <input class="form-input" type="number" min="5" max="300" name="otp_resend_cooldown_seconds" value="<?= e($settings['otp_resend_cooldown_seconds'] ?? '30') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Max Wrong Attempts</label>
                    <input class="form-input" type="number" min="1" max="20" name="otp_max_wrong_attempts" value="<?= e($settings['otp_max_wrong_attempts'] ?? '5') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">OTP Validity (Minutes)</label>
                    <input class="form-input" type="number" min="2" max="60" name="otp_validity_minutes" value="<?= e($settings['otp_validity_minutes'] ?? '10') ?>" required>
                </div>
            </div>

            <!-- 5. Traffic & Flood -->
            <div class="rate-section-label">
                <?= icon('zap', 14) ?> Flood &amp; Traffic Protection
            </div>
            <div class="dash-grid" style="grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                <div class="form-group">
                    <label class="form-label">Global Requests / Min per IP</label>
                    <input class="form-input" type="number" min="0" max="1200" name="rate_global_ip_per_minute" value="<?= e($settings['rate_global_ip_per_minute'] ?? '300') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Max Orders per 10 Min</label>
                    <input class="form-input" type="number" min="5" max="300" name="rate_order_create_attempts" value="<?= e($settings['rate_order_create_attempts'] ?? '50') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Max UTR Tries per Order</label>
                    <input class="form-input" type="number" min="3" max="30" name="rate_utr_submit_attempts" value="<?= e($settings['rate_utr_submit_attempts'] ?? '10') ?>" required>
                </div>
            </div>

            <div class="settings-save-row">
                <button class="btn btn-primary btn-sm flex items-center gap-2" type="submit">
                    <?= icon('check-circle', 14) ?> Save Rate Limits &amp; Anti-Spam
                </button>
            </div>

            <!-- Emergency Lockout Reset Sub-bar -->
            <div style="margin:14px -24px 0;padding:14px 24px;background:rgba(239,68,68,0.06);border:1px solid rgba(239,68,68,0.2);border-radius:var(--r-md);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div>
                    <div style="font-weight:600;font-size:var(--fs-md);color:var(--ink-danger);">Emergency Lockout Reset</div>
                </div>
                <button type="submit" form="formClearLimits" class="btn btn-danger btn-sm flex items-center gap-2" onclick="return confirm('Clear all current rate-limit counters and unblock all locked users/IPs immediately?');">
                    <?= icon('refresh', 13) ?> Clear All Active Lockouts
                </button>
            </div>
        </form>

        <!-- CARD 3: OUTBOUND EMAIL & OTP DELIVERY (SMTP) -->
        <form method="post" action="<?= url('admin/settings.php') ?>" class="card" id="card-smtp" style="padding:22px 24px;margin-bottom:20px;display:block;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_smtp">

            <div class="settings-card-head">
                <div class="flex items-center gap-2">
                    <h2 class="card-title settings-card-title flex items-center gap-2">
                        <span style="color:#10b981;"><?= icon('mail', 18) ?></span> Outbound Email &amp; OTP Delivery (SMTP)
                    </h2>
                    <span class="badge badge-success">
                        Active Sender: <?= e($smtpConfig['user'] ?: 'Not configured') ?>
                    </span>
                </div>
            </div>

            <div class="dash-grid" style="grid-template-columns:1.5fr 1fr;gap:14px;margin-bottom:14px;">
                <div class="form-group">
                    <label class="form-label">Gmail / SMTP Email Address</label>
                    <input class="form-input" type="email" name="smtp_email" value="<?= e($settings['smtp_email'] ?? $smtpConfig['user']) ?>" placeholder="e.g. yourgateway@gmail.com" required>
                </div>
                <div class="form-group">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                        <label class="form-label" style="margin:0;">Gmail 16-Digit App Password</label>
                        <?php if (!empty($smtpConfig['pass'])): ?>
                            <span class="badge badge-success"><?= icon('check', 12) ?> Connected &amp; Saved</span>
                        <?php else: ?>
                            <span class="badge badge-warning"><?= icon('alert', 12) ?> Not Saved</span>
                        <?php endif; ?>
                    </div>
                    <div style="position:relative;display:flex;align-items:center;">
                        <input class="form-input" id="smtp_password_input" type="password" name="smtp_password" value="<?= e($smtpConfig['pass']) ?>" placeholder="Enter 16-char app password" style="padding-right:42px;font-family:monospace;letter-spacing:1px;" required>
                        <button type="button" id="toggleSmtpPassBtn" onclick="toggleSmtpPassword()" style="position:absolute;right:8px;background:none;border:none;cursor:pointer;color:var(--color-ink-muted,#64748b);padding:6px;display:flex;align-items:center;" title="Show or hide password">
                            <span id="eyeIconOpen"><?= icon('eye', 16) ?></span>
                            <span id="eyeIconClosed" style="display:none;"><?= icon('eye-off', 16) ?></span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="dash-grid" style="grid-template-columns:1.5fr 1fr;gap:14px;margin-bottom:18px;">
                <div class="form-group">
                    <label class="form-label">SMTP Host</label>
                    <input class="form-input" type="text" name="smtp_host" value="<?= e($settings['smtp_host'] ?? 'smtp.gmail.com') ?>" placeholder="smtp.gmail.com">
                </div>
                <div class="form-group">
                    <label class="form-label">SMTP Port (TLS)</label>
                    <input class="form-input" type="number" name="smtp_port" value="<?= e($settings['smtp_port'] ?? '587') ?>" placeholder="587">
                </div>
            </div>

            <div class="smtp-test-row">
                <div class="smtp-test-meta">
                    <?= icon('send', 15) ?> <span>Test Live Email Transmission</span>
                </div>
                <div class="smtp-test-controls">
                    <input class="form-input smtp-test-input" type="email" name="test_email" form="formTestSmtp" value="<?= e($user['email']) ?>" required placeholder="recipient@gmail.com">
                    <button class="btn btn-success btn-sm flex items-center gap-2" type="submit" form="formTestSmtp">
                        <?= icon('send', 13) ?> Send Test Email
                    </button>
                </div>
            </div>

            <div class="settings-save-row">
                <button class="btn btn-primary btn-sm flex items-center gap-2" type="submit">
                    <?= icon('check-circle', 14) ?> Save Email Settings
                </button>
            </div>
        </form>

    </div>

    <!-- Right Column: Diagnostics & Quick Links -->
    <div style="display:flex;flex-direction:column;gap:20px;">
        <!-- Environment Diagnostics Panel -->
        <div class="card" style="padding:22px 24px;">
            <h2 class="card-title settings-card-title settings-aside-title flex items-center gap-2">
                <span style="color:#7c3aed;"><?= icon('activity', 18) ?></span> Environment
            </h2>
            <div style="display:flex;flex-direction:column;gap:2px;">
                <?php foreach ($env as $label => $val): ?>
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--color-border);font-size:13px;">
                        <span class="text-mute"><?= e($label) ?></span>
                        <span style="font-weight:600;<?= str_contains((string) $val, 'DEFAULT') ? 'color:#dc2626;' : '' ?>"><?= e((string) $val) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="alert alert-info" style="margin-top:16px;font-size:12.5px;">
                <?= icon('alert', 15) ?>
                <span>Change <code>APP_KEY</code> and database credentials in <code>config/config.php</code> before going live.</span>
            </div>
        </div>

        <!-- Quick Access Card -->
        <div class="card" style="padding:22px 24px;">
            <h2 class="card-title settings-card-title settings-aside-title flex items-center gap-2">
                <span style="color:var(--color-primary);"><?= icon('shield', 18) ?></span> Quick Navigation
            </h2>
            <div class="settings-quicknav">
                <a href="<?= url('admin/risk.php') ?>" class="btn btn-secondary btn-sm btn-block flex items-center justify-between">
                    <span class="flex items-center gap-2"><?= icon('alert', 14) ?> Risk &amp; Anti-Fraud Log</span>
                    <?= icon('arrow-right', 12) ?>
                </a>
                <a href="<?= url('admin/system.php') ?>" class="btn btn-secondary btn-sm btn-block flex items-center justify-between">
                    <span class="flex items-center gap-2"><?= icon('activity', 14) ?> System Logs &amp; Crons</span>
                    <?= icon('arrow-right', 12) ?>
                </a>
                <a href="<?= url('admin/subscription-settings.php') ?>" class="btn btn-secondary btn-sm btn-block flex items-center justify-between">
                    <span class="flex items-center gap-2"><?= icon('refresh', 14) ?> Subscription Hub</span>
                    <?= icon('arrow-right', 12) ?>
                </a>
            </div>
        </div>
    </div>
</div>

<form id="formClearLimits" method="post" action="<?= url('admin/settings.php') ?>" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="clear_all_rate_limits">
</form>

<form id="formTestSmtp" method="post" action="<?= url('admin/settings.php') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="test_smtp">
</form>

<script>
function toggleSmtpPassword() {
    var input = document.getElementById('smtp_password_input');
    var eyeOpen = document.getElementById('eyeIconOpen');
    var eyeClosed = document.getElementById('eyeIconClosed');
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        if (eyeOpen) eyeOpen.style.display = 'none';
        if (eyeClosed) eyeClosed.style.display = 'inline-flex';
    } else {
        input.type = 'password';
        if (eyeOpen) eyeOpen.style.display = 'inline-flex';
        if (eyeClosed) eyeClosed.style.display = 'none';
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
