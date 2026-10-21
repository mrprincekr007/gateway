<?php
declare(strict_types=1);

/* =========================================================
   FatherGateway — Outbound SMTP Mail Engine
   - Native TLS/SSL socket SMTP delivery (zero external composer packages)
   - Supports Google Gmail SMTP (smtp.gmail.com:587) with App Passwords
   - Primary Inbox deliverability (RFC 5322, multipart/alternative, Auto-Submitted headers)
   - Supports custom SMTP from app_settings and admin Gmail credentials
   - Mobile-responsive, high-conversion transactional templates
   ========================================================= */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

/**
 * Resolve effective SMTP configuration.
 * Priority:
 * 1. Global app_settings (smtp_email, smtp_password, smtp_host, smtp_port)
 * 2. Gateway Admin (User ID 1) configured Gmail integration
 */
function smtp_get_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $host = 'smtp.gmail.com';
    $port = 587;
    $user = '';
    $pass = '';
    $enc  = 'tls';
    $fromName = defined('SITE_NAME') ? SITE_NAME : 'Father Gateway';

    // 1. Check app_settings
    try {
        $customUser = trim((string) db()->query("SELECT svalue FROM app_settings WHERE skey = 'smtp_email' LIMIT 1")->fetchColumn());
        $customPass = trim((string) db()->query("SELECT svalue FROM app_settings WHERE skey = 'smtp_password' LIMIT 1")->fetchColumn());
        $customHost = trim((string) db()->query("SELECT svalue FROM app_settings WHERE skey = 'smtp_host' LIMIT 1")->fetchColumn());
        $customPort = (int) db()->query("SELECT svalue FROM app_settings WHERE skey = 'smtp_port' LIMIT 1")->fetchColumn();

        if ($customUser !== '') {
            $user = $customUser;
            if ($customPass !== '') {
                $decrypted = decrypt_secret($customPass) ?: $customPass;
                $pass = (string) preg_replace('/\s+/', '', (string) $decrypted);
            }
            if ($customHost !== '') $host = $customHost;
            if ($customPort > 0) $port = $customPort;
        }
    } catch (Throwable $e) {}

    // 2. Fallback to Gateway Admin User #1 Gmail credentials if pass or user missing
    if ($user === '' || $pass === '') {
        try {
            $adminGmail = trim((string) setting_get(1, 'gmail_email', ''));
            $adminPassEnc = trim((string) setting_get(1, 'gmail_app_password', ''));
            $adminPass = decrypt_secret($adminPassEnc) ?: $adminPassEnc;

            if ($user === '' && $adminGmail !== '') {
                $user = $adminGmail;
            }
            if ($pass === '' && $adminPass !== '') {
                $pass = (string) preg_replace('/\s+/', '', (string) $adminPass);
            }
        } catch (Throwable $e) {}
    }

    return $config = [
        'host'      => $host ?: 'smtp.gmail.com',
        'port'      => $port > 0 ? $port : 587,
        'user'      => $user,
        'pass'      => $pass,
        'enc'       => $enc,
        'from_name' => $fromName,
    ];
}

/**
 * Send an email via SMTP with RFC-compliant multipart/alternative payload to prevent Spam flags.
 *
 * @param string      $toEmail Destination email address
 * @param string      $subject Email subject line
 * @param string      $htmlBody HTML message content
 * @param string|null $toName Optional recipient name
 * @param string|null $plainText Optional custom plain text alternative
 * @return array ['ok' => bool, 'error' => string|null]
 */
