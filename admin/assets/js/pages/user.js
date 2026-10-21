function openOrderReconcileModal(orderId, orderCode, amount) {
    document.getElementById('reconcile-order-id').value = orderId;
    document.getElementById('reconcile-order-info').innerHTML = 'Reconciling Order: <strong>' + orderCode + '</strong> for <strong>' + amount + '</strong>';
    openAdminModal('modal-order-reconcile');
}

function switchAdminTab(targetTabId, btn) {
    var panes = document.querySelectorAll('.admin-tab-pane');
    panes.forEach(function(p) { p.classList.remove('active'); });

    var btns = document.querySelectorAll('.admin-tab-btn');
    btns.forEach(function(b) { b.classList.remove('active'); });

    var targetPane = document.getElementById(targetTabId);
    if (targetPane) {
        targetPane.classList.add('active');
    }
    if (btn) {
        btn.classList.add('active');
    }

    if (targetTabId === 'tab-userinfo' && typeof window.invalidateUserMap === 'function') {
        setTimeout(function() {
            window.invalidateUserMap();
        }, 150);
    }
}
