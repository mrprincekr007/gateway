<?php
declare(strict_types=1);

/* =========================================================
   FatherGateway — Enterprise Security & Rate Limiting Engine
   Protects against:
   - Brute-force credential stuffing (Login/Admin)
   - Account creation / spam bot floods (Register)
   - Password reset mail bombing (Forgot Password)
   - Order creation DDoS & phantom order spam (Checkout/API)
   - Automated UTR brute-force guessing & IMAP hammering
   - Malicious web scrapers & automated vulnerability scanners
   - IP Blacklist active filtering
   - Attack signature heuristics (SQLi, XSS, Path Traversal)
   ========================================================= */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

/**
 * Trusted reverse-proxy list (comma-separated IPs / IPv4 CIDRs) from env.
 * Only when the direct socket peer is in this list are forwarding headers trusted.
 */
function security_trusted_proxies(): array
{
    static $list = null;
    if ($list !== null) {
        return $list;
    }
    $list = [];
    $raw = (string) env('TRUSTED_PROXIES', '');
    foreach (explode(',', $raw) as $p) {
        $p = trim($p);
        if ($p !== '') {
            $list[] = $p;
        }
    }
    return $list;
}

/** Match an IPv4 address against an exact IP or CIDR rule. */
function security_ip_matches(string $ip, string $rule): bool
{
    if ($ip === $rule) {
        return true;
    }
    if (strpos($rule, '/') === false) {
        return false;
    }
    [$subnet, $bits] = explode('/', $rule, 2);
    $bits = (int) $bits;
    if ($bits < 0 || $bits > 32) {
        return false;
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        || !filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }
    $ipLong    = ip2long($ip);
    $subnetLong = ip2long($subnet);
    if ($ipLong === false || $subnetLong === false) {
        return false;
    }
    $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
    return ($ipLong & $mask) === ($subnetLong & $mask);
}

/**
 * Resolve the real client IP address.
 * Forwarding headers (CF-Connecting-IP, X-Real-IP, X-Forwarded-For) are only
 * trusted when the direct peer (REMOTE_ADDR) is a configured trusted proxy.
 * This prevents clients from spoofing their IP to bypass rate limits/blacklists.
 */
function security_ip(): string
{
    static $clientIp = null;
    if ($clientIp !== null) {
        return $clientIp;
    }

    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    if (!filter_var($remote, FILTER_VALIDATE_IP)) {
        $remote = '127.0.0.1';
    }

    $isTrusted = false;
    foreach (security_trusted_proxies() as $rule) {
        if (security_ip_matches($remote, $rule)) {
            $isTrusted = true;
            break;
        }
    }

    if (!$isTrusted) {
        return $clientIp = $remote;
    }

    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,     // Cloudflare
        $_SERVER['HTTP_X_REAL_IP'] ?? null,             // Nginx
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,       // Proxies / Load Balancers
    ];

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || trim($candidate) === '') {
            continue;
        }
        // X-Forwarded-For may contain a comma-separated list of IPs (client, proxy1, proxy2)
        foreach (explode(',', $candidate) as $ip) {
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $clientIp = $ip;
            }
        }
    }

    return $clientIp = $remote;
}

/**
 * Directory for atomic file-based rate limits and security cache.
 */
function security_storage_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }

    $dir = APP_ROOT . '/storage/security/limits';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Check if Rate Limiting is globally turned ON or OFF.
 * Default is true (ON), but admin can set 'rate_limiting_enabled' to '0' to turn it completely OFF.
 */
function security_is_rate_limiting_enabled(): bool
{
    static $enabled = null;
    if ($enabled !== null) {
        return $enabled;
    }

    try {
        $stmt = db()->prepare("SELECT svalue FROM app_settings WHERE skey = 'rate_limiting_enabled' LIMIT 1");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        if ($val === '0') {
            return $enabled = false;
        }
    } catch (Throwable $e) {}

    return $enabled = true;
}

