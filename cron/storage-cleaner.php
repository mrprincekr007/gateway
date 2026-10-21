<?php
declare(strict_types=1);

/* =========================================================
   FatherGateway — Automated Hosting Storage Cleaner (Cron)
   
   Usage via cPanel Cron Jobs (Daily at 3:00 AM recommended):
     /usr/local/bin/php /home/username/public_html/cron/storage-cleaner.php
     
   Usage via External Web Cron (cron-job.org / EasyCron):
     https://yourdomain.com/cron/storage-cleaner.php?token=YOUR_CRON_TOKEN
   ========================================================= */

require_once __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/storage_cleaner.php';

$isCli = (PHP_SAPI === 'cli');
$cronToken = get('token');
$expectedToken = defined('APP_KEY') ? substr(hash('sha256', APP_KEY . '_storage_cron'), 0, 32) : '';

// Security guard: Only allow CLI execution or authenticated HTTP requests
if (!$isCli && ($cronToken === '' || !hash_equals($expectedToken, $cronToken))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Access denied. Run via CLI (cPanel Cron) or provide valid token:\n/cron/storage-cleaner.php?token=" . $expectedToken);
}

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

$now = date('Y-m-d H:i:s');
echo "=========================================================\n";
echo " FatherGateway Hosting & Database Storage Optimizer\n";
echo " Executed at: {$now}\n";
echo " Mode: " . ($isCli ? "CLI (Cron / Task Scheduler)" : "Web Hook") . "\n";
echo "=========================================================\n\n";

// Execute cleanup
$report = storage_run_clean([
    'webhook_days' => 14,
    'order_days'   => 30, // Only deletes expired/failed orders older than 30 days
    'audit_days'   => 90,
    'clean_logs'   => true,
    'optimize_db'  => true,
]);

echo "[1/4] Expired/Failed Orders Purged:  " . (int) $report['purged']['expired_orders'] . " (paid orders untouched)\n";
echo "[2/4] Transient Webhook Logs Purged: " . (int) $report['purged']['webhook_logs'] . "\n";
echo "[3/4] Stale Tokens & Audit Logs:     " . (int) ($report['purged']['password_resets'] + $report['purged']['audit_logs']) . "\n";
echo "[4/4] Server Log Files Freed:        " . format_storage_bytes((int) $report['purged']['logs_bytes_freed']) . "\n";

echo "\nDatabase Table Defragmentation (Reclaimed Disk Space):\n";
foreach ($report['purged']['optimized_tables'] as $tbl => $status) {
    echo "  - `{$tbl}`: {$status}\n";
}

echo "\nSummary: " . $report['summary'] . "\n";
echo "Execution finished successfully in " . $report['elapsed_ms'] . "ms.\n";
