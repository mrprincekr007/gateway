<?php
declare(strict_types=1);

/* Public page header (landing, auth). Set $pageTitle before including. */
require_once APP_ROOT . '/includes/icons.php';

$pageTitle = $pageTitle ?? SITE_NAME;
$bodyClass = $bodyClass ?? 'page';
$extraCss  = $extraCss ?? [];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= e($pageTitle) ?></title>
<link rel="icon" type="image/png" href="<?= asset('assets/img/logo.png') ?>">
<link rel="apple-touch-icon" href="<?= asset('assets/img/logo.png') ?>">
<meta name="theme-color" content="#060b1c">
<meta property="og:title" content="<?= e($pageTitle) ?>">
<meta property="og:description" content="<?= e(SITE_TAGLINE) ?>">
<meta property="og:type" content="website">
<meta name="twitter:card" content="summary_large_image">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Sora:wght@500;600;700&family=Poppins:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<?php if (strpos((string) ($bodyClass ?? ''), 'auth-page') !== false || !empty($isAuth)): ?>
<link rel="stylesheet" href="<?= asset('assets/css/auth.css') ?>">
<?php elseif (($bodyClass ?? '') === 'pay-page' || !empty($isPayPage)): ?>
<link rel="stylesheet" href="<?= asset('assets/css/pay.css') ?>">
<?php else: ?>
<link rel="stylesheet" href="<?= asset('assets/css/landing.css') ?>">
<?php endif; ?>
<?php foreach ($extraCss as $css): ?>
<link rel="stylesheet" href="<?= asset($css) ?>">
<?php endforeach; ?>
</head>
<body class="<?= e($bodyClass) ?>">
