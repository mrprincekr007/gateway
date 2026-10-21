<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';

$user   = require_login();
$active = 'prompts';
$activeNav = 'prompts';

// Hide standard white heading bar to display glowing dark hero stage
$hidePageHeading = true;

// Reuse the existing docs design language for layout, terminal & code blocks
$extraCss = ['user/assets/css/pages/docs.css'];

$sections = [
    'overview' => ['title' => 'Overview',       'icon' => 'sparkles',   'desc' => 'Beginner guide - copy, paste, done'],
    'html'     => ['title' => 'HTML + CSS + JS', 'icon' => 'code',       'desc' => 'Static sites and Firebase Hosting'],
    'php'      => ['title' => 'PHP + MySQL',     'icon' => 'database',   'desc' => 'Classic cPanel / Apache stack'],
    'node'     => ['title' => 'Node.js',         'icon' => 'server',     'desc' => 'Express & serverless backends'],
    'app'      => ['title' => 'Mobile App',      'icon' => 'smartphone', 'desc' => 'Flutter / React Native apps'],
    'telegram' => ['title' => 'Telegram Bot',    'icon' => 'send',       'desc' => 'Python Telegram selling bot'],
];

$current = get('tab', 'overview');
if (!isset($sections[$current])) {
    $current = 'overview';
}

$sectionKeys = array_keys($sections);
$currIndex   = array_search($current, $sectionKeys, true);
$prevKey     = $currIndex > 0 ? $sectionKeys[$currIndex - 1] : null;
$nextKey     = $currIndex < count($sectionKeys) - 1 ? $sectionKeys[$currIndex + 1] : null;

$api            = url('api');
$siteName       = SITE_NAME;
$sampleCheckout = url('pay.php?order_id=fg_XXXXXXXX');

$p_html = <<<PROMPT
You are a senior web developer. Integrate the {$siteName} UPI payment API into a
website built with plain HTML, CSS and vanilla JavaScript (hosted on Firebase
Hosting or any static host). Then give me the complete code.

BASE URL: {$api}
AUTH HEADER: Authorization: Bearer YOUR_API_KEY

SECURITY (must follow):
- A browser / static site must NEVER contain the API key.
- On Firebase, keep the key inside a Cloud Function secret and expose an HTTPS
  callable function createPayment that calls the gateway server-side.
- The browser may only call the PUBLIC endpoint /api/checkout-status.php.

ENDPOINTS:
1) POST {$api}/create-order   (call this server-side only)
   JSON body: { "amount": 500.00, "customer_name": "Ravi" }
   The response is ALWAYS wrapped inside a "data" object:
   {
     "status": "success",
     "data": {
       "order_id": "fg_XXXXXXXX",
       "payable_amount": "500.00",
       "upi_id": "merchant@upi",
       "qr_url": "{$api}/qr-image.php?order_id=fg_XXXXXXXX",
       "checkout_url": "{$sampleCheckout}",
       "upi_intent": "upi://pay?..."
     }
   }
   Read the fields as resp.data.order_id, resp.data.checkout_url, resp.data.qr_url.
2) GET {$api}/checkout-status.php?order_id=fg_XXXXXXXX   (PUBLIC, no key needed)
   -> { "status": "pending" | "success" | "expired", "utr": "...", "sender_name": "..." }
3) GET {$api}/qr-image.php?order_id=fg_XXXXXXXX   -> an SVG QR image (use in <img src>).

BUILD:
- A "Pay Now" button that calls the createPayment Cloud Function with the amount.
- Show the returned QR ( <img src="{resp.data.qr_url}"> ) and a link to resp.data.checkout_url.
- Poll GET {$api}/checkout-status.php?order_id=... every 3 seconds.
- On "success": stop polling and show a success message with the UTR.
- On "expired": show an expired message.

Deliver the complete working code: firebase-functions/index.js (createPayment),
index.html, style.css and script.js.
PROMPT;

$p_php = <<<PROMPT
You are a senior PHP developer. Integrate the {$siteName} UPI payment API into a
PHP + MySQL website (Apache / cPanel).