/**
 * High-performance, atomic sliding-window rate limiter.
 *
 * @param string $bucket       Unique namespace (e.g., 'login', 'register', 'global', 'order_create')
 * @param string $identifier   Unique key to throttle (e.g. IP address, email, order ID)
 * @param int    $maxAttempts  Maximum allowed attempts in the window
 * @param int    $windowSeconds Time window in seconds
 * @return array ['allowed' => bool, 'remaining' => int, 'limit' => int, 'retry_after' => int, 'count' => int]
 */
function security_rate_limit(string $bucket, string $identifier, int $maxAttempts, int $windowSeconds): array
{
    // If rate limiting is globally turned OFF in Admin Settings, allow all requests unconditionally
    if (!security_is_rate_limiting_enabled()) {
        return [
            'allowed'     => true,
            'remaining'   => 999999,
            'limit'       => $maxAttempts,
            'retry_after' => 0,
            'count'       => 0,
        ];
    }

    $dir  = security_storage_dir();
    $hash = sha1($bucket . '::' . strtolower(trim($identifier)));
    $file = $dir . '/' . $hash . '.json';
    $now  = time();

    // Probabilistic garbage collection: 1 in 200 requests prunes files older than 24h
    if (mt_rand(1, 200) === 1) {
        security_cleanup_stale_limits();
    }

    $fp = @fopen($file, 'c+');
    if (!$fp) {
        // Fallback open without locking if filesystem has strict permissions
        return ['allowed' => true, 'remaining' => $maxAttempts, 'limit' => $maxAttempts, 'retry_after' => 0, 'count' => 1];
    }

    // Atomic exclusive file lock to prevent race conditions during high concurrency
    @flock($fp, LOCK_EX);

    // Read directly from file pointer without stat cache dependency
    rewind($fp);
    $raw = stream_get_contents($fp);
    $data = [];
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    $cutoff = $now - $windowSeconds;
    $timestamps = array_filter(
        $data['hits'] ?? [],
        static fn ($ts) => is_int($ts) && $ts > $cutoff
    );

    $count = count($timestamps);

    if ($count >= $maxAttempts) {
        $oldest = reset($timestamps) ?: $cutoff;
        $retryAfter = max(1, ($oldest + $windowSeconds) - $now);

        @flock($fp, LOCK_UN);
        @fclose($fp);

        return [
            'allowed'     => false,
            'remaining'   => 0,
            'limit'       => $maxAttempts,
            'retry_after' => $retryAfter,
            'count'       => $count,
        ];
    }

    // Append current timestamp hit
    $timestamps[] = $now;
    $count = count($timestamps);

    // Write back updated timestamps atomically
    rewind($fp);
    fwrite($fp, (string) json_encode(['hits' => array_values($timestamps), 'updated' => $now]));
    ftruncate($fp, ftell($fp));
    fflush($fp);

    @flock($fp, LOCK_UN);
    @fclose($fp);

    $remaining = max(0, $maxAttempts - $count);

    return [
        'allowed'     => true,
        'remaining'   => $remaining,
        'limit'       => $maxAttempts,
        'retry_after' => 0,
        'count'       => $count,
    ];
}

/**
 * Remove rate-limit files that have not been touched for > 24 hours.
 */
function security_cleanup_stale_limits(): void
{
    $dir = security_storage_dir();
    $cutoff = time() - 86400;
    $files = @glob($dir . '/*.json');
    if (!is_array($files)) {
        return;
    }
    foreach ($files as $f) {
        if (@filemtime($f) < $cutoff) {
            @unlink($f);
        }
    }
}

/**
 * Enforce a rate limit or terminate immediately with 429 Too Many Requests.
 */
function security_enforce(string $bucket, string $identifier, int $maxAttempts, int $windowSeconds, string $errorMsg = 'Rate limit exceeded. Please wait a moment.'): void
{
    $res = security_rate_limit($bucket, $identifier, $maxAttempts, $windowSeconds);

    if (!headers_sent()) {
        header('X-RateLimit-Limit: ' . $res['limit']);
        header('X-RateLimit-Remaining: ' . $res['remaining']);
        if (!$res['allowed']) {
            header('Retry-After: ' . $res['retry_after']);
        }
    }

    if (!$res['allowed']) {
        security_render_429($errorMsg, $res['retry_after']);
    }
}

