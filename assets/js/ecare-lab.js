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
    window.addEventListener('resize', syncArrows);
    document.addEventListener('DOMContentLoaded', syncArrows);
    syncArrows();
})();
