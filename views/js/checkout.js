(function () {
    'use strict';
    var cfg = window.NAKOPAY || {};
    if (!cfg.pollUrl) return;

    var statusEl = document.getElementById('nakopay-status');
    var qrCanvas = document.getElementById('nakopay-qr-canvas');
    var copyBtns = document.querySelectorAll('.nakopay-copy-btn');

    var bip21 = cfg.bip21 ||
        ('bitcoin:' + cfg.address + (cfg.amount ? '?amount=' + cfg.amount : ''));

    if (qrCanvas && typeof QRious !== 'undefined') {
        new QRious({ element: qrCanvas, value: bip21, size: 220, level: 'M' });
    }

    copyBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.getAttribute('data-target'));
            if (!input) return;
            input.select();
            try { document.execCommand('copy'); } catch (e) {}
            if (navigator.clipboard) {
                navigator.clipboard.writeText(input.value).catch(function () {});
            }
            var prev = btn.textContent;
            btn.textContent = 'Copied';
            setTimeout(function () { btn.textContent = prev; }, 1400);
        });
    });

    var STATUS_TEXT = {
        pending:    'Waiting for payment…',
        processing: 'Payment detected, waiting for confirmation…',
        paid:       'Payment received - redirecting…',
        completed:  'Payment confirmed - redirecting…',
        expired:    'Payment window expired.',
        cancelled:  'Payment cancelled.'
    };

    function applyStatus(s) {
        if (!statusEl) return;
        statusEl.setAttribute('data-status', s);
        statusEl.textContent = STATUS_TEXT[s] || ('Status: ' + s);
    }

    function poll() {
        fetch(cfg.pollUrl, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j) return schedule();
                applyStatus(j.status);
                if (j.redirect) {
                    setTimeout(function () { window.location.href = j.redirect; }, 1500);
                    return;
                }
                schedule();
            })
            .catch(function () { schedule(); });
    }
    function schedule() { setTimeout(poll, 5000); }
    schedule();
})();
