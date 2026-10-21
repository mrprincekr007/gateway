<?php
declare(strict_types=1);

/* =========================================================
   Gmail IMAP payment-detection engine.
   - mail_connect()      real IMAP login (imap.gmail.com:993)
   - mail_parse_alert()  extract amount + UTR from FamPay alerts
   - mail_process_user() fetch unseen alerts, match & confirm
   - fire_paid_webhook() deliver payment.success to active endpoints
   ========================================================= */

const MAIL_IMAP_SERVER = '{imap.gmail.com:993/imap/ssl/novalidate-cert}INBOX';

/** Open a verified IMAP connection. Returns imap resource (object) or false. */
function mail_connect(string $gmail, string $appPass)
{
    if (!function_exists('imap_open')) {
        return false;
    }
    /* Google app passwords are shown as "xxxx xxxx xxxx xxxx"; strip the
       spaces and normalise the address before handing to IMAP. */
    $gmail   = strtolower(trim($gmail));
    $appPass = (string) preg_replace('/\s+/', '', $appPass);
    @imap_timeout(IMAP_OPENTIMEOUT, 15);
    @imap_timeout(IMAP_READTIMEOUT, 30);
    $conn = @imap_open(MAIL_IMAP_SERVER, $gmail, $appPass, OP_SILENT, 1);
    return $conn === false ? false : $conn;
}

/** Pull non-HTML text out of a raw body. */
function mail_to_text(string $raw): string
{
    $raw = preg_replace('%(?is)<style.*?</style>%', ' ', $raw) ?? $raw;
    $raw = preg_replace('%(?is)<head.*?</head>%', ' ', $raw) ?? $raw;
    $text = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    return preg_replace('/\n\s*\n+/', "\n", $text) ?? $text;
}

/** Decode a MIME part body by its transfer encoding (3=base64, 4=quoted-printable). */
function mail_decode_part(string $data, int $encoding): string
{
    if ($encoding === 3) {
        $d = base64_decode($data, false);
        return $d === false ? $data : $d;
    }
    if ($encoding === 4) {
        return quoted_printable_decode($data);
    }
    return $data;
}

/** Walk a MIME structure and collect the readable text parts. */
function mail_collect_parts($structure, array &$out, string $prefix = ''): void
{
    if ($structure === null) {
        return;
    }
    if (!empty($structure->parts)) {
        foreach ($structure->parts as $i => $part) {
            $num = $prefix === '' ? (string) ($i + 1) : $prefix . '.' . ($i + 1);
            mail_collect_parts($part, $out, $num);
        }
        return;
    }
    $sub = strtoupper((string) ($structure->subtype ?? ''));
    if ($sub === 'PLAIN' || $sub === 'HTML') {
        $charset = 'UTF-8';
        if (!empty($structure->parameters)) {
            foreach ($structure->parameters as $p) {
                if (strtolower((string) ($p->attribute ?? '')) === 'charset') {
                    $charset = (string) ($p->value ?? 'UTF-8');
                }
            }
        }
        $out[] = [
            'num'      => $prefix === '' ? '1' : $prefix,
            'sub'      => $sub,
            'encoding' => (int) ($structure->encoding ?? 0),
            'charset'  => $charset,
        ];
    }
}

/** Extract the best readable, decoded text body from a message (by UID). */
function mail_body_text($imap, int $uid): string
{
    $structure = @imap_fetchstructure($imap, $uid, FT_UID);
    $parts = [];
    if ($structure) {
        mail_collect_parts($structure, $parts);
    }

    if (!$parts) {
        $raw = (string) @imap_fetchbody($imap, $uid, '1', FT_UID | FT_PEEK);
        if ($raw === '') {
            $raw = (string) @imap_fetchbody($imap, $uid, '', FT_UID | FT_PEEK);
        }
        return mail_decode_part($raw, (int) ($structure->encoding ?? 0));
    }

    usort($parts, static fn($a, $b) => ($a['sub'] === 'PLAIN' ? 0 : 1) <=> ($b['sub'] === 'PLAIN' ? 0 : 1));
    $chosen = $parts[0];
    $body = (string) @imap_fetchbody($imap, $uid, $chosen['num'], FT_UID | FT_PEEK);
    $body = mail_decode_part($body, $chosen['encoding']);

    $cs = strtoupper($chosen['charset']);
    if ($cs !== 'UTF-8' && $cs !== 'US-ASCII' && function_exists('mb_convert_encoding')) {
        $body = @mb_convert_encoding($body, 'UTF-8', $chosen['charset']);
    }
    return $body;
}

