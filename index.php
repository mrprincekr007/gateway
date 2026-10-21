<?php
declare(strict_types=1);

require __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/qr.php';
require_once APP_ROOT . '/includes/subscriptions.php';

$pageTitle = SITE_NAME . ' — ' . SITE_TAGLINE;
$bodyClass = 'landing has-glow';
include APP_ROOT . '/includes/header.php';

$demoUpi = 'merchant@fathergateway';
$currency = defined('APP_CURRENCY') ? APP_CURRENCY : (defined('CURRENCY_SYMBOL') && is_string(CURRENCY_SYMBOL) ? CURRENCY_SYMBOL : '₹');
?>

<header class="landing-nav">
    <div class="container nav-inner">
        <a class="brand" href="<?= url('index.php') ?>">
            <img src="<?= asset('assets/img/logo.png') ?>" alt="<?= e(SITE_NAME) ?>" class="brand-logo-img">
            <span class="logo-text"><?= e(SITE_NAME) ?></span>
        </a>

        <nav class="nav-links">
            <a href="#features">Features</a>
            <a href="#how-it-works">How it works</a>
            <a href="#api">API</a>
            <a href="#pricing">Pricing</a>
            <a href="#faq">FAQ</a>
        </nav>

        <div class="nav-actions">
            <?php if (is_logged_in()): ?>
                <a class="btn btn-primary btn-sm" href="<?= url('user/dashboard.php') ?>">Dashboard</a>
            <?php else: ?>
                <a class="nav-login" href="<?= url('auth.php') ?>">Sign in</a>
                <a class="btn btn-primary btn-sm" href="<?= url('auth.php?mode=signup') ?>">Get started</a>
            <?php endif; ?>
            <button class="nav-burger" id="navBurger" type="button" aria-label="Menu" aria-expanded="false">
                <span></span><span></span><span></span>
            </button>
        </div>
    </div>
    <div class="mobile-nav" id="mobileNav">
        <a href="#features"><span class="mn-ic"><?= icon('layout-dashboard', 17) ?></span><span class="mn-label">Features</span><span class="mn-arrow"><?= icon('chevron-right', 16) ?></span></a>
        <a href="#how-it-works"><span class="mn-ic"><?= icon('activity', 17) ?></span><span class="mn-label">How it works</span><span class="mn-arrow"><?= icon('chevron-right', 16) ?></span></a>
        <a href="#api"><span class="mn-ic"><?= icon('plug', 17) ?></span><span class="mn-label">API</span><span class="mn-arrow"><?= icon('chevron-right', 16) ?></span></a>
        <a href="#pricing"><span class="mn-ic"><?= icon('rupee', 17) ?></span><span class="mn-label">Pricing</span><span class="mn-arrow"><?= icon('chevron-right', 16) ?></span></a>
        <a href="#faq"><span class="mn-ic"><?= icon('message', 17) ?></span><span class="mn-label">FAQ</span><span class="mn-arrow"><?= icon('chevron-right', 16) ?></span></a>
        <div class="mobile-nav-actions">
            <?php if (is_logged_in()): ?>
                <a class="btn btn-primary mobile-nav-btn" href="<?= url('user/dashboard.php') ?>">Dashboard</a>
            <?php else: ?>
                <a class="btn btn-secondary mobile-nav-btn" href="<?= url('auth.php') ?>">Sign in</a>
                <a class="btn btn-primary mobile-nav-btn" href="<?= url('auth.php?mode=signup') ?>">Get started</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<!-- ================= HERO ================= -->
