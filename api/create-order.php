<?php
declare(strict_types=1);

/* =========================================================
   POST /api/create-order
   Canonical create-order entry point. Creates a 5-minute
   pending session and returns the hosted checkout URL, QR
   image URL and UPI intent deep-link.
   ========================================================= */

require_once __DIR__ . '/../includes/api.php';

[$key, $user] = api_auth();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'POST' && $method !== 'GET') {
    api_fail('Use POST for this endpoint.', 405);
}

$in = api_params();

$data = api_create_order($user, [
    'amount'         => $in['amount'] ?? '',
    'upi'            => $in['upi'] ?? '',
    'customer_name'  => $in['customer_name'] ?? '',
    'customer_email' => $in['customer_email'] ?? '',
    'customer_phone' => $in['customer_phone'] ?? ($in['customer_contact'] ?? ''),
    'webhook_url'    => $in['webhook_url'] ?? '',
    'redirect_url'   => $in['redirect_url'] ?? '',
]);

api_ok($data);
