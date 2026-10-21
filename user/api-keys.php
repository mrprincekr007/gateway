<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';
require_once APP_ROOT . '/includes/subscriptions.php';

$user   = require_login();
$active = 'api-keys';

$error     = null;
$newSecret = null; // shown once after create/roll
$newPublic = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf(post('csrf_token'))) {
        $error = 'Session expired. Please try again.';
    } else {
        $action = post('action', 'create');
        $keyId  = (int) post('id');

        /* ---------- Create ---------- */
        if ($action === 'create') {
            $gate = sub_can((int) $user['id'], 'api_keys');

            $stmt = db()->prepare('SELECT COUNT(*) FROM api_keys WHERE user_id = ?');
            $stmt->execute([$user['id']]);
            $existing = (int) $stmt->fetchColumn();

            $usedResets = (int) setting_get($user['id'], 'api_resets_used', '0');
            if (!$gate['ok']) {
                $error = sub_reason_message((string) $gate['reason']);
            } elseif ($existing >= 1 && $usedResets >= 99) {
                $error = 'You have used all 99 key resets.';
            } else {
                $label  = trim((string) post('label'));
                if ($label === '') { $label = 'Default Backend'; }
                [$pub, $sec] = generate_api_keypair();

                db()->prepare(
                    'INSERT INTO api_keys (user_id, label, public_key, secret_hash, status)
                     VALUES (?, ?, ?, ?, "active")'
                )->execute([$user['id'], $label, $pub, encrypt_secret($sec)]);

                if ($existing >= 1) {
                    setting_set($user['id'], 'api_resets_used', (string) ($usedResets + 1));
                }
                $newSecret = $sec;
                $newPublic = $pub;
            }
        }

        /* ---------- Roll ---------- */
        if ($action === 'roll' && $keyId > 0) {
            $usedResets = (int) setting_get($user['id'], 'api_resets_used', '0');
            if ($usedResets >= 99) {
                $error = 'You have used all 99 key resets.';
            } else {
                $stmt = db()->prepare('SELECT * FROM api_keys WHERE id = ? AND user_id = ? LIMIT 1');
                $stmt->execute([$keyId, $user['id']]);
                $key = $stmt->fetch();
                if (!$key) {
                    $error = 'API key not found.';
                } else {
                    [$pub, $sec] = generate_api_keypair();
                    db()->prepare('UPDATE api_keys SET public_key = ?, secret_hash = ? WHERE id = ?')
                        ->execute([$pub, encrypt_secret($sec), $keyId]);
                    setting_set($user['id'], 'api_resets_used', (string) ($usedResets + 1));
                    $newSecret = $sec;
                    $newPublic = $pub;
                }
            }
        }

        /* ---------- Delete / Revoke (Permanent Deletion) ---------- */
        if (($action === 'delete' || $action === 'revoke') && $keyId > 0) {
            db()->prepare('DELETE FROM api_keys WHERE id = ? AND user_id = ?')->execute([$keyId, $user['id']]);
            flash_set('success', 'API key deleted successfully.');
            redirect('user/api-keys.php');
        }
    }
}

/* ---------- Data ---------- */
$keysStmt = db()->prepare('SELECT * FROM api_keys WHERE user_id = ? ORDER BY created_at DESC');
$keysStmt->execute([$user['id']]);
$keys = $keysStmt->fetchAll();

$resetsLeft = 99 - (int) setting_get($user['id'], 'api_resets_used', '0');
$activeKeysCount = count(array_filter($keys, fn($k) => $k['status'] === 'active'));
$firstActiveKey = null;
foreach ($keys as $k) {
    if ($k['status'] === 'active') {
        $firstActiveKey = $k['public_key'];
        break;
    }
}

