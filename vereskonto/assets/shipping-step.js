/**
 * Vereskonto shipping step: choosing another saved address asks the
 * server for that address's real shipping options (window.vklShip).
 */
(function () {
    'use strict';

    var cfg = window.vklShip || {};

    function init() {
        var form = document.querySelector('.vkl-ship-form');
        var box = document.getElementById('vkl-ship-rates');
        if (!form || !box || !window.fetch) { return; }

        var submit = form.querySelector('button[type="submit"]');
        var seq = 0;

        function quote(addressId) {
            var mine = ++seq;
            var body = new FormData();
            body.append('action', 'vkl_ship_quote');
            body.append('nonce', cfg.nonce || '');
            body.append('address_id', addressId);

            box.setAttribute('aria-busy', 'true');
            box.classList.add('is-loading');
            if (submit) { submit.disabled = true; }
            box.innerHTML = '<p class="vkl-ship-loading">' + (cfg.loading || '…') + '</p>';

            fetch(cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (mine !== seq) { return; } // a newer choice is on its way
                    if (res && res.success && res.data && typeof res.data.html === 'string') {
                        box.innerHTML = res.data.html;
                    } else {
                        var msg = res && res.data && res.data.message ? res.data.message : cfg.failed;
                        box.innerHTML = '';
                        var p = document.createElement('p');
                        p.className = 'vkl-ship-none';
                        p.textContent = msg || '';
                        box.appendChild(p);
                    }
                })
                .catch(function () {
                    if (mine !== seq) { return; }
                    box.innerHTML = '';
                    var p = document.createElement('p');
                    p.className = 'vkl-ship-none';
                    p.textContent = cfg.failed || '';
                    box.appendChild(p);
                })
                .then(function () {
                    if (mine !== seq) { return; }
                    box.removeAttribute('aria-busy');
                    box.classList.remove('is-loading');
                    if (submit) { submit.disabled = !box.querySelector('input[name="vkl_ship_rate"]'); }
                });
        }

        form.addEventListener('change', function (e) {
            var t = e.target;
            if (t && t.name === 'vkl_address_id' && t.checked) { quote(t.value); }
        });

        if (submit) { submit.disabled = !box.querySelector('input[name="vkl_ship_rate"]'); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
