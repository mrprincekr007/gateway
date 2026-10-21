<?php
declare(strict_types=1);

/* =========================================================
   POST /api/verify-webhook
   action=send  -> deliver a payment.success event (real or sample)
                   to the account's active endpoint and report the result
   action=sign  -> compute the signature for a given raw payload
   ========================================================= */

require_once __DIR__ . '/../includes/api.php';

[$key, $user] = api_auth();

$action = api_param('action', 'send');

$whStmt = db()->prepare('SELECT * FROM webhooks WHERE user_id = ? AND status = "active" LIMIT 1');
$whStmt->execute([$user['id']]);
$hook = $whStmt->fetch();
if (!$hook) {
    api_fail('No active webhook endpoint configured.', 400);
}

$header = 'X-' . SITE_NAME . '-Signature';

if ($action === 'sign') {
    $payload = (string) (api_params()['payload'] ?? '');
    $sig = hash_hmac('sha256', $payload, (string) $hook['secret']);
    api_ok(['signature' => $sig, 'header' => $header]);
}

$orderId = api_param('order_id');
if ($orderId !== '') {
    $os = db()->prepare('SELECT * FROM orders WHERE order_id = ? AND user_id = ? LIMIT 1');
    $os->execute([$orderId, $user['id']]);
    $order = $os->fetch();
    if (!$order) {
        api_fail('Order not found.', 404);
    }
    $payload = [
        'event'          => 'payment.success',
        'order_id'       => $order['order_id'],
        'amount'         => (float) $order['amount'],
        'payable_amount' => (float) $order['payable_amount'],
        'status'         => $order['status'],
        'transaction_id' => $order['transaction_id'],
        'utr'            => $order['utr'],
        'sender_name'    => $order['sender_name'],
        'payment_time'   => $order['paid_at'] ? ist_datetime((string) $order['paid_at']) : ist_datetime(),
        'timestamp'      => time(),
    ];
} else {
    $payload = [
        'event'          => 'payment.success',
        'order_id'       => 'fg_TEST1234',
        'amount'         => 1.00,
        'payable_amount' => 1.00,
        'status'         => 'success',
        'transaction_id' => 'FMP000000000',
        'utr'            => '000000000000',
        'sender_name'    => 'Test Customer',
        'payment_time'   => ist_datetime(),
        'timestamp'      => time(),
    ];
}

$body = json_encode($payload, JSON_UNESCAPED_SLASHES);
$sig  = hash_hmac('sha256', $body, (string) $hook['secret']);

$ch = curl_init((string) $hook['endpoint_url']);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        $header . ': ' . $sig,
        'X-' . SITE_NAME . '-Event: payment.success',
    ],
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
]);
$response = curl_exec($ch);
$code     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$err      = curl_error($ch);
curl_close($ch);

$ok = $err === '' && $code >= 200 && $code < 300;

db()->prepare(
    'INSERT INTO webhook_logs (user_id, order_id, endpoint_url, response_code, status, payload)
     VALUES (?, ?, ?, ?, ?, ?)'
)->execute([
    $user['id'],
    $payload['order_id'],
    $hook['endpoint_url'],
    $code ?: null,
    $ok ? 'success' : 'failed',
    json_encode(['payload' => $payload, 'response' => $response !== false ? substr((string) $response, 0, 300) : $err, 'signature' => $sig], JSON_UNESCAPED_SLASHES),
]);

api_ok([
    'delivered'    => $ok,
    'response_code'=> $code,
    'endpoint'     => $hook['endpoint_url'],
    'signature'    => $sig,
    'header'       => $header,
    'payload'      => $payload,
]);
