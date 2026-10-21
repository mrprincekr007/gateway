<?php
declare(strict_types=1);

/* =========================================================
   FatherGateway — Unified OTP & Email Verification Engine
   - Cryptographic 6-digit random codes
   - Atomic database persistence with 10-minute expiry
   - Strict 5-attempt brute-force lock per code
   - 60-second resend cooldown timer
   - Beautiful branded Gmail transactional delivery
   ========================================================= */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require_once APP_ROOT . '/includes/smtp.php';

/**
 * Check if Email OTP is enabled in system settings.
 */
function otp_is_enabled(): bool
{
    try {
        $val = db()->query("SELECT svalue FROM app_settings WHERE skey = 'email_otp_enabled' LIMIT 1")->fetchColumn();
        return $val === false || $val === '1' || $val === null;
    } catch (Throwable $e) {
        return true;
    }
}

/**
 * Generate and dispatch a 6-digit OTP code to a user's Gmail.
 *
 * @param string     $email   User email address
 * @param string     $purpose 'register' or 'forgot_password'
 * @param array|null $payload Data to preserve until verified (e.g., name, password hash)
 * @return array ['ok' => bool, 'error' => string|null, 'code' => string, 'retry_after' => int]
 */
function otp_send(string $email, string $purpose, ?array $payload = null): array
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Please provide a valid email address.'];
    }

    // 1. Check Resend Cooldown
    if (security_is_rate_limiting_enabled()) {
        $cooldown = security_setting('otp_resend_cooldown_seconds', 30);
        $stmt = db()->prepare('SELECT created_at FROM email_otps WHERE email = ? AND purpose = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$email, $purpose]);
        $lastCreated = $stmt->fetchColumn();

        if ($lastCreated) {
            $elapsed = time() - strtotime((string) $lastCreated);
            if ($elapsed < $cooldown) {
                $wait = $cooldown - $elapsed;
                return ['ok' => false, 'error' => "Please wait {$wait} second(s) before requesting another OTP.", 'retry_after' => $wait];
            }
        }
    } else {
        $cooldown = 0; // When rate limiting is turned OFF, instant resend is allowed for developer testing
    }

    // 2. Generate cryptographically random 6-digit code
    $otpCode = (string) random_int(100000, 999999);
    $validityMins = security_setting('otp_validity_minutes', 10);
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$validityMins} minutes"));
    $payloadJson = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;

    // 3. Clear existing pending codes for this email and purpose
    db()->prepare('DELETE FROM email_otps WHERE email = ? AND purpose = ?')->execute([$email, $purpose]);

    // 4. Insert fresh OTP record
    db()->prepare(
        'INSERT INTO email_otps (email, otp_code, purpose, payload, attempts, expires_at, created_at)
         VALUES (?, ?, ?, ?, 0, ?, NOW())'
    )->execute([$email, $otpCode, $purpose, $payloadJson, $expiresAt]);

    // 5. Select template content by purpose
    $siteName = defined('SITE_NAME') ? SITE_NAME : 'Father Gateway';
    if ($purpose === 'register') {
        $subject = "{$otpCode} is your {$siteName} verification code";
        $title   = "Verify Your Email Address";
        $desc    = "Welcome to <b>{$siteName}</b>. To complete your merchant registration and activate your account, please enter this 6-digit code:";
    } elseif ($purpose === 'change_password') {
        $subject = "{$otpCode} is your {$siteName} security code";
        $title   = "Authorize Password Change";
        $desc    = "We received a request to change the security password for your <b>{$siteName}</b> account. Enter this 6-digit code to authorize the change:";
    } else {
        $subject = "{$otpCode} is your {$siteName} password reset code";
        $title   = "Reset Your Account Password";
        $desc    = "We received a request to reset your <b>{$siteName}</b> account password. Enter this 6-digit code to choose a new password:";
    }

    $html = smtp_render_otp_template($otpCode, $title, $desc);

    // 6. Send via SMTP
    $smtpRes = smtp_send($email, $subject, $html);

    if (!$smtpRes['ok']) {
        $isLocal   = (APP_ENV === 'local');
        // Only expose the OTP in the response when explicitly enabled for local development.
        $exposeDev = $isLocal && (string) env('OTP_DEV_EXPOSE', '0') === '1';

        if ($isLocal) {
            // Local-only diagnostic log; never written in production.
            $logDir = APP_ROOT . '/storage/logs';
            if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
            @file_put_contents($logDir . '/otp.log', "[" . date('Y-m-d H:i:s') . "] [LOCAL OTP] TO: {$email} | CODE: {$otpCode} | PURPOSE: {$purpose}\n", FILE_APPEND);
        }

        if ($exposeDev) {
            return [
                'ok'          => true,
                'dev_notice'  => "Local fallback active (OTP: {$otpCode})",
                'code'        => $otpCode,
                'retry_after' => $cooldown,
            ];
        }

        return [
            'ok'    => false,
            'error' => 'Failed to deliver OTP to your Gmail. Please verify your address or try again shortly.',
        ];
    }

    return [
        'ok'          => true,
        'code'        => $otpCode,
        'retry_after' => $cooldown,
    ];
}

