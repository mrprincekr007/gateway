<?php
declare(strict_types=1);

/* =========================================================
   FatherGateway — Admin Controller & Helper Library
   Complete Platform Control & Multi-Merchant Governance
   ========================================================= */

require_once APP_ROOT . '/includes/mail.php';

/** Count of users holding the admin role. */
function admin_admins_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM users WHERE role = "admin"')->fetchColumn();
}

/** Resolve a target user row (or null). */
function admin_find_user(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Resolve order row with merchant details. */
function admin_find_order(string $orderId): ?array
{
    $stmt = db()->prepare('
        SELECT o.*, u.name AS merchant_name, u.email AS merchant_email, u.merchant_id AS merchant_code
        FROM orders o JOIN users u ON u.id = o.user_id
        WHERE o.order_id = ? LIMIT 1
    ');
    $stmt->execute([$orderId]);
    return $stmt->fetch() ?: null;
}

/** Guard sensitive changes so an admin cannot lock themselves out. */
function admin_guard_change(array $target, int $actorId): ?string
{
    if ((int) $target['id'] === $actorId) {
        return 'You cannot change your own core permissions or status from here.';
    }
    return null;
}

/** Activate / suspend a user. Returns an error string or null. */
function admin_set_status(int $id, string $status, int $actorId): ?string
{
    if (!in_array($status, ['active', 'suspended'], true)) {
        return 'Unknown status.';
    }
    $target = admin_find_user($id);
    if (!$target) {
        return 'User not found.';
    }
    if ($err = admin_guard_change($target, $actorId)) {
        return $err;
    }
    if ($status === 'suspended' && $target['role'] === 'admin' && admin_admins_count() <= 1) {
        return 'Cannot suspend the last remaining admin.';
    }
    db()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, $id]);
    return null;
}

/** Change a user's role. Returns an error string or null. */
function admin_set_role(int $id, string $role, int $actorId): ?string
{
    if (!in_array($role, ['user', 'admin'], true)) {
        return 'Unknown role.';
    }
    $target = admin_find_user($id);
    if (!$target) {
        return 'User not found.';
    }
    if ($err = admin_guard_change($target, $actorId)) {
        return $err;
    }
    if ($role === 'user' && $target['role'] === 'admin' && admin_admins_count() <= 1) {
        return 'Cannot remove the last remaining admin.';
    }
    db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
    return null;
}

/** Delete a user and all owned rows (cascades). Returns an error string or null. */
function admin_delete_user(int $id, int $actorId): ?string
{
    $target = admin_find_user($id);
    if (!$target) {
        return 'User not found.';
    }
    if ($err = admin_guard_change($target, $actorId)) {
        return $err;
    }
    if ($target['role'] === 'admin' && admin_admins_count() <= 1) {
        return 'Cannot delete the last remaining admin.';
    }
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    return null;
}

/** Register a fresh merchant/user from admin console. */
function admin_create_user(string $name, string $email, string $password, ?string $upiId = null, string $role = 'user'): array|string
{
    $name  = trim($name);
    $email = strtolower(trim($email));
    $upiId = trim((string) $upiId);

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid name and email address.';
    }
    if (strlen($password) < 6) {
        return 'Password must be at least 6 characters.';
    }

    $exists = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $exists->execute([$email]);
    if ($exists->fetch()) {
        return 'An account with this email address already exists.';
    }

    $merchantId = generate_merchant_id();
    $hash       = password_hash($password, PASSWORD_DEFAULT);

    $stmt = db()->prepare('
        INSERT INTO users (name, email, password_hash, merchant_id, upi_id, role, status)
        VALUES (?, ?, ?, ?, ?, ?, "active")
    ');
    $stmt->execute([
        $name,
        $email,
        $hash,
        $merchantId,
        $upiId !== '' ? $upiId : null,
        in_array($role, ['user', 'admin'], true) ? $role : 'user'
    ]);

    $newId = (int) db()->lastInsertId();
    return admin_find_user($newId) ?: 'Failed to create user.';
}

