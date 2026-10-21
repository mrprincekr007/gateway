<?php
declare(strict_types=1);

require __DIR__ . '/config/config.php';

$sections = [
    ['1. Information we process', 'To provide the service we store your account details (name, email, UPI ID), API keys, payment links, orders and webhook configuration. Payment app credentials you connect (such as a Gmail app password) are encrypted at rest with AES-256-GCM and used only to read payment confirmation alerts.'],
    ['2. Payment data', 'Transaction records include order IDs, amounts, UPI references (UTR / RRN) and status. We do not process or store full card numbers or bank account details.'],
    ['3. How we use data', 'We use your data to operate the service, verify payments, deliver webhooks, provide support, and improve reliability. We do not sell your personal data.'],
    ['4. Data retention', 'Account data and transaction history are retained for as long as your account is active. You may request deletion of your account and associated data at any time.'],
    ['5. Cookies and sessions', 'The service uses session cookies to keep you signed in. They do not track you across unrelated sites.'],
    ['6. Third-party services', 'We rely on third-party services (e.g. UPI networks, Gmail IMAP, hosting providers) to deliver the service. Their privacy policies apply to the parts they process on your behalf.'],
    ['7. Security', 'Passwords are stored as salted hashes. Secrets are encrypted at rest. HTTPS is used for all traffic. No plaintext secrets are shown after creation.'],
    ['8. Your rights', 'Depending on your jurisdiction, you may have rights to access, correct or delete your data, and to object to certain processing. Contact us to exercise these rights.'],
];

$pageTitle = 'Privacy Policy';
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
    <h1 style="font-size:28px;" data-reveal>Privacy Policy</h1>
    <p class="text-mute mt-2" style="font-size:13.5px;" data-reveal>Last updated: <?= e(date('F j, Y')) ?></p>

    <div class="card mt-6" data-reveal>
        <?php foreach ($sections as $i => [$title, $body]): ?>
            <div style="padding:16px 0;<?= $i ? 'border-top:1px solid var(--color-hairline-2);' : '' ?>">
                <h2 style="font-size:16px;"><?= e($title) ?></h2>
                <p class="text-mute mt-2" style="font-size:13.5px;line-height:1.7;"><?= e($body) ?></p>
            </div>
        <?php endforeach; ?>
    </div>

    <p class="text-mute mt-6" style="font-size:12.5px;">Privacy questions? Contact <a href="mailto:<?= e(SITE_EMAIL) ?>" style="color:var(--color-primary);"><?= e(SITE_EMAIL) ?></a>.</p>
</main>

<?php include APP_ROOT . '/includes/footer.php'; ?>