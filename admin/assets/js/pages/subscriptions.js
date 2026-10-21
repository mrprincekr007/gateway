(function () {
    'use strict';

    function setVal(id, v) {
        var el = document.getElementById(id);
        if (el) el.value = (v === null || v === undefined) ? '' : v;
    }

    function setCheck(id, on) {
        var el = document.getElementById(id);
        if (el) el.checked = !!on;
    }

    window.openPlanModal = function (planId) {
        var plans = window.__SUB_PLANS || [];
        var p = null;
        for (var i = 0; i < plans.length; i++) {
            if (parseInt(plans[i].id, 10) === parseInt(planId, 10)) { p = plans[i]; break; }
        }

        var title = document.getElementById('planModalTitle');
        if (title) title.textContent = p ? 'Edit Plan' : 'New Plan';

        setVal('plan-id', p ? p.id : 0);
        setVal('plan-name', p ? p.name : '');
        setVal('plan-tagline', p ? p.tagline : '');
        setVal('plan-price', p ? p.price : '0');
        setVal('plan-duration', p ? p.duration_days : 30);
        setVal('plan-api', p ? p.api_key_limit : 1);
        setVal('plan-txn', p ? p.txn_limit : 100);
        setVal('plan-link', p ? p.link_limit : 10);
        setVal('plan-webhook', p ? p.webhook_limit : 1);
        setVal('plan-badge', p ? p.badge : '');
        setVal('plan-sort', p ? p.sort_order : 0);
        setVal('plan-features', p ? p.features : '');
        setVal('plan-status', p ? p.status : 'active');

        setCheck('plan-api-on', p ? p.api_enabled : 1);
        setCheck('plan-webhook-on', p ? p.webhooks_enabled : 1);
        setCheck('plan-priority', p ? p.priority_support : 0);
        setCheck('plan-trial', p ? p.is_trial : 0);

        if (typeof window.openAdminModal === 'function') {
            window.openAdminModal('modal-plan');
        }
    };
})();
