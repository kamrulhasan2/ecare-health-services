// Behaviour test for the password show/hide toggle on the registration forms.
// Needs Node and Playwright; optional, the PHP harnesses do not depend on it.
//   npm i -D playwright && npx playwright install chromium
//   node tests/layout/check-password.js
//
// password.html loads the real stylesheet, the real ecare-script.js, and
// jQuery from the WordPress install this plugin sits inside, so the handler
// under test is the shipped one rather than a copy.
//
// It exists because of a bug a code review would not have caught: the icons
// are <svg>, SVGElement does not reflect a `hidden` IDL property the way
// HTMLElement does, and jQuery's .prop('hidden', true) therefore set a
// JavaScript field that no CSS ever reads. Every visible symptom looked right
// except the one that mattered - the eye never changed.
const { chromium } = require('playwright');

(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
  await p.goto('file://' + require('path').join(__dirname, 'password.html'));

  const results = await p.evaluate(() => {
    const R = [];
    const t = (label, ok, got) => R.push({ label, ok: !!ok, got: String(got) });
    const vis = (el) => getComputedStyle(el).display !== 'none';

    if (typeof window.jQuery !== 'function') {
      t('jQuery is available (WordPress copy, relative path)', false, 'missing');
      return R;
    }

    const wraps = document.querySelectorAll('.ecare-password-wrap');
    const wrap  = wraps[0];
    const input = wrap.querySelector('input');
    const btn   = wrap.querySelector('.ecare-password-toggle');
    const eyeOpen    = wrap.querySelector('.ecare-eye-show');
    const eyeSlashed = wrap.querySelector('.ecare-eye-hide');

    t('both password fields are wrapped', wraps.length === 2, wraps.length);

    input.value = 'SuperSecret123';

    const ir = input.getBoundingClientRect();
    const br = btn.getBoundingClientRect();
    t('the button sits inside the field', br.right <= ir.right + 1 && br.left > ir.left,
      Math.round(ir.right - br.left) + 'px in from the right edge');
    t('the field reserves room for it, so long values do not run underneath',
      parseInt(getComputedStyle(input).paddingRight, 10) >= 36, getComputedStyle(input).paddingRight);
    t('the button is type=button, not a submit', btn.type === 'button', btn.type);

    t('starts masked', input.type === 'password', input.type);
    t('open eye visible, slashed eye not', vis(eyeOpen) && !vis(eyeSlashed),
      'open=' + vis(eyeOpen) + ' slashed=' + vis(eyeSlashed));

    btn.click();

    t('one click reveals the password', input.type === 'text', input.type);
    t('the icon really swaps - slashed eye now rendered', !vis(eyeOpen) && vis(eyeSlashed),
      'open=' + vis(eyeOpen) + ' slashed=' + vis(eyeSlashed));
    t('aria-pressed becomes true', btn.getAttribute('aria-pressed') === 'true', btn.getAttribute('aria-pressed'));
    t('the label tells a screen reader what happens next',
      btn.getAttribute('aria-label') === 'Hide password', btn.getAttribute('aria-label'));
    t('focus returns to the field', document.activeElement === input, document.activeElement === input);
    t('the caret lands at the end, so typing continues', input.selectionStart === 14, input.selectionStart);
    t('the value is untouched', input.value === 'SuperSecret123', input.value);
    t('the form was not submitted', document.getElementById('submitted').hidden, 'not submitted');

    btn.click();

    t('a second click masks it again', input.type === 'password', input.type);
    t('and the open eye is back', vis(eyeOpen) && !vis(eyeSlashed),
      'open=' + vis(eyeOpen) + ' slashed=' + vis(eyeSlashed));
    t('aria-pressed back to false', btn.getAttribute('aria-pressed') === 'false', btn.getAttribute('aria-pressed'));

    const other = wraps[1].querySelector('input');
    wraps[1].querySelector('.ecare-password-toggle').click();
    t('the two fields toggle independently',
      input.type === 'password' && other.type === 'text', input.type + ' / ' + other.type);

    return R;
  });

  let fail = 0;
  for (const r of results) {
    console.log(`  ${r.ok ? 'PASS' : 'FAIL'}  ${r.label}  (${r.got})`);
    if (!r.ok) { fail++; }
  }

  await b.close();
  console.log(fail ? `\n${fail} FAILED of ${results.length}` : `\nall good (${results.length} assertions)`);
  process.exit(fail ? 1 : 0);
})();
