<?php
declare(strict_types=1);

/* =========================================================
   GET /api/check-order?order_id=fg_XXXXXXXX
   Returns the current status of one order.
   ========================================================= */

require_once __DIR__ . '/../includes/api.php';

[$key, $user] = api_auth();

$orderId = api_param('order_id');
if ($orderId === '') {
    api_fail('"order_id" is required.', 400);
}

$stmt = db()->prepare('SELECT * FROM orders WHERE order_id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$orderId, $user['id']]);
$order = $stmt->fetch();
if (!$order) {
    api_fail('Order not found.', 404);
}

/* Pending order ho toh live Gmail credit check karo (throttled). */
$order = api_imap_pull($order);

api_ok([
    'order_id'       => $order['order_id'],
    'status'         => $order['status'],
    'amount'         => (float) $order['amount'],
    'payable_amount' => (float) $order['payable_amount'],
    'utr'            => $order['utr'],
    'customer_name'  => $order['customer_name'],
    'upi'            => $order['upi_id'],
    'paid_at'        => $order['paid_at'] ? date('c', strtotime((string) $order['paid_at'])) : null,
    'created_at'     => date('c', strtotime((string) $order['created_at'])),
]);
