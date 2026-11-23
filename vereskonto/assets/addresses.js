/**
 * Vereskonto address book: map pin on the address form, and the saved-
 * address picker on the checkout page. Config arrives as window.vklAddr.
 */
(function () {
    'use strict';

    var cfg = window.vklAddr || {};
    var t = cfg.i18n || {};

    // ------------------------------------------------------------------
    // Address form: Leaflet map with one draggable pin.
    // ------------------------------------------------------------------
    function initMap() {
        var el = document.getElementById('vkl-addr-map');
        if (!el || typeof window.L === 'undefined') { return; }

        var L = window.L;
        var lat = document.getElementById('vkl-addr-lat');
        var lng = document.getElementById('vkl-addr-lng');
        var hint = document.getElementById('vkl-addr-hint');
        var suggest = document.getElementById('vkl-addr-suggest');
        var locate = document.getElementById('vkl-addr-locate');
        var city = document.getElementById('vkl-addr-city');
        var line = document.getElementById('vkl-addr-line');
        var has = lat.value !== '' && lng.value !== '';
        var start = has ? [parseFloat(lat.value), parseFloat(lng.value)] : (cfg.center || [35.6997, 51.338]);

        var map = L.map(el, { scrollWheelZoom: false, tap: true }).setView(start, has ? 17 : 11);
        L.tileLayer(cfg.tiles, { maxZoom: 19, attribution: cfg.attribution }).addTo(map);

        // A CSS pin: no marker images to load from the CDN.
        var icon = L.divIcon({ className: 'vkl-addr-pin', html: '<span></span>', iconSize: [30, 42], iconAnchor: [15, 42] });
        var marker = null;
        var geoTimer = null;

        function place(latlng, fromUser) {
            if (!marker) {
                marker = L.marker(latlng, { draggable: true, icon: icon, keyboard: true, title: t.picked || '' }).addTo(map);
                marker.on('dragend', function () { place(marker.getLatLng(), true); });
            } else {
                marker.setLatLng(latlng);
            }
            lat.value = latlng.lat.toFixed(6);
            lng.value = latlng.lng.toFixed(6);
            el.classList.remove('is-missing');
            if (hint) { hint.textContent = t.picked || ''; }
            if (fromUser) {
                clearTimeout(geoTimer);
                geoTimer = setTimeout(function () { reverse(latlng); }, 600);
            }
        }

        // Pin → suggested street/city (OpenStreetMap Nominatim), offered,
        // never forced: the customer's own text always wins.
        function reverse(latlng) {
            if (!cfg.geocode || !suggest || !window.fetch) { return; }
            var url = cfg.geocode + '?format=jsonv2&accept-language=fa&zoom=18&lat=' + encodeURIComponent(latlng.lat) + '&lon=' + encodeURIComponent(latlng.lng);
            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) {
                    if (!d || !d.address) { return; }
                    var a = d.address;
                    var c = a.city || a.town || a.village || a.county || '';
                    var street = [a.suburb || a.neighbourhood || '', a.road || ''].filter(Boolean).join('، ');
                    if (city && !city.value && c) { city.value = c; }
                    if (!street) { return; }
                    if (line && !line.value) { line.value = street; return; }
                    suggest.innerHTML = '';
                    suggest.appendChild(document.createTextNode((t.suggested || '') + ' ' + street + ' '));
                    var use = document.createElement('button');
                    use.type = 'button';
                    use.className = 'button';
                    use.textContent = t.use || 'OK';
                    use.addEventListener('click', function () { line.value = street; suggest.hidden = true; line.focus(); });
                    suggest.appendChild(use);
                    suggest.hidden = false;
                })
                .catch(function () {});
        }

        if (has) { place(L.latLng(start[0], start[1]), false); }
        map.on('click', function (e) { place(e.latlng, true); });

        if (locate) {
            if (!navigator.geolocation) { locate.hidden = true; }
            locate.addEventListener('click', function () {
                if (hint) { hint.textContent = t.locating || ''; }
                navigator.geolocation.getCurrentPosition(function (p) {
                    var ll = L.latLng(p.coords.latitude, p.coords.longitude);
                    map.setView(ll, 17);
                    place(ll, true);
                }, function () {
                    if (hint) { hint.textContent = t.noLocate || ''; }
                }, { enableHighAccuracy: true, timeout: 10000 });
            });
        }

        // The pin is required: say so on the map instead of a silent fail.
        var form = el.closest('form');
        if (form) {
            form.addEventListener('submit', function (e) {
                if (lat.value === '' || lng.value === '') {
                    e.preventDefault();
                    el.classList.add('is-missing');
                    if (hint) { hint.textContent = t.pick || ''; }
                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        }

        // Latin digits in number fields.
        ['vkl-addr-postcode', 'vkl-addr-phone'].forEach(function (id) {
            var f = document.getElementById(id);
            if (!f) { return; }
            f.addEventListener('input', function () {
                var v = f.value.replace(/[۰-۹]/g, function (d) { return d.charCodeAt(0) - 1776; }).replace(/[٠-٩]/g, function (d) { return d.charCodeAt(0) - 1632; }).replace(/[^\d+]/g, '');
                if (v !== f.value) { f.value = v; }
            });
        });

        // The map is laid out before fonts/CSS settle on some themes.
        setTimeout(function () { map.invalidateSize(); }, 300);
    }

    // ------------------------------------------------------------------
    // Checkout: fill the address fields from a saved address.
    // ------------------------------------------------------------------
    function initPicker() {
        var select = document.getElementById('vkl-address-id');
        if (!select || !cfg.saved || !cfg.saved.length) { return; }

        var byId = {};
        cfg.saved.forEach(function (a) { byId[a.id] = a; });

        function set(id, value) {
            var f = document.getElementById(id);
            if (!f || value === undefined) { return; }
            f.value = value;
            if (window.jQuery) { window.jQuery(f).trigger('change'); } else { f.dispatchEvent(new Event('change', { bubbles: true })); }
        }

        function apply() {
            var a = byId[select.value];
            if (!a) { return; }
            // Billing NAME and PHONE are the account holder's; only the
            // address goes there. The recipient goes to the shipping fields.
            ['billing', 'shipping'].forEach(function (p) {
                set(p + '_country', 'IR');
                set(p + '_state', a.state);
                set(p + '_city', a.city);
                set(p + '_address_1', a.address_1);
                set(p + '_address_2', a.address_2);
                set(p + '_postcode', a.postcode);
            });
            set('shipping_first_name', a.first_name);
            set('shipping_last_name', a.last_name);
            set('shipping_phone', a.phone);
            if (window.jQuery) { window.jQuery(document.body).trigger('update_checkout'); }
        }

        select.addEventListener('change', apply);
        // Start on the default address (the first option).
        if (window.jQuery) { window.jQuery(apply); } else { apply(); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initMap(); initPicker(); });
    } else {
        initMap();
        initPicker();
    }
})();
