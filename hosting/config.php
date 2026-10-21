<?php
declare(strict_types=1);

/* =========================================================
   FatherGateway - Production Configuration (Hosting / cPanel)
   Enterprise Kernel: Security, Sessions, Rate Limiting & DB
   ========================================================= */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

/* ---------- 1. cPanel Database Connection Settings ---------- */
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'fatherga_fathergateway');
define('DB_USER', 'fatherga_fathergateway');
define('DB_PASS', '@pk811312');
define('DB_CHARSET', 'utf8mb4');

/* ---------- 2. Production App Settings ---------- */
define('SITE_NAME', 'Father Gateway');
define('SITE_TAGLINE', 'UPI Payment Gateway & Webhooks');
define('SITE_EMAIL', 'support@fathergateway.in');
define('SUPPORT_WHATSAPP', '910000000000');
define('APP_CURRENCY', '₹');
if (!defined('CURRENCY_SYMBOL')) {
    define('CURRENCY_SYMBOL', '₹');
}
define('APP_ENV', 'production');

/* Order / Link Entity Prefixes */
define('ORDER_PREFIX', 'fg_');
define('LINK_PREFIX', 'lnk_');
define('KEY_PREFIX', 'pk_');

/* ---------- 3. Cryptographic Security Key (Pre-Generated 256-Bit) ---------- */
define('APP_KEY', '0f6a72aecbd33cb1c247cce93f82c9c2e0dd3d82717c2dea3e1d650d83719944');

/* ---------- 4. Helper Function env() (Compatibility) ---------- */
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed {
        return $default;
    }
}

/* ---------- 5. Session & SSL (HTTPS / Cloudflare Auto-Detection) ---------- */
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

/* ---------- 6. Error Handling (Production Safe Logging) ---------- */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
$logDir = APP_ROOT . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
ini_set('error_log', $logDir . '/app.log');

/* ---------- 7. Base URL (Auto-Detects Domain, Subdomain & Subfolders) ---------- */
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
