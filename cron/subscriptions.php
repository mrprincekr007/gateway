<?php
declare(strict_types=1);

/* =========================================================
   Subscription Cron:
     1-Shot (Cron / Task Scheduler):
       C:\xampp\php\php.exe cron/subscriptions.php
     Continuous Daemon:
       C:\xampp\php\php.exe cron/subscriptions.php --daemon
   Expires stale plans/payments and polls the payment gateway
   for subscription payments that have been paid.
   ========================================================= */

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/subscriptions.php';

$isCli     = (PHP_SAPI === 'cli');
$cronToken = get('token');
$expected  = defined('APP_KEY') ? substr(hash('sha256', APP_KEY), 0, 32) : '';

if (!$isCli && ($cronToken === '' || !hash_equals($expected, $cronToken))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Access denied. Run via CLI or provide ?token=" . $expected);
}

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

$isDaemon = in_array('--daemon', $argv ?? [], true) || in_array('-d', $argv ?? [], true);
$interval = 30;

if ($isDaemon) {
    echo "=========================================================\n";
    echo "FatherGateway Subscription Daemon Started\n";
    echo "   Expiring stale plans every {$interval}s + syncing gateway payments\n";
    echo "   Press Ctrl+C to stop at any time\n";
    echo "=========================================================\n\n";
}

do {
    $start = microtime(true);

    try {
        sub_ensure_tables();
        sub_expire_stale();

        $matched = sub_gateway_sweep();

        printf("[%s] expiry sweep done, subscription payments matched=%d\n",
            date('Y-m-d H:i:s'),
            $matched);
    } catch (\Throwable $e) {
        printf("[%s] ERROR %s\n", date('Y-m-d H:i:s'), $e->getMessage());
    }

    if (!$isDaemon) {
        printf("Done in %.1fs\n", microtime(true) - $start);
        break;
    }

    sleep($interval);
} while ($isDaemon);
