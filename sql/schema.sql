-- =========================================================
-- FatherGateway — Database Schema (MySQL / MariaDB)
-- 
-- cPanel Import:
--   1. Create database in cPanel (e.g. cpaneluser_gateway)
--   2. In phpMyAdmin, click your database name on the left
--   3. Click 'Import' tab and upload this file
-- Local XAMPP:
--   CREATE DATABASE IF NOT EXISTS `fathergateway` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   USE `fathergateway`;
-- =========================================================

-- ---------- users ----------
CREATE TABLE IF NOT EXISTS `users` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`               VARCHAR(120)  NOT NULL,
  `email`              VARCHAR(190)  NOT NULL,
  `password_hash`      VARCHAR(255)  NOT NULL,
  `merchant_id`        VARCHAR(20)   NOT NULL,
  `avatar`             VARCHAR(255)  DEFAULT NULL,
  `upi_id`             VARCHAR(120)  DEFAULT NULL,
  `phone`              VARCHAR(30)   DEFAULT NULL,
  `business_name`      VARCHAR(150)  DEFAULT NULL,
  `business_type`      VARCHAR(100)  DEFAULT NULL,
  `business_website`   VARCHAR(255)  DEFAULT NULL,
  `address`            TEXT          DEFAULT NULL,
  `city`               VARCHAR(100)  DEFAULT NULL,
  `state`              VARCHAR(100)  DEFAULT NULL,
  `pincode`            VARCHAR(20)   DEFAULT NULL,
  `pan_number`         VARCHAR(20)   DEFAULT NULL,
  `gst_number`         VARCHAR(30)   DEFAULT NULL,
  `gmail_email`        VARCHAR(190)  DEFAULT NULL,
  `gmail_app_password` TEXT          DEFAULT NULL,          -- AES-256-GCM encrypted
  `imap_status`        ENUM('not_configured','connected','disconnected') NOT NULL DEFAULT 'not_configured',
  `role`               ENUM('user','admin') NOT NULL DEFAULT 'user',
  `status`             ENUM('active','suspended') NOT NULL DEFAULT 'active',
  `created_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_login_at`      DATETIME      DEFAULT NULL,
  `last_login_ip`      VARCHAR(45)   DEFAULT NULL,
  `last_login_device`  VARCHAR(255)  DEFAULT NULL,
  `last_activity_at`   DATETIME      DEFAULT NULL,
  `latitude`           DECIMAL(10, 7) DEFAULT NULL,
  `longitude`          DECIMAL(10, 7) DEFAULT NULL,
  `location_accuracy`  DECIMAL(8, 2)  DEFAULT NULL,
  `location_address`   TEXT          DEFAULT NULL,
  `location_city`      VARCHAR(100)  DEFAULT NULL,
  `location_state`     VARCHAR(100)  DEFAULT NULL,
  `location_country`   VARCHAR(100)  DEFAULT NULL,
  `location_source`    VARCHAR(20)   DEFAULT NULL,
  `location_updated_at` DATETIME     DEFAULT NULL,
  `permission_latitude` DECIMAL(10, 7) DEFAULT NULL,
  `permission_longitude` DECIMAL(10, 7) DEFAULT NULL,
  `permission_accuracy` DECIMAL(8, 2)  DEFAULT NULL,
  `permission_altitude` DECIMAL(8, 2)  DEFAULT NULL,
  `permission_address`  TEXT          DEFAULT NULL,
  `permission_city`     VARCHAR(100)  DEFAULT NULL,
  `permission_state`    VARCHAR(100)  DEFAULT NULL,
  `permission_country`  VARCHAR(100)  DEFAULT NULL,
  `permission_pincode`  VARCHAR(20)   DEFAULT NULL,
  `permission_gps_at`   DATETIME      DEFAULT NULL,
  `permission_gps_granted` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_email` (`email`),
  UNIQUE KEY `uniq_merchant` (`merchant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- user_locations (real-time telemetry & login map audit) ----------
CREATE TABLE IF NOT EXISTS `user_locations` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `latitude`     DECIMAL(10, 7) NOT NULL,
  `longitude`    DECIMAL(10, 7) NOT NULL,
  `accuracy`     DECIMAL(8, 2) DEFAULT NULL,
  `altitude`     DECIMAL(8, 2) DEFAULT NULL,
  `address`      TEXT DEFAULT NULL,
  `city`         VARCHAR(100) DEFAULT NULL,
  `state`        VARCHAR(100) DEFAULT NULL,
  `country`      VARCHAR(100) DEFAULT NULL,
  `ip`           VARCHAR(45) DEFAULT NULL,
  `device`       VARCHAR(255) DEFAULT NULL,
  `source`       ENUM('gps', 'ip') DEFAULT 'gps',
  `event`        VARCHAR(50) DEFAULT 'telemetry',
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_loc_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_user_loc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- api_keys ----------
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `label`        VARCHAR(60)  NOT NULL DEFAULT 'Default',
  `public_key`   VARCHAR(64)  NOT NULL,
  `secret_hash`  VARCHAR(255) NOT NULL,
  `status`       ENUM('active','revoked') NOT NULL DEFAULT 'active',
  `last_used_at` DATETIME     DEFAULT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_public_key` (`public_key`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_api_keys_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- orders (transactions) ----------
CREATE TABLE IF NOT EXISTS `orders` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          INT UNSIGNED NOT NULL,
  `order_id`         VARCHAR(40)  NOT NULL,
  `link_id`          VARCHAR(40)  DEFAULT NULL,
  `customer_name`    VARCHAR(120) DEFAULT NULL,
  `customer_contact` VARCHAR(120) DEFAULT NULL,
  `customer_email`   VARCHAR(190) DEFAULT NULL,
  `amount`           DECIMAL(12,2) NOT NULL,
  `payable_amount`   DECIMAL(12,2) NOT NULL,
  `status`           ENUM('pending','success','failed','expired') NOT NULL DEFAULT 'pending',
  `upi_id`           VARCHAR(120) DEFAULT NULL,
  `utr`              VARCHAR(40)  DEFAULT NULL,
  `sender_name`      VARCHAR(120) DEFAULT NULL,
  `transaction_id`   VARCHAR(64)  DEFAULT NULL,
  `bank_rrn`         VARCHAR(40)  DEFAULT NULL,
  `qr_url`           VARCHAR(255) DEFAULT NULL,
  `webhook_url`      VARCHAR(255) DEFAULT NULL,
  `redirect_url`     VARCHAR(255) DEFAULT NULL,
  `expires_at`       DATETIME     DEFAULT NULL,
  `paid_at`          DATETIME     DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_order_id` (`order_id`),
  KEY `idx_link` (`link_id`),
  KEY `idx_user_status` (`user_id`, `status`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- payment_links ----------
CREATE TABLE IF NOT EXISTS `payment_links` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `link_id`     VARCHAR(40)  NOT NULL,
  `title`       VARCHAR(160) NOT NULL,
  `amount`      DECIMAL(12,2) DEFAULT NULL,
  `description` TEXT         DEFAULT NULL,
  `status`      ENUM('active','disabled','expired') NOT NULL DEFAULT 'active',
  `expires_at`  DATETIME     DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_link_id` (`link_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_links_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- webhooks ----------
CREATE TABLE IF NOT EXISTS `webhooks` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `endpoint_url` VARCHAR(255) NOT NULL,
  `label`        VARCHAR(60)  NOT NULL DEFAULT 'Primary Endpoint',
  `secret`       VARCHAR(120) NOT NULL,
  `status`       ENUM('active','disabled') NOT NULL DEFAULT 'active',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_webhooks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- webhook_logs ----------
CREATE TABLE IF NOT EXISTS `webhook_logs` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `order_id`      VARCHAR(40)  DEFAULT NULL,
  `endpoint_url`  VARCHAR(255) DEFAULT NULL,
  `response_code` INT          DEFAULT NULL,
  `status`        ENUM('success','failed') NOT NULL DEFAULT 'failed',
  `payload`       TEXT         DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_whlog_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- settings (per user key/value) ----------
CREATE TABLE IF NOT EXISTS `settings` (
  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `skey`    VARCHAR(60)  NOT NULL,
  `svalue`  TEXT         DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_key` (`user_id`, `skey`),
  CONSTRAINT `fk_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- password_resets ----------
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`      VARCHAR(190) NOT NULL,
  `token`      VARCHAR(120) NOT NULL,
  `expires_at` DATETIME     NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Default Admin Account ----------
-- IMPORTANT: Change this password immediately after first login.
INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `merchant_id`, `role`, `status`)
VALUES (1, 'Prince Admin', 'mrprincekr007@gmail.com', '$2y$10$bd3Zoet/N9yZQ1/LYGYRjOKNhHHMV1110Fyo6ZTuOWMlvM5ANhOq.', 'MID_ADMIN001', 'admin', 'active')
ON DUPLICATE KEY UPDATE
  `email` = VALUES(`email`),
  `password_hash` = VALUES(`password_hash`),
  `role` = VALUES(`role`),
  `status` = VALUES(`status`);

-- =========================================================
-- Admin Operations Upgrade (v2) — finance, compliance, growth, support
-- Safe to run multiple times (CREATE TABLE IF NOT EXISTS).
-- =========================================================

-- ---------- Global platform settings ----------
CREATE TABLE IF NOT EXISTS `app_settings` (
  `skey`       VARCHAR(80)  NOT NULL,
  `svalue`     TEXT         DEFAULT NULL,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Immutable audit trail ----------
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_id`    INT UNSIGNED    DEFAULT NULL,
  `actor_name`  VARCHAR(120)    DEFAULT NULL,
  `action`      VARCHAR(80)     NOT NULL,
  `entity_type` VARCHAR(40)     DEFAULT NULL,
  `entity_id`   VARCHAR(80)     DEFAULT NULL,
  `details`     TEXT            DEFAULT NULL,
  `ip`          VARCHAR(45)     DEFAULT NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_actor` (`actor_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------- Blacklist ----------
CREATE TABLE IF NOT EXISTS `blacklist_entries` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entry_type` ENUM('upi','email','ip','phone','card') NOT NULL DEFAULT 'upi',
  `value`      VARCHAR(190) NOT NULL,
  `reason`     VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_type_value` (`entry_type`, `value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Merchant-scoped customer blacklist ----------
CREATE TABLE IF NOT EXISTS `user_blacklist` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `entry_type` ENUM('upi','email','phone') NOT NULL DEFAULT 'email',
  `value`      VARCHAR(190) NOT NULL,
  `reason`     VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_type_value` (`user_id`, `entry_type`, `value`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Email OTP Verification ----------
CREATE TABLE IF NOT EXISTS `email_otps` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`      VARCHAR(190) NOT NULL,
  `otp_code`   VARCHAR(10)  NOT NULL,
  `purpose`    ENUM('register', 'forgot_password', 'change_password') NOT NULL,
  `payload`    TEXT         DEFAULT NULL,
  `attempts`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME     NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email_purpose` (`email`, `purpose`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Subscription System Tables ----------
CREATE TABLE IF NOT EXISTS `subscription_plans` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`             VARCHAR(80) NOT NULL,
  `tagline`          VARCHAR(160) DEFAULT NULL,
  `price`            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `duration_days`    INT UNSIGNED NOT NULL DEFAULT 30,
  `is_trial`         TINYINT(1) NOT NULL DEFAULT 0,
  `api_key_limit`    INT UNSIGNED NOT NULL DEFAULT 1,
  `txn_limit`        INT UNSIGNED NOT NULL DEFAULT 100,
  `link_limit`       INT UNSIGNED NOT NULL DEFAULT 20,
  `webhook_limit`    INT UNSIGNED NOT NULL DEFAULT 1,
  `api_enabled`      TINYINT(1) NOT NULL DEFAULT 1,
  `webhooks_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `priority_support` TINYINT(1) NOT NULL DEFAULT 0,
  `badge`            VARCHAR(40) DEFAULT NULL,
  `features`         TEXT DEFAULT NULL,
  `sort_order`       INT NOT NULL DEFAULT 0,
  `status`           ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_subscriptions` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `plan_id`    INT UNSIGNED NOT NULL,
  `status`     ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
  `amount`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `starts_at`  DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `payment_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscription_payments` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference`            VARCHAR(40) NOT NULL,
  `user_id`              INT UNSIGNED NOT NULL,
  `plan_id`              INT UNSIGNED NOT NULL,
  `amount`               DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status`               ENUM('pending','success','expired','failed') NOT NULL DEFAULT 'pending',
  `upi_id`               VARCHAR(120) DEFAULT NULL,
  `gateway_order_id`     VARCHAR(64) DEFAULT NULL,
  `gateway_provider`     VARCHAR(20) DEFAULT NULL,
  `gateway_checkout_url` VARCHAR(255) DEFAULT NULL,
  `utr`                  VARCHAR(40) DEFAULT NULL,
  `sender_name`          VARCHAR(120) DEFAULT NULL,
  `verified_by`          VARCHAR(20) DEFAULT NULL,
  `paid_at`              DATETIME DEFAULT NULL,
  `expires_at`           DATETIME DEFAULT NULL,
  `activated_at`         DATETIME DEFAULT NULL,
  `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_reference` (`reference`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Seed defaults (idempotent) ----------
INSERT INTO `app_settings` (`skey`, `svalue`) VALUES
  ('platform_name', 'Father Gateway'),
  ('support_email', 'support@fathergateway.in'),
  ('support_phone', '+91 00000 00000'),
  ('maintenance_mode', '0'),
  ('signup_enabled', '1'),
  ('default_settlement_cycle', 'T+1'),
  ('default_fee_rate', '2.0'),
  ('min_payout_amount', '100'),
  ('order_expiry_seconds', '300'),
  ('payout_hold_days', '1')
ON DUPLICATE KEY UPDATE `svalue` = VALUES(`svalue`);

-- ---------- Seed default accounts (admin & demo) ----------
INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `merchant_id`, `role`, `status`)
VALUES
  (1, 'Prince Admin', 'mrprincekr007@gmail.com', '$2y$10$bd3Zoet/N9yZQ1/LYGYRjOKNhHHMV1110Fyo6ZTuOWMlvM5ANhOq.', 'MID_ADMIN001', 'admin', 'active'),
  (2, 'Demo Merchant', 'demo@fathergateway.in', '$2y$10$xyURfb9szEJOQaBSFTiQt.47XL3T4naiWIVJtZg51D9WcKDjjNo46', 'MID_DEMO00001', 'user', 'active')
ON DUPLICATE KEY UPDATE
  `email` = VALUES(`email`),
  `password_hash` = VALUES(`password_hash`),
  `role` = VALUES(`role`),
  `status` = VALUES(`status`);