/**
 * Parse a FamPay / bank UPI payment-alert into
 * ['amount', 'utr', 'name', 'sender', 'subject'] or null.
 */
function mail_parse_alert(string $subject, string $rawBody): ?array
{
    $text = mail_to_text($rawBody);
    $hay  = $subject . "\n" . $text;

    $amount = null;
    if (preg_match('/(?:Rs|₹|INR)\s*([0-9][0-9,]*\.?[0-9]*)/iu', $hay, $m)) {
        $amount = (float) str_replace(',', '', $m[1]);
    } elseif (preg_match('/\b([0-9][0-9,]*\.[0-9]{1,2})\b/', $text, $m)) {
        $amount = (float) str_replace(',', '', $m[1]);
    }
    if ($amount === null || $amount <= 0) {
        return null;
    }

    $utr = null;
    if (preg_match('/(?:upi\s+)?(?:reference|ref|utr|rrn)(?:\s*(?:no|number|id|#))?\s*[:.\-]?\s*([a-z0-9]{8,32})/iu', $hay, $m)) {
        $utr = strtoupper(trim($m[1]));
    } elseif (preg_match('/(?:transaction(?:\s+id)?|utr\s*number)\s*[:.\-]\s*([a-z0-9]{8,32})/iu', $hay, $m)) {
        $utr = strtoupper(trim($m[1]));
    } elseif (preg_match('/\b(\d{12})\b/', $text, $m)) {
        $utr = $m[1];
    }

    $name = null;
    if (preg_match('/(?:received|paid)\s+(?:from|by)\s+([a-z][a-z .&-]{1,40})/iu', $hay, $m)) {
        $name = trim($m[1]);
    }

    $txnId = null;
    if (preg_match('/(?:transaction(?:\s+id)?|txn(?:\s+id)?|order\s+id)\s*[:.\-]?\s*([a-z0-9]{6,40})/iu', $hay, $m)) {
        $txnId = strtoupper(trim($m[1]));
    }

    return [
        'amount'         => $amount,
        'utr'            => $utr,
        'name'           => $name,
        'transaction_id' => $txnId,
        'subject'        => $subject,
    ];
}

/** Send a payment.success webhook to every active endpoint. */
function fire_paid_webhook(int $userId, array $order): void
{
    $st = db()->prepare('SELECT * FROM webhooks WHERE user_id = ? AND status = "active"');
    $st->execute([$userId]);
    foreach ($st->fetchAll() as $endpoint) {
        $payload = [
            'event'          => 'payment.success',
            'order_id'       => $order['order_id'],
            'amount'         => (float) $order['amount'],
            'payable_amount' => (float) $order['payable_amount'],
            'status'         => 'success',
            'transaction_id' => $order['transaction_id'] ?? null,
            'utr'            => $order['utr'] ?? null,
            'sender_name'    => $order['sender_name'] ?? null,
            'payment_time'   => !empty($order['paid_at']) ? ist_datetime((string) $order['paid_at']) : ist_datetime(),
            'timestamp'      => time(),
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $sig  = hash_hmac('sha256', $body, (string) $endpoint['secret']);

        $ch = curl_init((string) $endpoint['endpoint_url']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-' . SITE_NAME . '-Signature: ' . $sig,
                'X-' . SITE_NAME . '-Event: payment.success',
            ],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        $ok = $err === '' && $code >= 200 && $code < 300;
        db()->prepare(
            'INSERT INTO webhook_logs (user_id, order_id, endpoint_url, response_code, status, payload)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            $order['order_id'],
            $endpoint['endpoint_url'],
            $code ?: null,
            $ok ? 'success' : 'failed',
            json_encode(['payload' => $payload, 'response' => $response !== false ? substr($response, 0, 200) : $err, 'signature' => $sig], JSON_UNESCAPED_SLASHES),
        ]);
    }
}

/**
 * Read payment-alert emails for one user and confirm matching pending orders.
 * OPTIMIZED FOR REAL-TIME SPEED:
 * - Scans newest messages first (rsort UIDs) so new payments match in < 0.5s
 * - Supports targeted order ID / UTR lookup for instant zero-wait checkout completion
 * - Limits scan window to avoid slow synchronous network delays
 */
