<?php
declare(strict_types=1);

/* =========================================================
   FatherGateway — Hosting & Database Storage Optimizer Engine
   Safely cleans ephemeral logs, stale abandoned checkouts &
   rotates error log files to keep hosting footprint minimal.
   Strict Rule: SUCCESSFUL (PAID) ORDERS ARE NEVER TOUCHED.
   ========================================================= */

require_once __DIR__ . '/functions.php';

/**
 * Gather storage and database footprint telemetry.
 */
function storage_get_stats(): array
{
    $pdo = db();

    // 1. Database table metrics from information_schema
    $dbSize = 0;
    $dbFree = 0;
    $tableCounts = [];

    try {
        $stmt = $pdo->prepare('
            SELECT table_name AS `name`,
                   table_rows AS `rows`,
                   data_length AS `data_bytes`,
                   index_length AS `index_bytes`,
                   (data_length + index_length) AS `total_bytes`,
                   data_free AS `free_bytes`
            FROM information_schema.TABLES
            WHERE table_schema = DATABASE()
        ');
        $stmt->execute();
        $tables = $stmt->fetchAll();

        foreach ($tables as $t) {
            $dbSize += (int) ($t['total_bytes'] ?? 0);
            $dbFree += (int) ($t['free_bytes'] ?? 0);
            $tableCounts[$t['name']] = (int) ($t['rows'] ?? 0);
        }
    } catch (Throwable $e) {
        // Fallback if information_schema restricted
    }

    // 2. Count purgeable transient records
    $oldWebhookLogs = 0;
    $oldExpiredOrders = 0;
    $totalSuccessOrders = 0;
    $oldAuditLogs = 0;
    $expiredTokens = 0;

    try {
        $oldWebhookLogs = (int) $pdo->query('
            SELECT COUNT(*) FROM `webhook_logs` 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)
        ')->fetchColumn();
    } catch (Throwable $e) {}

    try {
        $oldExpiredOrders = (int) $pdo->query("
            SELECT COUNT(*) FROM `orders` 
            WHERE `status` IN ('expired', 'failed')
              AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ")->fetchColumn();
    } catch (Throwable $e) {}

    try {
        $totalSuccessOrders = (int) $pdo->query("
            SELECT COUNT(*) FROM `orders` WHERE `status` = 'success'
        ")->fetchColumn();
    } catch (Throwable $e) {}

    try {
        $oldAuditLogs = (int) $pdo->query('
            SELECT COUNT(*) FROM `audit_logs` 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
        ')->fetchColumn();
    } catch (Throwable $e) {}

    try {
        $expiredTokens = (int) $pdo->query('
            SELECT COUNT(*) FROM `password_resets` 
            WHERE expires_at < NOW() OR created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ')->fetchColumn();
    } catch (Throwable $e) {}

    // 3. Inspect server log files on disk
    $logFiles = [];
    $totalLogBytes = 0;

    $candidateLogPaths = [
        APP_ROOT . '/error_log',
        APP_ROOT . '/public_html/error_log',
        APP_ROOT . '/storage/logs/laravel.log',
        APP_ROOT . '/storage/logs/app.log',
        APP_ROOT . '/storage/logs/system.log',
        APP_ROOT . '/storage/logs/imap.log',
    ];

    // Scan storage/logs directory for any .log files
    $logsDir = APP_ROOT . '/storage/logs';
    if (is_dir($logsDir)) {
        $scanned = @glob($logsDir . '/*.log') ?: [];
        foreach ($scanned as $sf) {
            if (!in_array($sf, $candidateLogPaths, true)) {
                $candidateLogPaths[] = $sf;
            }
        }
    }

    foreach ($candidateLogPaths as $path) {
        if (is_file($path)) {
            $bytes = (int) @filesize($path);
            $totalLogBytes += $bytes;
            $logFiles[] = [
                'path' => $path,
                'basename' => basename($path),
                'size_bytes' => $bytes,
                'size_formatted' => format_storage_bytes($bytes),
            ];
        }
    }

    return [
        'database_size_bytes'     => $dbSize,
        'database_size_formatted' => format_storage_bytes($dbSize),
        'database_free_bytes'     => $dbFree,
        'database_free_formatted' => format_storage_bytes($dbFree),
        'old_webhook_logs'        => $oldWebhookLogs,
        'old_expired_orders'      => $oldExpiredOrders,
        'protected_success_orders'=> $totalSuccessOrders,
        'old_audit_logs'          => $oldAuditLogs,
        'expired_tokens'          => $expiredTokens,
        'log_files'               => $logFiles,
        'total_log_bytes'         => $totalLogBytes,
        'total_log_formatted'     => format_storage_bytes($totalLogBytes),
    ];
}

/**
 * Execute deep storage optimization and safe cleanup.
 *
 * @param array $options Configuration overrides
 * @return array Detailed execution report
 */
function storage_run_clean(array $options = []): array
{
    $pdo = db();
    $startTime = microtime(true);

    $webhookDays = max(1, (int) ($options['webhook_days'] ?? 14));
    $orderDays   = max(7, (int) ($options['order_days'] ?? 30));
    $auditDays   = max(30, (int) ($options['audit_days'] ?? 90));
    $cleanLogs   = (bool) ($options['clean_logs'] ?? true);
    $optimizeDb  = (bool) ($options['optimize_db'] ?? true);

    $purged = [
        'webhook_logs'    => 0,
        'expired_orders'  => 0,
        'audit_logs'      => 0,
        'password_resets' => 0,
        'logs_trimmed'    => 0,
        'logs_bytes_freed'=> 0,
        'optimized_tables'=> [],
    ];

    // 1. Purge Webhook Logs (> N days)
    try {
        $stmt = $pdo->prepare('DELETE FROM `webhook_logs` WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
        $stmt->execute([$webhookDays]);
        $purged['webhook_logs'] = $stmt->rowCount();
    } catch (Throwable $e) {}

    // 2. Purge Stale Expired & Failed Orders (> N days)
    // CRITICAL SAFETY CHECK: NEVER TOUCH SUCCESSFUL ORDERS
    try {
        $stmt = $pdo->prepare("
            DELETE FROM `orders` 
            WHERE `status` IN ('expired', 'failed')
              AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$orderDays]);
        $purged['expired_orders'] = $stmt->rowCount();
    } catch (Throwable $e) {}

    // 3. Purge Expired Password Reset Tokens
    try {
        $stmt = $pdo->query('DELETE FROM `password_resets` WHERE expires_at < NOW() OR created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)');
        $purged['password_resets'] = $stmt->rowCount();
    } catch (Throwable $e) {}

    // 4. Purge Stale Audit Logs (> N days)
    try {
        $stmt = $pdo->prepare('DELETE FROM `audit_logs` WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
        $stmt->execute([$auditDays]);
        $purged['audit_logs'] = $stmt->rowCount();
    } catch (Throwable $e) {}

    // 5. Trim/Rotate Oversized Log Files on Hosting (> 3MB)
    if ($cleanLogs) {
        $candidateLogPaths = [
            APP_ROOT . '/error_log',
            APP_ROOT . '/public_html/error_log',
        ];
        $logsDir = APP_ROOT . '/storage/logs';
        if (is_dir($logsDir)) {
            $scanned = @glob($logsDir . '/*.log') ?: [];
            foreach ($scanned as $sf) {
                $candidateLogPaths[] = $sf;
            }
        }

        foreach ($candidateLogPaths as $lp) {
            if (is_file($lp) && is_writable($lp)) {
                $size = (int) @filesize($lp);
                // If larger than 3MB, keep only last 500 lines to prevent disk fill
                if ($size > (3 * 1024 * 1024)) {
                    $content = @file($lp, FILE_IGNORE_NEW_LINES);
                    if (is_array($content)) {
                        $tail = array_slice($content, -500);
                        @file_put_contents($lp, implode("\n", $tail) . "\n");
                        $newSize = (int) @filesize($lp);
                        $purged['logs_trimmed']++;
                        $purged['logs_bytes_freed'] += max(0, $size - $newSize);
                    }
                }
            }
        }
    }

    // 6. Defragment / OPTIMIZE Tables to Reclaim OS Hard Disk Space
    if ($optimizeDb) {
        $tablesToOptimize = ['orders', 'webhook_logs', 'audit_logs', 'password_resets', 'settings'];
        foreach ($tablesToOptimize as $tbl) {
            try {
                $row = $pdo->query("OPTIMIZE TABLE `{$tbl}`")->fetch();
                $msg = $row['Msg_text'] ?? 'OK';
                $purged['optimized_tables'][$tbl] = $msg;
            } catch (Throwable $e) {
                $purged['optimized_tables'][$tbl] = 'error: ' . $e->getMessage();
            }
        }
    }

    $elapsed = round((microtime(true) - $startTime) * 1000, 2);

    $summaryParts = [];
    if ($purged['expired_orders'] > 0) $summaryParts[] = "{$purged['expired_orders']} expired orders";
    if ($purged['webhook_logs'] > 0)   $summaryParts[] = "{$purged['webhook_logs']} webhook logs";
    if ($purged['password_resets'] > 0)$summaryParts[] = "{$purged['password_resets']} expired tokens";
    if ($purged['audit_logs'] > 0)     $summaryParts[] = "{$purged['audit_logs']} audit logs";
    if ($purged['logs_bytes_freed'] > 0) $summaryParts[] = format_storage_bytes($purged['logs_bytes_freed']) . ' log files freed';

    $summaryText = $summaryParts 
        ? 'Storage Cleaned: ' . implode(', ', $summaryParts) . " in {$elapsed}ms."
        : "Storage is already clean. Database tables optimized in {$elapsed}ms.";

    return [
        'ok'              => true,
        'summary'         => $summaryText,
        'purged'          => $purged,
        'elapsed_ms'      => $elapsed,
        'executed_at'     => date('Y-m-d H:i:s'),
    ];
}

/**
 * Format bytes into human-readable string.
 */
function format_storage_bytes(int|float $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow   = $bytes > 0 ? (int) floor(log($bytes) / log(1024)) : 0;
    $pow   = min($pow, count($units) - 1);
    $val   = $bytes / (1024 ** $pow);
    return round($val, $precision) . ' ' . $units[$pow];
}
