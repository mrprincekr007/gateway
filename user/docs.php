<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';

$user   = require_login();
$active = 'docs';
$activeNav = 'docs';

// Hide standard white heading bar to display glowing dark hero stage
$hidePageHeading = true;

$sections = [
    'overview'       => ['title' => 'Overview',        'icon' => 'book',         'desc' => 'Architecture & end-to-end payment lifecycle'],
    'quickstart'     => ['title' => 'Quickstart',      'icon' => 'zap',          'desc' => 'Generate your first dynamic order in 60s'],
    'authentication' => ['title' => 'Authentication',  'icon' => 'lock',         'desc' => 'Bearer token authorization & security'],
    'orders'         => ['title' => 'Create Order',    'icon' => 'plus',         'desc' => 'POST /api/create-order API reference'],
    'check'          => ['title' => 'Check Status',    'icon' => 'check-circle', 'desc' => 'Server verification & safe client polling'],
    'webhooks'       => ['title' => 'Webhooks',        'icon' => 'send',         'desc' => 'Instant payment notifications & HMAC-SHA256'],
    'events'         => ['title' => 'Events',          'icon' => 'bell',         'desc' => 'Dispatched event catalog & payload schemas'],
    'errors'         => ['title' => 'Errors',          'icon' => 'alert',        'desc' => 'Standard HTTP status codes & JSON errors'],
];

$current = get('page', 'overview');
if (!isset($sections[$current])) { 
    $current = 'overview'; 
}

$sectionKeys = array_keys($sections);
$currIndex   = array_search($current, $sectionKeys, true);
$prevKey     = $currIndex > 0 ? $sectionKeys[$currIndex - 1] : null;
$nextKey     = $currIndex < count($sectionKeys) - 1 ? $sectionKeys[$currIndex + 1] : null;

$api = url('api');

$pageTitle   = $sections[$current]['title'] . ' · API Documentation';
$pageHeading = 'Docs';
$pageSub     = 'Integrate ' . e(SITE_NAME) . ' payments into your applications.';
include __DIR__ . '/includes/header.php';
?>

<!-- GLOWING DARK HERO STAGE ("Kahi Kahi Pe Dark UI") -->
<div class="dash-hero-stage-dark" data-reveal>
    <div class="hero-stage-mesh-top" aria-hidden="true"></div>
    <div class="hero-stage-mesh-bottom" aria-hidden="true"></div>

    <div class="hero-stage-header">
        <div class="hero-eyebrow">
            <span class="dot"></span>
            <span>REST API V2.0 &middot; Developer Guide</span>
        </div>
        <div class="hero-sla-pill">
            <span class="sla-dot"></span>
            <span>JSON Schemas &middot; Live Code Samples</span>
        </div>
    </div>

    <div class="hero-stage-headline">
        <h1 class="dash-hero-heading">
            API <span class="accent">Documentation</span>
        </h1>
        <p class="dash-hero-desc">
            Complete technical reference for order creation, dynamic UPI QR generation, UTR reconciliation, and signed webhooks.
        </p>
    </div>

    <div class="hero-meta-ribbon">
        <div class="meta-ribbon-cell" title="API Base URL Endpoint">
            <div class="meta-cell-label">Base URL</div>
            <div class="meta-cell-value">
                <span class="val-upi"><?= e($api) ?></span>
                <button type="button" class="meta-copy-btn" data-copy="<?= e($api) ?>" aria-label="Copy Base URL">
                    <?= icon('copy', 12) ?>
                </button>
            </div>
        </div>

        <div class="meta-ribbon-cell" title="Authentication header standard">
            <div class="meta-cell-label">Authentication</div>
            <div class="meta-cell-value">
                <span class="meta-badge-ok"><span class="mini-beacon"></span> Bearer Token</span>
            </div>
        </div>

        <div class="meta-ribbon-cell" title="Dynamic QR payment window">
            <div class="meta-cell-label">QR Validity</div>
            <div class="meta-cell-value">
                <span class="val-rate">300s Dynamic Window</span>
            </div>
        </div>

        <div class="meta-ribbon-cell" title="Payload serialization format">
            <div class="meta-cell-label">Payload Format</div>
            <div class="meta-cell-value">
                <span class="val-accent">JSON / Multipart Form</span>
            </div>
        </div>
    </div>

    <div class="hero-stage-btns">
        <a href="<?= url('user/api-keys.php') ?>" class="btn-hero-primary">
            <?= icon('key', 15) ?> API Keys
        </a>
        <a href="<?= url('user/prompts.php') ?>" class="btn-hero-secondary">
            <?= icon('sparkles', 14) ?> AI Builder
        </a>
        <a href="<?= url('user/webhooks.php') ?>" class="btn-hero-secondary">
            <?= icon('send', 14) ?> Webhooks
        </a>
    </div>
</div>

<!-- 4 VIBRANT COLORFUL METRIC CARDS -->
<div class="dash-kpi-grid-light" data-reveal>
    <!-- KPI 1: Primary Endpoint -->
    <div class="light-kpi-card" style="--kpi-top-bar:#2563eb;--kpi-bubble-bg:rgba(37,99,235,0.12);--kpi-bubble-color:#2563eb;--kpi-bubble-glow:rgba(37,99,235,0.25);">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Create Order</span>
            <span class="kpi-icon-glow"><?= icon('zap', 18) ?></span>
        </div>
        <div class="kpi-number-display" style="font-size:16px;font-family:'JetBrains Mono',monospace;color:#1e40af;margin-bottom:8px;">
            POST /create-order
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill up">
                <?= icon('check', 11) ?> Active
            </span>
            <span>REST API</span>
        </div>
    </div>

    <!-- KPI 2: Auth Standard -->
    <div class="light-kpi-card" style="--kpi-top-bar:#7c3aed;--kpi-bubble-bg:rgba(124,58,237,0.12);--kpi-bubble-color:#7c3aed;--kpi-bubble-glow:rgba(124,58,237,0.25);">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Auth Standard</span>
            <span class="kpi-icon-glow"><?= icon('lock', 18) ?></span>
        </div>
        <div class="kpi-number-display" style="font-size:18px;font-family:'JetBrains Mono',monospace;color:#6d28d9;margin-bottom:8px;">
            Bearer &lt;KEY&gt;
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill up" style="background:rgba(124,58,237,0.1);color:#7c3aed;">
                <?= icon('shield', 11) ?> SHA-256
            </span>
            <span>HTTP Header</span>
        </div>
    </div>

    <!-- KPI 3: Order Validity -->
    <div class="light-kpi-card" style="--kpi-top-bar:#10b981;--kpi-bubble-bg:rgba(16,185,129,0.12);--kpi-bubble-color:#059669;--kpi-bubble-glow:rgba(16,185,129,0.25);">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Order Validity</span>
            <span class="kpi-icon-glow"><?= icon('clock', 18) ?></span>
        </div>
        <div class="kpi-number-display" style="font-size:20px;color:#059669;margin-bottom:8px;">
            300 Seconds
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill up">
                <?= icon('check', 11) ?> 5 Mins
            </span>
            <span>Matching</span>
        </div>
    </div>

    <!-- KPI 4: Webhook Signature -->
    <div class="light-kpi-card" style="--kpi-top-bar:#f59e0b;--kpi-bubble-bg:rgba(245,158,11,0.12);--kpi-bubble-color:#d97706;--kpi-bubble-glow:rgba(245,158,11,0.25);">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Webhook Signature</span>
            <span class="kpi-icon-glow"><?= icon('shield', 18) ?></span>
        </div>
        <div class="kpi-number-display" style="font-size:18px;font-family:'JetBrains Mono',monospace;color:#b45309;margin-bottom:8px;">
            HMAC-SHA256
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill up" style="background:rgba(245,158,11,0.1);color:#b45309;">
                <?= icon('shield', 11) ?> Signed
            </span>
            <span>Payload</span>
        </div>
    </div>
