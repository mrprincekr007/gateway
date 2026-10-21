/* =========================================================
   Dashboard Lazy-Loaded Boxes
   Each heavy box shows a skeleton until its own content is
   streamed in — the rest of the page never waits for it.
   ========================================================= */
function loadDashboardBox(box) {
    if (!box) return;
    var state = box.getAttribute('data-dash-state');
    if (state === 'loading' || state === 'done') return;

    var endpoint = box.getAttribute('data-dash-endpoint');
    var name = box.getAttribute('data-dash-box');
    if (!endpoint || !name) return;

    box.setAttribute('data-dash-state', 'loading');
    box.classList.add('is-loading');
    box.classList.remove('is-error');

    var url = endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + 'box=' + encodeURIComponent(name);

    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.text();
        })
        .then(function (html) {
            box.innerHTML = html;
            box.classList.remove('is-loading');
            box.classList.add('is-loaded');
            box.setAttribute('data-dash-state', 'done');
        })
        .catch(function () {
            box.classList.remove('is-loading');
            box.classList.add('is-error');
            box.setAttribute('data-dash-state', 'error');
            box.innerHTML = '<div class="dash-box-error">' +
                '<span>Could not load this section.</span>' +
                '<button type="button" class="btn btn-secondary btn-xs" data-dash-retry>Retry</button>' +
                '</div>';
        });
}

function initDashboardBoxes() {
    document.querySelectorAll('[data-dash-box]').forEach(function (box, i) {
        if (i === 0) {
            loadDashboardBox(box);
        } else {
            setTimeout(function () { loadDashboardBox(box); }, i * 60);
        }
    });
}

if (!window.__dashBoxRetryBound) {
    window.__dashBoxRetryBound = true;
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-dash-retry]');
        if (!btn) return;
        var box = btn.closest('[data-dash-box]');
        if (box) {
            box.setAttribute('data-dash-state', '');
            loadDashboardBox(box);
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDashboardBoxes);
} else {
    initDashboardBoxes();
}

function openReconcileModalWith(orderId) {
    var input = document.getElementById('reconcileOrderIdInput');
    if (input) input.value = orderId;
    if (typeof openAdminModal === 'function') {
        openAdminModal('quickReconcileModal');
    }
}