<section class="hero">
    <canvas id="hero3d" class="hero-3d" aria-hidden="true"></canvas>
    <div class="container">
        <div class="hero-grid">
            <div class="hero-copy">
                <span class="eyebrow"><span class="dot"></span> P2P UPI Engine</span>
                <h1 class="hero-title split-text-container" data-text-animate="word" aria-label="Direct UPI payments. Zero gateway fees.">
                    <span class="split-line"><span class="split-mask"><span class="split-word" style="--i:0;">Direct&nbsp;</span></span><span class="split-mask"><span class="split-word" style="--i:1;">UPI&nbsp;</span></span><span class="split-mask"><span class="split-word" style="--i:2;">payments.</span></span></span><br><span class="split-line"><span class="accent"><span class="split-mask"><span class="split-word" style="--i:3;">Zero&nbsp;</span></span><span class="split-mask"><span class="split-word" style="--i:4;">gateway&nbsp;</span></span><span class="split-mask"><span class="split-word" style="--i:5;">fees.</span></span></span></span>
                </h1>
                <p class="hero-sub">Direct bank settlement. Dynamic QR codes and automated webhooks.</p>

                <div class="hero-actions" style="display: flex; align-items: center; gap: 12px; position: relative; z-index: 10;">
                    <?php if (is_logged_in()): ?>
                    <a class="btn btn-primary btn-lg" href="<?= url('user/dashboard.php') ?>">
                        Go to Dashboard
                        <?= icon('arrow-right', 16) ?>
                    </a>
                    <?php else: ?>
                    <a class="btn btn-primary btn-lg" href="<?= url('register.php') ?>">
                        Get Started Free
                        <?= icon('arrow-right', 16) ?>
                    </a>
                    <?php endif; ?>
                    <a class="btn btn-secondary btn-lg" href="#api">API Docs</a>
                </div>

                <div class="hero-stats">
                    <div class="hero-stat">
                        <div class="hero-stat-num">0%</div>
                        <div class="hero-stat-label">Fee</div>
                    </div>
                    <span class="hero-sep"></span>
                    <div class="hero-stat">
                        <div class="hero-stat-num">&lt;3s</div>
                        <div class="hero-stat-label">UTR Match</div>
                    </div>
                    <span class="hero-sep"></span>
                    <div class="hero-stat">
                        <div class="hero-stat-num">100%</div>
                        <div class="hero-stat-label">Direct Bank</div>
                    </div>
                </div>
            </div>

            <div class="hero-stage">
                <div class="checkout">
                    <div class="checkout-head">
                        <span class="gw-brand">
                            <img src="<?= asset('assets/img/logo.png') ?>" alt="<?= e(SITE_NAME) ?>" style="width:16px;height:16px;object-fit:contain;vertical-align:middle;margin-right:6px;">
                            <?= e(SITE_NAME) ?> Checkout
                        </span>
                        <span class="badge wait"><?= icon('clock', 11) ?> Awaiting</span>
                    </div>

                    <div class="checkout-label">Amount due</div>
                    <div class="checkout-amt"><?= e($currency) ?>1,250<small>.00</small></div>

                    <div class="pay-rows">
                        <div class="pay-row">
                            <span class="pay-row-key">Pay to</span>
                            <span class="pay-row-val"><?= e($demoUpi) ?></span>
                        </div>
                        <div class="pay-row">
                            <span class="pay-row-key">Order ID</span>
                            <span class="pay-row-val">fg_9F2C81B4</span>
                        </div>
                        <div class="pay-row">
                            <span class="pay-row-key">Scan to pay</span>
                            <span class="qr-box"><?= qr_svg('upi://pay?pa=' . rawurlencode($demoUpi) . '&pn=' . rawurlencode(SITE_NAME) . '&am=1250.00&cu=INR&tn=fg_9F2C81B4', 3, 1) ?></span>
                        </div>
                    </div>

                    <button class="checkout-cta" type="button">
                        <?= icon('scan-line', 16) ?>
                        Verify payment
                    </button>
                </div>

                <div class="chip chip-1">
                    <?= icon('check-circle', 15) ?> Webhook delivered
                </div>
                <div class="chip chip-2">
                    <?= icon('activity', 15) ?> UTR captured
                </div>

                <div class="hero-toast" id="heroToast" data-cur="<?= e($currency) ?>" aria-live="polite">
                    <span class="hero-toast-ic"><?= icon('check-circle', 17) ?></span>
                    <div>
                        <div class="hero-toast-t">Payment received</div>
                        <div class="hero-toast-s"><?= e($currency) ?>1,250.00 &middot; just now</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ================= STATS (floats over hero) ================= -->