</div>

<!-- MOBILE HORIZONTAL SCROLLABLE PILL NAV (<= 900px) -->
<nav class="docs-mobile-nav" aria-label="Documentation sections navigation">
    <?php foreach ($sections as $key => $sec): ?>
        <a class="docs-mobile-pill <?= $current === $key ? 'is-active' : '' ?>" href="?page=<?= e($key) ?>" data-docs-tab="<?= e($key) ?>">
            <?= icon($sec['icon'], 14) ?>
            <span><?= e($sec['title']) ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<!-- MAIN DOCUMENTATION LAYOUT (Sticky Sidebar + Rich Content) -->
<div class="docs-layout-container">
    <!-- DESKTOP STICKY SIDEBAR -->
    <aside class="docs-sidebar-sticky">
        <div class="docs-sidebar-title">
            <?= icon('book', 14) ?>
            <span>API Reference</span>
        </div>
        <nav style="display:flex;flex-direction:column;">
            <?php foreach ($sections as $key => $sec): ?>
                <a class="docs-nav-item <?= $current === $key ? 'is-active' : '' ?>" href="?page=<?= e($key) ?>" data-docs-tab="<?= e($key) ?>">
                    <?= icon($sec['icon'], 16) ?>
                    <span><?= e($sec['title']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div style="margin-top:20px;padding:14px;background:var(--surface-sunken);border-radius:14px;border:1px solid var(--color-hairline);">
            <div style="font-size:12px;font-weight:700;color:var(--color-ink);display:flex;align-items:center;gap:6px;">
                <?= icon('key', 14, 'style="color:#2563eb;"') ?>
                <span>API Credentials</span>
            </div>
            <p style="font-size:12px;color:var(--color-ink-mute);margin:6px 0 10px;line-height:1.4;">
                Generate an active API key and start testing live requests.
            </p>
            <a href="<?= url('user/api-keys.php') ?>" class="btn btn-primary btn-sm" style="width:100%;justify-content:center;font-size:12px;">
                Manage Keys
            </a>
        </div>
    </aside>

    <!-- MAIN SECTION CONTENT AREA -->
    <div style="min-width:0;">

        <!-- SECTION: OVERVIEW -->
        <div class="docs-section-panel <?= $current === 'overview' ? 'is-active' : '' ?>" id="docs-sec-overview" data-docs-sec="overview">
            <div class="docs-card">
            <h2 class="docs-section-heading">
                <?= icon('book', 20, 'style="color:#2563eb;"') ?>
                Overview &amp; Architecture
            </h2>
            <p class="docs-section-sub">
                Initiate orders, render dynamic UPI QR codes, and receive real-time webhooks.
            </p>

            <div style="background:rgba(37,99,235,0.04);border:1px solid rgba(37,99,235,0.15);border-radius:12px;padding:12px 16px;margin-bottom:16px;">
                <div style="font-weight:700;color:#1e40af;font-size:13.5px;display:flex;align-items:center;gap:6px;">
                    <?= icon('shield', 15) ?>
                    <span>Direct Bank Settlement &middot; Zero Fees</span>
                </div>
                <p style="margin:4px 0 0;font-size:12px;color:#334155;line-height:1.45;">
                    Payments go directly to your bank account via UPI. Alerts are verified in real time.
                </p>
            </div>

            <h3 style="font-size:15px;font-weight:700;color:#0f172a;margin:18px 0 10px;">Payment Lifecycle</h3>
            <div class="docs-pipeline">
                <div class="docs-pipe-step">
                    <div class="docs-pipe-num">1</div>
                    <div class="docs-pipe-content">
                        <h4>Create Order</h4>
                        <p>POST /api/create-order with amount and UPI ID.</p>
                    </div>
                </div>

                <div class="docs-pipe-step">
                    <div class="docs-pipe-num">2</div>
                    <div class="docs-pipe-content">
                        <h4>Render QR / Checkout</h4>
                        <p>Display dynamic QR image or open hosted payment link.</p>
                    </div>
                </div>

                <div class="docs-pipe-step">
                    <div class="docs-pipe-num">3</div>
                    <div class="docs-pipe-content">
                        <h4>Customer Pays</h4>
                        <p>Customer completes transfer in GPay, PhonePe, Paytm, or BHIM.</p>
                    </div>
                </div>

                <div class="docs-pipe-step">
                    <div class="docs-pipe-num">4</div>
                    <div class="docs-pipe-content">
                        <h4>UTR Auto-Reconciliation</h4>
                        <p>Background engine extracts 12-digit UTR and marks order paid.</p>
                    </div>
                </div>

                <div class="docs-pipe-step">
                    <div class="docs-pipe-num">5</div>
                    <div class="docs-pipe-content">
                        <h4>Webhook Delivered</h4>
                        <p>Signed HMAC payload dispatched to your endpoint.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION: QUICKSTART -->
    <div class="docs-section-panel <?= $current === 'quickstart' ? 'is-active' : '' ?>" id="docs-sec-quickstart" data-docs-sec="quickstart">
        <div class="docs-card">
            <h2 class="docs-section-heading">
                <?= icon('zap', 20, 'style="color:#2563eb;"') ?>
                Quickstart Guide
            </h2>
            <p class="docs-section-sub">
                Create and test your first dynamic UPI payment session in less than 60 seconds.
            </p>

            <h3 style="font-size:15px;font-weight:700;color:#0f172a;margin:18px 0 8px;">Step 1: Create an Order</h3>
            <p style="font-size:13.5px;color:#64748b;margin-bottom:12px;">
                Execute the following request using cURL or your preferred programming language. Replace <code class="mono">YOUR_API_KEY</code> with your token from <a href="<?= url('user/api-keys.php') ?>" style="color:#2563eb;font-weight:600;">API Keys</a>.
            </p>

            <!-- MULTI-LANGUAGE CODE TERMINAL -->
            <div class="docs-terminal">
                <div class="docs-terminal-top">
                    <div class="docs-terminal-dots">
                        <span class="docs-dot red"></span>
                        <span class="docs-dot yellow"></span>
                        <span class="docs-dot green"></span>
                        <span class="docs-terminal-lang" id="qsLangLabel">cURL / BASH</span>
                    </div>
                    <div class="docs-code-tabs">
                        <button type="button" class="docs-code-tab-btn is-active" onclick="switchQsCode('curl', this)">cURL</button>
                        <button type="button" class="docs-code-tab-btn" onclick="switchQsCode('php', this)">PHP</button>
                        <button type="button" class="docs-code-tab-btn" onclick="switchQsCode('node', this)">Node.js</button>
                        <button type="button" class="docs-code-tab-btn" onclick="switchQsCode('python', this)">Python</button>
                    </div>
                    <button class="docs-copy-action" type="button" id="qsCopyBtn" data-copy='curl -X POST <?= e($api) ?>/create-order \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -d "amount=500" \
  -d "upi=<?= e($user['upi_id'] ?? 'merchant@upi') ?>"'>
                        <?= icon('copy', 12) ?> Copy
                    </button>
                </div>
                <div class="docs-terminal-body">
                    <!-- cURL Snippet -->
                    <pre id="qs-snippet-curl">curl -X POST <span class="code-str"><?= e($api) ?>/create-order</span> \
  -H <span class="code-str">"Authorization: Bearer YOUR_API_KEY"</span> \
  -d <span class="code-str">"amount=500"</span> \
  -d <span class="code-str">"upi=<?= e($user['upi_id'] ?? 'merchant@upi') ?>"</span> \
  -d <span class="code-str">"customer_name=Ravi Sharma"</span></pre>

                    <!-- PHP Snippet -->
                    <pre id="qs-snippet-php" style="display:none;"><span class="code-kw">&lt;?php</span>
<span class="code-fn">$ch</span> = <span class="code-fn">curl_init</span>(<span class="code-str">'<?= e($api) ?>/create-order'</span>);
<span class="code-fn">curl_setopt_array</span>(<span class="code-fn">$ch</span>, [
    <span class="code-fn">CURLOPT_POST</span>           =&gt; <span class="code-kw">true</span>,
    <span class="code-fn">CURLOPT_RETURNTRANSFER</span> =&gt; <span class="code-kw">true</span>,
    <span class="code-fn">CURLOPT_HTTPHEADER</span>     =&gt; [
        <span class="code-str">'Authorization: Bearer YOUR_API_KEY'</span>,
        <span class="code-str">'Content-Type: application/x-www-form-urlencoded'</span>
    ],
    <span class="code-fn">CURLOPT_POSTFIELDS</span>     =&gt; <span class="code-fn">http_build_query</span>([
        <span class="code-str">'amount'</span>        =&gt; <span class="code-num">500.00</span>,
        <span class="code-str">'upi'</span>           =&gt; <span class="code-str">'<?= e($user['upi_id'] ?? 'merchant@upi') ?>'</span>,
        <span class="code-str">'customer_name'</span> =&gt; <span class="code-str">'Ravi Sharma'</span>
    ])
]);
<span class="code-fn">$response</span> = <span class="code-fn">json_decode</span>(<span class="code-fn">curl_exec</span>(<span class="code-fn">$ch</span>), <span class="code-kw">true</span>);
<span class="code-fn">curl_close</span>(<span class="code-fn">$ch</span>);
<span class="code-fn">header</span>(<span class="code-str">'Location: '</span> . <span class="code-fn">$response</span>[<span class="code-str">'data'</span>][<span class="code-str">'checkout_url'</span>]);</pre>

                    <!-- Node.js Snippet -->
                    <pre id="qs-snippet-node" style="display:none;"><span class="code-kw">const</span> response = <span class="code-kw">await</span> <span class="code-fn">fetch</span>(<span class="code-str">'<?= e($api) ?>/create-order'</span>, {
  method: <span class="code-str">'POST'</span>,
  headers: {
    <span class="code-str">'Authorization'</span>: <span class="code-str">'Bearer YOUR_API_KEY'</span>,
    <span class="code-str">'Content-Type'</span>: <span class="code-str">'application/x-www-form-urlencoded'</span>
  },
  body: <span class="code-kw">new</span> <span class="code-fn">URLSearchParams</span>({
    amount: <span class="code-str">'500.00'</span>,
    upi: <span class="code-str">'<?= e($user['upi_id'] ?? 'merchant@upi') ?>'</span>,
    customer_name: <span class="code-str">'Ravi Sharma'</span>
  })
});
<span class="code-kw">const</span> resp = <span class="code-kw">await</span> response.<span class="code-fn">json</span>();
console.<span class="code-fn">log</span>(<span class="code-str">'Checkout URL:'</span>, resp.data.checkout_url);</pre>

                    <!-- Python Snippet -->
                    <pre id="qs-snippet-python" style="display:none;"><span class="code-kw">import</span> requests

payload = {
    <span class="code-str">'amount'</span>: <span class="code-str">'500.00'</span>,
    <span class="code-str">'upi'</span>: <span class="code-str">'<?= e($user['upi_id'] ?? 'merchant@upi') ?>'</span>,
    <span class="code-str">'customer_name'</span>: <span class="code-str">'Ravi Sharma'</span>
}
headers = {
    <span class="code-str">'Authorization'</span>: <span class="code-str">'Bearer YOUR_API_KEY'</span>
}

r = requests.<span class="code-fn">post</span>(<span class="code-str">'<?= e($api) ?>/create-order'</span>, data=payload, headers=headers)
resp = r.<span class="code-fn">json</span>()
print(<span class="code-str">'Checkout URL:'</span>, resp[<span class="code-str">'data'</span>][<span class="code-str">'checkout_url'</span>])</pre>
                </div>
            </div>

            <h3 style="font-size:15px;font-weight:700;color:#0f172a;margin:24px 0 8px;">Step 2: JSON Response (200 OK)</h3>
            <div class="docs-terminal">
                <div class="docs-terminal-top">
                    <div class="docs-terminal-dots">
                        <span class="docs-dot red"></span>
                        <span class="docs-dot yellow"></span>
                        <span class="docs-dot green"></span>
                        <span class="docs-terminal-lang">200 OK &middot; JSON</span>
                    </div>
                    <button class="docs-copy-action" type="button" data-copy='{
  "status": "success",
  "data": {
    "order_id": "fg_9F2C81B4",
    "amount": 500.00,
    "payable_amount": 500.00,
    "upi_id": "<?= e($user['upi_id'] ?? 'merchant@upi') ?>",
    "qr_url": "<?= e($api) ?>/qr-image.php?order_id=fg_9F2C81B4",
    "checkout_url": "<?= e(url('pay.php?order_id=fg_9F2C81B4')) ?>",
    "upi_intent": "upi://pay?pa=<?= urlencode($user['upi_id'] ?? 'merchant@upi') ?>&pn=<?= urlencode(SITE_NAME) ?>&am=500.00&tr=fg_9F2C81B4&cu=INR",
    "expires_at_ist": "<?= date('d-m-Y H:i:s', time() + 300) ?>"
  }
}'>
                        <?= icon('copy', 12) ?> Copy JSON
                    </button>
                </div>
                <div class="docs-terminal-body">