BASE URL: {$api}
AUTH HEADER: Authorization: Bearer YOUR_API_KEY
Keep the API key in a config file or environment variable — never in HTML output.

ENDPOINTS:
1) POST {$api}/create-order
   Send JSON: { "amount": 500.00, "customer_name": "..", "customer_email": "..",
                "customer_phone": "..", "redirect_url": "..", "webhook_url": ".." }
   The response fields are inside a "data" object:
   {
     "status": "success",
     "data": {
       "order_id": "fg_XXXXXXXX",
       "payable_amount": "500.00",
       "upi_id": "merchant@upi",
       "qr_url": "{$api}/qr-image.php?order_id=fg_XXXXXXXX",
       "checkout_url": "{$sampleCheckout}",
       "upi_intent": "upi://pay?..."
     }
   }
2) GET {$api}/check-order.php?order_id=fg_XXXXXXXX   (key required)
   -> data.status = "pending" | "success", and data.utr once paid.

WEBHOOK (optional): the gateway can POST a JSON event "payment.success" to your
webhook_url with header "X-Father Gateway-Signature" = HMAC-SHA256 of the raw
request body using your webhook secret. Verify it with hash_hmac() + hash_equals()
before you trust the payload.

BUILD (use PHP cURL for the calls):
- config.php   : holds the API key + a helper doing the cURL request.
- db.sql       : a "payments" table (order_id, amount, status, utr, created_at).
- pay.php      : amount form -> create-order -> show QR ( <img src="{data.qr_url}"> )
                 and a "Pay on UPI" button (data.checkout_url / data.upi_intent).
- status.php   : calls check-order.php and updates the DB row when status=success.
- webhook.php  : verifies the signature and marks the order paid.

Deliver all files with clean, secure code and clear comments.
PROMPT;

$p_node = <<<PROMPT
You are a senior Node.js developer. Integrate the {$siteName} UPI payment API into
a Node.js website / backend (Express or serverless).

BASE URL: {$api}
AUTH: read the key from process.env.GATEWAY_API_KEY and send the header
"Authorization: Bearer <key>". Never expose the key to the browser.

ENDPOINTS:
1) POST {$api}/create-order
   Body: { "amount": 500.00, "customer_name": "..", "redirect_url": "..", "webhook_url": ".." }
   Response (fields are inside "data"):
   { "status": "success", "data": {
       "order_id": "fg_XXXXXXXX", "payable_amount": "500.00", "upi_id": "merchant@upi",
       "qr_url": "{$api}/qr-image.php?order_id=fg_XXXXXXXX",
       "checkout_url": "{$sampleCheckout}", "upi_intent": "upi://pay?..." } }
2) GET {$api}/check-order.php?order_id=fg_XXXXXXXX   (send the Bearer header)
3) PUBLIC: GET {$api}/checkout-status.php?order_id=fg_XXXXXXXX   (no key) for polling.
4) Webhook: verify header "X-Father Gateway-Signature" (HMAC-SHA256 of the raw body
   with your secret) before marking an order paid.

FLOW:
- POST /api/create-order  -> your server calls the gateway -> returns { checkout_url, qr_url }
  to the front-end.
- Front-end shows the QR + "Pay Now" button and polls checkout-status until paid.

Deliver a complete Express app: server.js, .env, public/index.html, public/app.js,
and a webhook route with signature verification.
PROMPT;

$p_app = <<<PROMPT
You are a senior mobile developer. Integrate the {$siteName} UPI payment API into a
mobile app (Flutter or React Native).

BASE URL: {$api}
AUTH HEADER: Authorization: Bearer YOUR_API_KEY

SECURITY (CRITICAL): Never hardcode the API key inside the mobile app — it can be
extracted from the package. Instead call the gateway from YOUR OWN backend and let
the app talk to that backend.