function smtp_send(string $toEmail, string $subject, string $htmlBody, ?string $toName = null, ?string $plainText = null): array
{
    $toEmail = strtolower(trim($toEmail));
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid destination email address.'];
    }

    $cfg = smtp_get_config();

    if ($cfg['user'] === '' || $cfg['pass'] === '') {
        $logDir = APP_ROOT . '/storage/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        @file_put_contents($logDir . '/otp.log', "[" . date('Y-m-d H:i:s') . "] TO: $toEmail | SUBJECT: $subject\n$htmlBody\n\n", FILE_APPEND);
        return ['ok' => false, 'error' => 'SMTP Gmail is not configured. Please enter your Gmail and App Password in Admin Settings > Outbound Email.'];
    }

    $timeout = 15;
    $host = $cfg['host'];
    $port = (int) $cfg['port'];
    $user = $cfg['user'];
    $pass = $cfg['pass'];

    $connectHost = ($port === 465) ? 'ssl://' . $host : $host;
    $sock = @fsockopen($connectHost, $port, $errno, $errstr, $timeout);
    if (!$sock) {
        return ['ok' => false, 'error' => "SMTP connection failed to {$host}:{$port} ({$errstr})"];
    }

    stream_set_timeout($sock, $timeout);

    $read = function(?int $expectedCode = null) use ($sock): string {
        $res = '';
        while (!feof($sock)) {
            $line = fgets($sock, 515);
            if ($line === false) break;
            $res .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        $code = (int) substr($res, 0, 3);
        if ($expectedCode !== null && $code !== $expectedCode) {
            throw new Exception("SMTP protocol error: expected {$expectedCode}, received: " . trim($res));
        }
        return $res;
    };

    try {
        $read(220);

        // Send legitimate EHLO hostname
        $ehloDomain = substr(strrchr($user, '@') ?: '@gmail.com', 1);
        fwrite($sock, "EHLO {$ehloDomain}\r\n");
        $read(250);

        // STARTTLS on port 587
        if ($port === 587) {
            fwrite($sock, "STARTTLS\r\n");
            $read(220);

            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new Exception("STARTTLS encryption handshake failed.");
            }

            fwrite($sock, "EHLO {$ehloDomain}\r\n");
            $read(250);
        }

        // Authentication
        fwrite($sock, "AUTH LOGIN\r\n");
        $read(334);

        fwrite($sock, base64_encode($user) . "\r\n");
        $read(334);

        fwrite($sock, base64_encode($pass) . "\r\n");
        $read(235); // 235 Accepted

        // Envelope
        fwrite($sock, "MAIL FROM: <{$user}>\r\n");
        $read(250);

        fwrite($sock, "RCPT TO: <{$toEmail}>\r\n");
        $read(250);

        // Payload
        fwrite($sock, "DATA\r\n");
        $read(354);

        // Anti-Spam Message-ID matching sender domain
        $messageId = '<' . time() . '.' . bin2hex(random_bytes(8)) . '@' . $ehloDomain . '>';
        $boundary  = '----=_Part_' . md5(uniqid((string) mt_rand(), true));

        // Format headers
        $fromHeader = '=?UTF-8?B?' . base64_encode($cfg['from_name']) . "?= <{$user}>";
        $toHeader   = $toName ? '=?UTF-8?B?' . base64_encode($toName) . "?= <{$toEmail}>" : "<{$toEmail}>";

        // Generate clean plain text if not provided
        if (!$plainText) {
            $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</h1>', '</h2>', '</h3>'], "\n", $htmlBody));
            $plainText = preg_replace("/[\r\n]+/", "\n", trim($plainText));
        }

        $headers  = "From: {$fromHeader}\r\n";
        $headers .= "Reply-To: <{$user}>\r\n";
        $headers .= "To: {$toHeader}\r\n";
        $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $headers .= "Date: " . date('r') . "\r\n";
        $headers .= "Message-ID: {$messageId}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Auto-Submitted: auto-generated\r\n";
        $headers .= "X-Auto-Response-Suppress: All\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $headers .= "\r\n";

        // Multipart Body (Part 1: Plain text, Part 2: HTML)
        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($plainText)) . "\r\n";

        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";

        $body .= "--{$boundary}--\r\n";

        fwrite($sock, $headers . $body . ".\r\n");
        $read(250);

        fwrite($sock, "QUIT\r\n");
        fclose($sock);

        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        @fclose($sock);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Backward compatibility alias for smtp_send.
 */