/**
 * Display or return JSON for HTTP 429 Too Many Requests.
 */
function security_render_429(string $message, int $retryAfter = 60): void
{
    http_response_code(429);

    $isApi = (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
        || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')
        || str_contains($_SERVER['REQUEST_URI'] ?? '', 'status.php');

    if ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'      => false,
            'error'       => $message,
            'retry_after' => $retryAfter,
            'code'        => 429,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Elegant, branded HTML security throttle screen with auto-refresh countdown
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>429 Too Many Requests · <?= htmlspecialchars(defined('SITE_NAME') ? SITE_NAME : 'Father Gateway') ?></title>
        <style>
            :root {
                --bg: #090d16;
                --card: #0f172a;
                --border: #1e293b;
                --text: #f8fafc;
                --mute: #94a3b8;
                --amber: #f59e0b;
                --blue: #2563eb;
            }
            * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
            body { background: var(--bg); color: var(--text); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
            .sec-card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 36px 32px; max-width: 480px; width: 100%; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); text-align: center; }
            .sec-icon { width: 56px; height: 56px; border-radius: 50%; background: rgba(245,158,11,0.12); color: var(--amber); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 20px; border: 1px solid rgba(245,158,11,0.3); }
            h1 { font-size: 22px; font-weight: 700; margin-bottom: 8px; color: #fff; }
            p { font-size: 14px; color: var(--mute); line-height: 1.6; margin-bottom: 24px; }
            .countdown-box { background: rgba(15,23,42,0.8); border: 1px dashed var(--border); border-radius: 10px; padding: 14px; margin-bottom: 24px; }
            .countdown-val { font-size: 26px; font-weight: 800; color: var(--amber); font-variant-numeric: tabular-nums; }
            .countdown-lbl { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--mute); margin-top: 4px; }
            .btn-home { display: inline-block; padding: 10px 20px; border-radius: 8px; background: var(--blue); color: #fff; font-size: 13px; font-weight: 600; text-decoration: none; transition: opacity 0.2s; }
            .btn-home:hover { opacity: 0.9; }
            .sec-footer { font-size: 11px; color: #475569; margin-top: 24px; }
        </style>
    </head>
    <body>
        <div class="sec-card">
            <div class="sec-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
            </div>
            <h1>Rate Limit Triggered</h1>
            <p><?= htmlspecialchars($message) ?></p>
            <div class="countdown-box">
                <div class="countdown-val" id="countdown"><?= (int) $retryAfter ?></div>
                <div class="countdown-lbl">Seconds until unlock</div>
            </div>
            <div>
                <a href="<?= htmlspecialchars(defined('BASE_URL') ? BASE_URL : '/') ?>" class="btn-home">Return Home</a>
            </div>
            <div class="sec-footer">
                FatherGateway Autonomous Security Guard &middot; IP: <?= htmlspecialchars(security_ip()) ?>
            </div>
        </div>
        <script>
            var sec = <?= (int) $retryAfter ?>;
            var el = document.getElementById('countdown');
            var timer = setInterval(function() {
                sec--;
                if (sec <= 0) {
                    clearInterval(timer);
                    location.reload();
                } else {
                    el.textContent = sec;
                }
            }, 1000);
        </script>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Check if a value is blacklisted in database.
 * Supports: 'ip', 'email', 'upi', 'phone'.
 */
function security_is_blacklisted(string $type, string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }

    static $cache = [];
    $cacheKey = $type . ':' . strtolower($value);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    try {
        $stmt = db()->prepare('SELECT id FROM blacklist_entries WHERE entry_type = ? AND LOWER(value) = LOWER(?) LIMIT 1');
        $stmt->execute([$type, $value]);
        $exists = (bool) $stmt->fetchColumn();
        return $cache[$cacheKey] = $exists;
    } catch (Throwable $e) {
        return false;
    }
}

/* ---------- Merchant-scoped blacklist ---------- */

/** Create the per-merchant blacklist table on first use. */
function user_blacklist_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS `user_blacklist` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `entry_type` ENUM('upi','email','phone') NOT NULL DEFAULT 'email',
                `value` VARCHAR(190) NOT NULL,
                `reason` VARCHAR(255) DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_user_type_value` (`user_id`, `entry_type`, `value`),
                KEY `idx_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        /* table already present */
    }
}

