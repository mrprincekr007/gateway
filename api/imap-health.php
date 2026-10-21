<?php
declare(strict_types=1);

/* =========================================================
   GET /api/imap-health?live=1
   Reports the Gmail/IMAP connection health for the account.
   ========================================================= */

require_once __DIR__ . '/../includes/api.php';

[$key, $user] = api_auth();

$gmail = (string) setting_get($user['id'], 'gmail_email', '');
$pass  = (string) setting_get($user['id'], 'gmail_app_password', '');
$configured = $gmail !== '' && $pass !== '';

$data = [
    'configured'   => $configured,
    'gmail_email'  => $gmail !== '' ? $gmail : null,
    'imap_status'  => $user['imap_status'] ?? 'not_configured',
    'last_check'   => setting_get($user['id'], 'imap_last_check', null),
];

if ($configured && api_param('live') === '1') {
    $plain = decrypt_secret($pass);
    $imap  = mail_connect($gmail, (string) $plain);
    if ($imap) {
        @imap_close($imap);
        $data['live_test'] = ['ok' => true, 'message' => 'IMAP login successful.'];
    } else {
        $data['live_test'] = ['ok' => false, 'message' => 'IMAP login failed: ' . (imap_last_error() ?: 'check the app password.')];
    }
}

api_ok($data);
