<?php
declare(strict_types=1);

/* =========================================================
   GET /api/verify-order.php?order_id=fg_XXXXXXXX   (API key required)
   Authoritative server-to-server order verification with full
   transaction metadata (UTR, verified sender_name, transaction_id).
   Also accepts a payment-link id prefixed with LINK_ / lnk_.
   ========================================================= */

require_once __DIR__ . '/../includes/api.php';

[$key, $user] = api_auth();

$orderId = api_param('order_id');
if ($orderId === '') {
    api_fail('"order_id" is required.', 400);
}

/* ---- Payment-link verification ---- */
if (preg_match('/^(?:LINK_|lnk_)/i', $orderId)) {
    $stripped = preg_replace('/^(?:LINK_|lnk_)/i', '', $orderId);
    $withPrefix = defined('LINK_PREFIX') ? LINK_PREFIX . $stripped : 'lnk_' . $stripped;

    $ls = db()->prepare('SELECT * FROM payment_links WHERE (link_id = ? OR link_id = ? OR link_id = ?) AND user_id = ? LIMIT 1');
    $ls->execute([$orderId, $withPrefix, $stripped, $user['id']]);
    $link = $ls->fetch();
    if (!$link) {
        api_fail('Payment link not found.', 404);
    }
    $actualLinkId = $link['link_id'];

    $os = db()->prepare(
        'SELECT * FROM orders WHERE (link_id = ? OR link_id = ? OR link_id = ?) AND user_id = ?
         ORDER BY (status = "success") DESC, id DESC LIMIT 1'
    );
    $os->execute([$actualLinkId, $withPrefix, $stripped, $user['id']]);
    $last = $os->fetch() ?: null;

    $paid = $last && $last['status'] === 'success';
    api_ok([
        'link_id'         => $actualLinkId,
        'status'          => $paid ? 'success' : ($link['status'] === 'disabled' ? 'disabled' : 'pending'),
        'paid'            => (bool) $paid,
        'order_id'        => $last['order_id'] ?? null,
        'amount'          => $paid ? (float) $last['payable_amount'] : ($link['amount'] !== null ? (float) $link['amount'] : null),
        'transaction_id'  => $last['transaction_id'] ?? null,
        'utr'             => $last['utr'] ?? null,
        'sender_name'     => $last['sender_name'] ?? null,
        'payment_time_ist'=> ($paid && $last['paid_at']) ? ist_datetime((string) $last['paid_at']) : null,
    ]);
}

/* ---- Single order verification ---- */
$stmt = db()->prepare('SELECT * FROM orders WHERE order_id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$orderId, $user['id']]);
$order = $stmt->fetch();
if (!$order) {
    api_fail('Order not found.', 404);
}

/* Pending order ho toh live Gmail credit check karo (throttled). */
$order = api_imap_pull($order);

api_ok([
    'order_id'         => $order['order_id'],
    'status'           => $order['status'],
    'amount'           => (float) $order['amount'],
    'payable_amount'   => (float) $order['payable_amount'],
    'upi_id'           => $order['upi_id'],
    'transaction_id'   => $order['transaction_id'],
    'utr'              => $order['utr'],
    'sender_name'      => $order['sender_name'],
    'customer_name'    => $order['customer_name'],
    'payment_time_ist' => $order['paid_at'] ? ist_datetime((string) $order['paid_at']) : null,
    'created_at_ist'   => $order['created_at'] ? ist_datetime((string) $order['created_at']) : null,
    'expires_at_ist'   => $order['expires_at'] ? ist_datetime((string) $order['expires_at']) : null,
]);
