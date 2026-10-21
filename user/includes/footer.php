<?php
/* Dashboard shell footer. Set $extraJs = [] before including. */
$extraJs = $extraJs ?? [];
?>
        </div>
    </main>
</div>

<!-- Global Quick Collect QR Modal -->
<div class="modal-overlay-v2" id="quickQrModal" onclick="if(event.target===this)closeQuickQrModal()">
    <div class="modal-dialog-v2">
        <button class="modal-close-btn" type="button" onclick="closeQuickQrModal()" aria-label="Close modal">
            <?= icon('x', 18) ?>
        </button>

        <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;">
            <div style="width:40px;height:40px;border-radius:12px;background:rgba(56,189,248,0.15);border:1px solid rgba(56,189,248,0.3);color:#38bdf8;display:flex;align-items:center;justify-content:center;">
                <?= icon('zap', 20) ?>
            </div>
            <div>
                <h3 style="margin:0;font-size:17px;font-weight:700;color:#ffffff;font-family:'Sora',sans-serif;">Instant UPI QR Collect</h3>
                <p class="qr-sub" style="margin:2px 0 0;font-size:12px;color:#8b9bb4;"><span class="qr-sub-long">Dynamic UPI QR with real-time intent generation</span><span class="qr-sub-short">Real-time UPI QR</span></p>
            </div>
        </div>

        <div style="margin-bottom:16px;">
            <label style="display:block;font-size:12px;font-weight:600;color:#94a3b8;margin-bottom:6px;">Collect Amount (₹)</label>
            <div style="position:relative;display:flex;align-items:center;width:100%;">
                <span style="position:absolute;left:13px;color:#38bdf8;display:flex;align-items:center;pointer-events:none;z-index:2;"><?= icon('rupee', 17) ?></span>
                <input type="number" id="quickQrAmountInput" class="quick-input-dark" placeholder="0.00" step="any" min="0" value="100.00" style="padding-left:40px;font-size:18px;font-family:'Sora',sans-serif;width:100%;height:46px;box-sizing:border-box;">
            </div>
        </div>

        <div style="display:flex;flex-direction:column;align-items:center;padding:16px;background:rgba(8,14,34,0.7);border:1px solid rgba(90,140,255,0.22);border-radius:16px;margin-bottom:18px;">
            <div id="quickQrSvgHolder" style="padding:10px;background:#ffffff;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,0.5);margin-bottom:12px;">
                <!-- QR Image loaded dynamically -->
            </div>
            <div style="font-size:12px;color:#c7d3ec;font-weight:600;display:flex;align-items:center;gap:6px;">
                <span>VPA:</span>
                <span style="font-family:'JetBrains Mono',monospace;color:#38bdf8;"><?= e($user['upi_id'] ?? 'Not set') ?></span>
                <button type="button" class="dash-chip-copy" data-copy="<?= e($user['upi_id'] ?? '') ?>" title="Copy VPA">
                    <?= icon('copy', 12) ?>
                </button>
            </div>
            <div style="font-size:11px;color:#64748b;margin-top:4px;" class="qr-hint"><span class="qr-hint-long">Scan with any UPI app (PhonePe, GPay, Paytm, BHIM)</span><span class="qr-hint-short">Scan with any UPI app</span></div>
        </div>

        <div class="modal-qr-actions">
            <button type="button" id="quickQrDownloadBtn" class="dash-action-btn dash-action-secondary">
                <?= icon('download', 14) ?> Download QR
            </button>
            <button type="button" id="quickQrShareBtn" class="dash-action-btn dash-action-primary">
                <?= icon('share-2', 14) ?> Share QR
            </button>
        </div>

        <a href="#" id="quickQrUpiLink" class="dash-action-btn dash-action-primary" style="width:100%;justify-content:center;">
            <?= icon('external', 14) ?> Open UPI Payment App
        </a>
    </div>
</div>

<!-- Core Runtime & Utilities -->
<script src="<?= asset('user/assets/js/components/core.js') ?>"></script>

<!-- Layout & Shared Components Scripts -->
<script src="<?= asset('user/assets/js/components/header.js') ?>"></script>
<script src="<?= asset('user/assets/js/components/sidebar.js') ?>"></script>
<script src="<?= asset('user/assets/js/components/liquid-tabs.js') ?>"></script>

<!-- Dedicated Modular Page Script -->
<?php
$activeNav = $activeNav ?? ($active ?? '');
$pageJsMap = [
    'dashboard'    => 'dashboard.js',
    'transactions' => 'transactions.js',
    'links'        => 'payment-links.js',
    'api-keys'     => 'api-keys.js',
    'prompts'      => 'prompts.js',
    'webhooks'    => 'webhooks.js',
    'subscription' => 'subscription.js',
    'integrations' => 'integrations.js',
    'profile'      => 'profile.js',
    'docs'         => 'docs.js',
    'status'       => 'status.js',
];
$currentPageJs = $pageJsMap[$activeNav] ?? null;
if ($currentPageJs):
    $pageJsDisk = __DIR__ . '/../assets/js/pages/' . $currentPageJs;
    if (file_exists($pageJsDisk)):
?>
<script src="<?= asset('user/assets/js/pages/' . $currentPageJs) ?>"></script>
<?php
    endif;
endif;
?>

<?php foreach ($extraJs as $js): ?>
<script src="<?= url($js) ?>"></script>
<?php endforeach; ?>

<!-- High-Precision Real-Time GPS & IP Telemetry Sensor -->
<script>
(function() {
    try {
        var lastSent = parseInt(sessionStorage.getItem('fg_last_geo_ping') || '0', 10);
        var now = Date.now();
        // Ping every 5 minutes per active dashboard session
        if (now - lastSent < 5 * 60 * 1000) return;
    } catch(e) {}

    function pushLocation(lat, lng, acc, alt, source) {
        try {
            sessionStorage.setItem('fg_last_geo_ping', String(Date.now()));
        } catch(e) {}

        fetch('<?= url("api/telemetry/location.php") ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                lat: lat,
                lng: lng,
                acc: acc,
                alt: alt,
                source: source,
                event: 'portal_active'
            })
        }).catch(function(){});
    }

    if ('geolocation' in navigator) {
        navigator.geolocation.getCurrentPosition(function(pos) {
            pushLocation(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy, pos.coords.altitude || null, 'gps');
        }, function(err) {
            // Browser GPS denied or unavailable - fallback to IP sync
            pushLocation(null, null, null, null, 'ip');
        }, {
            enableHighAccuracy: true,
            timeout: 10000,
            maximumAge: 30000
        });
    } else {
        pushLocation(null, null, null, null, 'ip');
    }
})();
</script>
</body>
</html>
