<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/admin.php';

$admin = require_admin();

$csrf = post('csrf_token') ?: get('csrf_token');
if (!verify_csrf($csrf)) {
    flash_set('danger', 'Security validation failed (invalid or expired session token).');
    redirect('admin/users.php');
}

$targetId = (int) (post('user_id') ?: post('id') ?: get('id'));
if ($targetId === (int) $admin['id']) {
    flash_set('info', 'You are already logged in as this administrator.');
    redirect('admin/users.php');
}

$target = admin_find_user($targetId);

if (!$target) {
    flash_set('danger', 'Merchant not found.');
    redirect('admin/users.php');
}

// Set impersonation session
$_SESSION['admin_impersonator_id'] = (int) $admin['id'];
$_SESSION['user_id'] = (int) $target['id'];

flash_set('info', 'Browsing merchant panel as ' . $target['name'] . ' (' . $target['email'] . '). Full control enabled.');
redirect('user/dashboard.php');
