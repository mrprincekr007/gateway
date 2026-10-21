<?php
declare(strict_types=1);

require __DIR__ . '/config/config.php';

http_response_code(404);
header('X-Robots-Tag: noindex, nofollow');

$reqPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

$pageTitle = '404 — Page not found · ' . SITE_NAME;
$bodyClass = 'landing has-glow';
$extraCss  = ['assets/css/error.css'];
include APP_ROOT . '/includes/header.php';
?>

<header class="landing-nav">
    <div class="container nav-inner">
        <a class="brand" href="<?= url('index.php') ?>">
            <img src="<?= asset('assets/img/logo.png') ?>" alt="<?= e(SITE_NAME) ?>" class="brand-logo-img">
            <span class="logo-text"><?= e(SITE_NAME) ?></span>
        </a>

        <nav class="nav-links">
            <a href="<?= url('index.php') ?>">Home</a>
            <a href="<?= url('index.php') ?>#features">Features</a>
            <a href="<?= url('index.php') ?>#pricing">Pricing</a>
            <a href="<?= url('user/docs.php') ?>">Docs</a>
        </nav>

        <div class="nav-actions">
            <?php if (is_logged_in()): ?>
                <a class="btn btn-primary btn-sm" href="<?= url('user/dashboard.php') ?>">Dashboard</a>
            <?php else: ?>
                <a class="nav-login" href="<?= url('login.php') ?>">Sign in</a>
                <a class="btn btn-primary btn-sm" href="<?= url('register.php') ?>">Get started</a>
            <?php endif; ?>
            <button class="nav-burger" id="navBurger" type="button" aria-label="Menu" aria-expanded="false">
                <span></span><span></span><span></span>
            </button>
        </div>
    </div>
    <div class="mobile-nav" id="mobileNav">
        <a href="<?= url('index.php') ?>"><span class="mn-ic"><?= icon('layout-dashboard', 17) ?></span><span class="mn-label">Home</span><span class="mn-arrow"><?= icon('chevron-right', 16) ?></span></a>
        <a href="<?= url('index.php') ?>#features"><span class="mn-ic"><?= icon('zap', 17) ?></span><span class="mn-label">Features</span><span class="mn-arrow"><?= icon('chevron-right', 16) ?></span></a>
        <a href="<?= url('index.php') ?>#pricing"><span class="mn-ic"><?= icon('rupee', 17) ?></span><span class="mn-label">Pricing</span><span class="mn-arrow"><?= icon('chevron-right', 16) ?></span></a>
        <a href="<?= url('user/docs.php') ?>"><span class="mn-ic"><?= icon('book', 17) ?></span><span class="mn-label">Docs</span><span class="mn-arrow"><?= icon('chevron-right', 16) ?></span></a>
        <div class="mobile-nav-actions">
            <?php if (is_logged_in()): ?>
                <a class="btn btn-primary mobile-nav-btn" href="<?= url('user/dashboard.php') ?>">Dashboard</a>
            <?php else: ?>
                <a class="btn btn-secondary mobile-nav-btn" href="<?= url('login.php') ?>">Sign in</a>
                <a class="btn btn-primary mobile-nav-btn" href="<?= url('register.php') ?>">Get started</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<main class="error-hero">
    <div class="error-orb" aria-hidden="true"></div>
    <div class="container">
        <div class="error-card" data-reveal="scale">
            <div class="error-code">404</div>

            <span class="error-badge"><?= icon('alert', 13) ?> Page not found</span>

            <h1 class="error-title">This page took a wrong turn</h1>
            <p class="error-sub">The link you followed may be broken, or the page may have been moved or removed. Let's get you back on track.</p>

            <code class="error-path" title="Requested path"><?= e($reqPath) ?></code>

            <div class="error-actions">
                <a class="btn btn-lg btn-primary" href="<?= url('index.php') ?>">
                    <?= icon('arrow-right', 16, 'style="transform:rotate(180deg);"') ?> Back to home
                </a>
                <?php if (is_logged_in()): ?>
                    <a class="btn btn-lg btn-secondary" href="<?= url('user/dashboard.php') ?>">Go to Dashboard</a>
                <?php else: ?>
                    <a class="btn btn-lg btn-secondary" href="<?= url('login.php') ?>">Sign in</a>
                <?php endif; ?>
            </div>

            <div class="error-links">
                <a href="<?= url('index.php') ?>">Home</a>
                <span class="error-dot"></span>
                <a href="<?= url('user/docs.php') ?>">Documentation</a>
                <span class="error-dot"></span>
                <a href="<?= url('status.php') ?>">System status</a>
                <span class="error-dot"></span>
                <a href="<?= e(SITE_EMAIL !== '' ? 'mailto:' . SITE_EMAIL : '#') ?>">Contact support</a>
            </div>
        </div>
    </div>
</main>

<!-- ================= FOOTER ================= -->
<footer class="landing-footer">
    <div class="container">
        <div class="footer-grid">
            <div class="footer-brand">
                <a class="brand" href="<?= url('index.php') ?>">
                    <img src="<?= asset('assets/img/logo.png') ?>" alt="<?= e(SITE_NAME) ?>" class="brand-logo-img">
                    <span class="logo-text"><?= e(SITE_NAME) ?></span>
                </a>
            </div>
            <div class="footer-col">
                <h4>Product</h4>
                <a href="<?= url('index.php') ?>#features">Features</a>
                <a href="<?= url('index.php') ?>#pricing">Pricing</a>
                <a href="<?= url('index.php') ?>#api">API</a>
                <a href="<?= url('status.php') ?>">System status</a>
            </div>
            <div class="footer-col">
                <h4>Resources</h4>
                <a href="<?= url('user/docs.php') ?>">Documentation</a>
                <a href="<?= url('index.php') ?>#faq">FAQ</a>
                <a href="<?= url('register.php') ?>">Create account</a>
                <a href="<?= url('login.php') ?>">Sign in</a>
            </div>
            <div class="footer-col">
                <h4>Company</h4>
                <a href="<?= url('terms.php') ?>">Terms</a>
                <a href="<?= url('privacy.php') ?>">Privacy</a>
                <a href="<?= url('refund-policy.php') ?>">Refund policy</a>
                <a href="<?= e(SITE_EMAIL !== '' ? 'mailto:' . SITE_EMAIL : '#') ?>">Support</a>
            </div>
        </div>
        <div class="footer-bottom">
            <span>&copy; <?= date('Y') ?> <?= e(SITE_NAME) ?>. All rights reserved.</span>
            <a class="footer-status" href="<?= url('status.php') ?>"><span class="dot"></span> System status</a>
        </div>
    </div>
</footer>

<?php
include APP_ROOT . '/includes/footer.php';
?>
