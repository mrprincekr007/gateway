<?php
declare(strict_types=1);

/* =========================================================
   FatherGateway — Helper Functions
   ========================================================= */

/* ---------- Output / URLs ---------- */

/** Escape HTML output. */
function e($value = ''): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build a full URL from an app-relative path. */
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/** Build a full URL for a local asset with a cache-busting version tag. */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = APP_ROOT . '/' . $path;
    $ver  = is_file($file) ? filemtime($file) : null;
    return url($path) . ($ver ? '?v=' . $ver : '');
}

/** Redirect and stop. */
function redirect(string $path): void
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}

/** Send a JSON response and stop. */
function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------- Input ---------- */

/** Read a trimmed POST value. */
function post(string $key, $default = ''): string
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : (string) $default;
}

/** Read a trimmed GET value. */
function get(string $key, $default = ''): string
{
    return isset($_GET[$key]) ? trim((string) $_GET[$key]) : (string) $default;
}

/** Read and decode the JSON request body. */
function json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/* ---------- CSRF ---------- */

/** Get (or create) the current CSRF token. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Render a hidden CSRF input. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Verify a submitted CSRF token. */
function verify_csrf(?string $token): bool
{
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/* ---------- Auth ---------- */

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

/** Currently logged-in user row (or null). */
function current_user(bool $refresh = false): ?array
{
    static $user = null;
    static $loaded = false;

    if ($loaded && !$refresh) {
        return $user;
    }
    $loaded = true;

    if (!is_logged_in()) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();

    if (!$row || $row['status'] !== 'active') {
        logout_user();
        return null;
    }

    $user = $row;

    // Heartbeat activity tracker (throttled to once every 60s, skipped if admin is impersonating)
    if (empty($_SESSION['admin_impersonator_id'])) {
        $lastPing = (int) ($_SESSION['last_activity_ping'] ?? 0);
        if (time() - $lastPing >= 60) {
            $_SESSION['last_activity_ping'] = time();
            try {
                $now = date('Y-m-d H:i:s');
                db()->prepare('UPDATE users SET last_activity_at = ? WHERE id = ?')->execute([$now, (int) $user['id']]);
                $user['last_activity_at'] = $now;
            } catch (\Throwable $e) {
                // Ignore ping errors
            }
        }
    }

    return $user;
}

/** Require a logged-in user, else redirect to login. */
function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    return $user;
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

/** Require an admin user. */
function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        die('Forbidden');
    }
    return $user;
}

/**
 * Parse an HTTP User-Agent string into structured OS, browser, device type, and icon.
 *
 * @param string|null $ua
 * @return array{os: string, browser: string, type: string, icon: string, summary: string}
 */
