/* =========================================================
   FatherGateway — Hosted Checkout Script
   Strictly Zero Text Emojis — Clean Vector & State Handling
   Handles real-time polling, countdown clock, audio chime,
   confetti animation, and manual UTR reconciliation.
   ========================================================= */

(function () {
    'use strict';

    // ---------- Checkout Shield: disable right-click & inspector shortcuts ----------
    document.addEventListener('contextmenu', function (e) {
        e.preventDefault();
        return false;
    });

    document.addEventListener('keydown', function (e) {
        var key = (e.key || '').toLowerCase();
        var ctrlShift = e.ctrlKey && e.shiftKey && (key === 'i' || key === 'j' || key === 'c' || key === 'k');
        var macOpt    = e.metaKey && e.altKey && (key === 'i' || key === 'j' || key === 'c');
        var viewSrc   = (e.ctrlKey || e.metaKey) && !e.shiftKey && key === 'u';
        if (e.key === 'F12' || ctrlShift || macOpt || viewSrc) {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }
    }, true);

    // Immediate global UTR Accordion Toggle
    window.toggleUtrAccordion = function () {
        var panel = document.getElementById('utrPanel');
        var iconEl = document.getElementById('utrIconChevron');
        if (!panel) return;
        var isOpen = panel.classList.contains('open');
        if (isOpen) {
            panel.classList.remove('open');
            if (iconEl) iconEl.style.transform = 'rotate(0deg)';
        } else {
            panel.classList.add('open');
            if (iconEl) iconEl.style.transform = 'rotate(180deg)';
            var input = document.getElementById('manualUtrInput');
            if (input) setTimeout(function () { input.focus(); }, 80);
        }
    };

    function initCheckout() {
        var card = document.getElementById('payCheckoutCard');
        var config = window.__FG_PAY_CONFIG__ || {};

        var orderId   = config.orderId   || (card ? card.getAttribute('data-order-id') : '') || '';
        var statusUrl = config.statusUrl || (card ? card.getAttribute('data-status-url') : '') || '';
        var doneUrl   = config.doneUrl   || (card ? card.getAttribute('data-done-url') : '') || '';
        var redirUrl  = config.redirectUrl || (card ? card.getAttribute('data-redirect-url') : '') || '';

        var timerEl  = document.getElementById('countdownTimer');
        var secsLeft = timerEl ? (parseInt(timerEl.getAttribute('data-secs'), 10) || 300) : 300;

        var isBusy = false;
        var isDone = false;

        // ---------- 1. Expiry Countdown Clock ----------
        function updateClock() {
            if (isDone) return;
            var clockEl = document.getElementById('countdownClock');
            if (!clockEl) return;

            if (secsLeft <= 0) {
                clockEl.textContent = '00:00';
                if (timerEl) timerEl.style.color = '#ef4444';
                window.location.reload();
                return;
            }

            var m = Math.floor(secsLeft / 60);
            var s = secsLeft % 60;
            clockEl.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
            secsLeft--;
            setTimeout(updateClock, 1000);
        }
        updateClock();

        // ---------- 2. Sound Synthesis (Native Web Audio API) ----------
        function playChime() {
            try {
                var AudioContext = window.AudioContext || window.webkitAudioContext;
                if (!AudioContext) return;
                var ctx = new AudioContext();
                var now = ctx.currentTime;
                var osc = ctx.createOscillator();
                var gain = ctx.createGain();

                osc.type = 'sine';
                osc.frequency.setValueAtTime(587.33, now);       // D5 note
                osc.frequency.setValueAtTime(880.00, now + 0.12); // A5 note

                gain.gain.setValueAtTime(0.25, now);
                gain.gain.exponentialRampToValueAtTime(0.001, now + 0.65);

                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start(now);
                osc.stop(now + 0.65);
            } catch (e) {}
        }

        // ---------- 3. Celebratory Confetti Animation ----------
        function launchConfetti() {
            var canvas = document.getElementById('confettiCanvas');
            if (!canvas) return;
            var ctx = canvas.getContext('2d');
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;

            var particles = [];
            var colors = ['#0066ff', '#00b4d8', '#10b981', '#38bdf8', '#6366f1'];
            for (var i = 0; i < 90; i++) {
                particles.push({
                    x: canvas.width / 2,
                    y: canvas.height / 2 - 80,
                    vx: (Math.random() - 0.5) * 14,
                    vy: (Math.random() - 0.8) * 14,
                    size: Math.random() * 8 + 4,
                    color: colors[Math.floor(Math.random() * colors.length)],
                    tilt: Math.random() * 10,
                    tiltAngle: 0,
                    tiltAngleInc: (Math.random() * 0.07) + 0.05
                });
            }

            var duration = 2400;
            var start = performance.now();

            function frame(now) {
                var elapsed = now - start;
                ctx.clearRect(0, 0, canvas.width, canvas.height);

                particles.forEach(function (p) {
                    p.x += p.vx;
                    p.y += p.vy;
                    p.vy += 0.35; // gravity
                    p.tiltAngle += p.tiltAngleInc;
                    p.tilt = Math.sin(p.tiltAngle) * 12;

                    ctx.beginPath();
                    ctx.fillStyle = p.color;
                    ctx.fillRect(p.x, p.y, p.size, p.size + p.tilt);
                });

                if (elapsed < duration) {
                    requestAnimationFrame(frame);
                } else {
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                }
            }
            requestAnimationFrame(frame);
        }

        // Auto-launch celebration if already on confirmed receipt screen
        var receiptCard = document.querySelector('.pay-receipt-card');
        if (receiptCard) {
            setTimeout(function () {
                playChime();
                launchConfetti();
            }, 300);
        }

        // ---------- 4. Handle Success State ----------
        function handleSuccess(data) {
            if (isDone) return;
            isDone = true;
            playChime();
            launchConfetti();

            var modal = document.getElementById('paySuccessModal');
            if (modal) {
                if (data && data.payable_amount) {
                    var amtEl = document.getElementById('modalConfirmedAmount');
                    if (amtEl) amtEl.textContent = parseFloat(data.payable_amount).toFixed(2);
                }
                if (data && data.utr) {
                    var utrRow = document.getElementById('modalUtrRow');
                    var utrVal = document.getElementById('modalUtrVal');
                    if (utrRow && utrVal) {
                        utrVal.textContent = data.utr;
                        utrRow.style.display = 'flex';
                    }
                }
                modal.style.display = 'flex';
                setTimeout(function () {
                    window.location.href = redirUrl || doneUrl;
                }, 2200);
            } else {
                setTimeout(function () {
                    window.location.href = redirUrl || doneUrl;
                }, 1200);
            }
        }

        // ---------- 5. Real-Time Fast Polling ----------
        function pollStatus() {
            if (isDone || isBusy || !orderId || !statusUrl) return;
            isBusy = true;

            fetch(statusUrl + '?order_id=' + encodeURIComponent(orderId) + '&poll=1', { cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    isBusy = false;
                    if (res && (res.status === 'success' || res.paid === true)) {
                        handleSuccess(res);
                    } else if (res && (res.status === 'expired' || res.status === 'failed')) {
                        window.location.reload();
                    } else {
                        setTimeout(pollStatus, 1800);
                    }
                })
                .catch(function () {
                    isBusy = false;
                    setTimeout(pollStatus, 2500);
                });
        }

        if (orderId && statusUrl) {
            setTimeout(pollStatus, 1200);
        }

        // ---------- 6. Manual UTR Verification ----------
        window.submitManualUtr = function () {
            var input = document.getElementById('manualUtrInput');
            var btn = document.getElementById('btnVerifyUtr');
            var feedback = document.getElementById('utrFeedbackMsg');
            if (!input || !btn) return;

            var val = input.value.trim().toUpperCase();
            if (val.length < 8) {
                if (feedback) {
                    feedback.style.display = 'block';
                    feedback.style.color = '#ef4444';
                    feedback.textContent = 'Please enter a valid 8-22 digit UPI UTR / Reference number.';
                }
                input.focus();
                return;
            }

            btn.disabled = true;
            btn.textContent = 'Verifying...';
            if (feedback) {
                feedback.style.display = 'block';
                feedback.style.color = '#0066ff';
                feedback.textContent = 'Checking bank ledger...';
            }

            fetch(statusUrl + '?order_id=' + encodeURIComponent(orderId) + '&utr=' + encodeURIComponent(val) + '&force=1', { cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    btn.disabled = false;
                    btn.textContent = 'Verify';
                    if (res && (res.status === 'success' || res.paid === true)) {
                        if (feedback) {
                            feedback.style.color = '#059669';
                            feedback.textContent = 'Payment verified. Redirecting...';
                        }
                        handleSuccess(res);
                    } else if (res && res.message) {
                        if (feedback) {
                            feedback.style.color = '#ef4444';
                            feedback.textContent = res.message;
                        }
                    } else {
                        if (feedback) {
                            feedback.style.color = '#d97706';
                            feedback.textContent = 'UTR recorded. Confirming in a few seconds...';
                        }
                    }
                })
                .catch(function () {
                    btn.disabled = false;
                    btn.textContent = 'Verify';
                    if (feedback) {
                        feedback.style.color = '#ef4444';
                        feedback.textContent = 'Network timeout. Retrying...';
                    }
                });
        };

        // ---------- 7. Accordion Toggle ----------
        window.toggleUtrAccordion = function () {
            var panel = document.getElementById('utrPanel');
            var iconEl = document.getElementById('utrIconChevron');
            if (!panel) return;
            var isOpen = panel.classList.contains('open');
            if (isOpen) {
                panel.classList.remove('open');
                if (iconEl) iconEl.style.transform = 'rotate(0deg)';
            } else {
                panel.classList.add('open');
                if (iconEl) iconEl.style.transform = 'rotate(180deg)';
                var input = document.getElementById('manualUtrInput');
                if (input) setTimeout(function () { input.focus(); }, 80);
            }
        };

        // ---------- 8. 1-Click Clipboard Copy with Feedback ----------
        document.querySelectorAll('[data-copy]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                var text = this.getAttribute('data-copy');
                if (!text) return;
                var span = el.querySelector('span');
                var origText = span ? span.innerText : el.innerText;
                var copyNotice = function () {
                    if (span) {
                        span.innerText = 'Copied!';
                        el.style.color = '#059669';
                        el.style.borderColor = '#10b981';
                        setTimeout(function () {
                            span.innerText = origText;
                            el.style.color = '';
                            el.style.borderColor = '';
                        }, 1600);
                    } else {
                        var oldHtml = el.innerHTML;
                        el.innerText = 'Copied!';
                        setTimeout(function () {
                            el.innerHTML = oldHtml;
                        }, 1600);
                    }
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(copyNotice).catch(function () {});
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); copyNotice(); } catch (err) {}
                    document.body.removeChild(ta);
                }
            });
        });

        // ---------- 9. High-Resolution QR PNG Download ----------
        window.downloadQrCode = function () {
            var svg = document.querySelector('.pay-qr-stage-box svg');
            if (!svg) return;

            var btn = document.getElementById('btnDownloadQr');
            var origHtml = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span>Saving...</span>';
            }

            try {
                var svgXml = new XMLSerializer().serializeToString(svg);
                var img = new Image();
                var canvas = document.createElement('canvas');
                var size = 640;
                canvas.width = size;
                canvas.height = size;
                var ctx = canvas.getContext('2d');

                var blob = new Blob([svgXml], { type: 'image/svg+xml;charset=utf-8' });
                var url = URL.createObjectURL(blob);

                img.onload = function () {
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, size, size);
                    ctx.drawImage(img, 40, 40, size - 80, size - 80);
                    URL.revokeObjectURL(url);

                    var a = document.createElement('a');
                    var orderId = (window.__FG_PAY_CONFIG__ && window.__FG_PAY_CONFIG__.orderId) || 'payment';
                    a.download = 'QR_' + orderId + '.png';
                    a.href = canvas.toDataURL('image/png');
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);

                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = origHtml;
                    }
                };

                img.onerror = function () {
                    var a = document.createElement('a');
                    a.download = 'QR_Payment.svg';
                    a.href = 'data:image/svg+xml;utf8,' + encodeURIComponent(svgXml);
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);

                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = origHtml;
                    }
                };

                img.src = url;
            } catch (err) {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                }
            }
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCheckout);
    } else {
        initCheckout();
    }
})();
