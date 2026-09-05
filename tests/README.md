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
    php tests/harness-booking-order.php
    php tests/harness-admin-lists.php
    php tests/harness-uninstall.php

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

**harness-booking-order.php** (17 assertions) — finding #21.
How a booking turns into a WooCommerce order. The product carrying the price is
keyed on caregiver-plus-package rather than on the display name, so a
caregiver's four packages no longer share one product whose price is rewritten
on every booking. Checked against the pre-fix code these assertions produce four
failures, so they are not decorative.

One caveat worth knowing: a sequential test cannot reproduce the race itself
(two requests interleaving between set_price() and add_product()). What it
proves is that the precondition is gone — the two bookings no longer touch the
same product. The total-equals-its-lines checks are likewise a guard against
future divergence, not a demonstration of a present one.

**harness-admin-lists.php** (27 assertions) — finding #14.
The admin list queries. Every list is bounded by a LIMIT, the offset follows the
page, the search box reaches patient name, phone and booking id, the KPI tiles
count the whole set rather than the page on screen, and a quote or a LIKE
wildcard typed into the search stays inside its string literal. Sixteen of these
fail against the pre-fix code.

**harness-ajax-registration.php** — finding #12.
Asserts admin-only AJAX actions are never registered as wp_ajax_nopriv_, and
that the public set is exactly the intended list. Add a new action to
ECare_Ajax::init() and this fails until you classify it.

**harness-uninstall.php** (38 assertions) — the uninstall guard.
`uninstall.php` used to drop `wp_ecare_bookings` and permanently delete every
provider, lab test and ambulance the moment somebody clicked Delete on the
Plugins screen — and deleting a plugin is ordinary housekeeping: replacing one
copy with another, clearing a duplicate folder. Now nothing goes unless
`ecare_delete_data_on_uninstall` is set to the exact string `yes`. Run 1 of the
harness asserts that a default site loses nothing at all — not a row, not a
query, not a file. Run 2 sets the option and asserts the cleanup is complete
and correctly scoped: both tables, all three post types including trashed and
auto-draft rows, the caregiver-type terms and their package prices, the seven
options and the rate-limit transients, and the private document store — while
pages, products, orders and Media Library attachments are left alone. Against
the pre-fix file 22 of these fail.

Two details worth keeping: the post sweep is batched with a pass counter, so a
post another plugin refuses to release cannot spin the loop for ever; and the
recursive file delete checks every step against the uploads basedir and unlinks
symlinks rather than following them, so a link planted inside the private
directory cannot aim the routine at the rest of the disk.

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