<div class="stats">
    <div class="container">
        <div class="stats-card">
            <div class="stat">
                <div class="stat-num"><span data-count="100" data-suffix="%">100%</span></div>
                <div class="stat-label">UPI Match</div>
            </div>
            <div class="stat">
                <div class="stat-num"><span data-prefix="&lt;" data-count="5" data-suffix="s">&lt;5s</span></div>
                <div class="stat-label">Status Poll</div>
            </div>
            <div class="stat">
                <div class="stat-num"><span data-count="300" data-suffix="s">300s</span></div>
                <div class="stat-label">Order Expiry</div>
            </div>
            <div class="stat">
                <div class="stat-num">24<span class="suffix">/7</span></div>
                <div class="stat-label">Webhook Delivery</div>
            </div>
        </div>
    </div>
</div>

<!-- ================= MARQUEE ================= -->
<div class="marquee" aria-hidden="true">
    <div class="marquee-track">
        <div class="marquee-group">
            <span class="marquee-item"><?= icon('link', 16) ?> Payment links</span>
            <span class="marquee-item"><?= icon('rupee', 16) ?> Any UPI ID</span>
            <span class="marquee-item"><?= icon('send', 16) ?> Signed webhooks</span>
            <span class="marquee-item"><?= icon('credit-card', 16) ?> Dynamic QR</span>
            <span class="marquee-item"><?= icon('activity', 16) ?> UTR match</span>
            <span class="marquee-item"><?= icon('shield', 16) ?> Zero fees</span>
        </div>
        <div class="marquee-group">
            <span class="marquee-item"><?= icon('link', 16) ?> Payment links</span>
            <span class="marquee-item"><?= icon('rupee', 16) ?> Any UPI ID</span>
            <span class="marquee-item"><?= icon('send', 16) ?> Signed webhooks</span>
            <span class="marquee-item"><?= icon('credit-card', 16) ?> Dynamic QR</span>
            <span class="marquee-item"><?= icon('activity', 16) ?> UTR match</span>
            <span class="marquee-item"><?= icon('shield', 16) ?> Zero fees</span>
        </div>
        <div class="marquee-group">
            <span class="marquee-item"><?= icon('link', 16) ?> Payment links</span>
            <span class="marquee-item"><?= icon('rupee', 16) ?> Any UPI ID</span>
            <span class="marquee-item"><?= icon('send', 16) ?> Signed webhooks</span>
            <span class="marquee-item"><?= icon('credit-card', 16) ?> Dynamic QR</span>
            <span class="marquee-item"><?= icon('activity', 16) ?> UTR match</span>
            <span class="marquee-item"><?= icon('shield', 16) ?> Zero fees</span>
        </div>
        <div class="marquee-group">
            <span class="marquee-item"><?= icon('link', 16) ?> Payment links</span>
            <span class="marquee-item"><?= icon('rupee', 16) ?> Any UPI ID</span>
            <span class="marquee-item"><?= icon('send', 16) ?> Signed webhooks</span>
            <span class="marquee-item"><?= icon('credit-card', 16) ?> Dynamic QR</span>
            <span class="marquee-item"><?= icon('activity', 16) ?> UTR match</span>
            <span class="marquee-item"><?= icon('shield', 16) ?> Zero fees</span>
        </div>
    </div>
</div>

