<?php
declare(strict_types=1);

/* =========================================================
   GET /api/checkout-status.php?order_id=fg_XXXXXXXX
   PUBLIC, no-auth polling endpoint for browser / bot clients.
   Returns: {"status":"success","order_id":"...","utr":"...",
             "sender_name":"..."} | {"status":"pending"} |
             {"status":"expired"}
   While pending it triggers a throttled IMAP pull of the
   merchant's FamApp / bank inbox so a real credit is matched live.
   ========================================================= */

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/mail.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, max-age=0');

function checkout_status_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

$orderId = get('order_id') !== '' ? get('order_id') : get('order');
if ($orderId === '') {
    checkout_status_out(['status' => 'error', 'message' => 'Missing order_id.'], 400);
}

$stmt = db()->prepare('SELECT * FROM orders WHERE order_id = ? LIMIT 1');
$stmt->execute([$orderId]);
$order = $stmt->fetch();
if (!$order) {
    checkout_status_out(['status' => 'error', 'message' => 'Order not found.'], 404);
}

/* Auto-expire a stale pending order. */
if ($order['status'] === 'pending' && $order['expires_at'] && strtotime((string) $order['expires_at']) <= time()) {
    db()->prepare('UPDATE orders SET status = "expired" WHERE id = ? AND status = "pending"')->execute([$order['id']]);
    $order['status'] = 'expired';
}

/* Rate limit checkout polling per order (max 60/min) */
if (!security_check_poll_rate((string) $order['order_id'])) {
    checkout_status_out(['status' => 'error', 'message' => 'Polling rate limit exceeded. Please wait a moment.'], 429);
}

/* Live auto-verification while pending (optimized real-time 2s cadence + instant manual UTR). */
if ($order['status'] === 'pending') {
    $uid       = (int) $order['user_id'];
    $gmail     = (string) setting_get($uid, 'gmail_email', '');
    $pass      = (string) setting_get($uid, 'gmail_app_password', '');
    $manualUtr = trim((string) ($_REQUEST['utr'] ?? ''));

    if ($manualUtr !== '') {
        // Rate limit UTR submission attempts (max 5 per 5 mins per order)
        if (!security_check_utr_rate((string) $order['order_id'])) {
            checkout_status_out(['status' => 'error', 'message' => 'Too many UTR verification attempts. Please wait 5 minutes.'], 429);
        }

        // Sanitize UTR
        $manualUtr = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $manualUtr));
        if (strlen($manualUtr) < 8 || strlen($manualUtr) > 32) {
            checkout_status_out(['status' => 'error', 'message' => 'Invalid UTR format. UTR must be 8-22 alphanumeric characters.'], 400);
        }

        // Prevent duplicate UTR abuse across other orders
        $dupChk = db()->prepare('SELECT id FROM orders WHERE user_id = ? AND utr = ? AND id != ? LIMIT 1');
        $dupChk->execute([$uid, $manualUtr, $order['id']]);
        if ($dupChk->fetch()) {
            checkout_status_out(['status' => 'error', 'message' => 'This UTR has already been claimed for another transaction.'], 400);
        }
    }

    if ($gmail !== '' && $pass !== '') {
        $force     = get('force') === '1' || $manualUtr !== '';
        $now       = time();
        $last      = (int) setting_get($uid, 'imap_last_autocheck', 0);
        $failUntil = (int) setting_get($uid, 'imap_fail_until', 0);

        // Real-time cadence: allows check every 2 seconds while live customer is on checkout!
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
                $order = array_merge($order, $fresh);
            }
        }
    }
}

$state = (string) $order['status'];
$expiresIn = $order['expires_at'] ? max(0, strtotime((string) $order['expires_at']) - time()) : 300;

if ($state === 'success') {
    checkout_status_out([
        'status'         => 'success',
        'order_id'       => $order['order_id'],
        'amount'         => (float) $order['payable_amount'],
        'utr'            => $order['utr'],
        'sender_name'    => $order['sender_name'],
        'paid_at'        => $order['paid_at'] ? ist_datetime((string) $order['paid_at']) : ist_datetime(),
    ]);
}

if ($state === 'expired' || $state === 'failed') {
    checkout_status_out([
        'status'   => $state === 'failed' ? 'expired' : $state,
        'order_id' => $order['order_id'],
    ]);
}

checkout_status_out([
    'status'     => 'pending',
    'order_id'   => $order['order_id'],
    'expires_in' => $expiresIn,
]);