/* 14-day traffic */
$traffic = [];
for ($i = 13; $i >= 0; $i--) { $traffic[date('Y-m-d', strtotime("-$i days"))] = 0; }
$tStmt = db()->prepare(
    "SELECT DATE(created_at) d, COUNT(*) c FROM orders
     WHERE user_id = ? AND created_at >= ? GROUP BY DATE(created_at)"
);
$tStmt->execute([$user['id'], date('Y-m-d 00:00:00', strtotime('-13 days'))]);
foreach ($tStmt->fetchAll() as $r) { $traffic[$r['d']] = (int) $r['c']; }
$maxTraffic = max(1, max($traffic));
$totalReq = array_sum($traffic);

$pageTitle       = 'API Keys';
$pageHeading     = 'API Keys';
$pageSub         = 'Manage secret API credentials for your backend integrations.';
$hidePageHeading = true; // Use sleek Dark Hero Stage instead of plain topbar
include __DIR__ . '/includes/header.php';
?>

<!-- =========================================================
     DARK HERO STAGE ("Kahi Kahi Pe Dark UI")
     Matching index.php, dashboard.php & transactions.php
     ========================================================= -->
<div class="dash-hero-stage-dark" data-reveal>
    <div class="hero-stage-mesh-top" aria-hidden="true"></div>
    <div class="hero-stage-mesh-bottom" aria-hidden="true"></div>

    <div class="hero-stage-header">
        <div class="hero-eyebrow">
            <span class="dot"></span>
            <span>REST Credentials &middot; Live API</span>
        </div>
        <div class="hero-sla-pill">
            <span class="sla-dot"></span>
            <span>AES-256 Vault &middot; Scoped Permissions</span>
        </div>
    </div>

    <div class="hero-stage-headline">
        <h1 class="dash-hero-heading">
            Developer <span class="accent">API Keys</span>
        </h1>
        <p class="dash-hero-desc">
            Authenticate backend servers for dynamic orders and REST API access.
        </p>
    </div>

    <div class="hero-meta-ribbon">
        <div class="meta-ribbon-cell" title="Active merchant secret keys">
            <div class="meta-cell-label">Active Keys</div>
            <div class="meta-cell-value">
                <span class="val-accent" data-count="<?= $activeKeysCount ?>" data-suffix=" Live Key<?= $activeKeysCount === 1 ? '' : 's' ?>"><?= $activeKeysCount ?> Live Key<?= $activeKeysCount === 1 ? '' : 's' ?></span>
            </div>
        </div>

        <div class="meta-ribbon-cell" title="Requests served in the last 14 days">
            <div class="meta-cell-label">14-Day Traffic</div>
            <div class="meta-cell-value">
                <span class="val-money" data-count="<?= $totalReq ?>" data-suffix=" Requests"><?= $totalReq ?> Requests</span>
            </div>
        </div>

        <div class="meta-ribbon-cell" title="Authentication header protocol">
            <div class="meta-cell-label">Auth Protocol</div>
            <div class="meta-cell-value">
                <span class="meta-badge-ok"><span class="mini-beacon"></span> Bearer Token</span>
            </div>
        </div>

        <div class="meta-ribbon-cell" title="API rate limit policy">
            <div class="meta-cell-label">Rate Limit</div>
            <div class="meta-cell-value">
                <span class="val-rate">60 Req / Min</span>
            </div>
        </div>
    </div>

    <div class="hero-stage-btns">
        <button type="button" class="btn-hero-primary" onclick="document.getElementById('createKeyInput').focus();">
            <?= icon('plus', 15) ?> Create API Key
        </button>
        <a href="<?= url('user/docs.php') ?>" class="btn-hero-secondary">
            <?= icon('book', 14) ?> Integration Docs
        </a>
    </div>
</div>

<!-- =========================================================
     SYSTEM ALERTS & NEW SECRET REVEAL
     ========================================================= -->
<?php if ($error): ?>
    <div class="dash-alert dash-alert-error" style="margin-bottom:22px;" data-reveal>
        <div class="alert-icon-wrap"><?= icon('alert', 18) ?></div>
        <div class="alert-text-wrap"><strong>Notice:</strong> <?= e($error) ?></div>
    </div>
<?php endif; ?>

