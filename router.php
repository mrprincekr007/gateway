<?php
declare(strict_types=1);

/* =========================================================
   Router for PHP's built-in dev server.
   Usage:  php -S 127.0.0.1:8000 router.php
   Lets extensionless paths like /api/create-order work locally.
   ========================================================= */

$uri  = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$root = __DIR__;
$path = $root . str_replace('/', DIRECTORY_SEPARATOR, $uri);

// Serve real files (assets, *.php, images) directly.
if ($uri !== '/' && is_file($path)) {
    return false;
}

// Clean payment URL rewrite: /pay/lnk_... or /pay/fg_...
if (preg_match('#^/pay/([A-Za-z0-9_\-]+)/?$#', $uri, $matches)) {
    $param = $matches[1];
    if (str_starts_with($param, 'lnk_')) {
        $_GET['link'] = $param;
    } else {
        $_GET['order'] = $param;
    }
    require $root . DIRECTORY_SEPARATOR . 'pay' . DIRECTORY_SEPARATOR . 'index.php';
    return true;
}

// Map /api/create-order -> api/create-order.php
if (is_file($path . '.php')) {
    require $path . '.php';
    return true;
}

// Directory index.
if (is_dir($path)) {
    $index = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'index.php';
    if (is_file($index)) {
        require $index;
        return true;
    }
}

if ($uri === '/') {
    require $root . DIRECTORY_SEPARATOR . 'index.php';
    return true;
}

http_response_code(404);
require $root . DIRECTORY_SEPARATOR . '404.php';
return true;
