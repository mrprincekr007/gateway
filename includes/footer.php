<?php
/* Public page footer. Set $extraJs = [] before including. */
$extraJs = $extraJs ?? [];
?>
<?php if (strpos((string) ($bodyClass ?? ''), 'auth-page') !== false || !empty($isAuth)): ?>
<script src="<?= asset('assets/js/auth.js') ?>"></script>
<?php elseif (($bodyClass ?? '') === 'pay-page' || !empty($isPayPage)): ?>
<!-- Checkout terminal uses dedicated pay.js script -->
<?php else: ?>
<script src="<?= asset('assets/js/landing.js') ?>"></script>
<?php endif; ?>
</body>
</html>
