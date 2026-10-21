<?php
declare(strict_types=1);

/* =========================================================
   FatherGateway — API bootstrap + auth middleware.
   Include this at the top of every api/* endpoint.
   ========================================================= */

require_once __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/mail.php';
require_once APP_ROOT . '/includes/subscriptions.php';

/** Common headers + CORS preflight. */
function api_boot(): void
{
    header('Content-Type: application/json; charset=utf-8');

    // CORS is opt-in: set API_CORS_ORIGIN in .env to allow a specific browser origin.
    // Server-to-server API calls do not need CORS, so no wildcard is sent by default.
    $allowedOrigin = trim((string) env('API_CORS_ORIGIN', ''));
    if ($allowedOrigin !== '') {
        header('Access-Control-Allow-Origin: ' . $allowedOrigin);
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Api-Key');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    }

    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/** Fail with a JSON error envelope: {"status":"error","message":...}. */
function api_fail(string $message, int $code = 400, ?string $type = null): void
{
    if ($type === null) {
        $type = match ($code) {
            401     => 'unauthorized',
            403     => 'suspended',
            404     => 'not_found',
            default => 'error',
        };
    }
    json_response(['status' => $type, 'message' => $message, 'code' => $code], $code);
}

/** Success envelope: {"status":"success","data":{...}}. */
function api_ok(array $data = []): void
{
    json_response(['status' => 'success', 'data' => $data], 200);
}

/** All request parameters (query + form + JSON body). */
function api_params(): array
{
    static $params = null;
    if ($params !== null) {
        return $params;
    }
    $params = array_merge($_GET, $_POST);
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ctype, 'application/json') !== false) {
        $params = array_merge($params, json_input());
    }
    return $params;
}

/** Read a single parameter. */
function api_param(string $key, string $default = ''): string
{
    $params = api_params();
    return isset($params[$key]) ? trim((string) $params[$key]) : $default;
}

/** Extract the bearer key from headers (falls back to ?api_key=). */
function api_bearer(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strtolower($k) === 'authorization') {
                $header = $v;
                break;
            }
        }
    }
    if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
        return trim($m[1]);
    }
    /* X-Api-Key header (FamGateway-compatible). */
    $xkey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($xkey !== '') {
        return trim($xkey);
    }
    return api_param('api_key');
}

/** Simple sliding-window rate limiter per API key. */
function api_rate_limit(string $publicKey): void
{
    $limit  = 120;
    $window = 60;
    // Store inside the protected storage directory (blocked from web access by .htaccess).
    $dir = APP_ROOT . '/storage/security/api_rate';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . DIRECTORY_SEPARATOR . sha1($publicKey) . '.json';
    $now = time();
    $state = ['start' => $now, 'count' => 0];

    // Atomic read-modify-write with an exclusive lock to avoid race conditions.
    $fp = @fopen($file, 'c+');
    if ($fp) {
        @flock($fp, LOCK_EX);
        rewind($fp);
        $raw = stream_get_contents($fp);
        if ($raw !== false && $raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j) && isset($j['start'], $j['count'])) {
                $state = $j;
            }
        }
        if ($now - (int) $state['start'] >= $window) {
            $state = ['start' => $now, 'count' => 0];
        }
        $state['count'] = (int) $state['count'] + 1;
        rewind($fp);
        fwrite($fp, (string) json_encode($state));
        ftruncate($fp, ftell($fp));
        fflush($fp);
        @flock($fp, LOCK_UN);
        @fclose($fp);
    } else {
        $state['count'] = 1;
    }

    header('X-RateLimit-Limit: ' . $limit);
    header('X-RateLimit-Remaining: ' . max(0, $limit - (int) $state['count']));
    if ($state['count'] > $limit) {
        api_fail('Too many requests. Slow down.', 429);
    }
}

/**
 * Authenticate the request and return [api_key_row, user_row].
 * Sends 401/403 and exits on failure.
 */
function api_auth(): array
{
    api_boot();

    $key = api_bearer();
    if ($key === '') {
        api_fail('Missing API key. Send header: Authorization: Bearer <key>', 401);
    }

    $stmt = db()->prepare(
        'SELECT k.*, u.status AS user_status
         FROM api_keys k JOIN users u ON u.id = k.user_id
         WHERE k.public_key = ? LIMIT 1'
    );
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if (!$row || $row['status'] !== 'active') {
        api_fail('Invalid or revoked API key.', 401);
    }
    if ($row['user_status'] !== 'active') {
        api_fail('This account is suspended.', 403);
    }

    db()->prepare('UPDATE api_keys SET last_used_at = NOW() WHERE id = ?')->execute([$row['id']]);
    api_rate_limit((string) $row['public_key']);

    $uStmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $uStmt->execute([$row['user_id']]);
    $user = $uStmt->fetch();
    if (!$user) {
        api_fail('Account not found.', 401);
    }
    return [$row, $user];
}

/** Build a UPI payment intent deep-link. */
function api_upi_intent(string $upi, string $payee, string $amount, string $note): string
{
    return 'upi://pay?' . http_build_query([
        'pa' => $upi,
        'pn' => $payee,
        'tr' => $note,
        'tn' => $note,
        'am' => number_format((float) $amount, 2, '.', ''),
        'cu' => 'INR',
    ]);
}

/**
 * Throttled live IMAP pull for a pending order (same engine as checkout-status.php).
 * Server-to-server checks (check-order / verify-order) otherwise only read the DB
 * and would never detect a Gmail credit notification on their own.
 * Returns the fresh order row (status/utr/sender_name may have updated).
 */
