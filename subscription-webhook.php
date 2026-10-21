<?php
declare(strict_types=1);

/* =========================================================
   Subscription webhook receiver.
   The configured payment provider (own gateway, a custom
   FatherGateway-compatible API, Razorpay or Cashfree) calls
   this after a subscription payment is paid. The provider is
   detected from the signature headers, the request is
   verified, and the matching subscription payment is
   activated automatically — no admin approval required.
   ========================================================= */

require __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/subscriptions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function sub_webhook_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    sub_webhook_out(['status' => 'error', 'message' => 'POST only.'], 405);
}

$raw = (string) file_get_contents('php://input');

/* Collect request headers as lower-case keys ("x-razorpay-signature"). */
$hdrs = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with((string) $k, 'HTTP_')) {
        $name = strtolower(str_replace('_', '-', substr((string) $k, 5)));
        $hdrs[$name] = trim((string) $v);
    }
}
if (function_exists('apache_request_headers')) {
    foreach (apache_request_headers() as $k => $v) {
        $hdrs[strtolower((string) $k)] = trim((string) $v);
    }
}

/* Detect the provider from the signature headers. */
if (isset($hdrs['x-razorpay-signature'])) {
    $provider = 'razorpay';
} elseif (isset($hdrs['x-webhook-signature']) || isset($hdrs['x-webhook-timestamp'])) {
    $provider = 'cashfree';
} else {
    $provider = sub_auto_provider();
}

$conf = sub_gateway_conf();

if (!sub_gw_verify($provider, $raw, $hdrs, $conf)) {
    sub_webhook_out(['status' => 'error', 'message' => 'Invalid signature.'], 401);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    sub_webhook_out(['status' => 'error', 'message' => 'Invalid JSON body.'], 400);
}

$parsed = sub_gw_parse($provider, $payload, $hdrs);
if (($parsed['status'] ?? 'ignored') !== 'success') {
    sub_webhook_out(['status' => 'ignored', 'message' => 'Unhandled event.']);
}

$remote = (string) ($parsed['remote'] ?? '');
$ref    = (string) ($parsed['reference'] ?? '');
$utr    = $parsed['utr'] ?? null;

if ($remote === '' && $ref === '') {
    sub_webhook_out(['status' => 'error', 'message' => 'Missing payment reference.'], 400);
}

$st = db()->prepare(
    'SELECT * FROM subscription_payments
      WHERE gateway_order_id = ? OR (reference = ? AND ? <> "")
      ORDER BY id DESC LIMIT 1'
);
$st->execute([$remote, $ref, $ref]);
$payment = $st->fetch();

if (!$payment) {
    sub_webhook_out(['status' => 'ignored', 'message' => 'No matching subscription payment.']);
}

if ((string) $payment['status'] !== 'success') {
    sub_mark_paid((string) $payment['reference'], 'auto', $utr !== null ? (string) $utr : null);
}

sub_webhook_out([
    'status'    => 'success',
    'reference' => (string) $payment['reference'],
    'order_id'  => $remote !== '' ? $remote : $ref,
]);