<pre><span class="code-pun">{</span>
  <span class="code-str">"status"</span><span class="code-pun">:</span> <span class="code-str">"success"</span><span class="code-pun">,</span>
  <span class="code-str">"data"</span><span class="code-pun">:</span> <span class="code-pun">{</span>
    <span class="code-str">"order_id"</span><span class="code-pun">:</span>       <span class="code-str">"fg_9F2C81B4"</span><span class="code-pun">,</span>
    <span class="code-str">"amount"</span><span class="code-pun">:</span>         <span class="code-num">500.00</span><span class="code-pun">,</span>
    <span class="code-str">"payable_amount"</span><span class="code-pun">:</span> <span class="code-num">500.00</span><span class="code-pun">,</span>
    <span class="code-str">"upi_id"</span><span class="code-pun">:</span>         <span class="code-str">"<?= e($user['upi_id'] ?? 'merchant@upi') ?>"</span><span class="code-pun">,</span>
    <span class="code-str">"qr_url"</span><span class="code-pun">:</span>         <span class="code-str">"<?= e($api) ?>/qr-image.php?order_id=fg_9F2C81B4"</span><span class="code-pun">,</span>
    <span class="code-str">"checkout_url"</span><span class="code-pun">:</span>   <span class="code-str">"<?= e(url('pay.php?order_id=fg_9F2C81B4')) ?>"</span><span class="code-pun">,</span>
    <span class="code-str">"upi_intent"</span><span class="code-pun">:</span>     <span class="code-str">"upi://pay?pa=<?= urlencode($user['upi_id'] ?? 'merchant@upi') ?>&amp;am=500.00&amp;tr=fg_9F2C81B4&amp;cu=INR"</span><span class="code-pun">,</span>
    <span class="code-str">"expires_at_ist"</span><span class="code-pun">:</span> <span class="code-str">"<?= date('d-m-Y H:i:s', time() + 300) ?>"</span>
  <span class="code-pun">}</span>
<span class="code-pun">}</span></pre>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION: AUTHENTICATION -->
    <div class="docs-section-panel <?= $current === 'authentication' ? 'is-active' : '' ?>" id="docs-sec-authentication" data-docs-sec="authentication">
        <div class="docs-card">
            <h2 class="docs-section-heading">
                <?= icon('lock', 20, 'style="color:#2563eb;"') ?>
                Authentication &amp; API Security
            </h2>
            <p class="docs-section-sub">
                All programmatic requests to protected endpoints require an active API key transmitted via HTTP Authorization headers.
            </p>

            <div class="docs-terminal">
                <div class="docs-terminal-top">
                    <div class="docs-terminal-dots">
                        <span class="docs-dot red"></span>
                        <span class="docs-dot yellow"></span>
                        <span class="docs-dot green"></span>
                        <span class="docs-terminal-lang">HTTP HEADER FORMAT</span>
                    </div>
                    <button class="docs-copy-action" type="button" data-copy="Authorization: Bearer YOUR_API_KEY">
                        <?= icon('copy', 12) ?> Copy Header
                    </button>
                </div>
                <div class="docs-terminal-body">