function api_imap_pull(array $order): array
{
    if (($order['status'] ?? '') !== 'pending') {
        return $order;
    }

    /* Auto-expire stale pending orders first. */
    if (!empty($order['expires_at']) && strtotime((string) $order['expires_at']) <= time()) {
        db()->prepare('UPDATE orders SET status = "expired" WHERE id = ? AND status = "pending"')
            ->execute([$order['id']]);
        $order['status'] = 'expired';
        return $order;
    }

    $uid  = (int) $order['user_id'];
    $gmail = (string) setting_get($uid, 'gmail_email', '');
    $pass  = (string) setting_get($uid, 'gmail_app_password', '');
    if ($gmail === '' || $pass === '') {
        return $order;  // merchant ne Gmail connect nahi kiya
    }

    $now       = time();
    $last      = (int) setting_get($uid, 'imap_last_autocheck', 0);
    $failUntil = (int) setting_get($uid, 'imap_fail_until', 0);

    /* Throttle: max 1 pull per 5s, skip during failure backoff (same counters as checkout-status). */
    if ($now < $failUntil || $now - $last < 5) {
        return $order;
    }

    setting_set($uid, 'imap_last_autocheck', (string) $now);
    try {
        $res = mail_process_user($uid, (string) $order['order_id']);
        if (!empty($res['connected'])) {
            setting_set($uid, 'imap_fail_until', '0');
        } else {
            setting_set($uid, 'imap_fail_until', (string) ($now + 45));
        }
    } catch (\Throwable $e) {
        setting_set($uid, 'imap_fail_until', (string) ($now + 45));
    }

    $r = db()->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $r->execute([$order['id']]);
    $fresh = $r->fetch();
    return $fresh ? array_merge($order, $fresh) : $order;
}

/**
 * Create a pending order for a user and return its public response array.
 * Shared by POST /api/create-order and GET /api/qr.php.
 *
 * @param array $user Authenticated user row.
 * @param array $in   amount, upi, customer_name, customer_email,
 *                    customer_phone, webhook_url, redirect_url.
 */
function api_create_order(array $user, array $in): array
{
    $gate = sub_can((int) $user['id'], 'transactions');
    if (!$gate['ok']) {
        api_fail(sub_reason_message((string) $gate['reason']), 402, 'payment_required');
    }

    $amount  = (float) ($in['amount'] ?? 0);
    $upi     = trim((string) ($in['upi'] ?? ''));
    $name    = trim((string) ($in['customer_name'] ?? ''));
    $email   = trim((string) ($in['customer_email'] ?? ''));
    $phone   = trim((string) ($in['customer_phone'] ?? ''));
    $wh      = trim((string) ($in['webhook_url'] ?? ''));
    $redirect = trim((string) ($in['redirect_url'] ?? ''));

    if ($amount <= 0) {
        api_fail('"amount" is required and must be greater than 0.', 400);
    }
    if ($amount > 200000) {
        api_fail('Order amount exceeds maximum single transaction limit (₹2,00,000).', 400);
    }
    if ($upi === '') {
        $upi = (string) ($user['upi_id'] ?? '');
    }
    if ($upi === '') {
        api_fail('No UPI ID available. Pass "upi" or set one under Integrations.', 400);
    }
    if (security_is_blacklisted('upi', $upi)) {
        api_fail('The target UPI identifier has been blacklisted for security reasons.', 403);
    }
    if ($email !== '' && security_is_blacklisted('email', $email)) {
        api_fail('Customer email address is restricted by security policy.', 403);
    }
    $merchantId = (int) $user['id'];
    if (($email !== '' && user_blacklist_is_blocked($merchantId, 'email', $email))
        || ($phone !== '' && user_blacklist_is_blocked($merchantId, 'phone', $phone))) {
        api_fail('This customer is on your merchant blacklist.', 403);
    }
    if (!security_check_order_create_rate(security_ip())) {
        api_fail('Order creation rate limit reached. Please wait a few moments.', 429);
    }
    if ($wh !== '' && !preg_match('#^https?://#i', $wh)) {
        api_fail('"webhook_url" must be a valid http(s) URL.', 400);
    }
    if ($redirect !== '' && !preg_match('#^https?://#i', $redirect)) {
        api_fail('"redirect_url" must be a valid http(s) URL.', 400);
    }

    $orderId  = generate_order_id();
    $created  = date('Y-m-d H:i:s');
    $expires  = date('Y-m-d H:i:s', strtotime('+5 minutes'));
    $checkout = url('pay.php?order_id=' . urlencode($orderId));
    $qrUrl    = url('api/qr-image.php?order_id=' . urlencode($orderId));
    $intent   = api_upi_intent($upi, (string) ($user['name'] ?? SITE_NAME), (string) $amount, $orderId);

    db()->prepare(
        'INSERT INTO orders
            (user_id, order_id, customer_name, customer_contact, customer_email, amount,
             payable_amount, status, upi_id, qr_url, webhook_url, redirect_url, expires_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, "pending", ?, ?, ?, ?, ?, ?)'
    )->execute([
        $user['id'],
        $orderId,
        $name !== '' ? $name : null,
        $phone !== '' ? $phone : null,
        $email !== '' ? $email : null,
        $amount,
        $amount,
        $upi,
        $qrUrl,
        $wh !== '' ? $wh : null,
        $redirect !== '' ? $redirect : null,
        $expires,
        $created,
    ]);

    return [
        'order_id'       => $orderId,
        'amount'         => number_format($amount, 2, '.', ''),
        'payable_amount' => number_format($amount, 2, '.', ''),
        'upi_id'         => $upi,
        'status'         => 'pending',
        'customer_name'  => $name !== '' ? $name : null,
        'qr_url'         => $qrUrl,
        'checkout_url'   => $checkout,
        'payment_link'   => $checkout,
        'upi_intent'     => $intent,
        'created_at_ist' => ist_datetime($created),
        'expires_at_ist' => ist_datetime($expires),
    ];
}
