/*
 * E-Care lab front end. No jQuery, nothing global except window.ecareLab
 * (set by wp_localize_script). Each feature binds only inside .ecl.
 */
(function () {
    'use strict';

    // Card rows: the ‹ › buttons scroll by one screenful of cards.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.ecl [data-ecl-scroll]') : null;
        if (!btn) { return; }
        var row = document.getElementById(btn.getAttribute('data-ecl-scroll'));
        if (!row) { return; }
        var dir = parseInt(btn.getAttribute('data-dir'), 10) || 1;
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        row.scrollBy({ left: dir * row.clientWidth * 0.9, behavior: reduce ? 'auto' : 'smooth' });
    });

    // Hide a row's arrows when everything already fits.
    function syncArrows() {
        document.querySelectorAll('.ecl .ecl-row').forEach(function (row) {
            var fits = row.scrollWidth <= row.clientWidth + 2;
            document.querySelectorAll('[data-ecl-scroll="' + row.id + '"]').forEach(function (b) { b.hidden = fits; });
        });
    }
    // All tests page: choices apply at once on wide screens; in the mobile
    // drawer they wait for "Show results" so several can be picked.
    document.querySelectorAll('.ecl [data-ecl-autosubmit]').forEach(function (form) {
        form.classList.add('ecl-js');
        var mq = window.matchMedia('(max-width: 900px)');
        // Leave empty fields and the default sort out of the address, so a
        // shared link reads ?category=diabetes and not ?q=&sort=name&type=...
        function tidy() {
            form.querySelectorAll('input[name], select[name]').forEach(function (el) {
                if (el.type === 'radio' && !el.checked) { return; }
                if (el.value === '' || (el.name === 'sort' && el.value === 'name')) { el.disabled = true; }
            });
        }
        function send() { tidy(); form.submit(); }
        form.addEventListener('submit', tidy);   // the Search / Show results buttons
        form.addEventListener('change', function (e) {
            if (e.target.name === 'q') { return; }
            if (e.target.name === 'sort' || !mq.matches) { send(); }
        });
        // Coming back with the Back button, fields disabled by tidy() must work again.
        window.addEventListener('pageshow', function () {
            form.querySelectorAll('[disabled]').forEach(function (el) { el.disabled = false; });
        });

        var backdrop = null;
        function open() {
            form.classList.add('ecl-drawer-anim');
            form.classList.add('is-drawer-open');
            backdrop = document.createElement('div');
            backdrop.className = 'ecl-drawer-backdrop';
            backdrop.addEventListener('click', close);
            form.appendChild(backdrop);
            var first = form.querySelector('.ecl-filters input');
            if (first) { first.focus(); }
            document.addEventListener('keydown', onKey);
        }
        function close() {
            form.classList.remove('is-drawer-open');
            if (backdrop) { backdrop.remove(); backdrop = null; }
            document.removeEventListener('keydown', onKey);
            var t = form.querySelector('[data-ecl-drawer="open"]');
            if (t) { t.focus(); }
        }
        function onKey(e) { if (e.key === 'Escape') { close(); } }
        form.addEventListener('click', function (e) {
            var b = e.target.closest('[data-ecl-drawer]');
            if (!b) { return; }
            if (b.getAttribute('data-ecl-drawer') === 'open') { open(); } else { close(); }
        });
    });

    window.addEventListener('resize', syncArrows);
    document.addEventListener('DOMContentLoaded', syncArrows);
    syncArrows();
})();