<!-- ================= FEATURES ================= -->
<section class="section" id="features">
    <div class="container">
        <div class="sec-head" data-reveal>
            <span class="kicker">Features</span>
            <h2 class="sec-title">Powerful features. Zero complexity.</h2>
        </div>

        <div class="bento">
            <div class="bento-card wide">
                <span class="bento-idx">01</span>
                <span class="bento-ic"><?= icon('link', 20) ?></span>
                <h3>Instant Payment Links</h3>
                <p>Branded checkout links &amp; dynamic QR in seconds.</p>
            </div>
            <div class="bento-card">
                <span class="bento-idx">02</span>
                <span class="bento-ic"><?= icon('rupee', 20) ?></span>
                <h3>Direct to Bank</h3>
                <p>Funds credit directly to your existing account.</p>
            </div>
            <div class="bento-card wide">
                <span class="bento-idx">03</span>
                <span class="bento-ic"><?= icon('send', 20) ?></span>
                <h3>Signed Webhooks</h3>
                <p>HMAC-SHA256 verified instant callbacks.</p>
            </div>
            <div class="bento-card">
                <span class="bento-idx">04</span>
                <span class="bento-ic"><?= icon('key', 20) ?></span>
                <h3>Developer API</h3>
                <p>Clean REST JSON endpoints with auto UTR matching.</p>
            </div>
            <div class="bento-card wide">
                <span class="bento-idx">05</span>
                <span class="bento-ic"><?= icon('credit-card', 20) ?></span>
                <h3>Dynamic UPI QR</h3>
                <p>Supports PhonePe, Google Pay, Paytm, and BHIM.</p>
            </div>
            <div class="bento-card">
                <span class="bento-idx">06</span>
                <span class="bento-ic"><?= icon('activity', 20) ?></span>
                <h3>Live Analytics</h3>
                <p>Real-time volume, orders, and delivery logs.</p>
            </div>
        </div>
    </div>
</section>

<!-- ================= HOW IT WORKS ================= -->
<section class="section steps-section" id="how-it-works">
    <div class="container">
        <div class="steps-head" data-reveal>
            <span class="kicker">How it works</span>
            <h2 class="sec-title">Up and running in 3 steps</h2>
            <div class="steps-progress" id="stepsProgress" aria-hidden="true">
                <span class="steps-progress-fill" id="stepsProgressFill"></span>
            </div>
        </div>

        <div class="steps-viewport">
            <div class="steps-track">
                <article class="step-card" data-step="1">
                    <span class="step-watermark">01</span>
                    <div class="step-top">
                        <span class="step-no">1</span>
                        <span class="step-ic"><?= icon('user', 22) ?></span>
                    </div>
                    <h3>Connect UPI</h3>
                    <p>Link your UPI handle and email in 60 seconds.</p>
                    <span class="step-tag"><i></i>Instant Setup</span>
                </article>
                <article class="step-card" data-step="2">
                    <span class="step-watermark">02</span>
                    <div class="step-top">
                        <span class="step-no">2</span>
                        <span class="step-ic"><?= icon('link', 22) ?></span>
                    </div>
                    <h3>Generate Link / QR</h3>
                    <p>Create dynamic payment links or checkout via API.</p>
                    <span class="step-tag"><i></i>Dynamic Intent</span>
                </article>
                <article class="step-card" data-step="3">
                    <span class="step-watermark">03</span>
                    <div class="step-top">
                        <span class="step-no">3</span>
                        <span class="step-ic"><?= icon('zap', 22) ?></span>
                    </div>
                    <h3>Instant Settlement</h3>
                    <p>Direct bank credit with automated UTR matching.</p>
                    <span class="step-tag"><i></i>Real-Time Match</span>
                </article>
            </div>
        </div>
    </div>
</section>

<!-- ================= API ================= -->
<section class="section" id="api">
    <div class="container">
        <div class="sec-head" data-reveal>
            <span class="kicker">API</span>
            <h2 class="sec-title">Built for developers</h2>
        </div>

        <div class="api-shell">
            <div data-reveal>
                <div class="code-block" id="codeBlock">
                    <div class="code-top">
                        <span class="code-dot red"></span>
                        <span class="code-dot yellow"></span>
                        <span class="code-dot green"></span>
                        <div class="code-tabs">
                            <button class="code-tab is-active" type="button" data-tab="curl">cURL</button>
                            <button class="code-tab" type="button" data-tab="php">PHP</button>
                            <button class="code-tab" type="button" data-tab="node">Node</button>
                        </div>
                        <button class="btn btn-ghost btn-sm code-copy" type="button" data-copy="curl -X POST <?= url('api/create-order.php') ?> -H &quot;Authorization: Bearer pk_live_****&quot; -d &quot;amount=1250&quot;"><?= icon('copy', 13) ?> Copy</button>
                    </div>
                    <div class="code-body">
