<?php
declare(strict_types=1);

/* =========================================================
   FatherGateway — Subscription payment gateway adapters.
   Pluggable providers used by the subscription engine to
   create a payment at an external gateway and to verify it:
     - own      : this site's own gateway (handled in-process)
     - custom   : any FatherGateway-compatible API (HTTP)
     - razorpay : Razorpay Payment Links
     - cashfree : Cashfree Payment Links
   Each provider speaks create / status / webhook-verify.
   ========================================================= */

require_once dirname(__DIR__) . '/config/config.php';

/** Tiny cURL helper returning ['ok','code','body','error']. */
function sub_gw_http(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array
{
    $ch = curl_init($url);
    $hdrs = [];
    foreach ($headers as $k => $v) {
        $hdrs[] = is_int($k) ? (string) $v : ($k . ': ' . $v);
    }
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $hdrs,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $res  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return [
        'ok'    => $res !== false && $err === '',
        'code'  => $code,
        'body'  => $res === false ? '' : (string) $res,
        'error' => $err,
    ];
}

/**
 * Create a hosted payment at the selected provider.
 * Returns ['ok'=>bool,'error'=>?string,'remote_id'=>?string,'checkout_url'=>?string,'upi_id'=>string].
 *
 * @param array $conf ['api_url','api_key','api_secret','secret']
 * @param array $ctx  ['name','email','phone','return_url']
 */
function sub_gw_create(string $provider, array $payment, array $ctx, array $conf): array
{
    switch ($provider) {
        case 'razorpay':
            return sub_gw_razorpay_create($payment, $ctx, $conf);
        case 'cashfree':
            return sub_gw_cashfree_create($payment, $ctx, $conf);
        case 'custom':
            return sub_gw_custom_create($payment, $ctx, $conf);
    }
    return ['ok' => false, 'error' => 'Unknown payment provider.', 'remote_id' => null, 'checkout_url' => null, 'upi_id' => ''];
}

/** Poll the provider for the current status of a hosted payment. */
function sub_gw_status(string $provider, array $payment, array $conf): array
{
    $remote = (string) ($payment['gateway_order_id'] ?? '');
    if ($remote === '') {
        return ['ok' => false, 'status' => 'pending', 'utr' => null, 'error' => 'No gateway reference.'];
    }
    switch ($provider) {
        case 'razorpay':
            return sub_gw_razorpay_status($remote, $conf);
        case 'cashfree':
            return sub_gw_cashfree_status($remote, $conf);
        case 'custom':
            return sub_gw_custom_status($remote, $conf);
    }
    return ['ok' => false, 'status' => 'pending', 'utr' => null, 'error' => 'Unknown payment provider.'];
}

/** Verify a provider webhook signature over the raw body. */
function sub_gw_verify(string $provider, string $raw, array $hdrs, array $conf): bool
{
    $secret = (string) ($conf['secret'] ?? '');
    if ($provider === 'razorpay') {
        $sig = (string) ($hdrs['x-razorpay-signature'] ?? '');
        return $secret !== '' && $sig !== '' && hash_equals(hash_hmac('sha256', $raw, $secret), $sig);
    }
    if ($provider === 'cashfree') {
        $clientSecret = (string) ($conf['api_secret'] ?? '');
        $sig = (string) ($hdrs['x-webhook-signature'] ?? '');
        $ts  = (string) ($hdrs['x-webhook-timestamp'] ?? '');
        return $clientSecret !== '' && $sig !== '' && $ts !== ''
            && hash_equals(base64_encode(hash_hmac('sha256', $ts . $raw, $clientSecret, true)), $sig);
    }
    /* own / custom — hex HMAC of the raw body, any *-signature header or bearer secret. */
    if ($secret === '') {
        return false;
    }
    $hex = hash_hmac('sha256', $raw, $secret);
    foreach ($hdrs as $k => $v) {
        if (str_ends_with((string) $k, 'signature') && $v !== '' && hash_equals($hex, (string) $v)) {
            return true;
        }
    }
    $auth = (string) ($hdrs['authorization'] ?? '');
    if (preg_match('/^Bearer\s+(.+)$/i', trim($auth), $m) && hash_equals($secret, trim($m[1]))) {
        return true;
    }
    return false;
}

/**
 * Normalise a provider webhook into ['status','reference','remote','utr'].
 * status is one of success | ignored.
 */
function sub_gw_parse(string $provider, array $payload, array $hdrs): array
{
    if ($provider === 'razorpay') {
        $event = (string) ($payload['event'] ?? '');
        if ($event !== 'payment_link.paid') {
            return ['status' => 'ignored'];
        }
        $entity = $payload['payload']['payment_link']['entity'] ?? [];
        return [
            'status'    => 'success',
            'reference' => (string) ($entity['reference_id'] ?? ''),
            'remote'    => (string) ($entity['id'] ?? ''),
            'utr'       => $payload['payload']['payment']['entity']['id'] ?? null,
        ];
    }
    if ($provider === 'cashfree') {
        $type = (string) ($payload['type'] ?? '');
        $link = $payload['data']['link'] ?? [];
        $lid  = (string) ($link['link_id'] ?? '');
        $ls   = strtoupper((string) ($link['link_status'] ?? ''));
        if ($lid === '') {
            return ['status' => 'ignored'];
        }
        if ($ls !== '' && $ls !== 'PAID') {
            return ['status' => 'ignored'];
        }
        if ($type !== '' && !in_array($type, ['PAYMENT_LINK_EVENT', 'PAYMENT_SUCCESS_WEBHOOK'], true)) {
            return ['status' => 'ignored'];
        }
        return [
            'status'    => 'success',
            'reference' => $lid,
            'remote'    => $lid,
            'utr'       => $payload['data']['payment']['bank_reference']
                            ?? ($payload['data']['payment']['cf_payment_id'] ?? null),
        ];
    }
    /* own / custom — FatherGateway-compatible payload. */
    $event = (string) ($payload['event'] ?? '');
    if ($event !== '' && $event !== 'payment.success') {
        return ['status' => 'ignored'];
    }
    if (empty($payload['order_id'])) {
        return ['status' => 'ignored'];
    }
    return [
        'status'    => 'success',
        'reference' => '',
        'remote'    => (string) $payload['order_id'],
        'utr'       => $payload['utr'] ?? ($payload['transaction_id'] ?? null),
    ];
}

/* ---------- Razorpay (Payment Links) ---------- */

function sub_gw_razorpay_base(array $conf): string
{
    return rtrim(($conf['api_url'] ?? '') !== '' ? (string) $conf['api_url'] : 'https://api.razorpay.com', '/');
}

function sub_gw_razorpay_create(array $payment, array $ctx, array $conf): array
{
    $body = json_encode([
        'amount'          => (int) round((float) $payment['amount'] * 100),
        'currency'        => 'INR',
        'accept_partial'  => false,
        'reference_id'    => (string) $payment['reference'],
        'description'     => 'Subscription ' . (string) $payment['reference'],
        'customer'        => array_filter([
            'name'    => $ctx['name'] !== '' ? $ctx['name'] : 'Customer',
            'email'   => $ctx['email'] !== '' ? $ctx['email'] : null,
            'contact' => $ctx['phone'] !== '' ? $ctx['phone'] : null,
        ]),
        'notify'          => ['sms' => false, 'email' => false],
        'reminder_enable' => false,
        'callback_url'    => $ctx['return_url'],
        'callback_method' => 'get',
    ], JSON_UNESCAPED_SLASHES);

    $ch = [
        'Content-Type'  => 'application/json',
        'Authorization' => 'Basic ' . base64_encode($conf['api_key'] . ':' . $conf['api_secret']),
    ];
    $res = sub_gw_http('POST', sub_gw_razorpay_base($conf) . '/v1/payment_links', $ch, $body);
    if (!$res['ok'] || $res['code'] < 200 || $res['code'] >= 300) {
        return ['ok' => false, 'error' => 'Razorpay: ' . sub_gw_err($res), 'remote_id' => null, 'checkout_url' => null, 'upi_id' => ''];
    }
    $data = json_decode($res['body'], true) ?: [];
    if (empty($data['short_url']) || empty($data['id'])) {
        return ['ok' => false, 'error' => 'Razorpay did not return a payment link.', 'remote_id' => null, 'checkout_url' => null, 'upi_id' => ''];
    }
    return ['ok' => true, 'error' => null, 'remote_id' => (string) $data['id'], 'checkout_url' => (string) $data['short_url'], 'upi_id' => ''];
}

function sub_gw_razorpay_status(string $remote, array $conf): array
{
    $ch = ['Authorization' => 'Basic ' . base64_encode($conf['api_key'] . ':' . $conf['api_secret'])];
    $res = sub_gw_http('GET', sub_gw_razorpay_base($conf) . '/v1/payment_links/' . rawurlencode($remote), $ch);
    if (!$res['ok'] || $res['code'] < 200 || $res['code'] >= 300) {
        return ['ok' => false, 'status' => 'pending', 'utr' => null, 'error' => sub_gw_err($res)];
    }
    $data = json_decode($res['body'], true) ?: [];
    $st = strtolower((string) ($data['status'] ?? ''));
    if ($st === 'paid') {
        return ['ok' => true, 'status' => 'success', 'utr' => null, 'error' => null];
    }
    if (in_array($st, ['expired', 'cancelled'], true)) {
        return ['ok' => true, 'status' => 'expired', 'utr' => null, 'error' => null];
    }
    return ['ok' => true, 'status' => 'pending', 'utr' => null, 'error' => null];
}

/* ---------- Cashfree (Payment Links) ---------- */

function sub_gw_cashfree_base(array $conf): string
{
    return rtrim(($conf['api_url'] ?? '') !== '' ? (string) $conf['api_url'] : 'https://api.cashfree.com/pg', '/');
}

function sub_gw_cashfree_headers(array $conf): array
{
    return [
        'Content-Type'    => 'application/json',
        'x-client-id'     => $conf['api_key'],
        'x-client-secret' => $conf['api_secret'],
        'x-api-version'   => '2023-08-01',
    ];
}

function sub_gw_cashfree_create(array $payment, array $ctx, array $conf): array
{
    $body = json_encode([
        'link_id'             => (string) $payment['reference'],
        'link_amount'         => (float) $payment['amount'],
        'link_currency'       => 'INR',
        'link_purpose'        => 'Subscription payment',
        'customer_details'    => array_filter([
            'customer_name'  => $ctx['name'] !== '' ? $ctx['name'] : 'Customer',
            'customer_email' => $ctx['email'] !== '' ? $ctx['email'] : null,
            'customer_phone' => $ctx['phone'] !== '' ? $ctx['phone'] : null,
        ]),
        'link_notify'         => ['send_sms' => false, 'send_email' => false],
        'link_meta'           => ['return_url' => $ctx['return_url']],
        'link_auto_reminders' => false,
    ], JSON_UNESCAPED_SLASHES);

    $res = sub_gw_http('POST', sub_gw_cashfree_base($conf) . '/links', sub_gw_cashfree_headers($conf), $body);
    if (!$res['ok'] || $res['code'] < 200 || $res['code'] >= 300) {
        return ['ok' => false, 'error' => 'Cashfree: ' . sub_gw_err($res), 'remote_id' => null, 'checkout_url' => null, 'upi_id' => ''];
    }
    $data = json_decode($res['body'], true) ?: [];
    if (empty($data['link_url'])) {
        return ['ok' => false, 'error' => 'Cashfree did not return a payment link.', 'remote_id' => null, 'checkout_url' => null, 'upi_id' => ''];
    }
    return [
        'ok'           => true,
        'error'        => null,
        'remote_id'    => (string) ($data['link_id'] ?? $payment['reference']),
        'checkout_url' => (string) $data['link_url'],
        'upi_id'       => '',
    ];
}

function sub_gw_cashfree_status(string $remote, array $conf): array
{
    $res = sub_gw_http('GET', sub_gw_cashfree_base($conf) . '/links/' . rawurlencode($remote), sub_gw_cashfree_headers($conf));
    if (!$res['ok'] || $res['code'] < 200 || $res['code'] >= 300) {
        return ['ok' => false, 'status' => 'pending', 'utr' => null, 'error' => sub_gw_err($res)];
    }
    $data = json_decode($res['body'], true) ?: [];
    $st = strtoupper((string) ($data['link_status'] ?? ''));
    if ($st === 'PAID') {
        return ['ok' => true, 'status' => 'success', 'utr' => null, 'error' => null];
    }
    if (in_array($st, ['EXPIRED', 'CANCELLED'], true)) {
        return ['ok' => true, 'status' => 'expired', 'utr' => null, 'error' => null];
    }
    return ['ok' => true, 'status' => 'pending', 'utr' => null, 'error' => null];
}

/* ---------- Custom (FatherGateway-compatible API) ---------- */

function sub_gw_custom_create(array $payment, array $ctx, array $conf): array
{
    $base = rtrim((string) ($conf['api_url'] ?? ''), '/');
    if ($base === '') {
        return ['ok' => false, 'error' => 'Custom gateway API URL is not set.', 'remote_id' => null, 'checkout_url' => null, 'upi_id' => ''];
    }
    $body = json_encode([
        'amount'         => number_format((float) $payment['amount'], 2, '.', ''),
        'customer_name'  => $ctx['name'],
        'customer_email' => $ctx['email'],
        'customer_phone' => $ctx['phone'],
        'redirect_url'   => $ctx['return_url'],
        'webhook_url'    => url('subscription-webhook.php'),
        'reference'      => (string) $payment['reference'],
    ], JSON_UNESCAPED_SLASHES);

    $ch = ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $conf['api_key']];
    $res = sub_gw_http('POST', $base . '/create-order', $ch, $body);
    if (!$res['ok'] || $res['code'] < 200 || $res['code'] >= 300) {
        return ['ok' => false, 'error' => 'Gateway: ' . sub_gw_err($res), 'remote_id' => null, 'checkout_url' => null, 'upi_id' => ''];
    }
    $data = json_decode($res['body'], true) ?: [];
    $d = $data['data'] ?? $data;
    if (empty($d['order_id'])) {
        return ['ok' => false, 'error' => 'Gateway did not return an order_id.', 'remote_id' => null, 'checkout_url' => null, 'upi_id' => ''];
    }
    $checkout = (string) ($d['checkout_url'] ?? ($d['payment_link'] ?? ($d['qr_url'] ?? '')));
    return ['ok' => true, 'error' => null, 'remote_id' => (string) $d['order_id'], 'checkout_url' => $checkout, 'upi_id' => (string) ($d['upi_id'] ?? '')];
}