<pre><span class="code-kw">Authorization</span>: Bearer <span class="code-str">&lt;your_api_key&gt;</span>
<span class="code-kw">Content-Type</span>:  <span class="code-str">application/x-www-form-urlencoded</span></pre>
                </div>
            </div>

            <h3 style="font-size:16px;font-weight:700;color:var(--color-ink);margin:24px 0 12px;">Security &amp; Best Practices Checklist</h3>
            <div style="display:flex;flex-direction:column;gap:12px;">
                <div style="display:flex;align-items:flex-start;gap:12px;padding:12px 14px;background:var(--surface-sunken);border-radius:10px;border:1px solid var(--color-hairline);">
                    <span style="color:#10b981;margin-top:2px;"><?= icon('check-circle', 18) ?></span>
                    <div>
                        <b style="color:var(--color-ink);font-size:13.5px;">Keep Keys on Your Server</b>
                        <p style="margin:2px 0 0;font-size:12.5px;color:var(--color-ink-mute);">Never commit keys into frontend code (React, Vue, mobile apps) or GitHub public repositories.</p>
                    </div>
                </div>

                <div style="display:flex;align-items:flex-start;gap:12px;padding:12px 14px;background:var(--surface-sunken);border-radius:10px;border:1px solid var(--color-hairline);">
                    <span style="color:#10b981;margin-top:2px;"><?= icon('check-circle', 18) ?></span>
                    <div>
                        <b style="color:var(--color-ink);font-size:13.5px;">Missing Header Returns 401 Unauthorized</b>
                        <p style="margin:2px 0 0;font-size:12.5px;color:var(--color-ink-mute);">Requests without a valid Bearer token will immediately receive a 401 JSON error.</p>
                    </div>
                </div>

                <div style="display:flex;align-items:flex-start;gap:12px;padding:12px 14px;background:var(--surface-sunken);border-radius:10px;border:1px solid var(--color-hairline);">
                    <span style="color:#10b981;margin-top:2px;"><?= icon('check-circle', 18) ?></span>
                    <div>
                        <b style="color:var(--color-ink);font-size:13.5px;">Instant One-Click Key Revocation</b>
                        <p style="margin:2px 0 0;font-size:12.5px;color:var(--color-ink-mute);">If you suspect a key compromise, revoke it immediately in the <a href="<?= url('user/api-keys.php') ?>" style="color:#2563eb;font-weight:600;">API Keys</a> console.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION: ORDERS -->
    <div class="docs-section-panel <?= $current === 'orders' ? 'is-active' : '' ?>" id="docs-sec-orders" data-docs-sec="orders">
        <div class="docs-card">
            <h2 class="docs-section-heading">
                <?= icon('plus', 20, 'style="color:#2563eb;"') ?>
                Create an Order
            </h2>
            <p class="docs-section-sub">
                Initializes a dynamic UPI session and returns a QR URL, hosted checkout page, and deep Intent payload.
            </p>

            <div style="display:flex;align-items:center;gap:10px;padding:10px 16px;background:#080e22;border-radius:10px;border:1px solid rgba(59,130,246,0.3);margin-bottom:16px;">
                <span class="badge badge-success" style="font-weight:700;">POST</span>
                <span class="mono" style="color:#60a5fa;font-size:14px;font-weight:600;"><?= e($api) ?>/create-order</span>
            </div>

            <h3 style="font-size:15px;font-weight:700;color:#0f172a;margin:20px 0 10px;">Request Parameters</h3>
            <div class="docs-table-wrap">
                <table class="docs-param-table">
                    <thead>
                        <tr>
                            <th>Parameter</th>
                            <th>Type</th>
                            <th>Requirement</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><span class="param-name">amount</span></td>
                            <td><span class="param-type">float / int</span></td>
                            <td><span class="param-badge-req">Required</span></td>
                            <td>Order amount in INR (Minimum: ₹1.00, e.g. <code class="mono">500.00</code>).</td>
                        </tr>
                        <tr>
                            <td><span class="param-name">upi</span></td>
                            <td><span class="param-type">string</span></td>
                            <td><span class="param-badge-opt">Optional</span></td>
                            <td>Your merchant UPI ID. Defaults to your account UPI ID if omitted.</td>
                        </tr>
                        <tr>
                            <td><span class="param-name">customer_name</span></td>
                            <td><span class="param-type">string</span></td>
                            <td><span class="param-badge-opt">Optional</span></td>
                            <td>Customer's full name for checkout greeting and invoice (e.g. <code class="mono">Ravi Sharma</code>).</td>
                        </tr>
                        <tr>
                            <td><span class="param-name">customer_email</span></td>
                            <td><span class="param-type">string</span></td>
                            <td><span class="param-badge-opt">Optional</span></td>
                            <td>Customer email address for invoice and payment alerts.</td>
                        </tr>
                        <tr>
                            <td><span class="param-name">customer_phone</span></td>
                            <td><span class="param-type">string</span></td>
                            <td><span class="param-badge-opt">Optional</span></td>
                            <td>Customer 10-digit mobile number with or without +91 prefix.</td>
                        </tr>
                        <tr>
                            <td><span class="param-name">redirect_url</span></td>
                            <td><span class="param-type">url string</span></td>
                            <td><span class="param-badge-opt">Optional</span></td>
                            <td>Where the browser redirects after successful checkout payment.</td>
                        </tr>
                        <tr>
                            <td><span class="param-name">webhook_url</span></td>
                            <td><span class="param-type">url string</span></td>
                            <td><span class="param-badge-opt">Optional</span></td>
                            <td>Endpoint receiving the signed <code class="mono">payment.success</code> notification.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h3 style="font-size:15px;font-weight:700;color:var(--color-ink);margin:24px 0 8px;">GET Query &amp; QR Image Direct Endpoints</h3>
            <p style="font-size:13px;color:var(--color-ink-mute);line-height:1.6;margin-bottom:12px;">
                We provide lightweight endpoints for direct QR embedding inside HTML or mobile applications:
            </p>
            <div style="background:var(--surface-sunken);border:1px solid var(--color-hairline);border-radius:12px;padding:14px 16px;">
                <div style="margin-bottom:10px;">
                    <b style="font-size:13px;color:var(--color-ink);">1. Direct SVG QR Image URL:</b>
                    <div style="margin-top:4px;">
                        <code class="mono" style="font-size:12.5px;color:#2563eb;word-break:break-all;">GET <?= e($api) ?>/qr-image.php?order_id={ORDER_ID}</code>
                    </div>
                    <span style="font-size:12px;color:var(--color-ink-mute);">Embed directly into <code class="mono">&lt;img src="..."&gt;</code> tags without intermediate decoding.</span>
                </div>
                <div>
                    <b style="font-size:13px;color:var(--color-ink);">2. Query Param Alias:</b>
                    <div style="margin-top:4px;">
                        <code class="mono" style="font-size:12.5px;color:#2563eb;word-break:break-all;">GET <?= e($api) ?>/qr.php?api_key=YOUR_KEY&amp;amount=500</code>
                    </div>
                    <span style="font-size:12px;color:var(--color-ink-mute);">Returns the exact same JSON format as <code class="mono">/create-order</code>.</span>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION: CHECK STATUS -->
    <div class="docs-section-panel <?= $current === 'check' ? 'is-active' : '' ?>" id="docs-sec-check" data-docs-sec="check">
        <div class="docs-card">
            <h2 class="docs-section-heading">
                <?= icon('check-circle', 20, 'style="color:#2563eb;"') ?>
                Verify Payment Status
            </h2>
            <p class="docs-section-sub">
                Verify payment status via server-to-server calls or public client-side safe polling.
            </p>

            <h3 style="font-size:15px;font-weight:700;color:#0f172a;margin:18px 0 8px;">Method 1: Server-to-Server Verification</h3>
            <p style="font-size:13px;color:#64748b;margin-bottom:10px;">
                Protected endpoint returning full settlement metadata (including bank UTR, transaction ID, and sender name).
                For a lightweight status-only check, <code class="mono">GET <?= e($api) ?>/check-order.php?order_id=fg_9F2C81B4</code> returns the same JSON envelope with the core fields (<code class="mono">status</code>, <code class="mono">amount</code>, <code class="mono">utr</code>).
            </p>

            <div class="docs-terminal">
                <div class="docs-terminal-top">
                    <div class="docs-terminal-dots">
                        <span class="docs-dot red"></span>
                        <span class="docs-dot yellow"></span>
                        <span class="docs-dot green"></span>
                        <span class="docs-terminal-lang">GET /verify-order.php</span>
                    </div>
                    <button class="docs-copy-action" type="button" data-copy="GET <?= e($api) ?>/verify-order.php?order_id=fg_9F2C81B4">
                        <?= icon('copy', 12) ?> Copy Request
                    </button>
                </div>
                <div class="docs-terminal-body">