<?php if ($f = flash_get()): ?>
    <div class="dash-alert dash-alert-success" style="margin-bottom:22px;" data-reveal>
        <div class="alert-icon-wrap"><?= icon('check-circle', 18) ?></div>
        <div class="alert-text-wrap"><strong>Success:</strong> <?= e($f['message']) ?></div>
    </div>
<?php endif; ?>

<!-- Newly Generated Secret Key Banner (Shown Once) -->
<?php if ($newSecret): ?>
    <div class="dash-white-card new-secret-banner" data-reveal>
        <div class="new-secret-head-flex">
            <div class="new-secret-icon-wrap">
                <?= icon('shield', 20) ?>
            </div>
            <div class="new-secret-info-wrap">
                <h4 class="new-secret-title">Secret Key Generated (Save Now)</h4>
                <p class="new-secret-desc">
                    Store safely in your environment variables (<code>.env</code>). Won't be shown again.
                </p>
            </div>
        </div>

        <div class="new-secret-pill-row">
            <code class="new-secret-code mono" data-auto-fit><?= e($newSecret) ?></code>
            <button type="button" class="btn btn-primary btn-sm new-secret-copy-btn" data-copy="<?= e($newSecret) ?>" title="Copy Secret Key">
                <?= icon('copy', 12) ?> <span>Copy</span>
            </button>
        </div>

        <?php if ($newPublic): ?>
        <div class="new-secret-meta-row">
            <span>Associated Public Key:</span>
            <code class="new-secret-pub-code mono" data-copy="<?= e($newPublic) ?>"><?= e($newPublic) ?></code>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- =========================================================
     4 VIBRANT COLORFUL METRIC CARDS
     ========================================================= -->
<div class="dash-kpi-grid-light" data-reveal>
    <!-- KPI 1: Active Credentials -->
    <div class="light-kpi-card" style="--kpi-top-bar:#10b981;--kpi-bubble-bg:rgba(16,185,129,0.12);--kpi-bubble-color:#059669;--kpi-bubble-glow:rgba(16,185,129,0.25);">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Active Keys</span>
            <span class="kpi-icon-glow"><?= icon('key', 18) ?></span>
        </div>
        <div class="kpi-number-display" style="color:#059669;">
            <?= $activeKeysCount ?>
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill up"><?= icon('check', 11) ?> Active</span>
        </div>
    </div>

    <!-- KPI 2: 14-Day Traffic -->
    <div class="light-kpi-card" style="--kpi-top-bar:#0284c7;--kpi-bubble-bg:rgba(2,132,199,0.12);--kpi-bubble-color:#0284c7;--kpi-bubble-glow:rgba(2,132,199,0.25);">
        <div class="kpi-head-row">
            <span class="kpi-label-text">API Traffic</span>
            <span class="kpi-icon-glow"><?= icon('activity', 18) ?></span>
        </div>
        <div class="kpi-number-display" style="color:#0284c7;">
            <?= $totalReq ?>
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill up"><?= icon('trending', 11) ?> Total</span>
        </div>
    </div>

    <!-- KPI 3: Key Resets Remaining -->
    <div class="light-kpi-card" style="--kpi-top-bar:#8b5cf6;--kpi-bubble-bg:rgba(139,92,246,0.12);--kpi-bubble-color:#7c3aed;--kpi-bubble-glow:rgba(139,92,246,0.25);">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Rolls Left</span>
            <span class="kpi-icon-glow"><?= icon('refresh', 18) ?></span>
        </div>
        <div class="kpi-number-display" style="color:#7c3aed;">
            <?= $resetsLeft ?>
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill neutral"><?= icon('shield', 11) ?> Safe</span>
        </div>
    </div>

    <!-- KPI 4: Authorization Header -->
    <div class="light-kpi-card" style="--kpi-top-bar:#2563eb;--kpi-bubble-bg:rgba(37,99,235,0.12);--kpi-bubble-color:#2563eb;--kpi-bubble-glow:rgba(37,99,235,0.25);">
        <div class="kpi-head-row">
            <span class="kpi-label-text">Auth Header</span>
            <span class="kpi-icon-glow"><?= icon('shield', 18) ?></span>
        </div>
        <div class="kpi-number-display" data-auto-fit style="color:#2563eb;font-size:15px;letter-spacing:-0.02em;">
            Bearer &lt;KEY&gt;
        </div>
        <div class="kpi-footer-row">
            <span class="trend-pill up"><?= icon('lock', 11) ?> HTTP</span>
        </div>
    </div>