function parse_user_agent(?string $ua = null): array
{
    $ua = $ua !== null ? $ua : (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (trim($ua) === '') {
        return [
            'os'      => 'Unknown OS',
            'browser' => 'Unknown Browser',
            'type'    => 'Unknown Device',
            'icon'    => 'monitor',
            'summary' => 'Unknown Device'
        ];
    }

    // 1. Operating System
    $os = 'Unknown OS';
    if (preg_match('/Windows NT 10\.0/i', $ua)) {
        $os = 'Windows 10/11';
    } elseif (preg_match('/Windows NT 6\.3/i', $ua)) {
        $os = 'Windows 8.1';
    } elseif (preg_match('/Windows NT 6\.1/i', $ua)) {
        $os = 'Windows 7';
    } elseif (preg_match('/Windows/i', $ua)) {
        $os = 'Windows';
    } elseif (preg_match('/Android (\d+(\.\d+)?)/i', $ua, $m)) {
        $os = 'Android ' . $m[1];
    } elseif (preg_match('/Android/i', $ua)) {
        $os = 'Android';
    } elseif (preg_match('/iPhone|iPod/i', $ua)) {
        $os = 'iOS';
    } elseif (preg_match('/iPad/i', $ua)) {
        $os = 'iPadOS';
    } elseif (preg_match('/Macintosh|Mac OS X/i', $ua)) {
        $os = 'macOS';
    } elseif (preg_match('/Linux/i', $ua)) {
        $os = 'Linux';
    } elseif (preg_match('/CrOS/i', $ua)) {
        $os = 'ChromeOS';
    }

    // 2. Browser
    $browser = 'Unknown Browser';
    if (preg_match('/Edg(?:e)?\/(\d+)/i', $ua, $m)) {
        $browser = 'Edge ' . $m[1];
    } elseif (preg_match('/SamsungBrowser\/(\d+)/i', $ua, $m)) {
        $browser = 'Samsung ' . $m[1];
    } elseif (preg_match('/OPR\/(\d+)/i', $ua, $m) || preg_match('/Opera/i', $ua)) {
        $browser = 'Opera';
    } elseif (preg_match('/Chrome\/(\d+)/i', $ua, $m) || preg_match('/CriOS\/(\d+)/i', $ua, $m)) {
        $browser = 'Chrome ' . $m[1];
    } elseif (preg_match('/Firefox\/(\d+)/i', $ua, $m) || preg_match('/FxiOS\/(\d+)/i', $ua, $m)) {
        $browser = 'Firefox ' . $m[1];
    } elseif (preg_match('/Safari\//i', $ua) && !preg_match('/Chrome/i', $ua)) {
        $browser = 'Safari';
    }

    // 3. Device Type & Icon
    $type = 'Desktop';
    $icon = 'monitor';
    if (preg_match('/iPad|Tablet|PlayBook/i', $ua)) {
        $type = 'Tablet';
        $icon = 'tablet';
    } elseif (preg_match('/Mobile|iPhone|iPod|Android.*Mobile|webOS|BlackBerry|IEMobile/i', $ua)) {
        $type = 'Mobile';
        $icon = 'smartphone';
    }

    $summary = "{$os} · {$browser} · {$type}";

    return [
        'os'      => $os,
        'browser' => $browser,
        'type'    => $type,
        'icon'    => $icon,
        'summary' => $summary,
    ];
}

/**
 * Check if a user was active within the given threshold (default 5 minutes).
 */
function is_user_online(?string $lastActivityAt, int $thresholdSeconds = 300): bool
{
    if (empty($lastActivityAt)) {
        return false;
    }
    $ts = strtotime($lastActivityAt);
    if ($ts === false) {
        return false;
    }
    return (time() - $ts) <= $thresholdSeconds;
}

/**
 * Render a live pulsing Active Now / Offline badge.
 */
function user_online_status_badge(?string $lastActivityAt, ?string $lastLoginAt = null): string
{
    if (is_user_online($lastActivityAt, 300)) {
        return '<span class="status-pill success flex items-center gap-1.5" style="display:inline-flex;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:600;background:rgba(16,185,129,0.12);color:#10b981;border:1px solid rgba(16,185,129,0.3);" title="Active within the last 5 minutes">'
            . '<span class="status-dot-pulse" style="width:7px;height:7px;border-radius:50%;background:#10b981;box-shadow:0 0 8px #10b981;display:inline-block;"></span> Active Now</span>';
    }

    if (!empty($lastActivityAt)) {
        $ago = time_ago($lastActivityAt);
        return '<span class="status-pill neutral flex items-center gap-1.5" style="display:inline-flex;padding:3px 9px;border-radius:999px;font-size:11px;color:var(--color-ink-muted,#64748b);background:rgba(148,163,184,0.1);border:1px solid rgba(148,163,184,0.25);" title="Last active: ' . htmlspecialchars($lastActivityAt, ENT_QUOTES, 'UTF-8') . '">'
            . '<span style="width:6px;height:6px;border-radius:50%;background:#94a3b8;display:inline-block;"></span> Seen ' . htmlspecialchars($ago, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    if (!empty($lastLoginAt)) {
        $ago = time_ago($lastLoginAt);
        return '<span class="status-pill neutral flex items-center gap-1.5" style="display:inline-flex;padding:3px 9px;border-radius:999px;font-size:11px;color:var(--color-ink-muted,#64748b);background:rgba(148,163,184,0.1);border:1px solid rgba(148,163,184,0.25);" title="Last login: ' . htmlspecialchars($lastLoginAt, ENT_QUOTES, 'UTF-8') . '">'
            . '<span style="width:6px;height:6px;border-radius:50%;background:#94a3b8;display:inline-block;"></span> Logged in ' . htmlspecialchars($ago, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    return '<span class="status-pill neutral flex items-center gap-1.5" style="display:inline-flex;padding:3px 9px;border-radius:999px;font-size:11px;color:var(--color-ink-muted,#64748b);background:rgba(148,163,184,0.1);border:1px solid rgba(148,163,184,0.25);">'
        . '<span style="width:6px;height:6px;border-radius:50%;background:#94a3b8;display:inline-block;"></span> Never logged in</span>';
}

/** Log a user in by id and record audit/device metadata. */
function login_user(int $userId): void
{
    if (!headers_sent() && session_status() === PHP_SESSION_ACTIVE) {
        @session_regenerate_id(true);
    }
    $_SESSION['user_id'] = $userId;

    // Record login IP, device, and timestamp
    try {
        $ip = function_exists('security_ip') ? security_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $dev = parse_user_agent($ua);
        $deviceStr = $dev['summary'];
        $now = date('Y-m-d H:i:s');

        $stmt = db()->prepare('
            UPDATE users 
            SET last_login_at = ?, last_login_ip = ?, last_login_device = ?, last_activity_at = ? 
            WHERE id = ?
        ');
        $stmt->execute([$now, $ip, $deviceStr, $now, $userId]);
        $_SESSION['last_activity_ping'] = time();
    } catch (\Throwable $e) {
        error_log('login_user metadata tracking error: ' . $e->getMessage());
    }
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* ---------- Encryption (AES-256-GCM) ---------- */

/** Encrypt a secret string for at-rest storage. Returns base64 payload. */
function encrypt_secret(?string $plain): ?string
{
    if ($plain === null || $plain === '') {
        return null;
    }
    $key = hash('sha256', APP_KEY, true);
    $iv  = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        return null;
    }
    return base64_encode($iv . $tag . $cipher);
}

/** Decrypt a stored secret. Returns null on failure. */
function decrypt_secret(?string $payload): ?string
{
    if ($payload === null || $payload === '') {
        return null;
    }
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) < 28) {
        return null;
    }
    $key  = hash('sha256', APP_KEY, true);
    $iv   = substr($raw, 0, 12);
    $tag  = substr($raw, 12, 16);
    $body = substr($raw, 28);
    $plain = openssl_decrypt($body, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? null : $plain;
}

/* ---------- Generators ---------- */

function generate_merchant_id(): string
{
    return (string) random_int(1000000000, 9999999999);
}

function generate_order_id(): string
{
    return ORDER_PREFIX . strtoupper(bin2hex(random_bytes(4)));
}

function generate_link_id(): string
{
    return LINK_PREFIX . strtoupper(bin2hex(random_bytes(4)));
}

/** Returns [public_key, secret_plain]. */
function generate_api_keypair(): array
{
    $public = KEY_PREFIX . bin2hex(random_bytes(12));
    $secret = bin2hex(random_bytes(24));
    return [$public, $secret];
}

function generate_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

/* ---------- Small helpers ---------- */

/** Format an amount with the currency symbol. */
function money($amount = 0): string
{
    $val = is_numeric($amount) ? (float) $amount : 0.0;
    $sym = defined('APP_CURRENCY') ? APP_CURRENCY : (defined('CURRENCY_SYMBOL') && is_string(CURRENCY_SYMBOL) ? CURRENCY_SYMBOL : '₹');
    return $sym . number_format($val, 2);
}

/** Human-friendly relative-ish date. */
function nice_date(?string $datetime): string
{
    if (!$datetime) {
        return '--';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return $datetime;
    }
    return date('D M j, g:ia', $ts);
}

/** Format a datetime (or timestamp) as Indian Standard Time: "d-m-Y H:i:s". */
function ist_datetime($value = null): string
{
    try {
        $tz = new DateTimeZone('Asia/Kolkata');
    } catch (Throwable $e) {
        return date('d-m-Y H:i:s', is_numeric($value) ? (int) $value : strtotime((string) $value));
    }
    if ($value === null || $value === '') {
        $dt = new DateTime('now', $tz);
    } elseif (is_numeric($value)) {
        $dt = new DateTime('@' . (int) $value);
        $dt->setTimezone($tz);
    } else {
        $dt = new DateTime((string) $value, $tz);
    }
    return $dt->format('d-m-Y H:i:s');
}

/** Human-friendly relative time (e.g. "2m ago", "1h ago"). */
function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '--';
    }
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 60) {
        return 'Just now';
    }
    if ($diff < 3600) {
        return (int) floor($diff / 60) . 'm ago';
    }
    if ($diff < 86400) {
        return (int) floor($diff / 3600) . 'h ago';
    }
    if ($diff < 604800) {
        return (int) floor($diff / 86400) . 'd ago';
    }
    return date('M j', $time);
}

/** Initials from a name (for avatar fallback). */
function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        if ($p !== '') {
            $out .= strtoupper(mb_substr($p, 0, 1));
        }
    }
    return $out !== '' ? $out : 'U';
}

