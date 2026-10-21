<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';

if (!empty($_SESSION['admin_impersonator_id'])) {
    $adminId = (int) $_SESSION['admin_impersonator_id'];
    $lastMerchantId = (int) ($_SESSION['user_id'] ?? 0);

    $_SESSION['user_id'] = $adminId;
    unset($_SESSION['admin_impersonator_id']);

    flash_set('success', 'Returned to Admin Console.');
    if ($lastMerchantId > 0) {
        redirect('admin/user.php?id=' . $lastMerchantId);
    }
    redirect('admin/users.php');
}

redirect('admin/index.php');