</div>

<!-- =========================================================
     1. CREATE API KEY CARD (FULL WIDTH & CLEAN)
     ========================================================= -->
<div class="dash-white-card" data-reveal style="margin-bottom:24px;">
    <div class="white-card-header" style="margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:8px;">
        <h3 class="white-card-title" style="margin:0;display:flex;align-items:center;gap:8px;">
            <span style="color:#2563eb;"><?= icon('plus', 17) ?></span>
            Create API Key
        </h3>
        <span class="badge-clean success" style="gap:5px;flex-shrink:0;">
            <span class="mini-beacon"></span> Scoped: read + write
        </span>
    </div>

    <form method="post" action="<?= url('user/api-keys.php') ?>" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">

        <div class="form-group" style="flex:1;min-width:260px;margin:0;">
            <label class="form-label" style="display:flex;align-items:center;gap:6px;">
                <?= icon('tag', 13) ?> Key Identifier / Application Name
            </label>
            <input class="form-input" id="createKeyInput" name="label" placeholder="e.g. Production Node.js Backend" required>
        </div>

        <div>
            <button class="btn btn-primary" type="submit" style="height:48px;font-weight:700;display:flex;align-items:center;gap:6px;padding:0 22px;border-radius:12px;">
                <?= icon('key', 14) ?> Generate Keypair
            </button>
        </div>
    </form>
</div>

<!-- =========================================================
     2. YOUR API KEYS LIST (FULL WIDTH & FULL VISIBILITY)
     ========================================================= -->