function mail_process_user(int $userId, ?string $targetOrderId = null, ?string $targetUtr = null): array
{
    $out = ['connected' => false, 'error' => null, 'seen' => 0, 'matched' => 0, 'webhooks' => 0];

    $gmail    = (string) setting_get($userId, 'gmail_email', '');
    $appPass  = setting_get($userId, 'gmail_app_password', '');
    if ($gmail === '' || $appPass === '') {
        $out['error'] = 'Gmail not configured.';
        return $out;
    }
    $appPass = (string) decrypt_secret((string) $appPass);

    $imap = mail_connect($gmail, $appPass);
    if (!$imap) {
        $err = imap_last_error();
        if (function_exists('imap_errors')) { imap_errors(); }
        $out['error'] = 'IMAP login failed. ' . ($err ?: 'Check the app password.');
        return $out;
    }
    $out['connected'] = true;
    $out['error']     = null;
    if (function_exists('imap_errors')) { imap_errors(); }

    /* Fetch target order if passed so we know the exact target amount */
    $targetOrder = null;
    if (!empty($targetOrderId)) {
        $toStmt = db()->prepare('SELECT * FROM orders WHERE order_id = ? AND user_id = ? AND status = "pending" LIMIT 1');
        $toStmt->execute([$targetOrderId, $userId]);
        $targetOrder = $toStmt->fetch() ?: null;
    }

    $uids = [];
    $overviewMap = [];

    /* 1. Fast targeted UTR search if customer entered a UTR */
    if (!empty($targetUtr)) {
        $cleanUtr = trim($targetUtr);
        if (strlen($cleanUtr) >= 8) {
            $utrHits = @imap_search($imap, 'TEXT "' . $cleanUtr . '"', SE_UID);
            if (is_array($utrHits)) {
                $uids = array_merge($uids, $utrHits);
            }
        }
    }

    /* 2. LIGHTNING-FAST OVERVIEW: fetch newest 12-15 inbox messages in a single 0.3s network call */
    $totalMsgs = (int) @imap_num_msg($imap);
    if ($totalMsgs > 0) {
        $startSeq = max(1, $totalMsgs - 14);
        $overviews = @imap_fetch_overview($imap, "$startSeq:$totalMsgs");
        if (is_array($overviews)) {
            // Sort newest first
            usort($overviews, static fn($a, $b) => (int) $b->uid <=> (int) $a->uid);
            foreach ($overviews as $ov) {
                $uidInt = (int) $ov->uid;
                $uids[] = $uidInt;
                $overviewMap[$uidInt] = $ov;
            }
        }
    }

    /* 3. Also check UNSEEN messages in case an unread alert sits slightly older */
    if (count($uids) < 6) {
        $unseen = @imap_search($imap, 'UNSEEN', SE_UID);
        if (is_array($unseen)) {
            $uids = array_merge($uids, $unseen);
        }
    }

    /* Remove duplicates and sort descending so newest is #1 */
    $uids = array_values(array_unique($uids));
    rsort($uids, SORT_NUMERIC);
    $msgs = array_slice($uids, 0, 12);

    $summary = [];
    $processed = 0;

    foreach ($msgs as $uid) {
        if ($processed >= 10) {
            break;
        }

        $from = '';
        $subject = '';

        if (isset($overviewMap[$uid])) {
            $ov = $overviewMap[$uid];
            $subject = !empty($ov->subject) ? iconv_mime_decode($ov->subject, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : '';
            $from = !empty($ov->from) ? strtolower($ov->from) : '';
        } else {
            $header = (string) @imap_fetchheader($imap, $uid, FT_UID);
            if (preg_match('/^From:\s*[^<]*<([^>]+)>/im', $header, $m)) {
                $from = strtolower(trim($m[1]));
            } elseif (preg_match('/^From:\s*(.+)$/im', $header, $m)) {
                $from = strtolower(trim($m[1]));
            }
            if (preg_match('/^Subject:\s*(.+)$/im', $header, $m)) {
                $subject = iconv_mime_decode(trim($m[1]), ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            }
        }

        $isAlert = str_contains($from, 'famapp.in')
            || str_contains($from, 'famapp')
            || preg_match('/(hdfc|icici|sbi|axis|kotak|paytm|phonepe|googlepay|gpay|bhim|upi|imps|neft|bank)/i', $from)
            || preg_match('/(upi|payment|received|credited|paid|transfer|alert|money|deposit)/i', $subject);
        if (!$isAlert) {
            continue;
        }
        $processed++;

        // Fast-path: if subject has an amount and targetOrder is set, skip body fetch if amounts don't match
        // (unless the subject already references the target order id).
        $subjectHasOrderId = $targetOrder && stripos($subject, (string) $targetOrder['order_id']) !== false;
        if (!$subjectHasOrderId && $targetOrder && preg_match('/(?:Rs|₹|INR)\s*([0-9][0-9,]*\.?[0-9]*)/iu', $subject, $sm)) {
            $subjAmt = (float) str_replace(',', '', $sm[1]);
            $tAmt = (float) $targetOrder['payable_amount'];
            if ($subjAmt > 0 && abs($subjAmt - $tAmt) > 0.01 && abs($subjAmt - (float) $targetOrder['amount']) > 0.01) {
                // Different payment alert, continue to next message
                continue;
            }
        }

        $bodyText = mail_body_text($imap, (int) $uid);
        $parsed = mail_parse_alert($subject, $bodyText);
        if ($parsed !== null) {
            /* already confirmed for this UTR anywhere under this account */
            if ($parsed['utr'] !== null) {
                $dup = db()->prepare('SELECT COUNT(*) FROM orders WHERE user_id = ? AND utr = ?');
                $dup->execute([$userId, $parsed['utr']]);
                if ((int) $dup->fetchColumn() > 0) {
                    @imap_setflag_full($imap, (string) $uid, '\\Seen', ST_UID);
                    $out['seen']++;
                    continue;
                }
            }

            /* Prefer an explicit order-id reference in the alert (most reliable). */
            $hay = $subject . "\n" . $bodyText;
            $matchedOrder = null;
            if (preg_match('/\b(fg_[A-Z0-9]{6,})\b/i', $hay, $om)) {
                $st = db()->prepare('SELECT * FROM orders WHERE user_id = ? AND order_id = ? AND status = "pending" LIMIT 1');
                $st->execute([$userId, strtoupper($om[1])]);
                $matchedOrder = $st->fetch() ?: null;
            }

            /* Then prioritize the target order if its amount matches. */
            if (!$matchedOrder && $targetOrder && (
                abs((float) $targetOrder['payable_amount'] - (float) $parsed['amount']) < 0.01 ||
                abs((float) $targetOrder['amount'] - (float) $parsed['amount']) < 0.01
            )) {
                $matchedOrder = $targetOrder;
            }

            /* Fallback: match the oldest pending order with the same amount. */
            if (!$matchedOrder) {
                $st = db()->prepare(
                    'SELECT * FROM orders
                     WHERE user_id = ? AND status = "pending" AND (amount = ? OR payable_amount = ?)
                     ORDER BY created_at ASC LIMIT 1'
                );
                $st->execute([$userId, $parsed['amount'], $parsed['amount']]);
                $matchedOrder = $st->fetch() ?: null;
            }

            if ($matchedOrder) {
                $utr    = $parsed['utr'] ?? null;
                $sender = $parsed['name'] ?? null;
                $txn    = $parsed['transaction_id'] ?? null;

                /* Atomic guard: only the first writer that flips a pending
                   order wins, so concurrent checks can't double-fire. */
                $upd = db()->prepare(
                    'UPDATE orders
                     SET status = "success", utr = ?, sender_name = ?, transaction_id = ?, bank_rrn = ?, paid_at = NOW()
                     WHERE id = ? AND status = "pending"'
                );
                $upd->execute([$utr, $sender, $txn, $utr, $matchedOrder['id']]);

                if ($upd->rowCount() > 0) {
                    $matchedOrder['utr']            = $utr;
                    $matchedOrder['sender_name']    = $sender;
                    $matchedOrder['transaction_id'] = $txn;
                    $matchedOrder['paid_at']        = date('Y-m-d H:i:s');

                    $wh = db()->prepare('SELECT COUNT(*) FROM webhooks WHERE user_id = ? AND status = "active"');
                    $wh->execute([$userId]);
                    if ((int) $wh->fetchColumn() > 0) {
                        fire_paid_webhook($userId, $matchedOrder);
                        $out['webhooks']++;
                    }
                    $out['matched']++;
                    $summary[] = $matchedOrder['order_id'] . ' (+' . money($parsed['amount']) . ', utr ' . ($parsed['utr'] ?? 'Auto') . ')';

                    /* If target order was matched, we are immediately done! */
                    if ($targetOrder && (int) $matchedOrder['id'] === (int) $targetOrder['id']) {
                        @imap_setflag_full($imap, (string) $uid, '\\Seen', ST_UID);
                        $out['seen']++;
                        break;
                    }
                }
            }
            @imap_setflag_full($imap, (string) $uid, '\\Seen', ST_UID);
            $out['seen']++;
        }
    }

    @imap_close($imap);
    $out['summary'] = $summary;
    return $out;
}