<pre class="code-pane is-active" data-pane="curl"><span class="code-com"># Create an order</span>
<span class="code-fn">curl</span> -X POST <?= e(url('api/create-order.php')) ?> \
  -H <span class="code-str">"Authorization: Bearer pk_live_****"</span> \
  -d <span class="code-str">"amount=1250"</span> \
  -d <span class="code-str">"customer_name=Ravi Sharma"</span> \
  -d <span class="code-str">"upi=<?= e($demoUpi) ?>"</span>

<span class="code-com"># Response</span>
{ <span class="code-str">"status"</span>: <span class="code-str">"success"</span>,
  <span class="code-str">"data"</span>: { <span class="code-str">"order_id"</span>: <span class="code-str">"fg_9F2C81B4"</span>, <span class="code-str">"checkout_url"</span>: <span class="code-str">"/pay.php?order_id=..."</span> } }</pre>
<pre class="code-pane" data-pane="php"><span class="code-com">// Create order &amp; handle webhook</span>
<span class="code-pun">&lt;?php</span>
<span class="code-fn">$res</span> = <span class="code-fn">Http</span>::<span class="code-fn">withToken</span>(<span class="code-str">'pk_live_****'</span>)
    -&gt;<span class="code-fn">post</span>(<span class="code-str">'<?= e(url('api/create-order.php')) ?>'</span>, [
        <span class="code-str">'amount'</span>        =&gt; <span class="code-num">1250</span>,
        <span class="code-str">'customer_name'</span> =&gt; <span class="code-str">'Ravi Sharma'</span>,
        <span class="code-str">'upi'</span>           =&gt; <span class="code-str">'<?= e($demoUpi) ?>'</span>,
    ]);</pre>
<pre class="code-pane" data-pane="node"><span class="code-kw">const</span> res = <span class="code-kw">await</span> <span class="code-fn">fetch</span>(<span class="code-str">'<?= e(url('api/create-order.php')) ?>'</span>, {
  <span class="code-fn">method</span>: <span class="code-str">'POST'</span>,
  <span class="code-fn">headers</span>: { <span class="code-fn">Authorization</span>: <span class="code-str">'Bearer pk_live_****'</span> },
  <span class="code-fn">body</span>: <span class="code-kw">new</span> <span class="code-fn">URLSearchParams</span>({ <span class="code-fn">amount</span>: <span class="code-num">1250</span> }),
});
<span class="code-kw">const</span> { data } = <span class="code-kw">await</span> res.<span class="code-fn">json</span>();</pre>
                    </div>
                </div>
            </div>

            <div class="api-points">
                <div class="api-point">
                    <span class="api-point-icon"><?= icon('zap', 18) ?></span>
                    <div>
                        <h4>Instant order creation</h4>
                        <p>Generate payment links and dynamic QR in one request.</p>
                    </div>
                </div>
                <div class="api-point">
                    <span class="api-point-icon"><?= icon('activity', 18) ?></span>
                    <div>
                        <h4>Real-time status</h4>
                        <p>Poll order status or receive instant webhook notifications.</p>
                    </div>
                </div>
                <div class="api-point">
                    <span class="api-point-icon"><?= icon('plug', 18) ?></span>
                    <div>
                        <h4>Signed webhooks</h4>
                        <p>HMAC-SHA256 signature verification on every event.</p>
                    </div>
                </div>
                <div class="api-point">
                    <span class="api-point-icon"><?= icon('shield', 18) ?></span>
                    <div>
                        <h4>Bank-grade security</h4>
                        <p>Encrypted keys, direct settlements, zero middleman custody.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ================= PRICING ================= -->