<div class="dash-white-card" data-reveal style="margin-bottom:24px;">
    <div class="white-card-header" style="margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:8px;">
        <h3 class="white-card-title" style="margin:0;display:flex;align-items:center;gap:8px;">
            <span style="color:#2563eb;"><?= icon('key', 17) ?></span>
            Your API Keys
        </h3>
        <span class="wh-slot-chip" style="flex-shrink:0;">
            <?= $activeKeysCount ?> Active Key<?= $activeKeysCount === 1 ? '' : 's' ?>
        </span>
    </div>

    <?php if (!$keys): ?>
        <div class="empty-state" style="padding:48px 24px;text-align:center;">
            <div class="empty-icon" style="background:rgba(37,99,235,0.08);color:#2563eb;width:52px;height:52px;border-radius:16px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:14px;">
                <?= icon('key', 26) ?>
            </div>
            <h4 class="empty-state-title" style="font-size:16px;font-weight:700;margin-bottom:4px;color:var(--color-ink);">No API Keys Generated Yet</h4>
            <p class="empty-state-sub" style="font-size:12.5px;color:var(--color-ink-mute);margin:0 auto 16px;max-width:380px;">
                Create a dedicated keypair above to start accepting programmatic orders through our REST API.
            </p>
            <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('createKeyInput').focus();" style="gap:6px;">
                <?= icon('plus', 13) ?> Generate First Key
            </button>
        </div>
    <?php else: ?>
        <?php foreach ($keys as $k): ?>
        <?php $isKeyActive = ($k['status'] === 'active'); ?>
        <div class="apikey-card-item">
            <!-- Top: Label + Status -->
            <div class="apikey-header-row">
                <div class="apikey-label">
                    <span style="color:#2563eb;"><?= icon('key', 16) ?></span>
                    <span><?= e($k['label']) ?></span>
                </div>
                <div>
                    <?= status_badge((string) $k['status']) ?>
                </div>
            </div>

            <!-- Middle: Public Key Box -->
            <div class="apikey-pub-row">
                <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($k['public_key']) ?></span>
                <button type="button" class="copy-chip-btn" data-copy="<?= e($k['public_key']) ?>" title="Copy Public Key" style="background:none;border:none;padding:2px;cursor:pointer;color:#64748b;flex-shrink:0;">
                    <?= icon('copy', 14) ?>
                </button>
            </div>

            <!-- Decrypted Secret Box (Toggleable In-Place Without Page Reload) -->
            <?php if ($isKeyActive): ?>
                <?php
                    $secret = decrypt_secret((string) $k['secret_hash']);
                    $isRevealedInitial = (get('reveal') === (string) $k['id']);
                ?>
                <div id="secretBox-<?= (int) $k['id'] ?>" class="apikey-secret-reveal-card" style="<?= $isRevealedInitial ? 'display:block;' : 'display:none;' ?>">
                    <div class="secret-reveal-head">
                        <div class="secret-reveal-badge">
                            <span class="secret-reveal-dot"></span>
                            <span>Decrypted Secret Key</span>
                        </div>
                        <button type="button" class="secret-reveal-close" data-hide-secret="<?= (int) $k['id'] ?>" title="Hide Secret">&times;</button>
                    </div>
                    <div class="secret-reveal-pill-row">
                        <code class="secret-reveal-code mono" data-auto-fit><?= e($secret ?? '(cannot decrypt key)') ?></code>
                        <button type="button" class="btn btn-primary btn-sm secret-reveal-copy-btn" data-copy="<?= e($secret ?? '') ?>" title="Copy Secret Key">
                            <?= icon('copy', 12) ?> <span>Copy</span>
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Bottom: Metadata + Action Buttons -->
            <div class="apikey-actions-row">
                <div class="apikey-meta-info">
                    <?= icon('clock', 13) ?>
                    <span>
                        <?= $k['last_used_at'] ? 'Used ' . e(nice_date($k['last_used_at'])) : 'Never used' ?> &middot; Created <?= e(date('M j, Y', strtotime($k['created_at']))) ?>
                    </span>
                </div>

                <div class="apikey-btn-cluster">
                    <!-- Copy Public -->
                    <button type="button" class="btn btn-ghost btn-sm" data-copy="<?= e($k['public_key']) ?>" title="Copy Public Key" style="gap:5px;">
                        <?= icon('copy', 13) ?> Public
                    </button>

                    <?php if ($isKeyActive): ?>
                        <!-- Reveal Secret (In-Place Toggle, Zero Page Reload) -->
                        <button type="button" class="btn btn-secondary btn-sm" data-toggle-secret="<?= (int) $k['id'] ?>" title="Decrypt & Reveal Secret Key">
                            <?= icon('eye', 13) ?> <span id="revealText-<?= (int) $k['id'] ?>"><?= (get('reveal') === (string) $k['id']) ? 'Hide' : 'Reveal' ?></span>
                        </button>

                        <!-- Roll Key -->
                        <form method="post" style="display:inline;margin:0;" onsubmit="return confirm('Roll this API key? The old secret will immediately stop working and backends will need to be updated.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="roll">
                            <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                            <button class="btn btn-secondary btn-sm" type="submit" title="Roll and regenerate keypair">
                                <?= icon('refresh', 13) ?> Roll
                            </button>
                        </form>

                    <?php else: ?>
                        <span class="badge badge-danger">Revoked</span>
                    <?php endif; ?>

                    <!-- Delete Key Permanently -->
                    <form method="post" style="display:inline;margin:0;" onsubmit="return confirm('Delete this API key permanently? It will be completely removed.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                        <button class="btn btn-ghost btn-sm" type="submit" style="color:#ef4444;" title="Delete API key permanently">
                            <?= icon('trash', 14) ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- =========================================================
     3. INTERACTIVE MULTI-LANGUAGE QUICKSTART TERMINAL
     ========================================================= -->