<pre><span class="code-kw">GET</span> <span class="code-str"><?= e($api) ?>/verify-order.php?order_id=fg_9F2C81B4</span>
<span class="code-kw">Authorization</span>: Bearer YOUR_API_KEY

<span class="code-com">// Response:</span>
<span class="code-pun">{</span>
  <span class="code-str">"status"</span><span class="code-pun">:</span>           <span class="code-str">"success"</span><span class="code-pun">,</span>
  <span class="code-str">"data"</span><span class="code-pun">:</span> <span class="code-pun">{</span>
    <span class="code-str">"order_id"</span><span class="code-pun">:</span>         <span class="code-str">"fg_9F2C81B4"</span><span class="code-pun">,</span>
    <span class="code-str">"status"</span><span class="code-pun">:</span>           <span class="code-str">"success"</span><span class="code-pun">,</span>
    <span class="code-str">"amount"</span><span class="code-pun">:</span>           <span class="code-num">500.00</span><span class="code-pun">,</span>
    <span class="code-str">"payable_amount"</span><span class="code-pun">:</span>   <span class="code-num">500.00</span><span class="code-pun">,</span>
    <span class="code-str">"transaction_id"</span><span class="code-pun">:</span>   <span class="code-str">"FMP987654321"</span><span class="code-pun">,</span>
    <span class="code-str">"utr"</span><span class="code-pun">:</span>              <span class="code-str">"415922837103"</span><span class="code-pun">,</span>
    <span class="code-str">"sender_name"</span><span class="code-pun">:</span>      <span class="code-str">"Rahul Sharma"</span><span class="code-pun">,</span>
    <span class="code-str">"payment_time_ist"</span><span class="code-pun">:</span> <span class="code-str">"<?= date('d-m-Y H:i:s') ?>"</span>
  <span class="code-pun">}</span>
<span class="code-pun">}</span></pre>
                </div>
            </div>

            <h3 style="font-size:15px;font-weight:700;color:#0f172a;margin:24px 0 8px;">Method 2: Safe Client-Side Polling (No Key Needed)</h3>
            <p style="font-size:13px;color:#64748b;margin-bottom:10px;">
                Use the lightweight public polling endpoint from browser JavaScript without exposing your secret API key. Returns status: <code class="mono">pending</code>, <code class="mono">success</code>, or <code class="mono">expired</code>:
            </p>

            <div class="docs-terminal">
                <div class="docs-terminal-top">
                    <div class="docs-terminal-dots">
                        <span class="docs-dot red"></span>
                        <span class="docs-dot yellow"></span>
                        <span class="docs-dot green"></span>
                        <span class="docs-terminal-lang">JAVASCRIPT CLIENT POLLING</span>
                    </div>
                    <button class="docs-copy-action" type="button" data-copy="const pollInterval = setInterval(async () => {
  const res = await fetch('<?= e($api) ?>/checkout-status.php?order_id=fg_9F2C81B4');
  const data = await res.json();
  if (data.status === 'success') {
    clearInterval(pollInterval);
    alert('Payment Received! UTR: ' + data.utr);
  }
}, 3000);">
                        <?= icon('copy', 12) ?> Copy Code
                    </button>
                </div>
                <div class="docs-terminal-body">