/** Flash message helpers. */
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- UI helpers ---------- */

/** Render a status badge, status => [badge-class, icon]. */
function status_badge(string $status): string
{
    $map = [
        'success'        => ['badge-success', 'check'],
        'pending'        => ['badge-warning', 'clock'],
        'failed'         => ['badge-danger',  'x'],
        'expired'        => ['badge-neutral', 'clock'],
        'active'         => ['badge-success', 'check'],
        'disabled'       => ['badge-neutral', 'x'],
        'revoked'        => ['badge-danger',  'x'],
        'suspended'      => ['badge-danger',  'lock'],
        'connected'      => ['badge-success', 'check'],
        'disconnected'   => ['badge-danger',  'alert'],
        'not_configured' => ['badge-neutral', 'plug'],
    ];
    [$cls, $icn] = $map[$status] ?? ['badge-neutral', 'circle'];
    return '<span class="badge ' . $cls . '">' . icon($icn, 12) . ' ' . e(ucwords(str_replace('_', ' ', $status))) . '</span>';
}

/** Pagination window helper for paginated lists. */
function page_window(int $page, int $totalPages): array
{
    $first = max(1, $page - 2);
    $last  = min($totalPages, $page + 2);
    return range($first, $last);
}

/** Read a setting row for a user (or null). */
function setting_get(int $userId, string $key, $default = null)
{
    $stmt = db()->prepare('SELECT svalue FROM settings WHERE user_id = ? AND skey = ? LIMIT 1');
    $stmt->execute([$userId, $key]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : $v;
}

function setting_set(int $userId, string $key, $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (user_id, skey, svalue) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)'
    );
    $stmt->execute([$userId, $key, $value]);
}

function setting_delete(int $userId, string $key): void
{
    $stmt = db()->prepare('DELETE FROM settings WHERE user_id = ? AND skey = ?');
    $stmt->execute([$userId, $key]);
}