<section class="section section-alt" id="pricing">
    <div class="container">
        <div class="sec-head" data-reveal>
            <span class="kicker">Pricing</span>
            <h2 class="sec-title">Simple, predictable pricing</h2>
        </div>

        <div class="pricing-grid">
            <?php
            $landingPlans = sub_plans(true);
            if (!$landingPlans) {
                $landingPlans = [
                    ['name' => 'Free Trial', 'price' => 0, 'duration_days' => 1, 'badge' => 'Free', 'is_trial' => 1, 'features' => "Full dashboard & payment links\nAPI keys & dynamic QR\nWebhooks & auto UTR detection\n1-day access, no card needed"],
                    ['name' => 'Starter Monthly', 'price' => 49, 'duration_days' => 30, 'badge' => '', 'is_trial' => 0, 'features' => "Payment links & dynamic QR\nWebhooks & events\nFull API access\nEmail support"],
                    ['name' => 'Pro Monthly', 'price' => 99, 'duration_days' => 30, 'badge' => 'Popular', 'is_trial' => 0, 'features' => "Unlimited links & QR\nWebhooks & events\nFull API access\nPriority support"],
                    ['name' => 'Pro Quarterly', 'price' => 249, 'duration_days' => 90, 'badge' => 'Best value', 'is_trial' => 0, 'features' => "Everything in Pro Monthly\nEverything unlimited\n3 months validity\nPriority support"],
                ];
            }
            foreach ($landingPlans as $plan):
                $isFree   = (float) $plan['price'] <= 0;
                $isTrial  = !empty($plan['is_trial']) || $isFree;
                $days     = $isTrial ? max(1, (int) sub_setting('sub_trial_days', (string) $plan['duration_days'])) : max(1, (int) $plan['duration_days']);
                $popular  = !$isFree && !empty($plan['badge']);
                $features = array_slice(array_filter(array_map('trim', explode("\n", (string) $plan['features']))), 0, 5);
            ?>
            <div class="price-card<?= $popular ? ' popular' : '' ?>">
                <?php if (!empty($plan['badge'])): ?>
                    <?php $bClass = 'badge-' . strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', (string) $plan['badge']), '-')); ?>
                    <span class="price-pop <?= e($bClass) ?>"><?= e($plan['badge']) ?></span>
                <?php endif; ?>
                <span class="price-name"><?= e($plan['name']) ?></span>
                <div class="price-amount">
                    <?php if ($isFree): ?>
                        Free <small>/ <?= $days > 1 ? $days . ' days' : '1 day' ?></small>
                    <?php else: ?>
                        <?= e($currency) ?><?= e(number_format((float) $plan['price'], 0)) ?> <small>/ <?= $days >= 90 ? '3 months' : ($days >= 30 ? 'month' : $days . ' days') ?></small>
                    <?php endif; ?>
                </div>
                <?php if (!empty($plan['tagline'])): ?>
                    <div class="price-desc"><?= e($plan['tagline']) ?></div>
                <?php endif; ?>
                <ul class="price-feat">
                    <?php foreach ($features as $f): 
                        if ($isTrial) {
                            $f = preg_replace('/\b\d+-day access\b/i', $days . '-day access', $f);
                        }
                    ?>
                        <li><?= icon('check', 13) ?> <?= e($f) ?></li>
                    <?php endforeach; ?>
                </ul>
                <a class="btn <?= $popular ? 'btn-primary' : 'btn-secondary' ?>" href="<?= url('register.php') ?>">Get started</a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ================= FAQ ================= -->