<pre><span class="code-kw">const</span> timer = <span class="code-fn">setInterval</span>(<span class="code-kw">async</span> () =&gt; {
  <span class="code-kw">const</span> res = <span class="code-kw">await</span> <span class="code-fn">fetch</span>(<span class="code-str">'<?= e($api) ?>/checkout-status.php?order_id='</span> + orderId);
  <span class="code-kw">const</span> data = <span class="code-kw">await</span> res.<span class="code-fn">json</span>();
  
  <span class="code-kw">if</span> (data.status === <span class="code-str">'success'</span>) {
    <span class="code-fn">clearInterval</span>(timer);
    <span class="code-fn">showSuccessScreen</span>(data.utr);
  } <span class="code-kw">else if</span> (data.status === <span class="code-str">'expired'</span>) {
    <span class="code-fn">clearInterval</span>(timer);
    <span class="code-fn">showExpiredScreen</span>();
  }
}, <span class="code-num">3000</span>); <span class="code-com">// Recommended interval: 3 to 5 seconds</span></pre>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION: WEBHOOKS -->
    <div class="docs-section-panel <?= $current === 'webhooks' ? 'is-active' : '' ?>" id="docs-sec-webhooks" data-docs-sec="webhooks">
        <div class="docs-card">
            <h2 class="docs-section-heading">
                <?= icon('send', 20, 'style="color:#2563eb;"') ?>
                Webhooks &amp; Signature Verification
            </h2>
            <p class="docs-section-sub">
                Whenever a customer's payment settles, our engine immediately pushes a signed HTTP POST JSON payload to your webhook endpoint.
            </p>

            <h3 style="font-size:15px;font-weight:700;color:#0f172a;margin:18px 0 8px;">Webhook JSON Payload (<code class="mono">payment.success</code>)</h3>
            <div class="docs-terminal">
                <div class="docs-terminal-top">
                    <div class="docs-terminal-dots">
                        <span class="docs-dot red"></span>
                        <span class="docs-dot yellow"></span>
                        <span class="docs-dot green"></span>
                        <span class="docs-terminal-lang">POST PAYLOAD &middot; APPLICATION/JSON</span>
                    </div>
                    <button class="docs-copy-action" type="button" data-copy='{
  "event": "payment.success",
  "order_id": "fg_9F2C81B4",
  "amount": 500.00,
  "payable_amount": 500.00,
  "status": "success",
  "transaction_id": "FMP987654321",
  "utr": "415922837103",
  "sender_name": "Rahul Sharma",
  "payment_time": "<?= date('d-m-Y H:i:s') ?>",
  "timestamp": <?= time() ?>
}'>
                        <?= icon('copy', 12) ?> Copy Payload
                    </button>
                </div>
                <div class="docs-terminal-body">
