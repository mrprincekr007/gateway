<?php
declare(strict_types=1);

/* =========================================================
   FatherGateway — Admin Operations Library (v2)
   Finance, compliance, growth & support modules.
   Auto-provisions its own tables so pages work after deploy
   even if the SQL migration was not imported manually.
   ========================================================= */

require_once __DIR__ . '/admin.php';

/** Idempotently create the v2 admin tables (no-op if they exist). */
function ops_ensure_tables(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $statements = [
        "CREATE TABLE IF NOT EXISTS `app_settings` (
            `skey` VARCHAR(80) NOT NULL, `svalue` TEXT DEFAULT NULL,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`skey`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `audit_logs` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `actor_id` INT UNSIGNED DEFAULT NULL,
            `actor_name` VARCHAR(120) DEFAULT NULL, `action` VARCHAR(80) NOT NULL,
            `entity_type` VARCHAR(40) DEFAULT NULL, `entity_id` VARCHAR(80) DEFAULT NULL,
            `details` TEXT DEFAULT NULL, `ip` VARCHAR(45) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), KEY `idx_actor` (`actor_id`), KEY `idx_action` (`action`), KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `blacklist_entries` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `entry_type` ENUM('upi','email','ip','phone','card') NOT NULL DEFAULT 'upi',
            `value` VARCHAR(190) NOT NULL, `reason` VARCHAR(255) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), UNIQUE KEY `uniq_type_value` (`entry_type`, `value`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($statements as $sql) {
        try {
            db()->exec($sql);
        } catch (Throwable $e) {
            /* table already present or engine mismatch — ignore */
        }
    }

    // NOTE: Plaintext password storage has been removed for security.
    // Passwords are stored only as one-way bcrypt hashes.
}

/* ---------- Global platform settings ---------- */

$GLOBALS['__ops_settings'] = null;

/** Load all global settings once. */
function ops_settings_all(): array
{
    if ($GLOBALS['__ops_settings'] !== null) {
        return $GLOBALS['__ops_settings'];
    }
    $out = [];
    try {
        foreach (db()->query('SELECT skey, svalue FROM app_settings') as $row) {
            $out[$row['skey']] = $row['svalue'];
        }
    } catch (Throwable $e) {
        $out = [];
    }
    return $GLOBALS['__ops_settings'] = $out;
}

function ops_setting(string $key, $default = null)
{
    $all = ops_settings_all();
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

function ops_setting_set(string $key, $value): void
{
    db()->prepare(
        'INSERT INTO app_settings (skey, svalue) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)'
    )->execute([$key, (string) $value]);
    $GLOBALS['__ops_settings'][$key] = (string) $value;
}

function ops_int(string $key, int $default = 0): int
{
    $v = ops_setting($key, null);
    return ($v === null || $v === '') ? $default : (int) $v;
}

/* ---------- Audit trail ---------- */

function ops_audit(string $action, ?string $entityType = null, $entityId = null, ?string $details = null): void
{
    $actor = current_user();
    try {
        db()->prepare(
            'INSERT INTO audit_logs (actor_id, actor_name, action, entity_type, entity_id, details, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $actor['id'] ?? null,
            $actor['name'] ?? 'System',
            $action,
            $entityType,
            $entityId !== null ? (string) $entityId : null,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        /* auditing must never break a request */
    }
}

ops_ensure_tables();
