<?php
declare(strict_types=1);

/* =========================================================
   GET /pay/status.php?order=<order_id>[&force=1]
   Public, minimal order-status feed used by the hosted checkout
   to poll for confirmation. While an order is pending this also
   triggers a throttled IMAP pull of the merchant's FamApp /
   bank notification inbox so a real credit is matched live.
   ========================================================= */

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/mail.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function pay_status_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

$orderId = get('order_id') !== '' ? get('order_id') : get('order');
if ($orderId === '') {
    pay_status_out(['status' => false, 'error' => 'Missing order id.'], 400);
}

$stmt = db()->prepare('SELECT * FROM orders WHERE order_id = ? LIMIT 1');
$stmt->execute([$orderId]);
$order = $stmt->fetch();
if (!$order) {
    pay_status_out(['status' => false, 'error' => 'Order not found.'], 404);
}

$state = (string) $order['status'];

/* Auto-expire a stale pending order. */
if ($state === 'pending' && $order['expires_at'] && strtotime((string) $order['expires_at']) <= time()) {
    db()->prepare('UPDATE orders SET status = "expired" WHERE id = ?')->execute([$order['id']]);
    $order['status'] = 'expired';
    $state = 'expired';
}

/* Polling rate limit per order */
if (!security_check_poll_rate((string) $order['order_id'])) {
    pay_status_out(['status' => false, 'error' => 'Polling rate limit exceeded. Please wait a moment.'], 429);
}

/* Live auto-verification: only while pending, optimized real-time 2s cadence + instant UTR */
if ($state === 'pending') {
    $uid       = (int) $order['user_id'];
    $gmail     = (string) setting_get($uid, 'gmail_email', '');
    $pass      = (string) setting_get($uid, 'gmail_app_password', '');
    $manualUtr = trim((string) ($_REQUEST['utr'] ?? ''));

    if ($manualUtr !== '') {
        // Rate limit UTR submission attempts
        if (!security_check_utr_rate((string) $order['order_id'])) {
            pay_status_out(['status' => false, 'error' => 'Too many UTR verification attempts. Please wait 5 minutes.'], 429);
        }

        $manualUtr = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $manualUtr));
        if (strlen($manualUtr) < 8 || strlen($manualUtr) > 32) {
            pay_status_out(['status' => false, 'error' => 'Invalid UTR format. Must be 8-22 characters.'], 400);
        }
    }

    if ($gmail !== '' && $pass !== '') {
        $force     = get('force') === '1' || $manualUtr !== '';
        $now       = time();
        $last      = (int) setting_get($uid, 'imap_last_autocheck', 0);
        $failUntil = (int) setting_get($uid, 'imap_fail_until', 0);

        if ($now >= $failUntil && ($force || $now - $last >= 2)) {
            setting_set($uid, 'imap_last_autocheck', (string) $now);
            try {
                $res = mail_process_user($uid, (string) $order['order_id'], $manualUtr ?: null);
                if (!empty($res['connected'])) {
                    setting_set($uid, 'imap_fail_until', '0');
                } else {
                    setting_set($uid, 'imap_fail_until', (string) ($now + 45));
                }
            } catch (\Throwable $e) {
                setting_set($uid, 'imap_fail_until', (string) ($now + 45));
            }
            $r = db()->prepare('SELECT status, utr, sender_name, paid_at FROM orders WHERE id = ? LIMIT 1');
            $r->execute([$order['id']]);
            $fresh = $r->fetch();
            if ($fresh) {
                $order['status']      = $fresh['status'];
                $order['utr']         = $fresh['utr'];
                $order['sender_name'] = $fresh['sender_name'];
                $order['paid_at']     = $fresh['paid_at'];
                $state                = (string) $fresh['status'];
            }
        }
    }
}

$expiresIn = $order['expires_at'] ? max(0, strtotime((string) $order['expires_at']) - time()) : 300;

pay_status_out([
    'status'      => $state,
    'paid'        => ($state === 'success'),
    'order_id'    => $order['order_id'],
    'amount'      => (float) $order['payable_amount'],
    'utr'         => $order['utr'] ?? null,
    'sender_name' => $order['sender_name'] ?? null,
    'expires_in'  => $expiresIn,
    'data'   => [
        'order_id'    => $order['order_id'],
        'status'      => $state,
        'amount'      => (float) $order['payable_amount'],
        'utr'         => $order['utr'] ?? null,
        'sender_name' => $order['sender_name'] ?? null,
        'expires_in'  => $expiresIn,
    ],
]);
