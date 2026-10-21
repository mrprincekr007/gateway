<?php
declare(strict_types=1);

/* =========================================================
   GET /api/qr-image.php?order_id=fg_XXXXXXXX
   Public QR image stream (SVG) for HTML <img> tags.
   ========================================================= */

require_once __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/qr.php';

$orderId = get('order_id') !== '' ? get('order_id') : get('order');
if ($orderId === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Provide ?order_id=...';
    exit;
}

$stmt = db()->prepare(
    'SELECT o.*, u.name AS merchant_name FROM orders o JOIN users u ON u.id = o.user_id
     WHERE o.order_id = ? LIMIT 1'
);
$stmt->execute([$orderId]);
$order = $stmt->fetch();
if (!$order) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Order not found';
    exit;
}
if ($order['status'] !== 'pending' && $order['status'] !== 'success') {
    http_response_code(410);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Order ' . $order['status'];
    exit;
}

$intent = 'upi://pay?' . http_build_query([
    'pa' => (string) $order['upi_id'],
    'pn' => (string) $order['merchant_name'],
    'tr' => (string) $order['order_id'],
    'tn' => (string) $order['order_id'],
    'am' => number_format((float) $order['payable_amount'], 2, '.', ''),
    'cu' => 'INR',
]);

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
echo qr_svg($intent, 8, 4);
