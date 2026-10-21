/* =========================================================
   FatherGateway — Dashboard Page JS
   Dual-Axis Area Chart, Real-Time Filter & In-Dashboard Quick Links
   ========================================================= */
(function () {
  'use strict';

  function initDashboardPage() {
    // ---------- In-Dashboard Quick Payment Link Generator ----------
    var quickGenForm = document.getElementById('quickDashboardForm');
    var quickResultBox = document.getElementById('quickResultBox');
    var quickResultLink = document.getElementById('quickResultLink');
    var quickResultCopyBtn = document.getElementById('quickResultCopyBtn');
    var quickResultWhatsapp = document.getElementById('quickResultWhatsapp');
    var quickResultQrHolder = document.getElementById('quickResultQrHolder');

    if (quickGenForm) {
      quickGenForm.onsubmit = function (e) {
        e.preventDefault();
        var amtInput = document.getElementById('quickInputAmount');
        var amt = parseFloat(amtInput ? amtInput.value : '0') || 0;

        if (amt <= 0) {
          if (window.toast) window.toast('Please enter a valid amount greater than ₹0', 'error');
          return;
        }

        var submitBtn = e.submitter || quickGenForm.querySelector('button[type="submit"]');
        var isQrBtn = submitBtn && submitBtn.classList.contains('quick-btn-qr');

        if (isQrBtn) {
          window.openQuickQrModal(amt);
          return;
        }

        var formData = new FormData(quickGenForm);
        formData.append('ajax', '1');

        var prevBtnHtml = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.innerHTML = '<span class="spin-dot"></span> Generating...';
        }

        fetch(window.location.href, {
          method: 'POST',
          body: formData,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = prevBtnHtml;
          }

          if (data.success && data.link_url) {
            if (quickResultBox) {
              quickResultBox.classList.add('show');
              if (quickResultLink) {
                quickResultLink.textContent = data.link_url;
                quickResultLink.href = data.link_url;
              }
              if (quickResultCopyBtn) {
                quickResultCopyBtn.setAttribute('data-copy', data.link_url);
              }
              if (quickResultWhatsapp) {
                var waText = encodeURIComponent('Pay ' + (window.GATEWAY_CURRENCY || '₹') + amt + ' securely via UPI: ' + data.link_url);
                quickResultWhatsapp.href = 'https://api.whatsapp.com/send?text=' + waText;
              }
              if (quickResultQrHolder) {
                var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&margin=3&data=' + encodeURIComponent(data.link_url);
                quickResultQrHolder.innerHTML = '<img src="' + qrUrl + '" alt="QR" style="width:160px;height:160px;display:block;margin:0 auto;border-radius:8px;">';
              }
            }
            if (window.toast) window.toast('Payment link created successfully!', 'success');
          } else {
            if (window.toast) window.toast(data.error || 'Failed to create link.', 'error');
          }
        })
        .catch(function () {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = prevBtnHtml;
          }
          quickGenForm.submit();
        });
      };
    }


    // ---------- Real-Time Table Filter & Search ----------
    var tableSearchInput = document.getElementById('dashTableSearchInput');
    var tableTabs = document.querySelectorAll('.tx-tab-btn, .table-tab-pill, .tx-filter-tabs button');
    var tableRows = document.querySelectorAll('#dashTransactionsTable tbody tr:not(.tx-filter-empty-row)');
    var mobileCards = document.querySelectorAll('.dash-tx-mobile-list .dash-tx-card-mobile');
    var emptyRow = document.querySelector('#dashTransactionsTable .tx-filter-empty-row');
    var mobileEmpty = document.querySelector('.dash-tx-mobile-empty');
    var footerCount = document.querySelector('.transactions-table-footer .footer-count-text');
    var totalRowsCount = tableRows.length || mobileCards.length;
    var currentTab = 'all';

    function isRowMatch(status, text, query) {
      var rowStatus = (status || '').toLowerCase();
      var rowText = (text || '').toLowerCase();

      var matchesTab = false;
      if (currentTab === 'all') {
        matchesTab = true;
      } else if (currentTab === 'success') {
        matchesTab = (rowStatus === 'success' || rowStatus === 'captured' || rowStatus === 'completed');
      } else if (currentTab === 'pending') {
        matchesTab = (rowStatus === 'pending');
      } else if (currentTab === 'failed') {
        matchesTab = (rowStatus === 'failed' || rowStatus === 'failure' || rowStatus === 'expired' || rowStatus === 'cancelled');
      } else {
        matchesTab = (rowStatus === currentTab);
      }

      var matchesSearch = (query === '') || (rowText.indexOf(query) !== -1);
      return matchesTab && matchesSearch;
    }

    function filterTable() {
      var query = (tableSearchInput ? tableSearchInput.value : '').toLowerCase().trim();
      var visibleCount = 0;

      tableRows.forEach(function (row) {
        var match = isRowMatch(row.getAttribute('data-status'), row.textContent, query);
        if (match) {
          row.style.display = '';
          visibleCount++;
        } else {
          row.style.display = 'none';
        }
      });

      var mobileVisible = 0;
      mobileCards.forEach(function (card) {
        var match = isRowMatch(card.getAttribute('data-status'), card.textContent, query);
        if (match) {
          card.style.display = '';
          mobileVisible++;
        } else {
          card.style.display = 'none';
        }
      });

      if (emptyRow) {
        emptyRow.style.display = (visibleCount === 0 && tableRows.length > 0) ? '' : 'none';
      }
      if (mobileEmpty) {
        mobileEmpty.style.display = (mobileVisible === 0 && mobileCards.length > 0) ? '' : 'none';
      }

      var activeCount = (window.innerWidth <= 768) ? mobileVisible : visibleCount;
      if (footerCount) {
        if (currentTab === 'all' && query === '') {
          footerCount.textContent = 'Recent ' + totalRowsCount + ' transactions';
        } else {
          footerCount.textContent = 'Showing ' + activeCount + ' of ' + totalRowsCount + ' transactions';
        }
      }
    }

    if (tableSearchInput) {
      tableSearchInput.addEventListener('input', filterTable);
    }

    // ---------- Liquid Jelly Glider for Transaction Filter Tabs ----------
    var txFilterContainer = document.getElementById('txFilterTabs');
    var txGlider = document.getElementById('txLiquidGlider');
    var currentGliderX = 0;
    var txStretchTimer = null;

    function moveTxGliderTo(tabBtn, isImmediate) {
      if (!txGlider || !tabBtn || !txFilterContainer) return;

      var targetX = tabBtn.offsetLeft;
      var targetY = tabBtn.offsetTop;
      var targetW = tabBtn.offsetWidth;
      var targetH = tabBtn.offsetHeight;

      if (!targetW || !targetH) return;

      var deltaX = targetX - currentGliderX;
      var distance = Math.abs(deltaX);

      if (!isImmediate && distance > 6) {
        txGlider.classList.remove('jelly-stretching-right', 'jelly-stretching-left');
        if (deltaX > 0) {
          txGlider.classList.add('jelly-stretching-right');
        } else {
          txGlider.classList.add('jelly-stretching-left');
        }

        clearTimeout(txStretchTimer);
        txStretchTimer = setTimeout(function () {
          txGlider.classList.remove('jelly-stretching-right', 'jelly-stretching-left');
        }, 380);
      }

      currentGliderX = targetX;

      if (isImmediate) {
        var oldTrans = txGlider.style.transition;
        txGlider.style.transition = 'none';
        txGlider.style.transform = 'translate3d(' + targetX + 'px, ' + targetY + 'px, 0)';
        txGlider.style.width = targetW + 'px';
        txGlider.style.height = targetH + 'px';
        txGlider.classList.add('is-active');
        txGlider.offsetHeight; // Force reflow
        txGlider.style.transition = oldTrans;
      } else {
        txGlider.style.transform = 'translate3d(' + targetX + 'px, ' + targetY + 'px, 0)';
        txGlider.style.width = targetW + 'px';
        txGlider.style.height = targetH + 'px';
        txGlider.classList.add('is-active');
      }
    }

    var initialActiveTab = document.querySelector('.tx-tab-btn.active');
    setTimeout(function () {
      if (initialActiveTab) moveTxGliderTo(initialActiveTab, true);
    }, 60);

    window.addEventListener('resize', function () {
      var current = document.querySelector('.tx-tab-btn.active');
      if (current) moveTxGliderTo(current, true);
    }, { passive: true });

    tableTabs.forEach(function (tab) {
      tab.addEventListener('click', function (e) {
        e.preventDefault();
        tableTabs.forEach(function (t) { t.classList.remove('active'); });
        tab.classList.add('active');

        // Organic Jelly Pop Animation on Tab
        tab.classList.remove('jelly-pop');
        void tab.offsetWidth; // trigger reflow
        tab.classList.add('jelly-pop');

        // Liquid Water Ripple from click point
        var rect = tab.getBoundingClientRect();
        var ripple = document.createElement('span');
        var size = Math.max(rect.width, rect.height);
        ripple.className = 'tx-liquid-ripple';
        ripple.style.width = ripple.style.height = size + 'px';
        ripple.style.left = (e.clientX - rect.left - size / 2) + 'px';
        ripple.style.top = (e.clientY - rect.top - size / 2) + 'px';
        tab.appendChild(ripple);
        setTimeout(function () { ripple.remove(); }, 560);

        // Move liquid jelly glider with spring physics
        moveTxGliderTo(tab, false);

        currentTab = (tab.getAttribute('data-tab') || 'all').toLowerCase();
        filterTable();
      });
    });


    // ---------- Interactive Activity Dual-Axis Chart Engine ----------
    (function initActivityChart() {
      var stage = document.getElementById('activityChartStage');
      var svgContainer = document.getElementById('activitySvgContainer');
      var tooltip = document.getElementById('activityTooltipCard');
      var titleText = document.getElementById('activityTitleText');
      var rangeBtns = document.querySelectorAll('.activity-range-btn');
      var payload = window.__ACTIVITY_PAYLOAD;

      if (!stage || !svgContainer || !payload) return;

      var currentRange = '7';

      function formatMoney(amount) {
        var curr = payload.currency || '₹';
        var num = parseFloat(amount || 0);
        return curr + num.toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
      }

      function renderChart(rangeKey) {
        var rangeData = payload['d' + rangeKey] || payload['d7'];
        if (!rangeData || !rangeData.points || rangeData.points.length === 0) return;

        var points = rangeData.points;
        var numPoints = points.length;

        // Dark Mode Detection
        var isDark = document.documentElement.classList.contains('dark-mode') ||
                     (document.body && document.body.classList.contains('dark-mode')) ||
                     document.documentElement.getAttribute('data-theme') === 'dark';

        var gridColor = isDark ? 'rgba(255, 255, 255, 0.08)' : '#f1f5f9';
        var textColor = isDark ? '#718096' : '#94a3b8';
        var highlightColor = isDark ? 'rgba(139, 92, 246, 0.16)' : '#f5f3ff';
        var lineColor = isDark ? '#38bdf8' : '#2563eb';
        var dotFill = isDark ? '#38bdf8' : '#2563eb';
        var dotStroke = isDark ? '#0f172a' : '#ffffff';
        var crosshairColor = isDark ? '#64748b' : '#94a3b8';

        // Responsive Mobile vs Desktop ViewBox Metrics
        var isMobile = (window.innerWidth < 640) || (svgContainer.clientWidth > 0 && svgContainer.clientWidth < 480);
        var W = isMobile ? 380 : 920;
        var H = isMobile ? 275 : 250;
        var padL = isMobile ? 46 : 60;
        var padR = isMobile ? 24 : 40;
        var padT = isMobile ? 22 : 25;
        var padB = isMobile ? 32 : 36;
        var plotW = W - padL - padR;
        var plotH = H - padT - padB;

        // Determine max values
        var maxRev = 0;
        var maxReq = 0;
        var peakIdx = 1; // Default highlight Oct 09 in 7D

        points.forEach(function (p, idx) {
          if (p.rev > maxRev) maxRev = p.rev;
          if (p.requests > maxReq) maxReq = p.requests;
          if (p.is_peak) peakIdx = idx;
        });

        // 5 Grid line Y coords
        var gridY = [
          padT,
          padT + plotH * 0.25,
          padT + plotH * 0.50, // Middle baseline
          padT + plotH * 0.75,
          padT + plotH
        ];

        // Scales
        var leftTicks = [];
        if (maxRev === 0) {
          leftTicks = [
            payload.currency + '1',
            payload.currency + '0.5',
            payload.currency + '0',
            payload.currency + '-0.5',
            payload.currency + '-1'
          ];
        } else {
          leftTicks = [
            formatMoney(maxRev),
            formatMoney(maxRev * 0.75),
            formatMoney(maxRev * 0.5),
            formatMoney(maxRev * 0.25),
            formatMoney(0)
          ];
        }

        var rightTicks = [];
        if (maxReq === 0) {
          rightTicks = ['8', '6', '4', '2', '0'];
        } else {
          var ceilReq = Math.max(8, Math.ceil(maxReq / 4) * 4);
          rightTicks = [
            String(ceilReq),
            String(Math.round(ceilReq * 0.75)),
            String(Math.round(ceilReq * 0.5)),
            String(Math.round(ceilReq * 0.25)),
            '0'
          ];
        }

        // Calculate coordinates
        var coords = [];
        var colW = numPoints > 1 ? (plotW / (numPoints - 1)) : plotW;

        for (var i = 0; i < numPoints; i++) {
          var x = padL + i * colW;
          var y = 0;

          if (maxRev === 0) {
            y = gridY[2]; // Exactly at middle line (₹0) matching screenshot
          } else {
            y = gridY[4] - (points[i].rev / maxRev) * plotH;
          }

          coords.push({
            x: Math.round(x * 10) / 10,
            y: Math.round(y * 10) / 10,
            data: points[i],
            idx: i
          });
        }

        // Line path
        var pathD = 'M ' + coords[0].x + ' ' + coords[0].y;
        for (var k = 1; k < coords.length; k++) {
          pathD += ' L ' + coords[k].x + ' ' + coords[k].y;
        }

        // Highlight column width & position
        var highlightColW = Math.max(isMobile ? 26 : 38, Math.min(isMobile ? 54 : 84, colW * 0.75));
        var highlightX = coords[peakIdx] ? (coords[peakIdx].x - highlightColW / 2) : 0;

        // Build SVG HTML
        var svgHtml = '<svg class="activity-chart-svg" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="xMidYMid meet">';

        // 1. Soft Purple Highlight Column for Peak/Selected day
        if (coords[peakIdx]) {
          svgHtml += '<rect x="' + highlightX + '" y="' + (gridY[0] - 2) + '" width="' + highlightColW + '" height="' + (plotH + 4) + '" fill="' + highlightColor + '" rx="5" />';
        }

        // 2. Horizontal Gridlines & Left/Right Labels
        var axisFontSize = isMobile ? '10.5' : '11.5';
        for (var g = 0; g < 5; g++) {
          var yLine = gridY[g];
          svgHtml += '<line x1="' + padL + '" y1="' + yLine + '" x2="' + (W - padR) + '" y2="' + yLine + '" stroke="' + gridColor + '" stroke-width="1.2" />';
          // Left Y Axis Label (Revenue)
          svgHtml += '<text x="' + (padL - (isMobile ? 8 : 12)) + '" y="' + (yLine + 3.5) + '" fill="' + textColor + '" font-size="' + axisFontSize + '" font-weight="500" text-anchor="end" font-family="system-ui, sans-serif">' + leftTicks[g] + '</text>';
          // Right Y Axis Label (Requests)
          svgHtml += '<text x="' + (W - padR + (isMobile ? 8 : 12)) + '" y="' + (yLine + 3.5) + '" fill="' + textColor + '" font-size="' + axisFontSize + '" font-weight="500" text-anchor="start" font-family="system-ui, sans-serif">' + rightTicks[g] + '</text>';
        }

        // 3. Vertical Crosshair guideline (hover state)
        svgHtml += '<line id="activityCrosshair" x1="0" y1="' + gridY[0] + '" x2="0" y2="' + gridY[4] + '" stroke="' + crosshairColor + '" stroke-dasharray="3 3" stroke-width="1.2" style="display:none;" />';

        // 4. Series Line
        svgHtml += '<path d="' + pathD + '" fill="none" stroke="' + lineColor + '" stroke-width="' + (isMobile ? '2.4' : '2.8') + '" stroke-linecap="round" stroke-linejoin="round" />';

        // 5. Data Points (Circles)
        coords.forEach(function (c) {
          svgHtml += '<circle cx="' + c.x + '" cy="' + c.y + '" r="' + (isMobile ? '3.5' : '4') + '" fill="' + dotFill + '" stroke="' + dotStroke + '" stroke-width="' + (isMobile ? '1.5' : '1.8') + '" class="activity-chart-dot" data-idx="' + c.idx + '" />';
        });

        // 6. Active Glowing Hover Dot
        svgHtml += '<g id="activityHoverGroup" style="display:none;">';
        svgHtml += '  <circle id="activityHoverPulse" r="8.5" fill="rgba(37,99,235,0.22)" />';
        svgHtml += '  <circle id="activityHoverCore" r="4.5" fill="' + dotFill + '" stroke="' + dotStroke + '" stroke-width="2" />';
        svgHtml += '</g>';

        // 7. Bottom X-Axis Date Labels
        var labelStep = numPoints <= 7 ? 1 : (numPoints <= 15 ? 2 : 4);
        var dateFontSize = isMobile ? '10' : '11';
        coords.forEach(function (c, idx) {
          if (idx % labelStep === 0 || idx === numPoints - 1) {
            svgHtml += '<text x="' + c.x + '" y="' + (H - (isMobile ? 8 : 10)) + '" fill="' + textColor + '" font-size="' + dateFontSize + '" font-weight="500" text-anchor="middle" font-family="system-ui, sans-serif">' + c.data.label + '</text>';
          }
        });

        svgHtml += '</svg>';
        svgContainer.innerHTML = svgHtml;

        // Hover & Interaction Elements
        var svgEl = svgContainer.querySelector('svg');
        var crosshair = svgContainer.querySelector('#activityCrosshair');
        var hoverGroup = svgContainer.querySelector('#activityHoverGroup');
        var hoverPulse = svgContainer.querySelector('#activityHoverPulse');
        var hoverCore = svgContainer.querySelector('#activityHoverCore');
        var tooltipDate = document.getElementById('tooltipDate');
        var tooltipRev = document.getElementById('tooltipRev');
        var tooltipReq = document.getElementById('tooltipReq');

        function updateHover(clientX) {
          var rect = svgEl.getBoundingClientRect();
          var scaleX = W / rect.width;
          var svgX = (clientX - rect.left) * scaleX;

          var closest = coords[0];
          var minDiff = Math.abs(svgX - coords[0].x);
          for (var i = 1; i < coords.length; i++) {
            var diff = Math.abs(svgX - coords[i].x);
            if (diff < minDiff) {
              minDiff = diff;
              closest = coords[i];
            }
          }

          if (crosshair) {
            crosshair.setAttribute('x1', closest.x);
            crosshair.setAttribute('x2', closest.x);
            crosshair.style.display = 'block';
          }

          if (hoverGroup && hoverPulse && hoverCore) {
            hoverPulse.setAttribute('cx', closest.x);
            hoverPulse.setAttribute('cy', closest.y);
            hoverCore.setAttribute('cx', closest.x);
            hoverCore.setAttribute('cy', closest.y);
            hoverGroup.style.display = 'block';
          }

          if (tooltip && tooltipDate && tooltipRev && tooltipReq) {
            tooltipDate.textContent = closest.data.label;
            tooltipRev.textContent = closest.data.rev_fmt || formatMoney(closest.data.rev);
            tooltipReq.textContent = (closest.data.requests || 0) + ' reqs';

            var pctX = (closest.x / W) * 100;
            var pctY = (closest.y / H) * 100;

            if (pctX > 64) {
              tooltip.classList.add('caret-right');
              tooltip.style.left = 'auto';
              tooltip.style.right = (100 - pctX + 2) + '%';
              tooltip.style.transform = 'translate(-12px, -50%)';
            } else {
              tooltip.classList.remove('caret-right');
              tooltip.style.right = 'auto';
              tooltip.style.left = (pctX + 2) + '%';
              tooltip.style.transform = 'translate(12px, -50%)';
            }

            tooltip.style.top = pctY + '%';
            tooltip.style.display = 'block';
          }
        }

        function hideHover() {
          if (crosshair) crosshair.style.display = 'none';
          if (hoverGroup) hoverGroup.style.display = 'none';
          if (tooltip) tooltip.style.display = 'none';
        }

        svgEl.onmousemove = function (e) {
          updateHover(e.clientX);
        };

        svgEl.onmouseleave = function () {
          hideHover();
        };

        svgEl.ontouchstart = function (e) {
          if (e.touches && e.touches[0]) {
            updateHover(e.touches[0].clientX);
          }
        };

        svgEl.ontouchmove = function (e) {
          if (e.touches && e.touches[0]) {
            updateHover(e.touches[0].clientX);
          }
        };

        svgEl.ontouchend = function () {
          hideHover();
        };
      }

      // Range Toggle click listeners
      rangeBtns.forEach(function (btn) {
        btn.onclick = function () {
          rangeBtns.forEach(function (b) { b.classList.remove('active'); });
          btn.classList.add('active');

          currentRange = btn.getAttribute('data-range') || '7';
          if (titleText) {
            titleText.textContent = 'Activity — Last ' + currentRange + ' Days';
          }
          renderChart(currentRange);
        };
      });

      // Window Resize Listener for dynamic mobile/desktop recalculation
      var resizeTimer;
      window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
          renderChart(currentRange);
        }, 120);
      });

      // Theme Change Observer for instant Dark/Light Mode rerender
      if (window.MutationObserver) {
        var themeObserver = new MutationObserver(function () {
          renderChart(currentRange);
        });
        themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme'] });
      }

      // Initial Render
      renderChart(currentRange);
    })();

    // ---------- Leads By Lead Sources Donut Chart Interactive Logic ----------
    (function () {
      var segments = document.querySelectorAll('.donut-segment');
      var legendItems = document.querySelectorAll('.donut-legend-item');
      var centerNum = document.getElementById('donutCenterNum');
      var centerSub = document.getElementById('donutCenterSub');

      if (!segments.length || !centerNum || !centerSub) return;

      var defaultNum = centerNum.textContent;
      var defaultSub = centerSub.textContent;

      function highlightSegment(idx) {
        var targetSeg = document.getElementById('donutSeg' + idx);

        segments.forEach(function (s, i) {
          if (i == idx) {
            s.style.opacity = '1';
            s.style.strokeWidth = '32';
            s.style.filter = 'drop-shadow(0 0 10px ' + (s.getAttribute('data-color') || '#8b5cf6') + ')';
          } else {
            s.style.opacity = '0.32';
            s.style.strokeWidth = '26';
            s.style.filter = 'none';
          }
        });

        legendItems.forEach(function (leg, i) {
          if (i == idx) {
            leg.classList.add('donut-hovered');
          } else {
            leg.classList.remove('donut-hovered');
          }
        });

        if (targetSeg) {
          var pct = targetSeg.getAttribute('data-pct') || '';
          var count = targetSeg.getAttribute('data-source-count') || '';
          var label = targetSeg.getAttribute('data-label') || '';
          centerNum.textContent = count;
          centerSub.textContent = label + ' (' + pct + ')';
          centerSub.style.color = targetSeg.getAttribute('data-color') || '#8b5cf6';
        }
      }

      function resetDonut() {
        segments.forEach(function (s) {
          s.style.opacity = '1';
          s.style.strokeWidth = '26';
          s.style.filter = 'none';
        });

        legendItems.forEach(function (leg) {
          leg.classList.remove('donut-hovered');
        });

        centerNum.textContent = defaultNum;
        centerSub.textContent = defaultSub;
        centerSub.style.color = '';
      }

      segments.forEach(function (seg) {
        var idx = seg.getAttribute('data-index');
        seg.addEventListener('mouseenter', function () { highlightSegment(idx); });
        seg.addEventListener('mouseleave', resetDonut);
      });

      legendItems.forEach(function (leg) {
        var idx = leg.getAttribute('data-index');
        leg.addEventListener('mouseenter', function () { highlightSegment(idx); });
        leg.addEventListener('mouseleave', resetDonut);
      });
    })();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDashboardPage);
  } else {
    initDashboardPage();
  }
  window.addEventListener('turbo:load', initDashboardPage);
  window.addEventListener('page:loaded', initDashboardPage);
})();