ENDPOINTS (call these from your backend):
1) POST {$api}/create-order   body: { "amount": 500.00 }
   Response (fields inside "data"):
   { "status": "success", "data": {
       "order_id": "fg_XXXXXXXX", "payable_amount": "500.00",
       "qr_url": "{$api}/qr-image.php?order_id=fg_XXXXXXXX",
       "checkout_url": "{$sampleCheckout}", "upi_intent": "upi://pay?..." } }
2) PUBLIC: GET {$api}/checkout-status.php?order_id=fg_XXXXXXXX   (no key)
   -> { "status": "pending" | "success" | "expired", "utr": "...", "sender_name": "..." }

FLOW:
1) User taps "Pay" -> the app calls your backend -> backend calls create-order.
2) Show the QR (load data.qr_url, which is an SVG) OR a button that launches the UPI
   app using data.upi_intent (url_launcher / Linking). Also offer data.checkout_url.
3) Poll GET {$api}/checkout-status.php?order_id=... every 3 seconds.
4) On "success" show a success screen with the UTR; on "expired" show an expired screen.

Deliver the complete app code (the pay screen, polling logic, state handling) plus
the small backend endpoint that holds the API key.
PROMPT;

$p_telegram = <<<PROMPT
You are a senior Python developer. Build a Telegram selling bot in Python that
accepts UPI payments through the {$siteName} gateway API. Then give me the full code.

BASE URL: {$api}
AUTH HEADER: Authorization: Bearer YOUR_API_KEY
Put the bot token and API key in a .env file (python-dotenv). Never hardcode them.

IMPORTANT QR NOTE: the gateway returns the QR as an SVG image, and Telegram sendPhoto
does NOT accept SVG. So build your own PNG QR from data.upi_intent using the
"qrcode[pil]" library, then send it with send_photo.

ENDPOINTS:
1) POST {$api}/create-order   (call from the bot server, never from the client)
   JSON: { "amount": 500.00, "customer_name": "Telegram User", "redirect_url": "..." }
   The response fields live inside a "data" object:
   { "status": "success", "data": {
       "order_id": "fg_XXXXXXXX", "payable_amount": "500.00", "upi_id": "merchant@upi",
       "qr_url": "{$api}/qr-image.php?order_id=fg_XXXXXXXX",
       "checkout_url": "{$sampleCheckout}", "upi_intent": "upi://pay?..." } }
2) GET {$api}/check-order.php?order_id=fg_XXXXXXXX   (key required)
   -> data.status = "pending" | "success" and data.utr once paid.

BOT FLOW (python-telegram-bot v20+ async, or pyTelegramBotAPI):
- /start shows a welcome + "Buy" button.
- /buy asks the amount (or product), then calls create-order.
- Generate a PNG QR from data.upi_intent (qrcode + PIL), send it via send_photo with
  a caption showing the amount + order id, and add an inline "Open UPI App" URL button
  that opens data.checkout_url (or the upi_intent deep link).
- Poll check-order.php every 5-10 seconds (asyncio task / job queue) and, when the
  status becomes "success", message the user the confirmation with the UTR and stop polling.
- Handle "expired" (5-minute window) by offering to create a new order.
- Keep a small orders table in SQLite (order_id, user_id, amount, status, utr, created_at).

Deliver the complete runnable bot: bot.py, requirements.txt, .env.example and README
with run steps. Use clean async code and clear comments.
PROMPT;

$pageTitle   = $sections[$current]['title'] . ' · AI Integration Guide';
$pageHeading = 'AI Builder';
$pageSub     = 'Copy a ready-made prompt and let your AI build the integration.';
include __DIR__ . '/includes/header.php';
?>