/** Check whether a customer identifier is blacklisted for one merchant. */
function user_blacklist_is_blocked(int $userId, string $type, string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }
    user_blacklist_ensure_table();

    static $cache = [];
    $cacheKey = $userId . ':' . $type . ':' . strtolower($value);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    try {
        $stmt = db()->prepare('SELECT id FROM user_blacklist WHERE user_id = ? AND entry_type = ? AND LOWER(value) = LOWER(?) LIMIT 1');
        $stmt->execute([$userId, $type, $value]);
        return $cache[$cacheKey] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/** Add an identifier to a merchant's blacklist. Returns false if it already exists. */
function user_blacklist_add(int $userId, string $type, string $value, ?string $reason = null): bool
{
    user_blacklist_ensure_table();
    $value = trim($value);
    if ($value === '') {
        return false;
    }
    try {
        db()->prepare('INSERT INTO user_blacklist (user_id, entry_type, value, reason) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $type, $value, $reason]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * WAF Scanner & Exploit Shield:
 * Blocks automated vulnerability scanners, path traversal, and obvious malicious injection probes.
 */
function security_waf_inspect(): void
{
    $ip = security_ip();
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    $uri = strtolower($_SERVER['REQUEST_URI'] ?? '');

    // 1. Check IP Blacklist
    if (security_is_blacklisted('ip', $ip)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        die('Access Denied: Your IP address (' . htmlspecialchars($ip) . ') is blacklisted on this platform.');
    }

    // 2. Bad Scanner User-Agent Signatures
    $badAgents = [
        'sqlmap', 'nikto', 'acunetix', 'masscan', 'dirbuster', 'nmap',
        'havij', 'zgrab', 'censys', 'nessus', 'gobuster', 'wpscan',
        'hydra', 'medusa', 'fuzz', 'webshag', 'cgicheck', 'burpcollaborator',
    ];

    foreach ($badAgents as $agent) {
        if ($agent !== '' && str_contains($ua, $agent)) {
            security_log_violation('Malicious Scanner Signature detected (' . $agent . ')');
            http_response_code(403);
            die('Access Denied: Automated security scanners are prohibited.');
        }
    }

    // 3. Attack Signature Heuristics in URI or Query String
    $badSignatures = [
        '../..', '..\\..', '/etc/passwd', 'windows/win.ini', 'proc/self/environ',
        'eval(', 'base64_decode(', 'passthru(', 'shell_exec(',
        'union+select', 'union/**/select', 'concat(', 'sleep(', 'benchmark(',
        '<script', '%3cscript', 'javascript:', 'onerror=', 'onload=',
    ];

    // Exclude legitimate admin system probe query if running from authenticated admin session
    $isAdminDiag = str_contains($uri, '/admin/system.php') || str_contains($uri, '/admin/index.php');

    if (!$isAdminDiag) {
        foreach ($badSignatures as $sig) {
            if (str_contains($uri, $sig)) {
                security_log_violation('URI attack signature detected: ' . $sig);
                http_response_code(403);
                die('Access Denied: Suspicious request parameters detected.');
            }
        }
    }
}

/**
 * Log a security violation into audit_logs and track for auto-ban.
 */
function security_log_violation(string $reason): void
{
    $ip = security_ip();

    // Log to audit trail if helper available
    if (function_exists('ops_audit')) {
        ops_audit('security.threat.blocked', 'ip_shield', $ip, $reason);
    }

    // Record violation hit. If 5 violations in 15 minutes, auto-ban IP!
    $strike = security_rate_limit('strikes', $ip, 5, 900);
    if (!$strike['allowed']) {
        try {
            db()->prepare(
                'INSERT IGNORE INTO blacklist_entries (entry_type, value, reason, created_at)
                 VALUES ("ip", ?, ?, NOW())'
            )->execute([$ip, 'Automated WAF defense: excessive threat violations (' . substr($reason, 0, 100) . ')']);
        } catch (Throwable $e) {}
    }
}

/* ---------- Context-Specific Rate Limiters & Dynamic Admin Controls ---------- */

/**
 * Security Rate Limiter Defaults.
 */
const SECURITY_RATE_DEFAULTS = [
    'rate_login_attempts'          => 5,
    'rate_login_lockout_minutes'   => 5,
    'rate_register_attempts'       => 10,
    'rate_register_window_minutes' => 15,
    'rate_reset_attempts'          => 5,
    'rate_reset_window_minutes'    => 10,
    'otp_resend_cooldown_seconds'  => 30,
    'otp_max_wrong_attempts'       => 5,
    'otp_validity_minutes'         => 10,
    'rate_global_ip_per_minute'    => 300,
    'rate_order_create_attempts'   => 50,
    'rate_utr_submit_attempts'     => 10,
];

/**
 * Retrieve a numeric security rate-limit setting from app_settings with fallback.
 */
function security_setting(string $key, ?int $default = null): int
{
    static $cache = [];
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    if ($default === null) {
        $default = SECURITY_RATE_DEFAULTS[$key] ?? 5;
    }

    try {
        $stmt = db()->prepare('SELECT svalue FROM app_settings WHERE skey = ? LIMIT 1');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== null && $val !== '') {
            return $cache[$key] = (int) $val;
        }
    } catch (Throwable $e) {}

    return $cache[$key] = $default;
}

/**
 * Clear all stored rate-limit counters and lockouts.
 *
 * @return int Number of files purged
 */
function security_clear_all_limits(): int
{
    $dir = security_storage_dir();
    $files = @glob($dir . '/*.json');
    $count = 0;
    if (is_array($files)) {
        foreach ($files as $f) {
            if (@unlink($f)) {
                $count++;
            }
        }
    }
    return $count;
}

/**
 * Count active rate-limit tracking files in storage.
 */
function security_count_active_limits(): int
{
    $dir = security_storage_dir();
    $files = @glob($dir . '/*.json');
    return is_array($files) ? count($files) : 0;
}

/**
 * Global IP request rate limit for normal browsing.
 * Allows up to N requests per minute per IP (0 = disabled).
 */
function security_rate_limit_global(): void
{
    $limit = security_setting('rate_global_ip_per_minute', 300);
    if ($limit <= 0) {
        return;
    }
    $ip = security_ip();
    security_enforce('global', $ip, $limit, 60, 'Too many requests from your IP. Please slow down.');
}

/**
 * Login brute-force protector.
 * Allows configurable failed attempts and lockout duration per IP or email.
 *
 * @return array ['locked' => bool, 'retry_after' => int, 'message' => string]
 */
function security_check_login_lockout(string $email, string $ip): array
{
    $emailKey    = strtolower(trim($email));
    $maxAttempts = security_setting('rate_login_attempts', 5);
    $windowSecs  = max(30, security_setting('rate_login_lockout_minutes', 5) * 60);

    $ipLimit   = security_rate_limit('login_ip', $ip, $maxAttempts, $windowSecs);
    $userLimit = $emailKey !== '' ? security_rate_limit('login_user', $emailKey, $maxAttempts, $windowSecs) : ['allowed' => true, 'retry_after' => 0];

    if (!$ipLimit['allowed'] || !$userLimit['allowed']) {
        $wait = max($ipLimit['retry_after'], $userLimit['retry_after']);
        $mins = (int) ceil($wait / 60);
        $timeStr = $mins > 1 ? "{$mins} minutes" : "{$wait} seconds";
        return [
            'locked'      => true,
            'retry_after' => $wait,
            'message'     => "Too many failed login attempts. For security, please wait {$timeStr} before trying again.",
        ];
    }

    return ['locked' => false, 'retry_after' => 0, 'message' => ''];
}

/**
 * Record a failed login attempt.
 */
function security_record_login_failure(string $email, string $ip): void
{
    $emailKey    = strtolower(trim($email));
    $maxAttempts = security_setting('rate_login_attempts', 5);
    $windowSecs  = max(30, security_setting('rate_login_lockout_minutes', 5) * 60);

    security_rate_limit('login_ip', $ip, $maxAttempts, $windowSecs);
    if ($emailKey !== '') {
        security_rate_limit('login_user', $emailKey, $maxAttempts, $windowSecs);
    }
    security_log_violation('Failed login attempt for: ' . ($emailKey ?: 'unknown'));
}

/**
 * Clear failed login attempts after successful authentication.
 */
function security_clear_login_failures(string $email, string $ip): void
{
    $dir = security_storage_dir();
    $f1 = $dir . '/' . sha1('login_ip::' . strtolower(trim($ip))) . '.json';
    $f2 = $dir . '/' . sha1('login_user::' . strtolower(trim($email))) . '.json';
    @unlink($f1);
    @unlink($f2);
}

/**
 * Account registration rate limit:
 * Allows configurable max registrations per window per IP.
 */
function security_check_register_rate(string $ip): ?string
{
    $maxAttempts = security_setting('rate_register_attempts', 10);
    $windowSecs  = max(30, security_setting('rate_register_window_minutes', 15) * 60);

    $res = security_rate_limit('register_ip', $ip, $maxAttempts, $windowSecs);
    if (!$res['allowed']) {
        $mins = (int) ceil($res['retry_after'] / 60);
        $timeStr = $mins > 1 ? "{$mins} minute(s)" : "{$res['retry_after']} second(s)";
        return "Registration limit reached. Please wait {$timeStr} before creating another account.";
    }
    return null;
}

/**
 * Password reset request limit:
 * Allows configurable max reset requests per window per IP.
 */
function security_check_password_reset_rate(string $email, string $ip): ?string
{
    $maxAttempts = security_setting('rate_reset_attempts', 5);
    $windowSecs  = max(30, security_setting('rate_reset_window_minutes', 10) * 60);

    $res = security_rate_limit('pwd_reset', $ip, $maxAttempts, $windowSecs);
    if (!$res['allowed']) {
        $mins = (int) ceil($res['retry_after'] / 60);
        $timeStr = $mins > 1 ? "{$mins} minute(s)" : "{$res['retry_after']} second(s)";
        return "Too many password reset requests. Please wait {$timeStr}.";
    }
    return null;
}

/**
 * Order Creation rate limit:
 * Configurable order creations per 10 minutes per IP.
 */
function security_check_order_create_rate(string $ip): bool
{
    $maxAttempts = security_setting('rate_order_create_attempts', 50);
    $res = security_rate_limit('order_create', $ip, $maxAttempts, 600);
    return $res['allowed'];
}

/**
 * Payment checkout polling rate limit:
 * Max 60 poll requests per minute per order.
 */
function security_check_poll_rate(string $orderId): bool
{
    $res = security_rate_limit('poll_order', $orderId, 60, 60);
    return $res['allowed'];
}

/**
 * Manual UTR submission limit:
 * Configurable UTR submission attempts per 5 minutes per order to stop brute-forcing UTRs.
 */
function security_check_utr_rate(string $orderId): bool
{
    $maxAttempts = security_setting('rate_utr_submit_attempts', 10);
    $res = security_rate_limit('utr_submit', $orderId, $maxAttempts, 300);
    return $res['allowed'];
}

/**
 * Send hardened HTTP security response headers.
 */
function security_send_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * Master boot function: Executed on every web request.
 */
function security_boot(): void
{
    // Skip security throttle in CLI mode (cron jobs, diagnostics, CLI scripts)
    if (php_sapi_name() === 'cli') {
        return;
    }

    // 1. Send Enterprise HTTP Security Headers
    security_send_headers();

    // 2. WAF & Blacklist Active Filter
    security_waf_inspect();

    // 3. Global IP Rate Limiter (180 requests/min)
    security_rate_limit_global();
}
