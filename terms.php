<?php
declare(strict_types=1);

require __DIR__ . '/config/config.php';

$sections = [
    ['1. Acceptance of terms', 'By creating an account or using the ' . SITE_NAME . ' platform, you agree to these terms. If you do not agree, do not use the service.'],
    ['2. Description of service', SITE_NAME . ' provides a tool to create UPI payment links, QR codes and webhook notifications. ' . SITE_NAME . ' is a facilitator and does not hold, settle or guarantee any funds. All payments are processed directly between you (the merchant) and your customer through your own UPI ID.'],
    ['3. Your account', 'You are responsible for keeping your login credentials and API keys confidential. Any activity performed under your account is your responsibility. Notify us immediately of any suspected unauthorized use.'],
    ['4. Merchant obligations', 'You agree to use the service only for lawful purposes and in compliance with applicable law. You may not use the service to collect payments for prohibited goods or services.'],
    ['5. Payment and fees', 'Certain plans may include a processing fee applied per successful payment. Fees are shown before you confirm a transaction. Free tiers are provided with the limits displayed on the pricing page.'],
    ['6. Availability and disclaimers', 'The service is provided "as is" without warranties of any kind. We do not guarantee uninterrupted availability, and we are not liable for failed deliveries, network outages, or third-party UPI/IMAP failures.'],
    ['7. Limitation of liability', 'To the maximum extent permitted by law, our liability is limited to the amount you have paid to us in the 30 days preceding the event giving rise to liability.'],
    ['8. Account termination', 'We may suspend or terminate accounts that violate these terms or applicable law. You may delete your account at any time.'],
    ['9. Changes to these terms', 'We may update these terms from time to time. Continued use of the service after changes constitutes acceptance of the updated terms.'],
];

$pageTitle = 'Terms of Service';
$bodyClass = 'page';
include APP_ROOT . '/includes/header.php';
?>

<header class="landing-nav">
    <div class="container nav-inner">
        <a class="brand" href="<?= url('index.php') ?>"><img src="<?= asset('assets/img/logo.png') ?>" alt="<?= e(SITE_NAME) ?>" class="brand-logo-img"><span class="logo-text"><?= e(SITE_NAME) ?></span></a>
        <div class="nav-actions"><?php if (is_logged_in()): ?><a class="btn btn-primary btn-sm" href="<?= url('user/dashboard.php') ?>">Dashboard</a><?php else: ?><a class="nav-login" href="<?= url('login.php') ?>">Sign in</a><?php endif; ?></div>
    </div>
</header>

<main class="container" style="padding-top:56px;padding-bottom:64px;max-width:760px;">
    <h1 style="font-size:28px;" data-reveal>Terms of Service</h1>
    <p class="text-mute mt-2" style="font-size:13.5px;" data-reveal>Last updated: <?= e(date('F j, Y')) ?></p>

    <div class="card mt-6" data-reveal>
        <?php foreach ($sections as $i => [$title, $body]): ?>
            <div style="padding:16px 0;<?= $i ? 'border-top:1px solid var(--color-hairline-2);' : '' ?>">
                <h2 style="font-size:16px;"><?= e($title) ?></h2>
                <p class="text-mute mt-2" style="font-size:13.5px;line-height:1.7;"><?= e($body) ?></p>
            </div>
        <?php endforeach; ?>
    </div>

    <p class="text-mute mt-6" style="font-size:12.5px;">Questions about these terms? Contact <a href="mailto:<?= e(SITE_EMAIL) ?>" style="color:var(--color-primary);"><?= e(SITE_EMAIL) ?></a>.</p>
</main>

<?php include APP_ROOT . '/includes/footer.php'; ?>