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

    // =====================================================================
    // Lab cart page. Everything there is a plain form; this only smooths it.
    // =====================================================================
    document.querySelectorAll('.ecl-cartp').forEach(function (root) {
        root.classList.add('ecl-has-js');
        // Choosing an area saves it straight away.
        root.querySelectorAll('select[data-ecl-submit-on-change]').forEach(function (sel) {
            sel.addEventListener('change', function () { if (sel.value) { sel.form.submit(); } });
        });
        // "Change" opens the lab list in place instead of reloading.
        root.querySelectorAll('[data-ecl-toggle]').forEach(function (b) {
            b.addEventListener('click', function (e) {
                var box = document.getElementById(b.getAttribute('data-ecl-toggle'));
                if (!box) { return; }
                e.preventDefault();
                box.hidden = !box.hidden;
                b.setAttribute('aria-expanded', box.hidden ? 'false' : 'true');
                if (!box.hidden) { var f = box.querySelector('button'); if (f) { f.focus(); } }
            });
        });
        // Clear Cart asks twice - without a browser dialog.
        root.querySelectorAll('[data-ecl-confirm]').forEach(function (b) {
            var label = b.textContent, timer = null;
            b.form.addEventListener('submit', function (e) {
                if (b.getAttribute('data-armed')) { clearTimeout(timer); return; }
                e.preventDefault();
                b.setAttribute('data-armed', '1');
                b.textContent = b.getAttribute('data-ecl-confirm');
                b.classList.add('is-armed');
                clearTimeout(timer);
                timer = setTimeout(function () { b.removeAttribute('data-armed'); b.textContent = label; b.classList.remove('is-armed'); }, 5000);
            });
        });
        // One click, one post: a double click must not add or remove twice.
        root.querySelectorAll('form.ecl-cp-form').forEach(function (f) {
            f.addEventListener('submit', function (e) {
                if (e.defaultPrevented) { return; }
                if (f.getAttribute('data-busy')) { e.preventDefault(); return; }
                f.setAttribute('data-busy', '1');
                f.classList.add('is-busy');
            });
        });
    });
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) { return; }
        document.querySelectorAll('.ecl-cartp form[data-busy]').forEach(function (f) { f.removeAttribute('data-busy'); f.classList.remove('is-busy'); });
    });

    // =====================================================================
    // Book Test modal: choose a lab and how many patients, then add to the
    // lab cart. Every price shown here is only a preview - the server reads
    // the offering again when the test is added.
    // Everything is built with textContent, never innerHTML, so a test or
    // lab name can never inject markup.
    // =====================================================================
    var L = window.ecareLab;
    if (!L || !L.ajax || !window.fetch || !window.FormData) { return; }   // links still lead to the detail page

    var T = {
        book: 'Book Test', loading: 'Loading…', failed: 'Could not load this test. Please try again.',
        chooseLab: 'Choose a lab', patients: 'Number of patients',
        price: 'Price', save: 'You save', total: 'Total', add: 'Add to Cart', update: 'Update Cart',
        adding: 'Adding…', added: 'Added to your lab cart', goCart: 'Go to Cart', keep: 'Continue browsing',
        loginNeed: 'Please log in to book a test.', login: 'Log in / Sign up', close: 'Close',
        otherLab: 'Your cart already has tests from %s. Only one lab can be selected per order.',
        switchTo: 'Move my cart to %s', startNew: 'Clear cart and add this test', cancel: 'Cancel',
        noSwitch: '%s does not offer every test in your cart.',
        sample: 'Sample', report: 'Report in', fasting: 'Fasting', yes: 'Yes', no: 'No',
        fewer: 'One patient fewer', more: 'One patient more',
        off: 'UNAVAILABLE', notArea: 'Does not collect in %s',
        noneArea: 'No lab collects this test in %s yet. You can change your area on the cart page.'
    };
    if (L.i18n) { for (var k in L.i18n) { if (Object.prototype.hasOwnProperty.call(L.i18n, k)) { T[k] = L.i18n[k]; } } }
    function fmt(s, v) { return s.replace('%s', v); }

    function money(n) {
        n = Number(n) || 0;
        var whole = Math.round(n * 100) % 100 === 0;
        return '৳' + n.toLocaleString('en-US', { minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: whole ? 0 : 2 });
    }
    function el(tag, attrs, kids) {
        var n = document.createElement(tag);
        if (attrs) {
            for (var a in attrs) {
                if (!Object.prototype.hasOwnProperty.call(attrs, a)) { continue; }
                if (a === 'text') { n.textContent = attrs[a]; }
                else if (a === 'class') { n.className = attrs[a]; }
                else { n.setAttribute(a, attrs[a]); }
            }
        }
        (kids || []).forEach(function (c) { if (c) { n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
        return n;
    }
    function post(action, data) {
        var body = new FormData();
        body.append('action', action);
        body.append('nonce', L.nonce);
        for (var k in data) { if (Object.prototype.hasOwnProperty.call(data, k)) { body.append(k, data[k]); } }
        return fetch(L.ajax, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json().then(function (j) { return { status: r.status, ok: !!(j && j.success), data: (j && j.data) || {} }; }); });
    }

    var M = null;         // the open modal: { root, dialog, body, opener, test, lab, n }
    function close() {
        if (!M) { return; }
        M.root.remove();
        document.documentElement.classList.remove('ecl-modal-open');
        document.removeEventListener('keydown', onKey, true);
        var back = M.opener;
        M = null;
        if (back && back.focus) { back.focus(); }
    }
    function focusables() {
        return Array.prototype.filter.call(
            M.dialog.querySelectorAll('button, a[href], input, [tabindex]:not([tabindex="-1"])'),
            function (n) { return !n.disabled && n.offsetParent !== null; }
        );
    }
    function onKey(e) {
        if (!M) { return; }
        if (e.key === 'Escape') { e.preventDefault(); close(); return; }
        if (e.key !== 'Tab') { return; }
        var f = focusables();
        if (!f.length) { return; }
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    function open(testId, opener) {
        close();
        var title = el('h2', { class: 'ecl-m-title', id: 'ecl-m-title', text: T.loading });
        var body  = el('div', { class: 'ecl-m-body' }, [el('p', { class: 'ecl-m-wait', text: T.loading })]);
        var x     = el('button', { type: 'button', class: 'ecl-m-x', 'aria-label': T.close, text: '×' });
        var dialog = el('div', { class: 'ecl-m', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'ecl-m-title', tabindex: '-1' },
            [el('div', { class: 'ecl-m-head' }, [title, x]), body]);
        var root = el('div', { class: 'ecl ecl-m-root' }, [el('div', { class: 'ecl-m-backdrop' }), dialog]);
        root.addEventListener('click', function (e) { if (e.target === root || e.target.classList.contains('ecl-m-backdrop')) { close(); } });
        x.addEventListener('click', close);
        document.body.appendChild(root);
        document.documentElement.classList.add('ecl-modal-open');
        document.addEventListener('keydown', onKey, true);
        M = { root: root, dialog: dialog, body: body, title: title, opener: opener, test: null, lab: 0, n: 1 };
        dialog.focus();

        var mine = M;
        post('ecare_lab_book_options', { test_id: testId }).then(function (r) {
            if (M !== mine) { return; }
            if (!r.ok || !r.data.labs || !r.data.labs.length) { return fail(r.data.message || T.failed); }
            M.test = r.data;
            render();
        }).catch(function () { if (M === mine) { fail(T.failed); } });
    }

    function fail(msg) {
        M.title.textContent = T.book;
        M.body.textContent = '';
        M.body.appendChild(el('p', { class: 'ecl-m-error', role: 'alert', text: msg }));
    }

    function render() {
        var t = M.test;
        M.title.textContent = t.title;
        M.body.textContent = '';

        // Keep the lab already in the cart when it offers this test; else the
        // cheapest. A lab that does not collect in the patient's area cannot be picked.
        var usable = t.labs.filter(function (l) { return l.serves_area !== false; });
        var ids = usable.map(function (l) { return l.id; });
        M.lab = ids.indexOf(t.cart_lab) >= 0 ? t.cart_lab : (usable[0] ? usable[0].id : 0);
        M.n   = t.in_cart > 0 ? t.in_cart : 1;

        var facts = el('ul', { class: 'ecl-m-facts' });
        if (t.subtitle) { M.body.appendChild(el('p', { class: 'ecl-m-sub', text: t.subtitle })); }
        if (t.sample) { facts.appendChild(el('li', null, [T.sample + ' ', el('strong', { text: t.sample })])); }
        if (t.report) { facts.appendChild(el('li', null, [T.report + ' ', el('strong', { text: t.report })])); }
        if (t.fasting) { facts.appendChild(el('li', null, [T.fasting + ' ', el('strong', { text: t.fasting === 'yes' ? T.yes : T.no })])); }
        if (facts.children.length) { M.body.appendChild(facts); }

        // Labs
        var list = el('div', { class: 'ecl-m-labs', role: 'radiogroup', 'aria-labelledby': 'ecl-m-labs-h' });
        t.labs.forEach(function (l) {
            var off = l.serves_area === false;
            var input = el('input', { type: 'radio', name: 'ecl-m-lab', value: String(l.id) });
            input.checked = l.id === M.lab;
            input.disabled = off;
            input.addEventListener('change', function () { M.lab = l.id; sync(); });
            var logo = l.logo ? el('img', { src: l.logo, alt: '' }) : el('span', { class: 'ecl-lab-initial', text: (l.name || '?').charAt(0).toUpperCase() });
            var price = off ? el('span', { class: 'ecl-m-lab-price' }, [el('span', { class: 'ecl-tag ecl-tag-off', text: T.off })])
                : el('span', { class: 'ecl-m-lab-price' }, [el('strong', { text: money(l.price) })]);
            if (!off && l.mrp > 0) { price.appendChild(el('del', { text: money(l.mrp) })); }
            var name = el('span', { class: 'ecl-m-lab-name' }, [el('span', { text: l.name })]);
            if (off) { name.appendChild(el('small', { class: 'ecl-m-lab-why', text: fmt(T.notArea, t.area_name) })); }
            else if (l.savings > 0) { name.appendChild(el('small', { text: T.save + ' ' + money(l.savings) + (l.discount ? ' (' + l.discount + '%)' : '') })); }
            list.appendChild(el('label', { class: 'ecl-m-lab' + (off ? ' is-off' : '') }, [input, logo, name, price]));
        });
        M.body.appendChild(el('h3', { class: 'ecl-m-h', id: 'ecl-m-labs-h', text: T.chooseLab }));
        M.body.appendChild(list);

        // Patients
        var minus = el('button', { type: 'button', class: 'ecl-step-btn', 'aria-label': T.fewer, text: '−' });
        var plus  = el('button', { type: 'button', class: 'ecl-step-btn', 'aria-label': T.more, text: '+' });
        var count = el('output', { class: 'ecl-step-n2', 'aria-live': 'polite' });
        minus.addEventListener('click', function () { if (M.n > 1) { M.n--; sync(); } });
        plus.addEventListener('click', function () { if (M.n < t.max) { M.n++; sync(); } });
        M.body.appendChild(el('div', { class: 'ecl-m-row' }, [
            el('h3', { class: 'ecl-m-h', text: T.patients }),
            el('div', { class: 'ecl-stepper' }, [minus, count, plus])
        ]));

        // Summary
        var sum = el('dl', { class: 'ecl-m-sum' });
        M.body.appendChild(sum);

        // Messages + actions
        var msg = el('div', { class: 'ecl-m-msg', 'aria-live': 'polite' });
        var foot = el('div', { class: 'ecl-m-foot' });
        M.body.appendChild(msg);
        M.body.appendChild(foot);
        M.ui = { minus: minus, plus: plus, count: count, sum: sum, msg: msg, foot: foot };

        if (!t.logged_in) {
            foot.appendChild(el('p', { class: 'ecl-m-note', text: T.loginNeed }));
            foot.appendChild(el('a', { class: 'ecl-btn ecl-btn-lg', href: t.login_url, text: T.login }));
        } else {
            var btn = el('button', { type: 'button', class: 'ecl-btn ecl-btn-lg', text: t.in_cart ? T.update : T.add });
            btn.addEventListener('click', function () { add(''); });
            if (!M.lab) {
                btn.disabled = true;
                foot.appendChild(el('p', { class: 'ecl-m-note', text: fmt(T.noneArea, t.area_name) }));
            }
            foot.appendChild(btn);
            M.ui.add = btn;
        }
        sync();
        var chosen = list.querySelector('input:checked');
        if (chosen) { chosen.focus(); }
    }

    function currentLab() {
        for (var i = 0; i < M.test.labs.length; i++) { if (M.test.labs[i].id === M.lab) { return M.test.labs[i]; } }
        return M.test.labs[0];
    }

    function sync() {
        var u = M.ui, l = currentLab(), n = M.n;
        u.count.textContent = String(n);
        u.minus.disabled = n <= 1;
        u.plus.disabled = n >= M.test.max;
        u.sum.textContent = '';
        function row(k, v, cls) { u.sum.appendChild(el('div', { class: cls || '' }, [el('dt', { text: k }), el('dd', { text: v })])); }
        row(T.price, money(l.price) + (n > 1 ? ' × ' + n : ''));
        if (l.savings > 0) { row(T.save, money(l.savings * n), 'ecl-m-save'); }
        row(T.total, money(l.price * n), 'ecl-m-total');
        u.msg.textContent = '';
    }

    function add(mode) {
        var u = M.ui, mine = M;
        u.msg.textContent = '';
        if (u.add) { u.add.disabled = true; u.add.textContent = T.adding; }
        post('ecare_lab_cart_add', { test_id: M.test.id, lab_id: M.lab, patients: M.n, on_conflict: mode }).then(function (r) {
            if (M !== mine) { return; }
            if (u.add) { u.add.disabled = !M.lab; u.add.textContent = M.test.in_cart ? T.update : T.add; }
            if (r.ok) { return done(r.data); }
            if (r.data.code === 'login' && r.data.login_url) { window.location.href = r.data.login_url; return; }
            if (r.data.code === 'other_lab') { return conflict(r.data); }
            u.msg.appendChild(el('p', { class: 'ecl-m-error', role: 'alert', text: r.data.message || T.failed }));
        }).catch(function () {
            if (M !== mine) { return; }
            if (u.add) { u.add.disabled = false; u.add.textContent = T.add; }
            u.msg.appendChild(el('p', { class: 'ecl-m-error', role: 'alert', text: T.failed }));
        });
    }

    function conflict(d) {
        var u = M.ui, lab = currentLab();
        var box = el('div', { class: 'ecl-m-conflict', role: 'alert' }, [el('p', { text: fmt(T.otherLab, d.current_lab || '') })]);
        var acts = el('div', { class: 'ecl-m-acts' });
        if (d.can_switch) {
            var sw = el('button', { type: 'button', class: 'ecl-btn', text: fmt(T.switchTo, lab.name) });
            sw.addEventListener('click', function () { add('switch'); });
            acts.appendChild(sw);
        } else {
            box.appendChild(el('p', { class: 'ecl-m-note', text: fmt(T.noSwitch, lab.name) }));
        }
        var fresh = el('button', { type: 'button', class: 'ecl-btn ecl-btn-ghost', text: T.startNew });
        fresh.addEventListener('click', function () { add('replace'); });
        var no = el('button', { type: 'button', class: 'ecl-btn ecl-btn-text', text: T.cancel });
        no.addEventListener('click', function () { u.msg.textContent = ''; if (u.add) { u.add.focus(); } });
        acts.appendChild(fresh);
        acts.appendChild(no);
        box.appendChild(acts);
        u.msg.textContent = '';
        u.msg.appendChild(box);
        (acts.querySelector('button') || fresh).focus();
    }

    function done(d) {
        M.body.textContent = '';
        M.body.appendChild(el('div', { class: 'ecl-m-done', role: 'status' }, [
            el('span', { class: 'ecl-m-tick', 'aria-hidden': 'true', text: '✓' }),
            el('p', { class: 'ecl-m-done-t', text: T.added }),
            el('p', { class: 'ecl-m-note', text: M.test.title + ' · ' + money(d.total) })
        ]));
        var keep = el('button', { type: 'button', class: 'ecl-btn ecl-btn-ghost', text: T.keep });
        keep.addEventListener('click', close);
        var go = el('a', { class: 'ecl-btn', href: d.cart_url || L.urls.cart, text: T.goCart });
        M.body.appendChild(el('div', { class: 'ecl-m-acts ecl-m-acts-end' }, [keep, go]));
        go.focus();
        try { document.dispatchEvent(new CustomEvent('ecl:cart', { detail: { count: d.count, total: d.total } })); } catch (e) { /* old browsers */ }
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.ecl [data-ecl-book]') : null;
        if (!b || e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) { return; }   // new-tab clicks still open the page
        var id = parseInt(b.getAttribute('data-ecl-book'), 10);
        if (!id) { return; }
        e.preventDefault();
        open(id, b);
    });

    // A card's Book link without JS lands on the detail page at #book; with
    // JS the same address opens the modal straight away.
    function fromHash() {
        if (window.location.hash !== '#book') { return; }
        var b = document.querySelector('.ecl-detail [data-ecl-book]#book');
        if (!b) { return; }
        if (window.history && history.replaceState) { history.replaceState(null, '', window.location.pathname + window.location.search); }
        open(parseInt(b.getAttribute('data-ecl-book'), 10), b);
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', fromHash); } else { fromHash(); }
})();
