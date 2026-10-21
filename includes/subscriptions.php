<?php
declare(strict_types=1);

/* =========================================================
   FatherGateway — Subscription engine
   Plans, per-merchant subscriptions, plan-limit gating and a
   self-serve UPI checkout that collects into the admin's UPI.
   Auto-provisions its own tables so pages work after deploy
   even if the SQL migration was not imported manually.
   ========================================================= */

require_once dirname(__DIR__) . '/config/config.php';
require_once APP_ROOT . '/includes/qr.php';
require_once APP_ROOT . '/includes/mail.php';
require_once APP_ROOT . '/includes/sub_gateways.php';

const SUB_PAYMENT_TTL_MINUTES = 30;

/** Idempotently create the subscription tables and seed defaults. */
function sub_ensure_tables(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $statements = [
        "CREATE TABLE IF NOT EXISTS `subscription_plans` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(80) NOT NULL,
            `tagline` VARCHAR(160) DEFAULT NULL,
            `price` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `duration_days` INT UNSIGNED NOT NULL DEFAULT 30,
            `is_trial` TINYINT(1) NOT NULL DEFAULT 0,
            `api_key_limit` INT UNSIGNED NOT NULL DEFAULT 1,
            `txn_limit` INT UNSIGNED NOT NULL DEFAULT 100,
            `link_limit` INT UNSIGNED NOT NULL DEFAULT 20,
            `webhook_limit` INT UNSIGNED NOT NULL DEFAULT 1,
            `api_enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `webhooks_enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `priority_support` TINYINT(1) NOT NULL DEFAULT 0,
            `badge` VARCHAR(40) DEFAULT NULL,
            `features` TEXT DEFAULT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `user_subscriptions` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `plan_id` INT UNSIGNED NOT NULL,
            `status` ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
            `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `starts_at` DATETIME NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `payment_id` INT UNSIGNED DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user` (`user_id`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `subscription_payments` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `reference` VARCHAR(40) NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `plan_id` INT UNSIGNED NOT NULL,
            `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `status` ENUM('pending','success','expired','failed') NOT NULL DEFAULT 'pending',
            `upi_id` VARCHAR(120) DEFAULT NULL,
            `utr` VARCHAR(40) DEFAULT NULL,
            `sender_name` VARCHAR(120) DEFAULT NULL,
            `verified_by` VARCHAR(20) DEFAULT NULL,
            `paid_at` DATETIME DEFAULT NULL,
            `expires_at` DATETIME DEFAULT NULL,
            `activated_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_reference` (`reference`),
            KEY `idx_user` (`user_id`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($statements as $sql) {
        db()->exec($sql);
    }

    /* Link a subscription payment to the payment created at the gateway.
       Run the column migration only once (persisted flag) instead of on every request. */
    $schemaMigrated = false;
    try {
        $schemaMigrated = (string) db()->query("SELECT svalue FROM app_settings WHERE skey = 'sub_schema_v2' LIMIT 1")->fetchColumn() === '1';
    } catch (\Throwable $e) {
        $schemaMigrated = false;
    }
    if (!$schemaMigrated) {
        try {
            $have = [];
            foreach (db()->query('SHOW COLUMNS FROM subscription_payments')->fetchAll() as $c) {
                $have[$c['Field']] = true;
            }
            if (empty($have['gateway_order_id'])) {
                db()->exec('ALTER TABLE subscription_payments ADD COLUMN `gateway_order_id` VARCHAR(64) DEFAULT NULL AFTER `upi_id`');
            }
            if (empty($have['gateway_provider'])) {
                db()->exec('ALTER TABLE subscription_payments ADD COLUMN `gateway_provider` VARCHAR(20) DEFAULT NULL AFTER `gateway_order_id`');
            }
            if (empty($have['gateway_checkout_url'])) {
                db()->exec('ALTER TABLE subscription_payments ADD COLUMN `gateway_checkout_url` VARCHAR(255) DEFAULT NULL AFTER `gateway_provider`');
            }
            db()->prepare("INSERT INTO app_settings (skey, svalue) VALUES ('sub_schema_v2', '1')
                           ON DUPLICATE KEY UPDATE svalue = '1'")->execute();
        } catch (\Throwable $e) {
            /* column check is best-effort */
        }
    }

    $defaults = [
        'sub_enabled'             => '1',
        'sub_trial_days'          => '1',
        'sub_auto_enabled'        => '0',
        'sub_auto_provider'       => 'own',
        'sub_auto_api_url'        => '',
        'sub_auto_api_key'        => '',
        'sub_auto_api_secret'     => '',
        'sub_auto_webhook_secret' => '',
    ];
    $ins = db()->prepare('INSERT IGNORE INTO app_settings (skey, svalue) VALUES (?, ?)');
    foreach ($defaults as $k => $v) {
        $ins->execute([$k, $v]);
    }

    $count = (int) db()->query('SELECT COUNT(*) FROM subscription_plans')->fetchColumn();
    if ($count === 0) {
        sub_seed_plans();
    }
}

/** Seed the four launch plans (trial, 49, 99, 249/3-month). */
function sub_seed_plans(): void
{
    $plans = [
        [
            'Free Trial', 'Full test drive — every feature for a day', 0, 1, 1,
            1, 10, 5, 1, 1, 1, 0, 'Free',
            "Full dashboard & payment links\nAPI keys & dynamic QR\nWebhooks & auto UTR detection\n1-day access, no card needed",
            1,
        ],
        [
            'Starter Monthly', 'For growing merchants', 49, 30, 0,
            2, 500, 50, 2, 1, 1, 0, null,
            "2 API keys\n500 transactions / month\n50 payment links / month\n2 webhook endpoints",
            2,
        ],
        [
            'Pro Monthly', 'Everything, unlimited', 99, 30, 0,
            0, 0, 0, 0, 1, 1, 1, 'Popular',
            "Unlimited API keys\nUnlimited transactions\nUnlimited payment links\nUnlimited webhooks\nPriority support",
            3,
        ],
        [
            'Pro Quarterly', '3 months of unlimited power', 249, 90, 0,
            0, 0, 0, 0, 1, 1, 1, 'Best value',
            "Everything in Pro Monthly\nEverything unlimited\n3 months validity\nPriority support",
            4,
        ],
    ];
    $stmt = db()->prepare(
        'INSERT INTO subscription_plans
            (name, tagline, price, duration_days, is_trial, api_key_limit, txn_limit, link_limit,
             webhook_limit, api_enabled, webhooks_enabled, priority_support, badge, features, sort_order, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active")'
    );
    foreach ($plans as $p) {
        $stmt->execute($p);
    }
}

/* ---------- Settings ---------- */

function sub_setting(string $key, $default = null)
{
    sub_ensure_tables();
    $stmt = db()->prepare('SELECT svalue FROM app_settings WHERE skey = ? LIMIT 1');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : $v;
}

function sub_setting_set(string $key, $value): void
{
    sub_ensure_tables();
    db()->prepare(
        'INSERT INTO app_settings (skey, svalue, updated_at) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), updated_at = NOW()'
    )->execute([$key, (string) $value]);
}

/* ---------- Plans ---------- */

function sub_plans(bool $activeOnly = true): array
{
    sub_ensure_tables();
    $sql = 'SELECT * FROM subscription_plans';
    if ($activeOnly) {
        $sql .= " WHERE status = 'active'";
    }
    $sql .= ' ORDER BY sort_order ASC, price ASC, id ASC';
    return db()->query($sql)->fetchAll();
}

function sub_plan(int $id): ?array
{
    sub_ensure_tables();
    $st = db()->prepare('SELECT * FROM subscription_plans WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function sub_trial_plan(): ?array
{
    sub_ensure_tables();
    $st = db()->query("SELECT * FROM subscription_plans WHERE is_trial = 1 AND status = 'active' ORDER BY sort_order ASC, id ASC LIMIT 1");
    return $st->fetch() ?: null;
}

/**
 * Create a new subscription plan tier.
 */
function sub_plan_create(array $data): int
{
    sub_ensure_tables();
    $name            = trim((string) ($data['name'] ?? 'Untitled Plan'));
    $tagline         = !empty($data['tagline']) ? trim((string) $data['tagline']) : null;
    $price           = max(0.0, (float) ($data['price'] ?? 0));
    $durationDays    = max(1, (int) ($data['duration_days'] ?? 30));
    $isTrial         = !empty($data['is_trial']) ? 1 : 0;
    $apiKeyLimit     = max(0, (int) ($data['api_key_limit'] ?? 1));
    $txnLimit        = max(0, (int) ($data['txn_limit'] ?? 100));
    $linkLimit       = max(0, (int) ($data['link_limit'] ?? 20));
    $webhookLimit    = max(0, (int) ($data['webhook_limit'] ?? 1));
    $apiEnabled      = isset($data['api_enabled']) ? (!empty($data['api_enabled']) ? 1 : 0) : 1;
    $webhooksEnabled = isset($data['webhooks_enabled']) ? (!empty($data['webhooks_enabled']) ? 1 : 0) : 1;
    $prioritySupport = !empty($data['priority_support']) ? 1 : 0;
    $badge           = !empty($data['badge']) ? trim((string) $data['badge']) : null;
    $features        = !empty($data['features']) ? trim((string) $data['features']) : null;
    $sortOrder       = (int) ($data['sort_order'] ?? 0);
    $status          = (($data['status'] ?? 'active') === 'inactive') ? 'inactive' : 'active';

    if ($isTrial) {
        db()->exec('UPDATE subscription_plans SET is_trial = 0');
    }

    $stmt = db()->prepare(
        'INSERT INTO subscription_plans
            (name, tagline, price, duration_days, is_trial, api_key_limit, txn_limit, link_limit,
             webhook_limit, api_enabled, webhooks_enabled, priority_support, badge, features, sort_order, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $name, $tagline, $price, $durationDays, $isTrial,
        $apiKeyLimit, $txnLimit, $linkLimit, $webhookLimit,
        $apiEnabled, $webhooksEnabled, $prioritySupport,
        $badge, $features, $sortOrder, $status,
    ]);

    return (int) db()->lastInsertId();
}

/**
 * Update an existing subscription plan tier.
 */
function sub_plan_update(int $id, array $data): bool
{
    sub_ensure_tables();
    $existing = sub_plan($id);
    if (!$existing) {
        return false;
    }

    $name            = trim((string) ($data['name'] ?? $existing['name']));
    $tagline         = array_key_exists('tagline', $data) ? (!empty($data['tagline']) ? trim((string) $data['tagline']) : null) : $existing['tagline'];
    $price           = isset($data['price']) ? max(0.0, (float) $data['price']) : (float) $existing['price'];
    $durationDays    = isset($data['duration_days']) ? max(1, (int) $data['duration_days']) : (int) $existing['duration_days'];
    $isTrial         = isset($data['is_trial']) ? (!empty($data['is_trial']) ? 1 : 0) : (int) $existing['is_trial'];
    $apiKeyLimit     = isset($data['api_key_limit']) ? max(0, (int) $data['api_key_limit']) : (int) $existing['api_key_limit'];
    $txnLimit        = isset($data['txn_limit']) ? max(0, (int) $data['txn_limit']) : (int) $existing['txn_limit'];
    $linkLimit       = isset($data['link_limit']) ? max(0, (int) $data['link_limit']) : (int) $existing['link_limit'];
    $webhookLimit    = isset($data['webhook_limit']) ? max(0, (int) $data['webhook_limit']) : (int) $existing['webhook_limit'];
    $apiEnabled      = isset($data['api_enabled']) ? (!empty($data['api_enabled']) ? 1 : 0) : (int) ($existing['api_enabled'] ?? 1);
    $webhooksEnabled = isset($data['webhooks_enabled']) ? (!empty($data['webhooks_enabled']) ? 1 : 0) : (int) ($existing['webhooks_enabled'] ?? 1);
    $prioritySupport = isset($data['priority_support']) ? (!empty($data['priority_support']) ? 1 : 0) : (int) ($existing['priority_support'] ?? 0);
    $badge           = array_key_exists('badge', $data) ? (!empty($data['badge']) ? trim((string) $data['badge']) : null) : $existing['badge'];
    $features        = array_key_exists('features', $data) ? (!empty($data['features']) ? trim((string) $data['features']) : null) : $existing['features'];
    $sortOrder       = isset($data['sort_order']) ? (int) $data['sort_order'] : (int) ($existing['sort_order'] ?? 0);
    $status          = isset($data['status']) ? (($data['status'] === 'inactive') ? 'inactive' : 'active') : $existing['status'];

    if ($isTrial) {
        db()->prepare('UPDATE subscription_plans SET is_trial = 0 WHERE id != ?')->execute([$id]);
    }

    $stmt = db()->prepare(
        'UPDATE subscription_plans SET
            name = ?, tagline = ?, price = ?, duration_days = ?, is_trial = ?,
            api_key_limit = ?, txn_limit = ?, link_limit = ?, webhook_limit = ?,
            api_enabled = ?, webhooks_enabled = ?, priority_support = ?,
            badge = ?, features = ?, sort_order = ?, status = ?
         WHERE id = ?'
    );
    return $stmt->execute([
        $name, $tagline, $price, $durationDays, $isTrial,
        $apiKeyLimit, $txnLimit, $linkLimit, $webhookLimit,
        $apiEnabled, $webhooksEnabled, $prioritySupport,
        $badge, $features, $sortOrder, $status,
        $id,
    ]);
}

/**
 * Delete a plan permanently (only if not the trial plan).
 */
function sub_plan_delete(int $id): bool
{
    sub_ensure_tables();
    $plan = sub_plan($id);
    if (!$plan || !empty($plan['is_trial'])) {
        return false;
    }
    $stmt = db()->prepare('DELETE FROM subscription_plans WHERE id = ?');
    return $stmt->execute([$id]);
}

/**
 * Toggle plan status between active and inactive.
 */
function sub_plan_toggle(int $id, ?string $status = null): string
{
    sub_ensure_tables();
    $plan = sub_plan($id);
    if (!$plan) {
        return 'inactive';
    }
    $newStatus = $status !== null ? $status : ($plan['status'] === 'active' ? 'inactive' : 'active');
    db()->prepare('UPDATE subscription_plans SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
    return $newStatus;
}

/** Human label for a plan limit (0 = unlimited). */
function sub_limit_label(int $n): string
{
    return $n <= 0 ? 'Unlimited' : number_format($n);
}

/* ---------- Current subscription / grant ---------- */

function sub_expire_user(int $userId): void
{
    db()->prepare("UPDATE user_subscriptions SET status = 'expired' WHERE user_id = ? AND status = 'active' AND expires_at <= NOW()")
        ->execute([$userId]);
}

function sub_cleanup_unpaid_payments(?int $userId = null): void
{
    try {
        if ($userId !== null && $userId > 0) {
            db()->prepare("DELETE FROM subscription_payments WHERE user_id = ? AND status != 'success' AND (status IN ('expired', 'failed', 'cancelled') OR (expires_at IS NOT NULL AND expires_at <= NOW()))")->execute([$userId]);
        } else {
            db()->exec("DELETE FROM subscription_payments WHERE status IN ('expired', 'failed', 'cancelled') OR (status = 'pending' AND expires_at IS NOT NULL AND expires_at <= NOW())");
        }
    } catch (\Throwable $e) {
        // best effort
    }
}

function sub_expire_stale(): void
{
    sub_ensure_tables();
    db()->exec("UPDATE user_subscriptions SET status = 'expired' WHERE status = 'active' AND expires_at <= NOW()");
    sub_cleanup_unpaid_payments();
}

/** The merchant's active subscription joined with its plan, or null. */
function sub_current(int $userId): ?array
{
    sub_ensure_tables();
    sub_expire_user($userId);
    $st = db()->prepare(
        "SELECT s.*, p.name AS plan_name, p.price AS plan_price, p.duration_days, p.is_trial,
                p.api_key_limit, p.txn_limit, p.link_limit, p.webhook_limit,
                p.api_enabled, p.webhooks_enabled, p.priority_support, p.badge, p.features, p.tagline
         FROM user_subscriptions s
         JOIN subscription_plans p ON p.id = s.plan_id
         WHERE s.user_id = ? AND s.status = 'active' AND s.expires_at > NOW()
         ORDER BY s.id DESC LIMIT 1"
    );
    $st->execute([$userId]);
    return $st->fetch() ?: null;
}

/** Give the free trial to a brand-new merchant (only if they never had a plan). */
function sub_bootstrap(int $userId): ?array
{
    sub_ensure_tables();
    $current = sub_current($userId);
    if ($current) {
        return $current;
    }
    $st = db()->prepare('SELECT COUNT(*) FROM user_subscriptions WHERE user_id = ?');
    $st->execute([$userId]);
    if ((int) $st->fetchColumn() > 0) {
        return null;
    }
    $trial = sub_trial_plan();
    if (!$trial) {
        return null;
    }
    sub_grant($userId, (int) $trial['id']);
    return sub_current($userId);
}

/** Activate (or switch to) a plan for a merchant. */
function sub_grant(int $userId, int $planId, ?int $paymentId = null, ?float $amount = null): ?array
{
    $plan = sub_plan($planId);
    if (!$plan) {
        return null;
    }
    $duration = (int) $plan['duration_days'];
    if ($plan['is_trial']) {
        $duration = max(1, (int) sub_setting('sub_trial_days', (string) $duration));
    }
    db()->prepare("UPDATE user_subscriptions SET status = 'expired' WHERE user_id = ? AND status = 'active'")
        ->execute([$userId]);

    $starts  = date('Y-m-d H:i:s');
    $expires = date('Y-m-d H:i:s', strtotime('+' . max(1, $duration) . ' days'));

    db()->prepare(
        'INSERT INTO user_subscriptions (user_id, plan_id, status, amount, starts_at, expires_at, payment_id)
         VALUES (?, ?, "active", ?, ?, ?, ?)'
    )->execute([$userId, $planId, $amount ?? (float) $plan['price'], $starts, $expires, $paymentId]);

    return sub_current($userId);
}

/* ---------- Limits & gating ---------- */

function sub_metric_column(string $metric): ?string
{
    return [
        'transactions' => 'txn_limit',
        'links'        => 'link_limit',
        'api_keys'     => 'api_key_limit',
        'webhooks'     => 'webhook_limit',
    ][$metric] ?? null;
}

/** Usage for the current billing period (or last 30 days if none). */
function sub_usage(int $userId, string $metric): int
{
    $current = sub_current($userId);
    $since = $current['starts_at'] ?? date('Y-m-d H:i:s', strtotime('-30 days'));
    switch ($metric) {
        case 'transactions':
            $st = db()->prepare('SELECT COUNT(*) FROM orders WHERE user_id = ? AND created_at >= ?');
            $st->execute([$userId, $since]);
            return (int) $st->fetchColumn();
        case 'links':
            $st = db()->prepare('SELECT COUNT(*) FROM payment_links WHERE user_id = ? AND created_at >= ?');
            $st->execute([$userId, $since]);
            return (int) $st->fetchColumn();
        case 'api_keys':
            $st = db()->prepare("SELECT COUNT(*) FROM api_keys WHERE user_id = ? AND status = 'active'");
            $st->execute([$userId]);
            return (int) $st->fetchColumn();
        case 'webhooks':
            $st = db()->prepare("SELECT COUNT(*) FROM webhooks WHERE user_id = ? AND status = 'active'");
            $st->execute([$userId]);
            return (int) $st->fetchColumn();
    }
    return 0;
}

/** Limit for a metric under the active plan (0 = unlimited, -1 = no plan). */
function sub_limit(int $userId, string $metric): int
{
    $col = sub_metric_column($metric);
    if ($col === null) {
        return 0;
    }
    $current = sub_current($userId);
    if (!$current) {
        return -1;
    }
    return (int) $current[$col];
}

function sub_feature(int $userId, string $feature): bool
{
    $current = sub_current($userId);
    if (!$current) {
        return false;
    }
    return match ($feature) {
        'api'              => (int) $current['api_enabled'] === 1,
        'webhooks'         => (int) $current['webhooks_enabled'] === 1,
        'priority_support' => (int) $current['priority_support'] === 1,
        default            => true,
    };
}

/**
 * Whether a merchant may perform an action.
 * Returns ['ok'=>bool, 'used'=>int, 'limit'=>int, 'reason'=>?string].
 */
function sub_can(int $userId, string $metric): array
{
    if ((string) sub_setting('sub_enabled', '1') === '0') {
        return ['ok' => true, 'used' => 0, 'limit' => 0, 'reason' => null];
    }

    sub_bootstrap($userId);
    $current = sub_current($userId);
    if (!$current) {
        return ['ok' => false, 'used' => 0, 'limit' => 0, 'reason' => 'no_plan'];
    }
    if (($metric === 'api_keys' || $metric === 'transactions') && (int) $current['api_enabled'] !== 1) {
        return ['ok' => false, 'used' => 0, 'limit' => 0, 'reason' => 'feature_locked'];
    }
    if ($metric === 'webhooks' && (int) $current['webhooks_enabled'] !== 1) {
        return ['ok' => false, 'used' => 0, 'limit' => 0, 'reason' => 'feature_locked'];
    }
    $used  = sub_usage($userId, $metric);
    $limit = sub_limit($userId, $metric);
    if ($limit > 0 && $used >= $limit) {
        return ['ok' => false, 'used' => $used, 'limit' => $limit, 'reason' => 'limit_reached'];
    }
    return ['ok' => true, 'used' => $used, 'limit' => $limit, 'reason' => null];
}

function sub_reason_message(string $reason): string
{
    return match ($reason) {
        'no_plan'        => 'Your free plan has ended. Please subscribe to continue.',
        'feature_locked' => 'This feature is not available on your current plan. Please upgrade.',
        'limit_reached'  => 'You have reached the limit for your current plan. Please upgrade to continue.',
        default          => 'Action not allowed on your current plan.',
    };
}

/* ---------- Auto collection (pluggable providers) ---------- */

/** Supported subscription payment providers. */
function sub_auto_providers(): array
{
    return [
        'own'      => 'Father Gateway (this site)',
        'custom'   => 'Custom API (FatherGateway-compatible)',
        'razorpay' => 'Razorpay Payment Links',
        'cashfree' => 'Cashfree Payment Links',
    ];
}

function sub_auto_enabled(): bool
{
    return (string) sub_setting('sub_auto_enabled', '0') === '1';
}

function sub_auto_provider(): string
{
    $p = (string) sub_setting('sub_auto_provider', 'own');
    return array_key_exists($p, sub_auto_providers()) ? $p : 'own';
}

/** Gateway API base URL as configured (empty = provider default). */
function sub_auto_api_url(): string
{
    return trim((string) sub_setting('sub_auto_api_url', ''));
}

function sub_auto_api_key(): string
{
    return trim((string) sub_setting('sub_auto_api_key', ''));
}

function sub_auto_api_secret(): string
{
    return (string) sub_setting('sub_auto_api_secret', '');
}

function sub_auto_secret(): string
{
    return (string) sub_setting('sub_auto_webhook_secret', '');
}

/** Provider configuration bag handed to the adapters. */
function sub_gateway_conf(): array
{
    return [
        'api_url'    => sub_auto_api_url(),
        'api_key'    => sub_auto_api_key(),
        'api_secret' => sub_auto_api_secret(),
        'secret'     => sub_auto_secret(),
    ];
}

/** URL the gateway should register as its webhook endpoint. */
function sub_gateway_webhook_url(): string
{
    return url('subscription-webhook.php');
}

/** Resolve the collection merchant account from the configured API key, with fallback to platform administrator. */
function sub_gateway_merchant(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $cache = null;
    $key = sub_auto_api_key();
    if ($key !== '') {
        $st = db()->prepare(
            'SELECT u.* FROM api_keys k JOIN users u ON u.id = k.user_id
             WHERE k.public_key = ? AND k.status = "active" LIMIT 1'
        );
        $st->execute([$key]);
        $u = $st->fetch();
        if ($u) {
            $cache = $u;
            return $cache;
        }
    }
    // Fallback: If no custom API key configured, use the platform administrator account
    try {
        $st = db()->query("SELECT * FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id ASC LIMIT 1");
        $admin = $st->fetch();
        if ($admin) {
            $cache = $admin;
            return $cache;
        }
    } catch (\Throwable $e) {
        // best effort
    }
    return null;
}

/** True when auto collection can run for the selected provider. */
function sub_gateway_ready(): bool
{
    $provider = sub_auto_provider();
    if ($provider === 'own') {
        $m = sub_gateway_merchant();
        return $m && trim((string) ($m['upi_id'] ?? '')) !== '';
    }
    if (!sub_auto_enabled()) {
        return false;
    }
    $key = sub_auto_api_key();
    if ($provider === 'razorpay' || $provider === 'cashfree') {
        return $key !== '' && sub_auto_api_secret() !== '';
    }
    if ($provider === 'custom') {
        return sub_auto_api_url() !== '' && $key !== '';
    }
    return false;
}

/** The gateway order behind a subscription payment, if any. */
function sub_gateway_order(?array $payment): ?array
{
    if (!$payment || empty($payment['gateway_order_id'])) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM orders WHERE order_id = ? LIMIT 1');
    $st->execute([(string) $payment['gateway_order_id']]);
    return $st->fetch() ?: null;
}

/**
 * Create a payment at the configured gateway for a subscription payment.
 * Returns ['ok'=>bool, 'error'=>?string].
 */
function sub_gateway_create_order(array $payment, int $userId): array
{
    $provider = sub_auto_provider();

    $subStmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $subStmt->execute([$userId]);
    $sub = $subStmt->fetch() ?: [];

    $ctx = [
        'name'       => (string) ($sub['name'] ?? ''),
        'email'      => (string) ($sub['email'] ?? ''),
        'phone'      => (string) ($sub['phone'] ?? ''),
        'return_url' => url('user/subscription.php?pay=' . urlencode((string) $payment['reference']) . '&ret=1'),
    ];

    $res = $provider === 'own'
        ? sub_gateway_create_order_own($payment, $ctx)
        : sub_gw_create($provider, $payment, $ctx, sub_gateway_conf());

    if (empty($res['ok'])) {
        return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Could not create the payment.')];
    }

    $remoteId    = (string) ($res['remote_id'] ?? '');
    $checkoutUrl = (string) ($res['checkout_url'] ?? '');
    $upi         = (string) ($res['upi_id'] ?? '');

    db()->prepare(
        'UPDATE subscription_payments
            SET gateway_order_id = ?, gateway_provider = ?, gateway_checkout_url = ?,
                upi_id = COALESCE(NULLIF(?, ""), upi_id)
          WHERE id = ?'
    )->execute([
        $remoteId !== '' ? $remoteId : null,
        $provider,
        $checkoutUrl !== '' ? $checkoutUrl : null,
        $upi,
        (int) $payment['id'],
    ]);

    return ['ok' => true, 'error' => null];
}

/**
 * Own-gateway order creation (in-process): collects into the merchant UPI.
 * Returns ['ok'=>bool, 'error'=>?string, 'remote_id'=>?, 'checkout_url'=>?, 'upi_id'=>?].
 */
function sub_gateway_create_order_own(array $payment, array $ctx): array
{
    $merchant = sub_gateway_merchant();
    if (!$merchant) {
        return ['ok' => false, 'error' => 'Gateway API key is invalid or revoked.'];
    }
    $upi = trim((string) ($merchant['upi_id'] ?? ''));
    if ($upi === '') {
        return ['ok' => false, 'error' => 'The collection account has no UPI ID connected.'];
    }
    $amount = (float) $payment['amount'];
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Invalid subscription amount.'];
    }

    $base    = sub_auto_api_url() !== '' ? sub_auto_api_url() : rtrim(BASE_URL, '/') . '/api';
    $orderId = generate_order_id();
    $created = date('Y-m-d H:i:s');
    $expires = date('Y-m-d H:i:s', strtotime('+' . SUB_PAYMENT_TTL_MINUTES . ' minutes'));
    $qrUrl   = $base . '/qr-image.php?order_id=' . urlencode($orderId);

    db()->prepare(
        'INSERT INTO orders
            (user_id, order_id, customer_name, customer_email, amount, payable_amount,
             status, upi_id, qr_url, redirect_url, expires_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, "pending", ?, ?, ?, ?, ?)'
    )->execute([
        (int) $merchant['id'],
        $orderId,
        $ctx['name'] ?: null,
        $ctx['email'] ?: null,
        $amount,
        $amount,
        $upi,
        $qrUrl,
        $ctx['return_url'],
        $expires,
        $created,
    ]);

    return [
        'ok'           => true,
        'error'        => null,
        'remote_id'    => $orderId,
        'checkout_url' => url('pay.php?order_id=' . urlencode($orderId)),
        'upi_id'       => $upi,
    ];
}

/**
 * Pull fresh status for a gateway-backed payment and activate it on success.
 * Returns the (possibly updated) payment status.
 */
function sub_gateway_sync(array $payment): string
{
    if (empty($payment['gateway_order_id'])) {
        return (string) $payment['status'];
    }
    $provider = (string) ($payment['gateway_provider'] ?? '') ?: sub_auto_provider();
    if ($provider === 'own') {
        return sub_gateway_sync_own($payment);
    }

    $res = sub_gw_status($provider, $payment, sub_gateway_conf());
    if (empty($res['ok'])) {
        return (string) $payment['status'];
    }
    $status = (string) ($res['status'] ?? '');

    if ($status === 'success') {
        if ((string) $payment['status'] !== 'success') {
            sub_mark_paid((string) $payment['reference'], 'auto', $res['utr'] ?? null);
        }
        return 'success';
    }
    if ($status === 'expired' && (string) $payment['status'] === 'pending') {
        db()->prepare("DELETE FROM subscription_payments WHERE id = ?")
            ->execute([(int) $payment['id']]);
        return 'expired';
    }
    return (string) $payment['status'];
}

/** Own-gateway status sync: targeted IMAP verify against the internal order. */
function sub_gateway_sync_own(array $payment): string
{
    $order = sub_gateway_order($payment);
    if (!$order) {
        return (string) $payment['status'];
    }

    if ($order['status'] === 'pending') {
        $mid   = (int) $order['user_id'];
        $gmail = (string) setting_get($mid, 'gmail_email', '');
        $pass  = (string) setting_get($mid, 'gmail_app_password', '');
        if ($gmail !== '' && $pass !== '') {
            $now       = time();
            $last      = (int) setting_get($mid, 'imap_last_autocheck', 0);
            $failUntil = (int) setting_get($mid, 'imap_fail_until', 0);
            if ($now >= $failUntil && $now - $last >= 2) {
                setting_set($mid, 'imap_last_autocheck', (string) $now);
                try {
                    mail_process_user($mid, (string) $order['order_id'], null);
                    setting_set($mid, 'imap_fail_until', '0');
                } catch (\Throwable $e) {
                    setting_set($mid, 'imap_fail_until', (string) ($now + 45));
                }
            }
            $order = sub_gateway_order($payment) ?? $order;
        }
    }

    if (($order['status'] ?? '') === 'success') {
        if ((string) $payment['status'] !== 'success') {
            sub_mark_paid((string) $payment['reference'], 'auto', $order['utr'] ?? null);
        }
        return 'success';
    }
    if (($order['status'] ?? '') === 'expired' && (string) $payment['status'] === 'pending') {
        db()->prepare("DELETE FROM subscription_payments WHERE id = ?")
            ->execute([(int) $payment['id']]);
        return 'expired';
    }
    return (string) $payment['status'];
}

/** Hosted checkout URL to send the subscriber to, if the gateway gave us one. */
function sub_payment_checkout_url(?array $payment): string
{
    if (!$payment) {
        return '';
    }
    return trim((string) ($payment['gateway_checkout_url'] ?? ''));
}

/** Sync every pending gateway-backed payment (used by cron). */
function sub_gateway_sweep(int $limit = 30): int
{
    sub_ensure_tables();
    $st = db()->query(
        "SELECT * FROM subscription_payments
         WHERE status = 'pending' AND gateway_order_id IS NOT NULL
         ORDER BY id DESC LIMIT " . max(1, $limit)
    );
    $matched = 0;
    foreach ($st->fetchAll() as $p) {
        if (sub_gateway_sync($p) === 'success') {
            $matched++;
        }
    }
    return $matched;
}

/**
 * Start a subscription payment for a paid plan.
 * Returns ['ok'=>bool, 'error'=>?string, 'payment'=>?array].
 */
function sub_create_payment(int $userId, int $planId): array
{
    sub_ensure_tables();
    $plan = sub_plan($planId);
    if (!$plan || $plan['status'] !== 'active') {
        return ['ok' => false, 'error' => 'This plan is not available.', 'payment' => null];
    }
    if ((float) $plan['price'] <= 0) {
        return ['ok' => false, 'error' => 'This is a free plan — no payment needed.', 'payment' => null];
    }

    if (!sub_gateway_ready()) {
        return ['ok' => false, 'error' => 'Online payment is not configured yet. Please contact support.', 'payment' => null];
    }

    // Delete any prior unpaid or pending subscription attempts for this user
    db()->prepare("DELETE FROM subscription_payments WHERE user_id = ? AND status != 'success'")->execute([$userId]);

    $reference = 'sub_' . strtoupper(bin2hex(random_bytes(5)));
    $expires   = date('Y-m-d H:i:s', strtotime('+' . SUB_PAYMENT_TTL_MINUTES . ' minutes'));

    db()->prepare(
        'INSERT INTO subscription_payments (reference, user_id, plan_id, amount, status, expires_at)
         VALUES (?, ?, ?, ?, "pending", ?)'
    )->execute([$reference, $userId, (int) $plan['id'], (float) $plan['price'], $expires]);

    $payment = sub_payment((int) db()->lastInsertId());
    if (!$payment) {
        return ['ok' => false, 'error' => 'Could not start the payment. Please try again.', 'payment' => null];
    }

    $created = sub_gateway_create_order($payment, $userId);
    if (!$created['ok']) {
        db()->prepare('DELETE FROM subscription_payments WHERE id = ?')->execute([(int) $payment['id']]);
        return ['ok' => false, 'error' => (string) $created['error'], 'payment' => null];
    }
    $payment = sub_payment((int) $payment['id']) ?? $payment;

    return ['ok' => true, 'error' => null, 'payment' => $payment];
}

function sub_payment(int $id): ?array
{
    sub_ensure_tables();
    $st = db()->prepare('SELECT * FROM subscription_payments WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function sub_payment_by_reference(string $reference): ?array
{
    sub_ensure_tables();
    $st = db()->prepare('SELECT * FROM subscription_payments WHERE reference = ? LIMIT 1');
    $st->execute([$reference]);
    return $st->fetch() ?: null;
}

/** Mark a payment paid and activate/renew the merchant's plan. */
function sub_activate_payment(array $payment, string $verifiedBy = 'auto', ?string $utr = null, ?string $sender = null): bool
{
    if ($payment['status'] === 'success' && !empty($payment['activated_at'])) {
        return false;
    }
    db()->prepare(
        "UPDATE subscription_payments
         SET status = 'success', utr = COALESCE(?, utr), sender_name = COALESCE(?, sender_name),
             verified_by = ?, paid_at = COALESCE(paid_at, NOW()), activated_at = NOW()
         WHERE id = ?"
    )->execute([$utr, $sender, $verifiedBy, (int) $payment['id']]);

    sub_grant((int) $payment['user_id'], (int) $payment['plan_id'], (int) $payment['id'], (float) $payment['amount']);
    return true;
}

/** Admin / manual confirmation of a subscription payment by reference. */
function sub_mark_paid(string $reference, string $verifiedBy = 'admin', ?string $utr = null): bool
{
    $payment = sub_payment_by_reference($reference);
    if (!$payment) {
        return false;
    }
    return sub_activate_payment($payment, $verifiedBy, $utr);
}

/** Admin aggregate counters for the subscription dashboard. */
function sub_stats(): array
{
    sub_ensure_tables();
    return [
        'plans'         => (int) db()->query('SELECT COUNT(*) FROM subscription_plans')->fetchColumn(),
        'active_subs'   => (int) db()->query("SELECT COUNT(*) FROM user_subscriptions WHERE status = 'active' AND expires_at > NOW()")->fetchColumn(),
        'pending_pay'   => (int) db()->query("SELECT COUNT(*) FROM subscription_payments WHERE status = 'pending'")->fetchColumn(),
        'revenue'       => (float) db()->query("SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE status = 'success'")->fetchColumn(),
        'mrr'           => (float) db()->query("SELECT COALESCE(SUM(s.amount),0) FROM user_subscriptions s WHERE s.status = 'active' AND s.expires_at > NOW() AND s.amount > 0")->fetchColumn(),
    ];
}