/**
 * Verify a submitted 6-digit OTP code.
 *
 * @param string $email   User email address
 * @param string $purpose 'register' or 'forgot_password'
 * @param string $code    User entered OTP code
 * @return array ['ok' => bool, 'error' => string|null, 'payload' => array|null]
 */
function otp_verify(string $email, string $purpose, string $code): array
{
    $email = strtolower(trim($email));
    $code  = trim($code);

    if (strlen($code) !== 6 || !ctype_digit($code)) {
        return ['ok' => false, 'error' => 'Please enter a valid 6-digit numeric OTP code.'];
    }

    $stmt = db()->prepare(
        'SELECT * FROM email_otps
         WHERE email = ? AND purpose = ?
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$email, $purpose]);
    $otp = $stmt->fetch();

    if (!$otp) {
        return ['ok' => false, 'error' => 'No active verification code found for this email. Please request a new code.'];
    }

    // Check expiry
    if (strtotime((string) $otp['expires_at']) <= time()) {
        db()->prepare('DELETE FROM email_otps WHERE id = ?')->execute([$otp['id']]);
        return ['ok' => false, 'error' => 'This verification code has expired. Please request a new code.'];
    }

    // Check brute-force attempts
    if (security_is_rate_limiting_enabled()) {
        $maxAttempts = security_setting('otp_max_wrong_attempts', 5);
        if ((int) $otp['attempts'] >= $maxAttempts) {
            db()->prepare('DELETE FROM email_otps WHERE id = ?')->execute([$otp['id']]);
            return ['ok' => false, 'error' => 'Too many incorrect attempts. For security, this OTP has been cancelled. Please request a new one.'];
        }
    } else {
        $maxAttempts = 999;
    }

    // Compare code
    if (!hash_equals((string) $otp['otp_code'], $code)) {
        db()->prepare('UPDATE email_otps SET attempts = attempts + 1 WHERE id = ?')->execute([$otp['id']]);
        $remaining = max(0, $maxAttempts - (int) $otp['attempts'] - 1);
        return ['ok' => false, 'error' => "Incorrect verification code. {$remaining} attempt(s) remaining."];
    }

    // Success! Parse preserved payload
    $payload = !empty($otp['payload']) ? json_decode((string) $otp['payload'], true) : null;

    // Delete used OTP
    db()->prepare('DELETE FROM email_otps WHERE id = ?')->execute([$otp['id']]);

    return [
        'ok'      => true,
        'payload' => is_array($payload) ? $payload : [],
    ];
}

/**
 * Resend OTP using existing pending payload.
 */
function otp_resend(string $email, string $purpose): array
{
    $email = strtolower(trim($email));
    $stmt = db()->prepare('SELECT payload FROM email_otps WHERE email = ? AND purpose = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$email, $purpose]);
    $rawPayload = $stmt->fetchColumn();
    $payload = $rawPayload ? json_decode((string) $rawPayload, true) : null;

    return otp_send($email, $purpose, is_array($payload) ? $payload : null);
}