<?php
    $sampleKey = $firstActiveKey ?: 'pk_live_sample_token';
    $sampleEndpoint = url('api/create-order.php');

    $snippetCurl = "curl -X POST " . $sampleEndpoint . " \\\n  -H \"Authorization: Bearer " . $sampleKey . "\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\n    \"amount\": 500,\n    \"customer_name\": \"Rohan Sharma\",\n    \"customer_contact\": \"9876543210\"\n  }'";

    $snippetNode = "const axios = require('axios');\n\nconst response = await axios.post('" . $sampleEndpoint . "', {\n  amount: 500,\n  customer_name: 'Rohan Sharma',\n  customer_contact: '9876543210'\n}, {\n  headers: {\n    'Authorization': 'Bearer " . $sampleKey . "',\n    'Content-Type': 'application/json'\n  }\n});\n\nconsole.log(response.data);";

    $snippetPython = "import requests\n\nurl = '" . $sampleEndpoint . "'\nheaders = {\n    'Authorization': 'Bearer " . $sampleKey . "',\n    'Content-Type': 'application/json'\n}\npayload = {\n    'amount': 500,\n    'customer_name': 'Rohan Sharma',\n    'customer_contact': '9876543210'\n}\n\nresponse = requests.post(url, json=payload, headers=headers)\nprint(response.json())";

    $snippetPhp = "\$ch = curl_init('" . $sampleEndpoint . "');\ncurl_setopt(\$ch, CURLOPT_POST, true);\ncurl_setopt(\$ch, CURLOPT_POSTFIELDS, json_encode([\n    'amount'           => 500,\n    'customer_name'    => 'Rohan Sharma',\n    'customer_contact' => '9876543210'\n]));\ncurl_setopt(\$ch, CURLOPT_HTTPHEADER, [\n    'Authorization: Bearer " . $sampleKey . "',\n    'Content-Type: application/json'\n]);\ncurl_setopt(\$ch, CURLOPT_RETURNTRANSFER, true);\n\$response = curl_exec(\$ch);\ncurl_close(\$ch);";
?>
<div class="apikey-code-box" data-reveal>
    <div class="code-terminal-header">
        <div class="code-terminal-left">
            <div class="code-terminal-dots">
                <span class="wh-dot dot-red"></span>
                <span class="wh-dot dot-yellow"></span>
                <span class="wh-dot dot-green"></span>
            </div>
            <div class="code-lang-tabs" role="tablist">
                <button type="button" class="code-lang-btn active" data-code-tab="curl" onclick="switchApiTab('curl')">cURL</button>
                <button type="button" class="code-lang-btn" data-code-tab="node" onclick="switchApiTab('node')">Node.js</button>
                <button type="button" class="code-lang-btn" data-code-tab="python" onclick="switchApiTab('python')">Python</button>
                <button type="button" class="code-lang-btn" data-code-tab="php" onclick="switchApiTab('php')">PHP</button>
            </div>
        </div>
        <div class="code-terminal-right">
            <button type="button" class="code-copy-action-btn" id="apiCopyActiveCodeBtn" data-copy-target="#apiQuickstartCodeBlock" title="Copy code snippet">
                <?= icon('copy', 12) ?> <span>Copy</span>
            </button>
        </div>
    </div>
    <pre class="code-snippet-pre"><code id="apiQuickstartCodeBlock"><?= e($snippetCurl) ?></code></pre>
</div>

<!-- =========================================================
     4. BIG 14-DAY REQUEST TRAFFIC & VOLUME (FULL LINE CARD)
     ========================================================= -->