function sub_gw_custom_status(string $remote, array $conf): array
{
    $base = rtrim((string) ($conf['api_url'] ?? ''), '/');
    if ($base === '') {
        return ['ok' => false, 'status' => 'pending', 'utr' => null, 'error' => 'Custom gateway API URL is not set.'];
    }
    $ch = ['Authorization' => 'Bearer ' . $conf['api_key']];
    $res = sub_gw_http('GET', $base . '/check-order.php?order_id=' . rawurlencode($remote), $ch);
    if (!$res['ok'] || $res['code'] < 200 || $res['code'] >= 300) {
        return ['ok' => false, 'status' => 'pending', 'utr' => null, 'error' => sub_gw_err($res)];
    }
    $data = json_decode($res['body'], true) ?: [];
    $d  = $data['data'] ?? $data;
    $st = strtolower((string) ($d['status'] ?? ''));
    if ($st === 'success') {
        return ['ok' => true, 'status' => 'success', 'utr' => $d['utr'] ?? null, 'error' => null];
    }
    if (in_array($st, ['expired', 'failed'], true)) {
        return ['ok' => true, 'status' => 'expired', 'utr' => null, 'error' => null];
    }
    return ['ok' => true, 'status' => 'pending', 'utr' => null, 'error' => null];
}

/** Readable error text from an HTTP helper result. */
function sub_gw_err(array $res): string
{
    $body = trim((string) ($res['body'] ?? ''));
    $json = json_decode($body, true);
    if (is_array($json)) {
        foreach (['message', 'error_description', 'error', 'reason'] as $k) {
            if (!empty($json[$k]) && is_string($json[$k])) {
                return $json[$k];
            }
        }
    }
    if ($body !== '') {
        return substr($body, 0, 200);
    }
    return (string) ($res['error'] ?? 'request failed');
}
