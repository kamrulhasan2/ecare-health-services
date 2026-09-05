# E-Care offline test harnesses

No WordPress, no database, no browser. Each file stubs the WordPress functions
the class under test touches, then asserts behaviour. Run them after every
change — they take under a second and catch the regressions that are otherwise
only visible in production.

From the plugin root:

    php tests/harness-secure-files.php
    php tests/harness-woocommerce.php
    php tests/harness-ajax-registration.php
    php tests/harness-caregiver-pricing.php

There is also an optional layout test, which needs Node and Playwright:

    npm i -D playwright && npx playwright install chromium
    node tests/layout/check.js

Each PHP harness exits 0 on success, 1 on any failure, so they chain:

    for f in tests/harness-*.php; do php "$f" || break; done

## What each one covers

**harness-secure-files.php** (76 assertions) — findings #1, #2, #5.
Private-storage guards, unguessable filenames, path-traversal containment,
IDOR on the delivery endpoint, upload size caps, the mime whitelist including
content sniffing (a PHP file renamed .png), and the per-IP throttle.

**harness-woocommerce.php** (20 assertions) — finding #3.
The HPOS meta read, and the status whitelist that stops a re-fired order hook
from dragging an 'assigned' ambulance back to 'approved' or reviving a
cancelled booking.

**harness-caregiver-pricing.php** (15 assertions) — finding #10.
The one function both the booking modal and the booking handler price from.
Covers the caregiver-type rate, the admin override, blank/zero/negative
overrides falling back rather than booking free, physiotherapist packages, and
zero-priced packages being dropped. If the modal and the handler ever stop
sharing this function, a customer gets quoted one number and charged another —
which has already happened once, at 1700 shown against 100 taken.

**harness-ajax-registration.php** — finding #12.
Asserts admin-only AJAX actions are never registered as wp_ajax_nopriv_, and
that the public set is exactly the intended list. Add a new action to
ECare_Ajax::init() and this fails until you classify it.

**tests/layout/check.js** — finding #20.
Loads the real stylesheet and measures the Create Family Member modal at 390px,
768px and desktop. It caught two things reading the CSS did not: the modal box
growing to 412px inside a 390px viewport (a flex item will not shrink below its
min-content unless you say so), and the Date of Birth dropdowns coming out at
58-72px on tablet and desktop, not only on phones.

## A note on the one seam in production code

`ECare_Secure_Files::is_real_upload()` exists only so the harness can override
`is_uploaded_file()`, which is always false outside a real HTTP POST. Runtime
behaviour is unchanged. Do not inline it.