<section class="section" id="faq">
    <div class="container">
        <div class="sec-head" data-reveal>
            <span class="kicker">FAQ</span>
            <h2 class="sec-title">Questions, answered</h2>
        </div>

        <div class="faq-list" data-stagger>
            <details class="faq-item">
                <summary>How do I receive payment confirmations? <span class="faq-chevr"><?= icon('chevron-right', 16) ?></span></summary>
                <div class="faq-body">Payments match using the UPI reference or UTR. Webhooks fire automatically upon settlement.</div>
            </details>
            <details class="faq-item">
                <summary>Do I need bank approvals or KYC? <span class="faq-chevr"><?= icon('chevron-right', 16) ?></span></summary>
                <div class="faq-body">No. You collect directly into your existing UPI ID. No paperwork or holding period.</div>
            </details>
            <details class="faq-item">
                <summary>Can developers integrate any stack? <span class="faq-chevr"><?= icon('chevron-right', 16) ?></span></summary>
                <div class="faq-body">Yes. REST API with JSON responses and signed webhooks compatible with any backend.</div>
            </details>
            <details class="faq-item">
                <summary>Which UPI apps are supported? <span class="faq-chevr"><?= icon('chevron-right', 16) ?></span></summary>
                <div class="faq-body">All UPI apps: Google Pay, PhonePe, Paytm, BHIM, and bank UPI apps.</div>
            </details>
            <details class="faq-item">
                <summary>Is there a monthly fee? <span class="faq-chevr"><?= icon('chevron-right', 16) ?></span></summary>
                <div class="faq-body">Pricing is a simple flat monthly plan &mdash; there are zero per-transaction gateway fees. Start on the free trial to try everything first, then pick a plan when you scale.</div>
            </details>
        </div>
    </div>
</section>

<!-- ================= CTA ================= -->
<section class="section">
    <div class="container">
        <div class="cta-panel" data-reveal="scale">
            <h2>Start taking payments today</h2>
            <p>Create your free account in minutes.</p>
            <div class="cta-actions">
                <?php if (is_logged_in()): ?>
                    <a class="btn btn-lg btn-light" href="<?= url('user/dashboard.php') ?>">Go to Dashboard</a>
                <?php else: ?>
                    <a class="btn btn-lg btn-light" href="<?= url('auth.php?mode=signup') ?>">Create free account</a>
                    <a class="btn btn-lg btn-outline" href="<?= url('auth.php') ?>">Sign in</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- ================= FOOTER ================= -->
<footer class="landing-footer">
    <div class="container">
        <div class="footer-grid">
            <div class="footer-brand">
                <a class="brand" href="<?= url('index.php') ?>">
                    <img src="<?= asset('assets/img/logo.png') ?>" alt="<?= e(SITE_NAME) ?>" class="brand-logo-img">
                    <span class="logo-text"><?= e(SITE_NAME) ?></span>
                </a>
            </div>
            <div class="footer-col">
                <h4>Product</h4>
                <a href="#features">Features</a>
                <a href="#pricing">Pricing</a>
                <a href="#api">API</a>
                <a href="<?= url('status.php') ?>">System status</a>
            </div>
            <div class="footer-col">
                <h4>Resources</h4>
                <a href="<?= url('user/docs.php') ?>">Documentation</a>
                <a href="#faq">FAQ</a>
                <a href="<?= url('auth.php?mode=signup') ?>">Create account</a>
                <a href="<?= url('auth.php') ?>">Sign in</a>
            </div>
            <div class="footer-col">
                <h4>Company</h4>
                <a href="<?= url('terms.php') ?>">Terms</a>
                <a href="<?= url('privacy.php') ?>">Privacy</a>
                <a href="<?= url('refund-policy.php') ?>">Refund policy</a>
                <a href="<?= e(SITE_EMAIL !== '' ? 'mailto:' . SITE_EMAIL : '#') ?>">Support</a>
            </div>
        </div>
        <div class="footer-bottom">
            <span>&copy; <?= date('Y') ?> <?= e(SITE_NAME) ?>. All rights reserved.</span>
            <a class="footer-status" href="<?= url('status.php') ?>"><span class="dot"></span> System status</a>
        </div>
    </div>
</footer>

<?php
include APP_ROOT . '/includes/footer.php';
?>
