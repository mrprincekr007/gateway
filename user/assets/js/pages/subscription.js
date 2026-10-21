(function () {
    'use strict';

    function animateBars() {
        document.querySelectorAll('.subx-usage-fill').forEach(function (bar) {
            var w = parseInt(bar.getAttribute('data-w'), 10);
            if (isNaN(w)) w = 0;
            requestAnimationFrame(function () {
                setTimeout(function () { bar.style.width = w + '%'; }, 90);
            });
        });
    }

    function initReveals() {
        var items = Array.prototype.slice.call(document.querySelectorAll('[data-reveal]'));
        if (!items.length) return;

        if (!('IntersectionObserver' in window)) {
            items.forEach(function (el) { el.classList.add('is-in'); });
            animateBars();
            return;
        }

        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                var el = entry.target;
                var idx = items.indexOf(el);
                el.style.transitionDelay = Math.min(Math.max(idx, 0) * 55, 330) + 'ms';
                el.classList.add('is-in');
                io.unobserve(el);
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

        items.forEach(function (el) { io.observe(el); });
        animateBars();
    }

    function initSubscriptionPage() {
        initReveals();
        animateBars();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSubscriptionPage);
    } else {
        initSubscriptionPage();
    }

    window.addEventListener('turbo:load', initSubscriptionPage);
})();