<div class="dash-hero-stage-dark" data-reveal>
    <div class="hero-stage-mesh-top" aria-hidden="true"></div>
    <div class="hero-stage-mesh-bottom" aria-hidden="true"></div>

    <div class="hero-stage-header">
        <div class="hero-eyebrow">
            <span class="dot"></span>
            <span>AI Integration Guide &middot; Copy &amp; Paste Prompts</span>
        </div>
        <div class="hero-sla-pill">
            <span class="sla-dot"></span>
            <span>Works with ChatGPT, Cursor, Claude &amp; more</span>
        </div>
    </div>

    <div class="hero-stage-headline">
        <h1 class="dash-hero-heading">
            Build with <span class="accent">AI</span>
        </h1>
        <p class="dash-hero-desc">
            Coding nahi aati? Koi dikkat nahi. Neeche se apna platform chuno, prompt copy karo,
            apne AI agent ko do — wo tumhare liye poora integration bana dega.
        </p>
    </div>

    <div class="hero-stage-btns">
        <a href="<?= url('user/api-keys.php') ?>" class="btn-hero-primary">
            <?= icon('key', 15) ?> Get API Key
        </a>
        <a href="<?= url('user/docs.php') ?>" class="btn-hero-secondary">
            <?= icon('book', 14) ?> Full API Docs
        </a>
    </div>
</div>

<div class="pmt-steps" data-reveal>
    <div class="pmt-step">
        <span class="pmt-step-num">1</span>
        <div class="pmt-step-body">
            <b>Pick your stack</b>
            <p>Choose HTML, PHP, Node.js or a Mobile App below.</p>
        </div>
    </div>
    <div class="pmt-step">
        <span class="pmt-step-num">2</span>
        <div class="pmt-step-body">
            <b>Copy the prompt</b>
            <p>One click copies a full instruction for your AI.</p>
        </div>
    </div>
    <div class="pmt-step">
        <span class="pmt-step-num">3</span>
        <div class="pmt-step-body">
            <b>Paste in your AI</b>
            <p>ChatGPT / Cursor me paste karo, phir apni API key daal do.</p>
        </div>
    </div>
</div>

