function logToConsole(message, color) {
    var consoleEl = document.getElementById('systemConsole');
    if (!consoleEl) return;
    
    var timeStr = new Date().toTimeString().split(' ')[0];
    var line = document.createElement('div');
    line.className = 'diag-console-log-line';
    if (color) {
        line.style.color = color;
    }
    
    line.innerHTML = '<span class="diag-console-ts">[' + timeStr + ']</span><span>' + message.replace(/\n/g, '<br>&nbsp;&nbsp;&nbsp;&nbsp;') + '</span>';
    consoleEl.appendChild(line);
    consoleEl.scrollTop = consoleEl.scrollHeight;
}

function clearConsole() {
    var consoleEl = document.getElementById('systemConsole');
    if (!consoleEl) return;
    var timeStr = new Date().toTimeString().split(' ')[0];
    consoleEl.innerHTML = '<div class="diag-console-log-line" style="color:#64748b;"><span class="diag-console-ts">[' + timeStr + ']</span><span>Console buffer cleared. System telemetry idle.</span></div>';
    if (window.toast) {
        window.toast('Console logs cleared', 'info');
    }
}

function copyConsoleLogs() {
    var consoleEl = document.getElementById('systemConsole');
    if (!consoleEl) return;
    var text = consoleEl.innerText || '';
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() {
            if (window.toast) window.toast('Console output copied to clipboard', 'success');
        });
    }
}

function toggleConsole() {
    var consoleEl = document.getElementById('systemConsole');
    var btn = document.getElementById('diagToggleBtn');
    if (!consoleEl) return;
    if (consoleEl.style.display === 'none') {
        consoleEl.style.display = 'block';
        if (btn) btn.innerHTML = '▲ Collapse';
    } else {
        consoleEl.style.display = 'none';
        if (btn) btn.innerHTML = '▼ Expand';
    }
}

function runDiagnostic(actionName) {
    var consoleEl = document.getElementById('systemConsole');
    var btn = document.getElementById('diagToggleBtn');
    if (consoleEl && consoleEl.style.display === 'none') {
        consoleEl.style.display = 'block';
        if (btn) btn.innerHTML = '▲ Collapse';
    }

    var csrf = document.getElementById('diagCsrf').value;
    logToConsole('<span class="diag-console-prompt">admin@fathergateway:~$</span> run-diagnostic --action=' + actionName, '#38bdf8');
    
    // Perform fetch request
    var formData = new FormData();
    formData.append('csrf_token', csrf);
    formData.append('action', actionName);
    formData.append('ajax', '1');
    
    fetch(window.ADMIN_SYSTEM_ENDPOINT, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(function(res) {
        return res.json();
    })
    .then(function(data) {
        if (data.ok) {
            logToConsole('✓ ' + (data.log || data.message), '#10b981');
            if (window.toast) {
                window.toast(data.message, 'success');
            }
            // If ping_db, update hero and KPI badges
            if (actionName === 'ping_db' && data.message) {
                var m = data.message.match(/avg ([\d\.]+)ms/);
                if (m && m[1]) {
                    var ms = m[1] + 'ms';
                    var heroEl = document.getElementById('heroDbLatency');
                    var kpiEl = document.getElementById('kpiDbLatency');
                    if (heroEl) heroEl.textContent = ms;
                    if (kpiEl) kpiEl.textContent = ms;
                }
            }
        } else {
            logToConsole('✗ ' + (data.log || data.error || data.message || 'Execution error'), '#ef4444');
            if (window.toast) {
                window.toast(data.error || data.message || 'Diagnostic failed', 'error');
            }
        }
    })
    .catch(function(err) {
        logToConsole('✗ Network or server error during execution: ' + err.message, '#ef4444');
        if (window.toast) {
            window.toast('Diagnostic probe error', 'error');
        }
    });
}

function optimizeSingleTable(tableName) {
    var csrf = document.getElementById('diagCsrf').value;
    logToConsole('<span class="diag-console-prompt">admin@fathergateway:~$</span> optimize table `' + tableName + '`', '#38bdf8');
    
    var formData = new FormData();
    formData.append('csrf_token', csrf);
    formData.append('action', 'optimize_single_table');
    formData.append('table', tableName);
    formData.append('ajax', '1');
    
    fetch(window.ADMIN_SYSTEM_ENDPOINT, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.ok) {
            logToConsole('✓ ' + (data.log || data.message), '#10b981');
            if (window.toast) window.toast(data.message, 'success');
        } else {
            logToConsole('✗ ' + (data.log || data.message || 'Failed'), '#ef4444');
            if (window.toast) window.toast(data.message || 'Failed', 'error');
        }
    })
    .catch(function(err) {
        logToConsole('✗ Error: ' + err.message, '#ef4444');
    });
}