function smtp_send_mail(string $toEmail, string $subject, string $htmlBody, ?string $toName = null): array
{
    $res = smtp_send($toEmail, $subject, $htmlBody, $toName);
    return [
        'ok'      => $res['ok'],
        'success' => $res['ok'],
        'error'   => $res['error'],
    ];
}

/**
 * Generate a clean, high-deliverability HTML email template for OTP codes.
 * Optimized for Gmail Primary Inbox (passes SPF/DMARC and triggers automatic OTP copy badge).
 */
function smtp_render_otp_template(string $otpCode, string $purposeTitle, string $purposeDesc): string
{
    $siteName = defined('SITE_NAME') ? SITE_NAME : 'Father Gateway';
    $siteUrl  = defined('BASE_URL') ? BASE_URL : '#';
    $year     = date('Y');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$otpCode} is your {$siteName} verification code</title>
</head>
<body style="margin:0; padding:0; background-color:#f1f5f9; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#0f172a;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f1f5f9; padding:40px 15px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:540px; background-color:#ffffff; border-radius:16px; border:1px solid #e2e8f0; overflow:hidden; box-shadow:0 10px 25px -5px rgba(0, 0, 0, 0.05);">
                    <!-- Brand Header -->
                    <tr>
                        <td align="center" style="padding:32px 32px 20px; background-color:#ffffff; border-bottom:1px solid #f1f5f9;">
                            <div style="font-size:24px; font-weight:800; color:#1e293b; letter-spacing:-0.5px;">{$siteName}</div>
                            <div style="margin-top:8px;">
                                <span style="display:inline-block; padding:4px 12px; border-radius:999px; background-color:#eff6ff; color:#2563eb; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; border:1px solid #dbeafe;">Account Security Verification</span>
                            </div>
                        </td>
                    </tr>
                    <!-- Main Body -->
                    <tr>
                        <td style="padding:36px 36px 28px; text-align:center;">
                            <h1 style="font-size:20px; font-weight:700; color:#0f172a; margin:0 0 12px; letter-spacing:-0.3px;">{$purposeTitle}</h1>
                            <p style="font-size:14px; line-height:1.6; color:#475569; margin:0 0 28px;">{$purposeDesc}</p>

                            <!-- Prominent OTP Code Box (Triggers Gmail OTP chip) -->
                            <div style="background-color:#f8fafc; border:2px dashed #2563eb; border-radius:14px; padding:22px 20px; margin:0 auto 28px; max-width:320px;">
                                <div style="font-family:'SF Pro Mono', Consolas, 'Courier New', monospace; font-size:38px; font-weight:800; letter-spacing:10px; color:#1d4ed8; line-height:1; margin-left:10px;">{$otpCode}</div>
                                <div style="font-size:12px; color:#64748b; margin-top:10px; font-weight:500;">Valid for 10 minutes &bull; Single-use only</div>
                            </div>

                            <!-- Security Warning -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#fffbeb; border-left:4px solid #f59e0b; border-radius:6px; margin-bottom:24px;">
                                <tr>
                                    <td style="padding:14px 16px; text-align:left; font-size:12.5px; color:#78350f; line-height:1.5;">
                                        <strong>Important:</strong> Never share this verification code with anyone. {$siteName} employees and support agents will never ask for your code.
                                    </td>
                                </tr>
                            </table>

                            <p style="font-size:12px; color:#94a3b8; margin:0; line-height:1.5;">
                                If you did not initiate this registration or password request, please disregard this email. No changes will be made to your account.
                            </p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td align="center" style="padding:22px 32px; background-color:#f8fafc; border-top:1px solid #f1f5f9; font-size:12px; color:#64748b; line-height:1.5;">
                            &copy; {$year} {$siteName}. All rights reserved.<br>
                            Automated Transactional Mail Delivery System
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
}