<nav class="docs-mobile-nav" aria-label="AI guide sections navigation">
    <?php foreach ($sections as $key => $sec): ?>
        <a class="docs-mobile-pill <?= $current === $key ? 'is-active' : '' ?>" href="?tab=<?= e($key) ?>" data-pmt-tab="<?= e($key) ?>">
            <?= icon($sec['icon'], 14) ?>
            <span><?= e($sec['title']) ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<div class="docs-layout-container">
    <aside class="docs-sidebar-sticky">
        <div class="docs-sidebar-title">
            <?= icon('sparkles', 14) ?>
            <span>AI Prompts</span>
        </div>
        <nav style="display:flex;flex-direction:column;">
            <?php foreach ($sections as $key => $sec): ?>
                <a class="docs-nav-item <?= $current === $key ? 'is-active' : '' ?>" href="?tab=<?= e($key) ?>" data-pmt-tab="<?= e($key) ?>">
                    <?= icon($sec['icon'], 16) ?>
                    <span><?= e($sec['title']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div style="margin-top:20px;padding:14px;background:var(--surface-sunken);border-radius:14px;border:1px solid var(--color-hairline);">
            <div style="font-size:12px;font-weight:700;color:var(--color-ink);display:flex;align-items:center;gap:6px;">
                <?= icon('key', 14, 'style="color:#2563eb;"') ?>
                <span>Your API Key</span>
            </div>
            <p style="font-size:12px;color:var(--color-ink-mute);margin:6px 0 10px;line-height:1.4;">
                Create a key and drop it where the prompt says <b>YOUR_API_KEY</b>.
            </p>
            <a href="<?= url('user/api-keys.php') ?>" class="btn btn-primary btn-sm" style="width:100%;justify-content:center;font-size:12px;">
                Manage Keys
            </a>
        </div>
    </aside>

    <div style="min-width:0;">

        <div class="docs-section-panel <?= $current === 'overview' ? 'is-active' : '' ?>" id="pmt-sec-overview" data-pmt-sec="overview">
            <div class="docs-card">
                <h2 class="docs-section-heading">
                    <?= icon('sparkles', 20, 'style="color:#2563eb;"') ?>
                    How to use this page
                </h2>
                <p class="docs-section-sub">
                    Even if you know nothing about coding, your AI agent can build the full integration for you — you just need to hand it the right prompt.
                </p>

                <div class="pmt-note pmt-note-info">
                    <?= icon('sparkles', 15) ?>
                    <div>
                        <b>Step by step</b>
                        <p>Apna platform chuno (sidebar me) → prompt <b>copy</b> karo → ChatGPT / Cursor / Claude me <b>paste</b> karo → usme <b>YOUR_API_KEY</b> ki jagah apni key daalo. Bas.</p>
                    </div>
                </div>

                <h3 style="font-size:15px;font-weight:700;color:var(--color-ink);margin:22px 0 10px;">Five rules to remember</h3>
                <div class="pmt-rules">
                    <div class="pmt-rule"><?= icon('check-circle', 15, 'style="color:#059669;"') ?><span>API key hamesha <b>server-side</b> rakho (PHP / Node / Cloud Function).</span></div>
                    <div class="pmt-rule"><?= icon('check-circle', 15, 'style="color:#059669;"') ?><span>HTML/JS/Firebase me key <b>browser me mat</b> daalo &mdash; Cloud Function use karo.</span></div>
                    <div class="pmt-rule"><?= icon('check-circle', 15, 'style="color:#059669;"') ?><span>Order banane ke liye <code class="mono">/api/create-order</code> use karo.</span></div>
                    <div class="pmt-rule"><?= icon('check-circle', 15, 'style="color:#059669;"') ?><span>Payment check ke liye public <code class="mono">/api/checkout-status.php</code> poll karo.</span></div>
                    <div class="pmt-rule"><?= icon('check-circle', 15, 'style="color:#059669;"') ?><span>Saara response <b>data</b> object ke andar aata hai.</span></div>
                </div>

                <h3 style="font-size:15px;font-weight:700;color:var(--color-ink);margin:24px 0 10px;">Quick reference</h3>
                <div class="pmt-facts">
                    <div class="pmt-fact">
                        <span class="pmt-fact-k">Base URL</span>
                        <span class="pmt-fact-v mono"><?= e($api) ?></span>
                    </div>
                    <div class="pmt-fact">
                        <span class="pmt-fact-k">Auth Header</span>
                        <span class="pmt-fact-v mono">Authorization: Bearer YOUR_API_KEY</span>
                    </div>
                    <div class="pmt-fact">
                        <span class="pmt-fact-k">Create Order</span>
                        <span class="pmt-fact-v mono">POST <?= e($api) ?>/create-order</span>
                    </div>
                    <div class="pmt-fact">
                        <span class="pmt-fact-k">Public Polling</span>
                        <span class="pmt-fact-v mono">GET <?= e($api) ?>/checkout-status.php?order_id=...</span>
                    </div>
                    <div class="pmt-fact">
                        <span class="pmt-fact-k">QR Image</span>
                        <span class="pmt-fact-v mono">GET <?= e($api) ?>/qr-image.php?order_id=... (SVG)</span>
                    </div>
                    <div class="pmt-fact">
                        <span class="pmt-fact-k">Success Shape</span>
                        <span class="pmt-fact-v mono">{ "status":"success", "data": { ... } }</span>
                    </div>
                </div>

                <div class="pmt-note pmt-note-warn" style="margin-top:18px;">
                    <?= icon('shield', 15) ?>
                    <div>
                        <b>Security first</b>
                        <p>Agar aapki website pure HTML/CSS/JS / Firebase hai to key <b>kabhi</b> frontend me na rakho. Prompt kehta hai isko Cloud Function me daalo — usi ko follow karo.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="docs-section-panel <?= $current === 'html' ? 'is-active' : '' ?>" id="pmt-sec-html" data-pmt-sec="html">
            <div class="docs-card">
                <div class="pmt-prompt-head">
                    <span class="pmt-prompt-ic" style="--pmt-ic:#2563eb;--pmt-ic-bg:rgba(37,99,235,0.12);"><?= icon('code', 20) ?></span>
                    <div>
                        <h2 class="docs-section-heading" style="margin:0;">HTML + CSS + JavaScript</h2>
                        <p class="docs-section-sub" style="margin:2px 0 0;">Static website ya Firebase Hosting ke liye.</p>
                    </div>
                </div>

                <div class="pmt-note pmt-note-warn">
                    <?= icon('shield', 15) ?>
                    <div>
                        <b>Important</b>
                        <p>Static / browser site me API key expose hoti hai. Prompt isko Firebase <b>Cloud Function</b> me rakhta hai — copy karke seedha apne AI ko do.</p>
                    </div>
                </div>

                <div class="docs-terminal">
                    <div class="docs-terminal-top">
                        <div class="docs-terminal-dots">
                            <span class="docs-dot red"></span>
                            <span class="docs-dot yellow"></span>
                            <span class="docs-dot green"></span>
                            <span class="docs-terminal-lang">AI PROMPT &middot; HTML / CSS / JS</span>
                        </div>
                        <button type="button" class="docs-copy-action" data-copy-target="#pmt-html-pre">
                            <?= icon('copy', 12) ?> <span>Copy Prompt</span>
                        </button>
                    </div>
                    <div class="docs-terminal-body">
                        <pre id="pmt-html-pre"><?= e($p_html) ?></pre>
                    </div>
                </div>

                <div class="pmt-uses">
                    <span class="pmt-tag"><?= icon('code', 13) ?> Vanilla JS</span>
                    <span class="pmt-tag"><?= icon('globe', 13) ?> Firebase Hosting</span>
                    <span class="pmt-tag"><?= icon('shield', 13) ?> Cloud Function</span>
                </div>
            </div>
        </div>

        <div class="docs-section-panel <?= $current === 'php' ? 'is-active' : '' ?>" id="pmt-sec-php" data-pmt-sec="php">
            <div class="docs-card">
                <div class="pmt-prompt-head">
                    <span class="pmt-prompt-ic" style="--pmt-ic:#7c3aed;--pmt-ic-bg:rgba(124,58,237,0.12);"><?= icon('database', 20) ?></span>
                    <div>
                        <h2 class="docs-section-heading" style="margin:0;">PHP + MySQL</h2>
                        <p class="docs-section-sub" style="margin:2px 0 0;">Classic Apache / cPanel website ke liye.</p>
                    </div>
                </div>

                <div class="pmt-note pmt-note-info">
                    <?= icon('check-circle', 15) ?>
                    <div>
                        <b>Server-side, so key safe hai</b>
                        <p>PHP me API key config file me rakho. Prompt orders ko MySQL me save karna aur webhook verify karna bhi cover karta hai.</p>
                    </div>
                </div>

                <div class="docs-terminal">
                    <div class="docs-terminal-top">
                        <div class="docs-terminal-dots">
                            <span class="docs-dot red"></span>
                            <span class="docs-dot yellow"></span>
                            <span class="docs-dot green"></span>
                            <span class="docs-terminal-lang">AI PROMPT &middot; PHP / MYSQL</span>
                        </div>
                        <button type="button" class="docs-copy-action" data-copy-target="#pmt-php-pre">
                            <?= icon('copy', 12) ?> <span>Copy Prompt</span>
                        </button>
                    </div>
                    <div class="docs-terminal-body">
                        <pre id="pmt-php-pre"><?= e($p_php) ?></pre>
                    </div>
                </div>

                <div class="pmt-uses">
                    <span class="pmt-tag"><?= icon('terminal', 13) ?> PHP cURL</span>
                    <span class="pmt-tag"><?= icon('database', 13) ?> MySQL</span>
                    <span class="pmt-tag"><?= icon('webhook', 13) ?> Webhook</span>
                </div>
            </div>
        </div>

        <div class="docs-section-panel <?= $current === 'node' ? 'is-active' : '' ?>" id="pmt-sec-node" data-pmt-sec="node">
            <div class="docs-card">
                <div class="pmt-prompt-head">
                    <span class="pmt-prompt-ic" style="--pmt-ic:#059669;--pmt-ic-bg:rgba(5,150,105,0.12);"><?= icon('server', 20) ?></span>
                    <div>
                        <h2 class="docs-section-heading" style="margin:0;">Node.js</h2>
                        <p class="docs-section-sub" style="margin:2px 0 0;">Express ya serverless backend ke liye.</p>
                    </div>
                </div>

                <div class="pmt-note pmt-note-info">
                    <?= icon('check-circle', 15) ?>
                    <div>
                        <b>Backend me key, frontend sirf poll</b>
                        <p>Key <code class="mono">.env</code> me rehti hai. Browser sirf public <code class="mono">checkout-status</code> endpoint call karta hai.</p>
                    </div>
                </div>

                <div class="docs-terminal">
                    <div class="docs-terminal-top">
                        <div class="docs-terminal-dots">
                            <span class="docs-dot red"></span>
                            <span class="docs-dot yellow"></span>
                            <span class="docs-dot green"></span>
                            <span class="docs-terminal-lang">AI PROMPT &middot; NODE.JS</span>
                        </div>
                        <button type="button" class="docs-copy-action" data-copy-target="#pmt-node-pre">
                            <?= icon('copy', 12) ?> <span>Copy Prompt</span>
                        </button>
                    </div>
                    <div class="docs-terminal-body">
                        <pre id="pmt-node-pre"><?= e($p_node) ?></pre>
                    </div>
                </div>

                <div class="pmt-uses">
                    <span class="pmt-tag"><?= icon('server', 13) ?> Express</span>
                    <span class="pmt-tag"><?= icon('webhook', 13) ?> Webhook verify</span>
                    <span class="pmt-tag"><?= icon('zap', 13) ?> Serverless-ready</span>
                </div>
            </div>
        </div>

        <div class="docs-section-panel <?= $current === 'app' ? 'is-active' : '' ?>" id="pmt-sec-app" data-pmt-sec="app">
            <div class="docs-card">
                <div class="pmt-prompt-head">
                    <span class="pmt-prompt-ic" style="--pmt-ic:#0891b2;--pmt-ic-bg:rgba(8,145,178,0.12);"><?= icon('smartphone', 20) ?></span>
                    <div>
                        <h2 class="docs-section-heading" style="margin:0;">Mobile App</h2>
                        <p class="docs-section-sub" style="margin:2px 0 0;">Flutter / React Native (Android + iOS).</p>
                    </div>
                </div>

                <div class="pmt-note pmt-note-warn">
                    <?= icon('shield', 15) ?>
                    <div>
                        <b>App me key kabhi hardcode na karo</b>
                        <p>App se nikaali ja sakti hai. Prompt app ko aapke <b>apne backend</b> se baat karwata hai, jo gateway call karta hai.</p>
                    </div>
                </div>

                <div class="docs-terminal">
                    <div class="docs-terminal-top">
                        <div class="docs-terminal-dots">
                            <span class="docs-dot red"></span>
                            <span class="docs-dot yellow"></span>
                            <span class="docs-dot green"></span>
                            <span class="docs-terminal-lang">AI PROMPT &middot; MOBILE APP</span>
                        </div>
                        <button type="button" class="docs-copy-action" data-copy-target="#pmt-app-pre">
                            <?= icon('copy', 12) ?> <span>Copy Prompt</span>
                        </button>
                    </div>
                    <div class="docs-terminal-body">
                        <pre id="pmt-app-pre"><?= e($p_app) ?></pre>
                    </div>
                </div>

                <div class="pmt-uses">
                    <span class="pmt-tag"><?= icon('smartphone', 13) ?> Flutter</span>
                    <span class="pmt-tag"><?= icon('smartphone', 13) ?> React Native</span>
                    <span class="pmt-tag"><?= icon('external', 13) ?> UPI deep-link</span>
                </div>
            </div>
        </div>

        <div class="docs-section-panel <?= $current === 'telegram' ? 'is-active' : '' ?>" id="pmt-sec-telegram" data-pmt-sec="telegram">
            <div class="docs-card">
                <div class="pmt-prompt-head">
                    <span class="pmt-prompt-ic" style="--pmt-ic:#229ED9;--pmt-ic-bg:rgba(34,158,217,0.12);"><?= icon('send', 20) ?></span>
                    <div>
                        <h2 class="docs-section-heading" style="margin:0;">Telegram Bot</h2>
                        <p class="docs-section-sub" style="margin:2px 0 0;">Python selling bot jo UPI payment leta hai.</p>
                    </div>
                </div>

                <div class="pmt-note pmt-note-warn">
                    <?= icon('alert', 15) ?>
                    <div>
                        <b>QR SVG ko PNG me convert karo</b>
                        <p>Gateway QR <code class="mono">SVG</code> deta hai, aur Telegram <code class="mono">sendPhoto</code> SVG accept nahi karta. Prompt bot ko <b>upi_intent</b> se PNG QR (<code class="mono">qrcode[pil]</code>) banwata hai.</p>
                    </div>
                </div>

                <div class="docs-terminal">
                    <div class="docs-terminal-top">
                        <div class="docs-terminal-dots">
                            <span class="docs-dot red"></span>
                            <span class="docs-dot yellow"></span>
                            <span class="docs-dot green"></span>
                            <span class="docs-terminal-lang">AI PROMPT &middot; TELEGRAM BOT</span>
                        </div>
                        <button type="button" class="docs-copy-action" data-copy-target="#pmt-telegram-pre">
                            <?= icon('copy', 12) ?> <span>Copy Prompt</span>
                        </button>
                    </div>
                    <div class="docs-terminal-body">
                        <pre id="pmt-telegram-pre"><?= e($p_telegram) ?></pre>
                    </div>
                </div>

                <div class="pmt-uses">
                    <span class="pmt-tag"><?= icon('send', 13) ?> python-telegram-bot</span>
                    <span class="pmt-tag"><?= icon('code', 13) ?> qrcode[pil]</span>
                    <span class="pmt-tag"><?= icon('database', 13) ?> SQLite</span>
                </div>
            </div>
        </div>

        <div class="docs-pager" id="pmtBottomPager">
            <div id="pmtPagerPrevHolder">
                <?php if ($prevKey): ?>
                    <a href="?tab=<?= e($prevKey) ?>" data-pmt-tab="<?= e($prevKey) ?>" class="docs-pager-btn" id="pmtPagerPrevBtn">
                        <?= icon('arrow-right', 14, 'style="transform:rotate(180deg);"') ?>
                        <span id="pmtPagerPrevTitle"><?= e($sections[$prevKey]['title']) ?></span>
                    </a>
                <?php endif; ?>
            </div>
            <div id="pmtPagerNextHolder">
                <?php if ($nextKey): ?>
                    <a href="?tab=<?= e($nextKey) ?>" data-pmt-tab="<?= e($nextKey) ?>" class="docs-pager-btn primary" id="pmtPagerNextBtn">
                        <span id="pmtPagerNextTitle"><?= e($sections[$nextKey]['title']) ?></span>
                        <?= icon('arrow-right', 14) ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="docs-card" style="margin-top:24px;background:linear-gradient(135deg,#0b1329,#060914);border-color:rgba(59,130,246,0.25);box-shadow:0 12px 30px -10px rgba(0,0,0,0.4);color:#ffffff;">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap;">
                <div>
                    <div style="display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;letter-spacing:0.06em;color:#38bdf8;text-transform:uppercase;margin-bottom:8px;">
                        <?= icon('rocket', 14) ?>
                        <span>READY TO BUILD</span>
                    </div>
                    <h3 style="font-size:17px;font-weight:700;color:#ffffff;margin:0 0 6px;">Need the exact API reference too?</h3>
                    <p style="font-size:13.5px;color:#94a3b8;margin:0;max-width:540px;line-height:1.5;">
                        Endpoints, request parameters, webhook payloads aur error codes — sab kuch detail me developer docs me hai.
                    </p>
                </div>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <a href="<?= url('user/docs.php') ?>" class="btn btn-primary" style="font-size:13px;padding:9px 16px;">
                        <?= icon('book', 14) ?> Open API Docs
                    </a>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
