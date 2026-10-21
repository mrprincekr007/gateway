<?php
declare(strict_types=1);

/* =========================================================
   FatherGateway API index — lists available endpoints.
   ========================================================= */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');
$base = url('api');
echo json_encode([
    'service'   => SITE_NAME . ' API',
    'version'   => '1.0',
    'auth'      => 'Authorization: Bearer <api_key>',
    'endpoints' => [
        'POST ' . $base . '/create-order'            => 'Create a 5-minute order (amount, customer_name, redirect_url, ...)',
        'GET  ' . $base . '/qr.php'                  => 'Create an order (query alias) or raw QR image (?order_id=)',
        'GET  ' . $base . '/qr-image.php'            => 'Public QR image stream for an order (?order_id=)',
        'GET  ' . $base . '/checkout-status.php'     => 'Public no-auth polling (?order_id=) -> status/utr/sender_name',
        'GET  ' . $base . '/verify-order.php'        => 'Authoritative order / LINK_ verification (?order_id=)',
        'GET  ' . $base . '/check-order'             => 'Order status (?order_id=...)',
        'GET  ' . $base . '/get-transactions'        => 'List orders (?page=&limit=&status=)',
        'POST ' . $base . '/manage-links'            => 'Create/list/disable payment links (action=)',
        'POST ' . $base . '/verify-webhook'          => 'Test configured webhook (action=send|sign)',
        'GET  ' . $base . '/imap-health'             => 'Gmail IMAP connection health (?live=1)',
        'GET  ' . $base . '/status'                  => 'Service + account status',
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
