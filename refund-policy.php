<?php
declare(strict_types=1);

require __DIR__ . '/config/config.php';

$sections = [
    ['1. About refunds', SITE_NAME . ' is a payment facilitation tool. Money collected through your payment links goes directly to your own UPI ID. Because we never hold funds, refunds for a transaction are initiated by you, the merchant, directly from your UPI app.'],
    ['2. Platform-fee refunds', 'If a platform processing fee was charged on a successful payment and you later issue a full refund to the customer, we will refund the corresponding platform fee for that transaction on request, provided the original payment was not fraudulent.'],
    ['3. Failed or duplicate payments', 'If a customer pays twice or a payment fails after capture, the funds remain with you (the merchant). Please refund the customer through your UPI app. We will mark the transaction status accordingly and assist with reconciliation.'],
    ['4. How to request a fee refund', 'Email us at ' . SITE_EMAIL . ' with your merchant ID, the order ID, and the UTR of the refunded payment. We will review and process eligible fee refunds within 7 business days.'],
    ['5. Disputes', 'Chargeback or withdrawal disputes are handled directly between you and your customer. We will provide documented transaction history to help you resolve them.'],
    ['6. No-service guarantee', 'If you believe a paid platform feature was not delivered as documented, contact support within 7 days for a pro-rata credit or refund evaluation.'],
];

$pageTitle = 'Refund Policy';
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
    <h1 style="font-size:28px;" data-reveal>Refund Policy</h1>
    <p class="text-mute mt-2" style="font-size:13.5px;" data-reveal>Last updated: <?= e(date('F j, Y')) ?></p>

    <div class="card mt-6" data-reveal>
        <?php foreach ($sections as $i => [$title, $body]): ?>
            <div style="padding:16px 0;<?= $i ? 'border-top:1px solid var(--color-hairline-2);' : '' ?>">
                <h2 style="font-size:16px;"><?= e($title) ?></h2>
                <p class="text-mute mt-2" style="font-size:13.5px;line-height:1.7;"><?= e($body) ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</main>

<?php include APP_ROOT . '/includes/footer.php'; ?>