/** Update merchant profile fields directly. */
function admin_update_profile(int $id, string $name, string $email, ?string $upiId, string $role, string $status, int $actorId): ?string
{
    $target = admin_find_user($id);
    if (!$target) {
        return 'User not found.';
    }

    $name  = trim($name);
    $email = strtolower(trim($email));
    $upiId = trim((string) $upiId);

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid name and email address.';
    }

    // Check duplicate email
    $dup = db()->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
    $dup->execute([$email, $id]);
    if ($dup->fetch()) {
        return 'That email address is already in use by another merchant.';
    }

    // Protection check for self
    if ((int) $target['id'] === $actorId) {
        // Can update name, upi, but not role/status
        $role   = $target['role'];
        $status = $target['status'];
    }

    db()->prepare('
        UPDATE users
        SET name = ?, email = ?, upi_id = ?, role = ?, status = ?
        WHERE id = ?
    ')->execute([
        $name,
        $email,
        $upiId !== '' ? $upiId : null,
        $role,
        $status,
        $id
    ]);

    return null;
}

/** Direct password reset for a merchant. */
function admin_reset_password(int $id, string $password): ?string
{
    if (strlen($password) < 6) {
        return 'Password must be at least 6 characters.';
    }
    $target = admin_find_user($id);
    if (!$target) {
        return 'User not found.';
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
    return null;
}

/** Manual UTR Reconciliation from Admin Panel. */
function admin_reconcile_order(string $orderId, string $utr, ?string $senderName, int $actorId): ?string
{
    $order = admin_find_order($orderId);
    if (!$order) {
        return 'Order not found.';
    }
    $utr = trim($utr);
    if ($utr === '') {
        return 'UTR number is required for manual settlement.';
    }

    $senderName = trim((string) $senderName) ?: 'Manual Verified by Admin';
    $now = date('Y-m-d H:i:s');

    db()->prepare('
        UPDATE orders
        SET status = "success", utr = ?, sender_name = ?, paid_at = ?
        WHERE order_id = ?
    ')->execute([$utr, $senderName, $now, $orderId]);

    // Refresh and fire webhooks
    $updated = admin_find_order($orderId);
    if ($updated) {
        fire_paid_webhook((int) $updated['user_id'], $updated);
    }

    return null;
}

/** Resend Webhook for an order. */
function admin_resend_webhook(string $orderId): ?string
{
    $order = admin_find_order($orderId);
    if (!$order) {
        return 'Order not found.';
    }
    fire_paid_webhook((int) $order['user_id'], $order);
    return null;
}

/** Create an API key on behalf of a merchant. */
function admin_create_api_key(int $userId, string $label): array|string
{
    $target = admin_find_user($userId);
    if (!$target) {
        return 'Merchant not found.';
    }
    $label = trim($label) ?: 'Admin Generated Key';
    [$public, $secret] = generate_api_keypair();
    $secretHash = password_hash($secret, PASSWORD_DEFAULT);

    db()->prepare('
        INSERT INTO api_keys (user_id, label, public_key, secret_hash, status)
        VALUES (?, ?, ?, ?, "active")
    ')->execute([$userId, $label, $public, $secretHash]);

    return [
        'id'           => (int) db()->lastInsertId(),
        'label'        => $label,
        'public_key'   => $public,
        'secret_plain' => $secret,
    ];
}

/** Revoke an API key. */
function admin_revoke_api_key(int $keyId): ?string
{
    db()->prepare('UPDATE api_keys SET status = "revoked" WHERE id = ?')->execute([$keyId]);
    return null;
}

/** Create a payment link on behalf of a merchant. */
function admin_create_payment_link(int $userId, string $title, float $amount, ?string $desc = null): array|string
{
    $target = admin_find_user($userId);
    if (!$target) {
        return 'Merchant not found.';
    }
    if ($amount <= 0) {
        return 'Amount must be greater than zero.';
    }
    $linkId = generate_link_id();
    $title  = trim($title) ?: 'Payment';

    db()->prepare('
        INSERT INTO payment_links (user_id, link_id, title, amount, description, status)
        VALUES (?, ?, ?, ?, ?, "active")
    ')->execute([$userId, $linkId, $title, $amount, $desc]);

    $id = (int) db()->lastInsertId();
    $stmt = db()->prepare('SELECT * FROM payment_links WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: 'Failed to create payment link.';
}

/** Toggle payment link status (active/disabled). */
function admin_toggle_payment_link(int $linkId, string $status): ?string
{
    if (!in_array($status, ['active', 'disabled'], true)) {
        return 'Invalid status.';
    }
    db()->prepare('UPDATE payment_links SET status = ? WHERE id = ?')->execute([$status, $linkId]);
    return null;
}

/** Delete a payment link. */
function admin_delete_payment_link(int $linkId): ?string
{
    db()->prepare('DELETE FROM payment_links WHERE id = ?')->execute([$linkId]);
    return null;
}
