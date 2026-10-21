<?php
declare(strict_types=1);

/* =========================================================
   GET /api/qr.php  — Query & hardware-display alias.
   Two modes:
     ?api_key=KEY&amount=499&customer_name=...   -> create an order (JSON)
     ?order_id=fg_XXXXXXXX                       -> raw QR image (SVG)
   Raw upi/amount params for ad-hoc QRs:
     ?upi=you@upi&amount=499&name=Store&note=Order1
   ========================================================= */

require_once __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/qr.php';

$in      = array_merge($_GET, $_POST);
$amount  = trim((string) ($in['amount'] ?? ''));
$orderId = trim((string) ($in['order_id'] ?? ($in['order'] ?? '')));

/* ---- Create mode: an amount is supplied ---- */
if ($amount !== '' && (float) $amount > 0) {
    require_once APP_ROOT . '/includes/api.php';
    [$key, $user] = api_auth();
    api_ok(api_create_order($user, [
        'amount'         => $in['amount'] ?? '',
        'upi'            => $in['upi'] ?? '',
        'customer_name'  => $in['customer_name'] ?? '',
        'customer_email' => $in['customer_email'] ?? '',
        'customer_phone' => $in['customer_phone'] ?? ($in['customer_contact'] ?? ''),
        'webhook_url'    => $in['webhook_url'] ?? '',
        'redirect_url'   => $in['redirect_url'] ?? '',
    ]));
    exit;
}

/* ---- Image mode ---- */
$upi = trim((string) ($in['upi'] ?? ''));
$name = trim((string) ($in['name'] ?? ''));
$note = trim((string) ($in['note'] ?? ''));

if ($orderId !== '') {
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
    $upi    = (string) $order['upi_id'];
    $amount = (string) $order['payable_amount'];
    $name   = (string) $order['merchant_name'];
    $note   = (string) $order['order_id'];
}

if ($upi === '' || $amount === '' || (float) $amount <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Provide ?order_id=... or ?upi=...&amount=...';
    exit;
}

$intent = 'upi://pay?' . http_build_query([
    'pa' => $upi,
    'pn' => $name !== '' ? $name : 'Merchant',
    'tr' => $note !== '' ? $note : 'Payment',
    'tn' => $note !== '' ? $note : 'Payment',
    'am' => number_format((float) $amount, 2, '.', ''),
    'cu' => 'INR',
]);

$svg = qr_svg($intent, 8, 4);

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo $svg;
