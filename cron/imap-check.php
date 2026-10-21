<?php
declare(strict_types=1);

/* =========================================================
   Cron & Real-Time Daemon Watcher:
     1-Shot (Cron / Task Scheduler):
       C:\xampp\php\php.exe cron/imap-check.php
     Continuous Real-Time Daemon (Live Sub-Second Detection):
       C:\xampp\php\php.exe cron/imap-check.php --daemon
   Polls Gmail for FamApp/bank UPI alerts and confirms orders.
   ========================================================= */

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/mail.php';

$isCli = (PHP_SAPI === 'cli');
$cronToken = get('token');
$expectedToken = defined('APP_KEY') ? substr(hash('sha256', APP_KEY), 0, 32) : '';

if (!$isCli && ($cronToken === '' || !hash_equals($expectedToken, $cronToken))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Access denied. Run via CLI (cPanel Cron Jobs) or provide valid token:\n/cron/imap-check.php?token=" . $expectedToken);
}

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

$isDaemon = in_array('--daemon', $argv ?? [], true) || in_array('-d', $argv ?? [], true);

if ($isDaemon) {
    echo "=========================================================\n";
    echo "⚡ FatherGateway Real-Time Payment Daemon Started\n";
    echo "   Watching Gmail IMAP every 3 seconds for instant credits\n";
    echo "   Press Ctrl+C to stop at any time\n";
    echo "=========================================================\n\n";
}

do {
    $start   = microtime(true);
    $watched = 0;
    $matched = 0;

    try {
        $st = db()->query('SELECT id, name FROM users WHERE imap_status = "connected"');
        $users = $st->fetchAll();
        foreach ($users as $u) {
            $res = mail_process_user((int) $u['id']);
            if (!empty($res['connected'])) {
                $watched++;
                if (!empty($res['matched'])) {
                    setting_set((int) $u['id'], 'imap_last_check', date('M j, Y g:i a') . ' — matched ' . $res['matched'] . ' payment(s): ' . implode(', ', $res['summary']));
                    printf("[%s] ⚡ MATCH! %-24s matched=%d webhooks=%d\n",
                        date('Y-m-d H:i:s'), substr((string) $u['name'], 0, 24),
                        $res['matched'], $res['webhooks']);
                } elseif (!$isDaemon) {
                    printf("[%s] %-28s checked=%d seen=%d matched=%d webhooks=%d\n",
                        date('Y-m-d H:i:s'), substr((string) $u['name'], 0, 28),
                        $res['seen'], $res['seen'], $res['matched'], $res['webhooks']);
                }
            } else {
                printf("[%s] %-28s ERROR %s\n", date('Y-m-d H:i:s'), $u['name'], $res['error'] ?? 'unknown');
            }
            $matched += (int) ($res['matched'] ?? 0);
        }
    } catch (\Throwable $e) {
        printf("[%s] Database / Network error: %s\n", date('Y-m-d H:i:s'), $e->getMessage());
    }

    if (!$isDaemon) {
        printf("\nDone. Users watched: %d, payments confirmed this run: %d, took %.1fs\n", $watched, $matched, microtime(true) - $start);
        break;
    }

    // Sleep 3 seconds before next iteration in daemon mode
    sleep(3);
} while ($isDaemon);