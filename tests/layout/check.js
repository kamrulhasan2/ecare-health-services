// Layout regression test for the Create Family Member modal (finding #20).
// Needs Node and Playwright; optional, the PHP harnesses do not depend on it.
//   npm i -D playwright && npx playwright install chromium
//   node tests/layout/check.js
const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch();
  let fail = 0;
  const check = (l, got, ok) => { console.log(`  ${ok?'PASS':'FAIL'}  ${l}  (${got})`); if(!ok) fail++; };

  for (const [w, h, label] of [[390,844,'390px phone'], [768,1024,'768px tablet'], [1280,900,'desktop']]) {
    const p = await b.newPage({ viewport: { width: w, height: h } });
    await p.goto('file://' + require('path').join(__dirname, 'modal.html'));
    const r = await p.evaluate(() => {
      const box = document.querySelector('.ecare-family-modal-box');
      const grid = document.querySelector('.ecare-family-form-grid');
      const dob = [...document.querySelectorAll('.ecare-family-dob-row select')].map(e => Math.round(e.getBoundingClientRect().width));
      const dobH = Math.round(document.querySelector('.ecare-family-dob-row select').getBoundingClientRect().height);
      const ht = [...document.querySelectorAll('.ecare-family-height-row input')].map(e => Math.round(e.getBoundingClientRect().width));
      return {
        cols: getComputedStyle(grid).gridTemplateColumns,
        boxW: Math.round(box.getBoundingClientRect().width),
        dob, dobH, ht,
        docW: document.documentElement.scrollWidth,
        vw: document.documentElement.clientWidth,
        overflow: [...document.querySelectorAll('*')].filter(e => e.getBoundingClientRect().right > document.documentElement.clientWidth + 1).length
      };
    });
    console.log(`\n=== ${label} ===`);
    const oneCol = r.cols.split(' ').length === 1;
    if (w <= 600) {
      check('grid collapses to one column', r.cols, oneCol);
      check('DOB selects are wide enough to read', r.dob.join('/'), Math.min(...r.dob) >= 90);
      check('DOB selects meet the 44px touch target', r.dobH + 'px', r.dobH >= 44);
    } else {
      check('grid stays two columns', r.cols, !oneCol);
      check('DOB selects sized', r.dob.join('/'), Math.min(...r.dob) >= 90);
    }
    check('no horizontal overflow', `${r.docW} vs ${r.vw}`, r.docW <= r.vw);
    check('nothing spills past the viewport', r.overflow + ' elements', r.overflow === 0);
    await p.close();
  }
  await b.close();
  console.log(fail ? `\n${fail} FAILED` : '\nall good');
  process.exit(fail ? 1 : 0);
})();
