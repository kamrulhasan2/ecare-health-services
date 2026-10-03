/*
 * Patient dashboard tabs: Doctor Consultations | Lab Tests | Caregiver | Ambulance.
 * EG Care's consultation sections are the first tab; E-Care's panels (printed
 * at the end of the dashboard) are moved up under the tab bar. The Family
 * Members box stays visible under every tab. Without this script every
 * section is simply shown.
 */
(function () {
    'use strict';
    function init() {
        var bar = document.querySelector('[data-ecl-mb-tabs]');
        var box = document.querySelector('[data-ecl-mb].is-tabbed');
        if (!bar || !box) { return; }
        var root = bar.closest('.eg-care-patient-dashboard') || document;
        var consult = Array.prototype.filter.call(root.querySelectorAll('.eg-care-dashboard-section'), function (s) {
            return !s.querySelector('#eg-care-family-add-trigger');   // Family Members belongs to every tab
        });
        bar.parentNode.insertBefore(box, bar.nextSibling);
        var tabs = bar.querySelectorAll('[data-ecl-tab]');
        var panels = box.querySelectorAll('[data-ecl-panel]');

        function show(name, focus) {
            var found = false;
            tabs.forEach(function (t) { if (t.getAttribute('data-ecl-tab') === name) { found = true; } });
            if (!found) { name = 'consult'; }
            tabs.forEach(function (t) {
                var on = t.getAttribute('data-ecl-tab') === name;
                t.setAttribute('aria-selected', on ? 'true' : 'false');
                t.tabIndex = on ? 0 : -1;
                if (on && focus) { t.focus(); }
            });
            consult.forEach(function (s) { s.hidden = name !== 'consult'; });
            panels.forEach(function (p) { p.hidden = p.getAttribute('data-ecl-panel') !== name; });
            box.hidden = name === 'consult';
            if (window.history && history.replaceState) {
                history.replaceState(null, '', name === 'consult' ? window.location.pathname + window.location.search : '#bookings-' + name);
            }
        }

        bar.addEventListener('click', function (e) {
            var t = e.target.closest('[data-ecl-tab]');
            if (t) { show(t.getAttribute('data-ecl-tab')); }
        });
        bar.addEventListener('keydown', function (e) {
            if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') { return; }
            var list = Array.prototype.slice.call(tabs);
            var i = list.indexOf(document.activeElement);
            if (i < 0) { return; }
            var next = list[(i + (e.key === 'ArrowRight' ? 1 : list.length - 1)) % list.length];
            show(next.getAttribute('data-ecl-tab'), true);
            e.preventDefault();
        });
        function fromHash() {
            var m = /^#bookings-(\w+)$/.exec(window.location.hash);
            return m ? m[1] : null;
        }
        window.addEventListener('hashchange', function () {   // a #bookings-lab link on this page
            var h = fromHash();
            if (h) { show(h); bar.scrollIntoView({ block: 'start' }); }
        });
        show(fromHash() || 'consult');
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