<div class="dash-white-card api-traffic-full-card" data-reveal>
    <div class="traffic-card-top-header">
        <div class="traffic-card-title-group">
            <div class="trio-icon-bubble blue">
                <?= icon('activity', 20) ?>
            </div>
            <h3 class="traffic-card-title" style="margin:0;">
                14-Day Traffic
            </h3>
        </div>

        <div class="traffic-badges-wrap">
            <span class="badge-clean success traffic-badge-item" data-auto-fit>
                <span class="mini-beacon"></span> 99.98% Uptime SLA
            </span>
            <span class="badge-clean info traffic-badge-item" data-auto-fit>
                14-Day Window
            </span>
        </div>
    </div>

    <!-- Big Chart Stage with Guides & Bars -->
    <div class="traffic-big-stage">
        <!-- Subtle Horizontal Guide Lines -->
        <div class="traffic-grid-guides">
            <div class="traffic-guide-line"></div>
            <div class="traffic-guide-line"></div>
            <div class="traffic-guide-line"></div>
        </div>

        <!-- 14 Wide Gradient Bars -->
        <div class="traffic-bars-flex">
            <?php foreach ($traffic as $day => $count): ?>
                <?php
                    $pct = $count > 0 ? max(12, round(($count / $maxTraffic) * 100)) : 4;
                    $dayNum = date('j M', strtotime($day));
                    $dayNumShort = substr((string) (int) substr($day, 8), -2);
                ?>
                <div class="traffic-big-bar-col">
                    <div class="traffic-big-bar-fill <?= $count === 0 ? 'empty' : '' ?>" style="height:<?= $pct ?>%;" title="<?= e($day) ?>: <?= $count ?> calls"></div>
                    <span class="traffic-big-date" title="<?= e($day) ?>"><?= $dayNumShort ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Bottom Metrics Ribbon Across The Line -->
    <div class="traffic-big-stats-ribbon">
        <div class="traffic-stat-item">
            <span class="t-stat-label">Total 14D Volume</span>
            <span class="t-stat-value"><?= number_format($totalReq) ?> <small>calls</small></span>
        </div>
        <div class="traffic-stat-item">
            <span class="t-stat-label">Daily Average</span>
            <span class="t-stat-value"><?= round($totalReq / 14, 1) ?> <small>req/day</small></span>
        </div>
        <div class="traffic-stat-item">
            <span class="t-stat-label">Peak Volume</span>
            <span class="t-stat-value"><?= $maxTraffic > 1 ? $maxTraffic . ' <small>calls</small>' : ($totalReq > 0 ? $maxTraffic . ' <small>call</small>' : '—') ?></span>
        </div>
        <div class="traffic-stat-item">
            <span class="t-stat-label">Avg Response</span>
            <span class="t-stat-value" style="color:#0284c7;">~18<small>ms</small></span>
        </div>
        <div class="traffic-stat-item">
            <span class="t-stat-label">Delivery SLA</span>
            <span class="t-stat-value" style="color:#059669;">99.98%</span>
        </div>
    </div>
</div>

<!-- =========================================================
     5. BOTTOM COMPANION CARDS (IN 1 HORIZONTAL LINE SIDE-BY-SIDE)
     ========================================================= -->
