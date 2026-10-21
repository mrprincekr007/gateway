<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/icons.php';

$token = get('token');
$error = null;
$valid = false;
$email = null;

if ($token !== '') {
    $stmt = db()->prepare('SELECT * FROM password_resets WHERE token = ? LIMIT 1');
    $stmt->execute([$token]);
    $reset = $stmt->fetch();
    if (!$reset || strtotime($reset['expires_at']) <= time()) {
        $error = 'This reset link is invalid or has expired.';
    } else {
        $valid = true;
        $email = (string) $reset['email'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {
    if (!verify_csrf(post('csrf_token'))) {
        $error = 'Session expired. Please try again.';
    } elseif (mb_strlen(post('password')) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif (post('password') !== post('password_confirm')) {
        $error = 'Passwords do not match.';
    } else {
        db()->prepare('UPDATE users SET password_hash = ? WHERE email = ?')
            ->execute([password_hash(post('password'), PASSWORD_DEFAULT), $email]);
        db()->prepare('DELETE FROM password_resets WHERE token = ?')->execute([$token]);
        flash_set('success', 'Password updated successfully. Please sign in with your new password.');
        redirect('login.php');
    }
}

$pageTitle = 'Reset Password · ' . SITE_NAME;
$bodyClass = 'auth-page';
include APP_ROOT . '/includes/header.php';
?>

<div class="auth-shell-v2">
    <!-- LEFT DARK LUXURY ASIDE (Desktop only > 960px) -->
    <aside class="auth-aside-dark">
        <a class="auth-aside-brand" href="<?= url('index.php') ?>">
            <img src="<?= asset('assets/img/logo.png') ?>" alt="<?= e(SITE_NAME) ?>" class="brand-logo-img">
            <span class="logo-text"><?= e(SITE_NAME) ?></span>
        </a>

        <div class="auth-aside-content">
            <div class="auth-aside-eyebrow">
                <span class="dot"></span>
                <span>SECURITY UPDATE</span>
            </div>
            <h2 class="auth-aside-heading">
                Set a strong new <span class="accent">password</span>.
            </h2>
            <p class="auth-aside-desc">
                Choose a strong password to protect your merchant account and API keys.
            </p>

            <!-- Floating Simulated BCrypt Badge -->
            <div class="auth-live-card">
                <div class="auth-live-card-top">
                    <span class="auth-live-card-amt" style="font-size:18px;">Encrypted</span>
                    <span class="auth-live-card-status">
                        <?= icon('shield', 12) ?> BCrypt Hash
                    </span>
                </div>
                <div class="auth-live-card-meta">
                    <span>Hardware Encrypted</span>
                    <span>Zero Plaintext</span>
                </div>
            </div>
        </div>

        <div class="auth-aside-footer">
            <?= icon('shield', 15, 'style="color:#38bdf8;"') ?>
            <span>256-Bit SSL Encrypted</span>
        </div>
    </aside>

    <!-- MOBILE HEADER (Clean & Minimal) -->
    <div class="auth-mobile-hero">
        <a class="auth-mobile-hero-brand" href="<?= url('index.php') ?>">
            <img src="<?= asset('assets/img/logo.png') ?>" alt="<?= e(SITE_NAME) ?>" class="brand-logo-img">
            <span class="logo-text"><?= e(SITE_NAME) ?></span>
        </a>
    </div>

    <!-- MAIN FORM SURFACE -->
    <main class="auth-main-panel">
        <div class="auth-mobile-card-wrap">
            <div class="auth-card-surface">
                <div class="auth-box-inner">
                    <!-- Desktop Brand Mark (shown > 960px) -->
                    <a class="auth-mobile-brand" href="<?= url('index.php') ?>">
                        <img src="<?= asset('assets/img/logo.png') ?>" alt="<?= e(SITE_NAME) ?>" class="brand-logo-img">
                        <span class="logo-text"><?= e(SITE_NAME) ?></span>
                    </a>

                    <h1 class="auth-heading-title">Reset password</h1>
                    <p class="auth-heading-sub">
                        <?= $valid ? 'Enter a new password for <b>' . e($email) . '</b>.' : 'Enter your password reset verification link.' ?>
                    </p>

                    <?php if ($error): ?>
                        <div class="dash-alert dash-alert-error" style="margin-bottom:20px;">
                            <div class="alert-icon-wrap"><?= icon('alert', 18) ?></div>
                            <div class="alert-text-wrap"><?= e($error) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($valid): ?>
                        <form method="post" action="<?= url('reset-password.php?token=' . urlencode($token)) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="token" value="<?= e($token) ?>">

                            <div class="auth-input-group">
                                <label for="password">New password</label>
                                <div class="auth-field-wrap">
                                    <span class="auth-field-icon"><?= icon('lock', 18) ?></span>
                                    <input class="auth-input-ctrl" type="password" id="password" name="password" placeholder="At least 8 characters" required autofocus autocomplete="new-password">
                                    <button type="button" class="auth-pwd-toggle" onclick="toggleAuthPwd('password', this)" aria-label="Toggle password visibility">
                                        <?= icon('eye', 18) ?>
                                    </button>
                                </div>
                            </div>

                            <div class="auth-input-group">
                                <label for="password_confirm">Confirm new password</label>
                                <div class="auth-field-wrap">
                                    <span class="auth-field-icon"><?= icon('lock', 18) ?></span>
                                    <input class="auth-input-ctrl" type="password" id="password_confirm" name="password_confirm" placeholder="Repeat new password" required autocomplete="new-password">
                                    <button type="button" class="auth-pwd-toggle" onclick="toggleAuthPwd('password_confirm', this)" aria-label="Toggle password visibility">
                                        <?= icon('eye', 18) ?>
                                    </button>
                                </div>
                            </div>

                            <button class="auth-btn-action" type="submit">
                                <span>Save Password &amp; Sign in</span>
                                <?= icon('arrow-right', 16) ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <div style="margin-top:20px;">
                            <a class="auth-btn-action" href="<?= url('forgot-password.php') ?>" style="text-decoration:none;">
                                <span>Request a New Reset Link</span>
                                <?= icon('arrow-right', 16) ?>
                            </a>
                        </div>
                    <?php endif; ?>

                    <p class="auth-switch-link">
                        Remember your password? <a href="<?= url('login.php') ?>">Back to sign in &rarr;</a>
                    </p>

                    <!-- Trust Strip -->
                    <div class="auth-trust-strip">
                        <span class="auth-trust-item"><?= icon('shield', 13) ?> 256-Bit SSL</span>
                        <span class="auth-trust-item"><?= icon('zap', 13) ?> Instant Update</span>
                        <span class="auth-trust-item"><?= icon('check', 13) ?> Salted Hash</span>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
function toggleAuthPwd(inputId, btn) {
    const input = document.getElementById(inputId);
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        btn.innerHTML = '<?= addslashes(icon('eye-off', 18)) ?>';
    } else {
        input.type = 'password';
        btn.innerHTML = '<?= addslashes(icon('eye', 18)) ?>';
    }
}
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>