<pre><span class="code-pun">{</span>
  <span class="code-str">"event"</span><span class="code-pun">:</span>          <span class="code-str">"payment.success"</span><span class="code-pun">,</span>
  <span class="code-str">"order_id"</span><span class="code-pun">:</span>       <span class="code-str">"fg_9F2C81B4"</span><span class="code-pun">,</span>
  <span class="code-str">"amount"</span><span class="code-pun">:</span>         <span class="code-num">500.00</span><span class="code-pun">,</span>
  <span class="code-str">"payable_amount"</span><span class="code-pun">:</span> <span class="code-num">500.00</span><span class="code-pun">,</span>
  <span class="code-str">"status"</span><span class="code-pun">:</span>         <span class="code-str">"success"</span><span class="code-pun">,</span>
  <span class="code-str">"transaction_id"</span><span class="code-pun">:</span> <span class="code-str">"FMP987654321"</span><span class="code-pun">,</span>
  <span class="code-str">"utr"</span><span class="code-pun">:</span>            <span class="code-str">"415922837103"</span><span class="code-pun">,</span>
  <span class="code-str">"sender_name"</span><span class="code-pun">:</span>    <span class="code-str">"Rahul Sharma"</span><span class="code-pun">,</span>
  <span class="code-str">"payment_time"</span><span class="code-pun">:</span>   <span class="code-str">"<?= date('d-m-Y H:i:s') ?>"</span><span class="code-pun">,</span>
  <span class="code-str">"timestamp"</span><span class="code-pun">:</span>      <span class="code-num"><?= time() ?></span>
<span class="code-pun">}</span></pre>
                </div>
            </div>

            <h3 style="font-size:15px;font-weight:700;color:#0f172a;margin:24px 0 8px;">HMAC-SHA256 Signature Verification</h3>
            <p style="font-size:13px;color:#64748b;line-height:1.6;margin-bottom:12px;">
                Every request carries the HTTP header <code class="mono">X-<?= e(SITE_NAME) ?>-Signature</code>. Verify this signature before fulfilling digital goods to prevent replay or spoofing attacks:
            </p>

            <div class="docs-terminal">
                <div class="docs-terminal-top">
                    <div class="docs-terminal-dots">
                        <span class="docs-dot red"></span>
                        <span class="docs-dot yellow"></span>
                        <span class="docs-dot green"></span>
                        <span class="docs-terminal-lang">PHP VERIFICATION SNIPPET</span>
                    </div>
                    <button class="docs-copy-action" type="button" data-copy="<?php
echo htmlspecialchars(<<<'EOD'
$rawBody   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_FATHER_GATEWAY_SIGNATURE'] ?? '';
$secret    = 'YOUR_WEBHOOK_SECRET';

$expectedSignature = hash_hmac('sha256', $rawBody, $secret);

if (!hash_equals($expectedSignature, $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}

$payload = json_decode($rawBody, true);
if ($payload['event'] === 'payment.success') {
    // Deliver credits / mark order paid in your DB!
}

http_response_code(200);
echo json_encode(['status' => 'received']);
EOD
);
?>">
                        <?= icon('copy', 12) ?> Copy Code
                    </button>
                </div>
                <div class="docs-terminal-body">
<pre><span class="code-kw">&lt;?php</span>
<span class="code-fn">$rawBody</span>   = <span class="code-fn">file_get_contents</span>(<span class="code-str">'php://input'</span>);
<span class="code-fn">$signature</span> = <span class="code-fn">$_SERVER</span>[<span class="code-str">'HTTP_X_<?= strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', SITE_NAME)) ?>_SIGNATURE'</span>] ?? <span class="code-str">''</span>;
<span class="code-fn">$secret</span>    = <span class="code-str">'YOUR_WEBHOOK_SECRET'</span>;

<span class="code-fn">$expectedSignature</span> = <span class="code-fn">hash_hmac</span>(<span class="code-str">'sha256'</span>, <span class="code-fn">$rawBody</span>, <span class="code-fn">$secret</span>);

<span class="code-kw">if</span> (!<span class="code-fn">hash_equals</span>(<span class="code-fn">$expectedSignature</span>, <span class="code-fn">$signature</span>)) {
    <span class="code-fn">http_response_code</span>(<span class="code-num">401</span>);
    <span class="code-kw">exit</span>(<span class="code-str">'Invalid signature'</span>);
}

<span class="code-fn">$payload</span> = <span class="code-fn">json_decode</span>(<span class="code-fn">$rawBody</span>, <span class="code-kw">true</span>);
<span class="code-kw">if</span> (<span class="code-fn">$payload</span>[<span class="code-str">'event'</span>] === <span class="code-str">'payment.success'</span>) {
    <span class="code-com">// 1. Mark order paid in database</span>
    <span class="code-com">// 2. Deliver product / service to user</span>
}

<span class="code-fn">http_response_code</span>(<span class="code-num">200</span>);
<span class="code-kw">echo</span> <span class="code-fn">json_encode</span>([<span class="code-str">'status'</span> =&gt; <span class="code-str">'received'</span>]);</pre>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION: EVENTS -->
    <div class="docs-section-panel <?= $current === 'events' ? 'is-active' : '' ?>" id="docs-sec-events" data-docs-sec="events">
        <div class="docs-card">
            <h2 class="docs-section-heading">
                <?= icon('bell', 20, 'style="color:#2563eb;"') ?>
                Dispatched Event Catalog
            </h2>
            <p class="docs-section-sub">
                Complete list of webhook lifecycle triggers dispatched to your registered endpoints.
            </p>

            <div class="docs-table-wrap">
                <table class="docs-param-table">
                    <thead>
                        <tr>
                            <th>Event Name</th>
                            <th>Trigger Description</th>
                            <th>Retry Policy</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <span class="param-name" style="color:#059669;background:rgba(16,185,129,0.08);">payment.success</span>
                            </td>
                            <td>
                                <b>Payment Received &amp; Reconciled.</b> Fired when customer completes payment and the 12-digit UTR is matched with bank alerts. Contains full order, transaction ID, and customer name.
                            </td>
                            <td>
                                <b>Up to 3 Retries</b> with exponential backoff if your endpoint returns non-2xx status.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div style="margin-top:20px;padding:16px;background:var(--surface-sunken);border-radius:12px;border:1px solid var(--color-hairline);">
                <b style="font-size:13.5px;color:var(--color-ink);">Why are there no <code class="mono">payment.expired</code> webhooks?</b>
                <p style="margin:6px 0 0;font-size:13px;color:var(--color-ink-mute);line-height:1.6;">
                    To protect merchant servers from excessive webhook traffic during shopping cart abandonments, our gateway does not flood webhooks on expired sessions. Sessions naturally expire after 300 seconds if unpaid.
                </p>
            </div>
        </div>
    </div>

    <!-- SECTION: ERRORS -->
    <div class="docs-section-panel <?= $current === 'errors' ? 'is-active' : '' ?>" id="docs-sec-errors" data-docs-sec="errors">
        <div class="docs-card">
            <h2 class="docs-section-heading">
                <?= icon('alert', 20, 'style="color:#ef4444;"') ?>
                Error Codes &amp; Standard Responses
            </h2>
            <p class="docs-section-sub">
                Our REST API uses standard HTTP response codes accompanied by informative JSON error envelopes.
            </p>

            <div class="docs-table-wrap">
                <table class="docs-param-table">
                    <thead>
                        <tr>
                            <th>HTTP Code</th>
                            <th>Error Status</th>
                            <th>Description &amp; Suggested Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><span class="mono" style="font-weight:700;color:#ef4444;">400</span></td>
                            <td><code class="mono">error</code></td>
                            <td><b>Bad Request.</b> Missing or invalid parameters (e.g. <code class="mono">amount</code> is missing/&le;&nbsp;0, exceeds the single transaction limit, or no UPI ID is available). Check parameter names.</td>
                        </tr>
                        <tr>
                            <td><span class="mono" style="font-weight:700;color:#f59e0b;">401</span></td>
                            <td><code class="mono">unauthorized</code></td>
                            <td><b>Invalid API Key.</b> Missing or invalid <code class="mono">Authorization: Bearer &lt;KEY&gt;</code> header.</td>
                        </tr>
                        <tr>
                            <td><span class="mono" style="font-weight:700;color:#e11d48;">402</span></td>
                            <td><code class="mono">payment_required</code></td>
                            <td><b>Plan Required.</b> Your subscription has no remaining transaction allowance or is not active. Subscribe or upgrade a plan to continue.</td>
                        </tr>
                        <tr>
                            <td><span class="mono" style="font-weight:700;color:#dc2626;">403</span></td>
                            <td><code class="mono">suspended</code></td>
                            <td><b>Account Inactive.</b> Merchant account has been suspended or disabled by administrator.</td>
                        </tr>
                        <tr>
                            <td><span class="mono" style="font-weight:700;color:#64748b;">404</span></td>
                            <td><code class="mono">not_found</code></td>
                            <td><b>Resource Not Found.</b> Specified order ID or requested API route does not exist.</td>
                        </tr>
                        <tr>
                            <td><span class="mono" style="font-weight:700;color:#f59e0b;">429</span></td>
                            <td><code class="mono">error</code></td>
                            <td><b>Rate Limit Exceeded.</b> Too many requests submitted in a short interval. Back off and retry.</td>
                        </tr>
                        <tr>
                            <td><span class="mono" style="font-weight:700;color:#ef4444;">500</span></td>
                            <td><code class="mono">error</code></td>
                            <td><b>Internal Gateway Error.</b> An unexpected error occurred on the gateway server.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h3 style="font-size:15px;font-weight:700;color:#0f172a;margin:24px 0 8px;">Standard Error Response Body</h3>
            <div class="docs-terminal">
                <div class="docs-terminal-top">
                    <div class="docs-terminal-dots">
                        <span class="docs-dot red"></span>
                        <span class="docs-dot yellow"></span>
                        <span class="docs-dot green"></span>
                        <span class="docs-terminal-lang">ERROR JSON ENVELOPE</span>
                    </div>
                </div>
                <div class="docs-terminal-body">
