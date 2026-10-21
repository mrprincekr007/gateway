<?php
declare(strict_types=1);

/* =========================================================
   FatherGateway — Enterprise Application Kernel & Config
   Supports: .env parsing, environment isolation, PSR-4 autoloading,
   error telemetry, session security & database bootstrapping.
   ========================================================= */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

/* ---------- 1. Zero-Dependency .env Loader ---------- */
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed {
        $val = getenv($key);
        if ($val === false) {
            $val = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        }
        if ($val === 'true' || $val === '(true)') return true;
        if ($val === 'false' || $val === '(false)') return false;
        if ($val === 'empty' || $val === '(empty)') return '';
        if ($val === 'null' || $val === '(null)') return null;
        return $val;
    }
}

$envFile = APP_ROOT . '/.env';
if (is_file($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

/* ---------- 2. Core Application Settings ---------- */
$isHostingEnv = !is_file($envFile) && is_file(APP_ROOT . '/hosting/config.php') && (
    (isset($_SERVER['DOCUMENT_ROOT']) && (strpos($_SERVER['DOCUMENT_ROOT'], 'fatherga') !== false || strpos($_SERVER['DOCUMENT_ROOT'], 'cpanel') !== false)) ||
    (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'fathergateway.in') !== false) ||
    (isset($_SERVER['HOME']) && strpos($_SERVER['HOME'], 'fatherga') !== false) ||
    (isset($_SERVER['USER']) && strpos($_SERVER['USER'], 'fatherga') !== false)
);

if (!defined('SITE_NAME'))        define('SITE_NAME', (string) env('SITE_NAME', 'Father Gateway'));
if (!defined('SITE_TAGLINE'))     define('SITE_TAGLINE', (string) env('SITE_TAGLINE', 'UPI Payment Gateway & Webhooks'));
if (!defined('SITE_EMAIL'))       define('SITE_EMAIL', (string) env('SITE_EMAIL', 'support@fathergateway.in'));
if (!defined('SUPPORT_WHATSAPP')) define('SUPPORT_WHATSAPP', (string) env('SUPPORT_WHATSAPP', '910000000000'));
if (!defined('APP_CURRENCY'))     define('APP_CURRENCY', (string) env('APP_CURRENCY', env('CURRENCY_SYMBOL', '₹')));
if (!defined('CURRENCY_SYMBOL'))  define('CURRENCY_SYMBOL', APP_CURRENCY);
if (!defined('APP_ENV'))          define('APP_ENV', (string) env('APP_ENV', $isHostingEnv ? 'production' : 'local'));

/* Entity Prefixes */
if (!defined('ORDER_PREFIX'))     define('ORDER_PREFIX', (string) env('ORDER_PREFIX', 'fg_'));
if (!defined('LINK_PREFIX'))      define('LINK_PREFIX', (string) env('LINK_PREFIX', 'lnk_'));
if (!defined('KEY_PREFIX'))       define('KEY_PREFIX', (string) env('KEY_PREFIX', 'pk_'));

/* ---------- 3. Database Connection Settings ---------- */
if (!defined('DB_HOST'))          define('DB_HOST', (string) env('DB_HOST', $isHostingEnv ? 'localhost' : '127.0.0.1'));
if (!defined('DB_PORT'))          define('DB_PORT', (string) env('DB_PORT', '3306'));
if (!defined('DB_NAME'))          define('DB_NAME', (string) env('DB_NAME', $isHostingEnv ? 'fatherga_fathergateway' : 'fathergateway'));
if (!defined('DB_USER'))          define('DB_USER', (string) env('DB_USER', $isHostingEnv ? 'fatherga_fathergateway' : 'root'));
if (!defined('DB_PASS'))          define('DB_PASS', (string) env('DB_PASS', ''));
if (!defined('DB_CHARSET'))       define('DB_CHARSET', (string) env('DB_CHARSET', 'utf8mb4'));

/* ---------- 4. Cryptographic Security Key ---------- */
if (!defined('APP_KEY'))          define('APP_KEY', (string) env('APP_KEY', 'CHANGE_ME_to_a_long_random_secret_string_1234567890'));

/* ---------- 5. SSL & Session Hardening ---------- */
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
    || (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $isHttps,
    ]);
    session_start();
}

/* ---------- 6. Error Handling & Production Telemetry ---------- */
if (APP_ENV === 'local') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    $logDir = APP_ROOT . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    ini_set('error_log', $logDir . '/app.log');
}

/* ---------- 7. Base URL Auto-Detection ---------- */
if (!defined('BASE_URL')) {
    $scheme  = $isHttps ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $appRoot = str_replace('\\', '/', APP_ROOT);
    $docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
    $path    = '';
    if ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) {
        $path = substr($appRoot, strlen($docRoot));
        $path = rtrim($path, '/');
    }
    define('BASE_URL', $scheme . '://' . $host . $path);
}

/* ---------- 8. Class Autoloader (PSR-4 Compliant) ---------- */
spl_autoload_register(function (string $class) {
    $file = APP_ROOT . '/classes/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

/* ---------- 9. Bootstrap Core Platform Helpers ---------- */
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/includes/functions.php';
require_once APP_ROOT . '/includes/icons.php';
require_once APP_ROOT . '/includes/security.php';
require_once APP_ROOT . '/includes/otp.php';
require_once APP_ROOT . '/includes/telemetry.php';

/* ---------- 10. Enterprise Security Engine & Rate Limiter Boot ---------- */
security_boot();