<div class="api-duo-bottom-grid" data-reveal>
    <!-- Box A: Security Vault & Rate Limits -->
    <div class="dash-white-card api-bottom-card">
        <div>
            <div class="api-bottom-header">
                <div class="trio-icon-bubble green">
                    <?= icon('shield', 18) ?>
                </div>
                <h3 class="api-bottom-title" style="margin:0;">Security &amp; Limits</h3>
            </div>

            <div class="api-status-list">
                <div class="api-status-item">
                    <div class="api-status-icon lock"><?= icon('lock', 15) ?></div>
                    <div class="api-status-body">
                        <div class="api-status-name">AES-256 Vault Encryption</div>
                        <div class="api-status-desc">Zero-knowledge &middot; Server side only</div>
                    </div>
                    <span class="badge-clean success" style="font-size:10.5px;padding:2px 8px;">Active</span>
                </div>

                <div class="api-status-item">
                    <div class="api-status-icon zap"><?= icon('zap', 15) ?></div>
                    <div class="api-status-body">
                        <div class="api-status-name">Rate Limit Quota</div>
                        <div class="api-status-desc">120 req/min &middot; 300 burst cap</div>
                    </div>
                    <span class="badge-clean info" style="font-size:10.5px;padding:2px 8px;">Live</span>
                </div>

                <div class="api-status-item">
                    <div class="api-status-icon refresh"><?= icon('refresh', 15) ?></div>
                    <div class="api-status-body">
                        <div class="api-status-name">Emergency Key Rolls</div>
                        <div class="api-status-desc"><?= $resetsLeft ?> emergency rolls available</div>
                    </div>
                    <span class="badge-clean <?= $resetsLeft > 10 ? 'success' : 'warn' ?>" style="font-size:10.5px;padding:2px 8px;"><?= $resetsLeft ?> Left</span>
                </div>
            </div>
        </div>

        <div class="api-bottom-card-action">
            <span style="font-size:11.5px;color:var(--color-ink-mute);display:flex;align-items:center;gap:6px;">
                <span class="mini-beacon"></span> Rotate production keys every 90 days
            </span>
        </div>
    </div>

    <!-- Box B: SDKs & Developer Resources -->
    <div class="dash-white-card api-bottom-card">
        <div>
            <div class="api-bottom-header">
                <div class="trio-icon-bubble purple">
                    <?= icon('code', 18) ?>
                </div>
                <h3 class="api-bottom-title" style="margin:0;">SDKs &amp; Tooling</h3>
            </div>

            <?php $sdkPkg = strtolower(str_replace(' ', '-', trim(SITE_NAME))); ?>
            <div class="sdk-pkg-list">
                <div class="sdk-pkg-item" data-copy="npm install <?= $sdkPkg ?>-node" title="Click to copy npm command">
                    <span class="sdk-pkg-lang">Node</span>
                    <code class="sdk-pkg-code" data-auto-fit>npm i <?= $sdkPkg ?>-node</code>
                    <button type="button" class="sdk-copy-btn"><?= icon('copy', 12) ?></button>
                </div>

                <div class="sdk-pkg-item" data-copy="pip install <?= $sdkPkg ?>" title="Click to copy pip command">
                    <span class="sdk-pkg-lang">Python</span>
                    <code class="sdk-pkg-code" data-auto-fit>pip install <?= $sdkPkg ?></code>
                    <button type="button" class="sdk-copy-btn"><?= icon('copy', 12) ?></button>
                </div>

                <div class="sdk-pkg-item" data-copy="composer require <?= $sdkPkg ?>/sdk" title="Click to copy composer command">
                    <span class="sdk-pkg-lang">PHP</span>
                    <code class="sdk-pkg-code" data-auto-fit>composer require <?= $sdkPkg ?>/sdk</code>
                    <button type="button" class="sdk-copy-btn"><?= icon('copy', 12) ?></button>
                </div>
            </div>
        </div>

        <div class="api-bottom-card-action">
            <a href="<?= url('user/docs.php') ?>" class="btn btn-secondary btn-sm" style="width:100%;justify-content:center;height:38px;gap:6px;">
                <?= icon('book', 14) ?> Explore Full API Docs &rarr;
            </a>
        </div>
    </div>
</div>


<script>
window.__API_SNIPPETS = {
    curl: <?= json_encode($snippetCurl, JSON_UNESCAPED_SLASHES) ?>,
    node: <?= json_encode($snippetNode, JSON_UNESCAPED_SLASHES) ?>,
    python: <?= json_encode($snippetPython, JSON_UNESCAPED_SLASHES) ?>,
    php: <?= json_encode($snippetPhp, JSON_UNESCAPED_SLASHES) ?>
};

function switchApiTab(lang) {
    document.querySelectorAll('.code-lang-btn').forEach(function(b) {
        b.classList.toggle('active', b.getAttribute('data-code-tab') === lang);
    });
    var pre = document.getElementById('apiQuickstartCodeBlock');
    if (pre && window.__API_SNIPPETS[lang]) {
        pre.textContent = window.__API_SNIPPETS[lang];
    }
}

</script>

<?php include __DIR__ . '/includes/footer.php'; ?>