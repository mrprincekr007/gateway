<?php
declare(strict_types=1);

/* =========================================================
   GET /api/status
   Basic service + account status.
   ========================================================= */

require_once __DIR__ . '/../includes/api.php';

[$key, $user] = api_auth();

$gmail = (string) setting_get($user['id'], 'gmail_email', '');
$pass  = (string) setting_get($user['id'], 'gmail_app_password', '');

$counts = ['total' => 0, 'success' => 0, 'pending' => 0];
$stmt = db()->prepare('SELECT status, COUNT(*) c FROM orders WHERE user_id = ? GROUP BY status');
$stmt->execute([$user['id']]);
foreach ($stmt->fetchAll() as $row) {
    $counts['total'] += (int) $row['c'];
    if (isset($counts[$row['status']])) {
        $counts[$row['status']] = (int) $row['c'];
    }
}

api_ok([
    'service'         => SITE_NAME . ' API',
    'status'          => 'ok',
    'version'         => '1.0',
    'time'            => date('c'),
    'account'         => $user['email'],
    'imap_configured' => $gmail !== '' && $pass !== '',
    'counts'          => $counts,
]);