<pre><span class="code-pun">{</span>
  <span class="code-str">"status"</span><span class="code-pun">:</span>  <span class="code-str">"error"</span><span class="code-pun">,</span>
  <span class="code-str">"message"</span><span class="code-pun">:</span> <span class="code-str">"Invalid API key provided. Verify Authorization header."</span><span class="code-pun">,</span>
  <span class="code-str">"code"</span><span class="code-pun">:</span>    <span class="code-num">401</span>
<span class="code-pun">}</span></pre>
                </div>
            </div>
        </div>
    </div>

        <!-- NAVIGATION PAGER (Previous / Next Buttons) -->
        <div class="docs-pager" id="docsBottomPager">
            <div id="docsPagerPrevHolder">
                <?php if ($prevKey): ?>
                    <a href="?page=<?= e($prevKey) ?>" data-docs-tab="<?= e($prevKey) ?>" class="docs-pager-btn" id="docsPagerPrevBtn">
                        <?= icon('arrow-right', 14, 'style="transform:rotate(180deg);"') ?>
                        <span id="docsPagerPrevTitle"><?= e($sections[$prevKey]['title']) ?></span>
                    </a>
                <?php endif; ?>
            </div>
            <div id="docsPagerNextHolder">
                <?php if ($nextKey): ?>
                    <a href="?page=<?= e($nextKey) ?>" data-docs-tab="<?= e($nextKey) ?>" class="docs-pager-btn primary" id="docsPagerNextBtn">
                        <span id="docsPagerNextTitle"><?= e($sections[$nextKey]['title']) ?></span>
                        <?= icon('arrow-right', 14) ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- DEVELOPER READY CALLOUT CARD -->
        <div class="docs-card" style="margin-top:24px;background:linear-gradient(135deg,#0b1329,#060914);border-color:rgba(59,130,246,0.25);box-shadow:0 12px 30px -10px rgba(0,0,0,0.4);color:#ffffff;">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap;">
                <div>
                    <div style="display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;letter-spacing:0.06em;color:#38bdf8;text-transform:uppercase;margin-bottom:8px;">
                        <?= icon('key', 14) ?>
                        <span>INTEGRATION READY</span>
                    </div>
                    <h3 style="font-size:17px;font-weight:700;color:#ffffff;margin:0 0 6px;">Ready to generate your first live API key?</h3>
                    <p style="font-size:13.5px;color:#94a3b8;margin:0;max-width:540px;line-height:1.5;">
                        Generate a live API key in seconds. Every key grants full read/write access to the API with HMAC-verified webhooks and zero setup latency.
                    </p>
                </div>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <a href="<?= url('user/api-keys.php') ?>" class="btn btn-primary" style="font-size:13px;padding:9px 16px;">
                        <?= icon('key', 14) ?> Create API Key
                    </a>
                    <a href="<?= url('user/webhooks.php') ?>" class="btn btn-secondary" style="font-size:13px;padding:9px 16px;background:rgba(255,255,255,0.08);border-color:rgba(255,255,255,0.15);color:#ffffff;">
                        <?= icon('send', 14) ?> Add Webhook
                    </a>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- CLIENT JAVASCRIPT FOR CODE TAB SWITCHING -->
<script>
function switchQsCode(lang, btn) {
    document.querySelectorAll('.docs-code-tab-btn').forEach(b => b.classList.remove('is-active'));
    btn.classList.add('is-active');

    const snippets = {
        'curl': document.getElementById('qs-snippet-curl'),
        'php': document.getElementById('qs-snippet-php'),
        'node': document.getElementById('qs-snippet-node'),
        'python': document.getElementById('qs-snippet-python')
    };

    const labelMap = {
        'curl': 'cURL / BASH',
        'php': 'PHP / cURL GUZZLE',
        'node': 'NODE.JS / FETCH',
        'python': 'PYTHON / REQUESTS'
    };

    Object.keys(snippets).forEach(k => {
        if (snippets[k]) snippets[k].style.display = (k === lang) ? 'block' : 'none';
    });

    const lbl = document.getElementById('qsLangLabel');
    if (lbl && labelMap[lang]) lbl.textContent = labelMap[lang];

    const cp = document.getElementById('qsCopyBtn');
    if (cp && snippets[lang]) {
        cp.setAttribute('data-copy', snippets[lang].innerText || snippets[lang].textContent);
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>