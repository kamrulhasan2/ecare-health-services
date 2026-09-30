<?php
/**
 * Guards ECare_Lab_Cart: the lab's own cart, kept apart from WooCommerce.
 *
 * What it protects:
 *   - one lab per cart; adding from another lab is refused unless the patient
 *     chooses to move the cart (only when that lab offers everything) or to
 *     start again
 *   - a lab that does not offer the test (or is switched off) cannot be added
 *   - patients stay between 1 and 10; the cart holds at most 30 tests
 *   - no price is ever stored: the cart is priced from the offerings each time,
 *     and a test the lab stopped offering is shown but not charged
 *   - an emptied cart leaves no user meta behind
 *   - the patient's area: only a real area is saved; a lab that does not
 *     collect there cannot be added or switched to, and blocks checkout
 *   - the Change list prices each lab for this cart and says why a lab is
 *     unavailable (a test it lacks, or the area)
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['umeta'] = array(); $GLOBALS['rows'] = array(); $GLOBALS['active'] = array();

function get_user_meta($u, $k, $s = false) { return $GLOBALS['umeta'][$u][$k] ?? ''; }
function update_user_meta($u, $k, $v) { $GLOBALS['umeta'][$u][$k] = $v; return true; }
function delete_user_meta($u, $k) { unset($GLOBALS['umeta'][$u][$k]); return true; }
function get_the_title($id) { return 'Test ' . $id; }
function is_wp_error($x) { return false; }
// Areas 501 (Dhanmondi) and 502 (Agrabad); 500 is a district.
$GLOBALS['terms'] = array(500 => 'district', 501 => 'area', 502 => 'area');
function get_term($id, $tax) { return isset($GLOBALS['terms'][$id]) ? (object) array('term_id' => $id) : null; }
class ECare_Locations {
    const TAXONOMY = 'ecare_location'; const LEVEL_AREA = 'area';
    public static function get_level($id) { return $GLOBALS['terms'][$id] ?? ''; }
}
// Which labs collect where.
$GLOBALS['coverage'] = array();
class ECare_Lab_Providers {
    public static function covers_area($lab, $area) { return !empty($GLOBALS['coverage'][$lab][$area]); }
}

// The real price rules; the rows themselves come from the world below.
class ECare_Lab_Offerings {
    const STATUS_ACTIVE = 'active';
    public static function effective_price($row) {
        $mrp = (float) $row->mrp; $price = (float) $row->price;
        return ($price > 0 && $price <= $mrp) ? $price : $mrp;
    }
    public static function available_for_test($test_id) {
        $out = array();
        foreach ($GLOBALS['rows'] as $r) {
            if ((int) $r->test_id === (int) $test_id && $r->status === 'active' && !empty($GLOBALS['active'][$r->provider_id])) {
                $out[] = $r;
            }
        }
        return $out;
    }
}

require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-lab-cart.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}
function offer($test, $lab, $mrp, $price = 0, $material = 0, $status = 'active') {
    $GLOBALS['rows'][] = (object) array('test_id' => $test, 'provider_id' => $lab, 'mrp' => $mrp, 'price' => $price, 'material_cost' => $material, 'status' => $status);
}
$C = 'ECare_Lab_Cart';
const U = 5;
const POPULAR = 99; const LABAID = 103; const IBN = 120;

// FBS(101): Popular 400, LabAid 450->380. CBC(89): Popular 500->450 (+50 material), LabAid 600.
// Package(105): Popular only. Lipid(77): IBN only, row switched off.
offer(101, POPULAR, 400);
offer(101, LABAID, 450, 380);
offer(89, POPULAR, 500, 450, 50);
offer(89, LABAID, 600);
offer(105, POPULAR, 800, 650);
offer(77, IBN, 900, 0, 0, 'inactive');
$GLOBALS['active'] = array(POPULAR => true, LABAID => true, IBN => true);

// ===========================================================================
echo "\n=== A. an empty cart ===\n";
// ===========================================================================
check('nothing stored: no lab, no items', $C::get(U), array('provider_id' => 0, 'items' => array()));
$GLOBALS['umeta'][U]['_ecare_lab_cart'] = 'garbage';
check('a damaged value reads as empty', $C::get(U), array('provider_id' => 0, 'items' => array()));
$GLOBALS['umeta'][U]['_ecare_lab_cart'] = array('provider_id' => 99, 'items' => array());
check('a lab with no items is no lab', $C::get(U)['provider_id'], 0);
$GLOBALS['umeta'] = array();

// ===========================================================================
echo "\n=== B. adding ===\n";
// ===========================================================================
check('a lab that does not offer the test is refused', $C::add(U, 105, LABAID, 1), array('ok' => false, 'code' => 'not_available'));
check('a switched-off offering is refused', $C::add(U, 77, IBN, 1)['code'], 'not_available');
$GLOBALS['active'][POPULAR] = false;
check('an inactive lab is refused', $C::add(U, 101, POPULAR, 1)['code'], 'not_available');
$GLOBALS['active'][POPULAR] = true;
check('...and nothing was stored by the refusals', isset($GLOBALS['umeta'][U]), false);

$r = $C::add(U, 101, LABAID, 2);
check('FBS at LabAid for 2', array($r['ok'], $C::get(U)), array(true, array('provider_id' => LABAID, 'items' => array(101 => 2))));
check('only the lab and the counts are stored - never a price', $GLOBALS['umeta'][U]['_ecare_lab_cart'], array('provider_id' => LABAID, 'items' => array(101 => 2)));
$C::add(U, 89, LABAID, 1);
check('a second test from the same lab', $C::get(U)['items'], array(101 => 2, 89 => 1));
$C::add(U, 101, LABAID, 3);
check('adding the same test again sets its patients, no duplicate', $C::get(U)['items'], array(101 => 3, 89 => 1));

// ===========================================================================
echo "\n=== C. patients ===\n";
// ===========================================================================
check('0 becomes 1', $C::clamp_patients(0), 1);
check('-4 becomes 1', $C::clamp_patients(-4), 1);
check('99 becomes 10', $C::clamp_patients(99), 10);
check('"3 people" becomes 3', $C::clamp_patients('3 people'), 3);
$C::add(U, 101, LABAID, 50);
check('an oversized request is clamped on add', $C::get(U)['items'][101], 10);
$C::set_patients(U, 101, 4);
check('set_patients', $C::get(U)['items'][101], 4);
$C::set_patients(U, 555, 4);
check('set_patients on a test not in the cart adds nothing', array_keys($C::get(U)['items']), array(101, 89));
$GLOBALS['umeta'][U]['_ecare_lab_cart']['items'][89] = 77;
check('a tampered stored count is clamped on read', $C::get(U)['items'][89], 10);
$C::set_patients(U, 89, 1);

// ===========================================================================
echo "\n=== D. one lab per cart ===\n";
// ===========================================================================
$before = $C::get(U);
$r = $C::add(U, 105, POPULAR, 1);
check('the package is only at Popular: refused with the current lab named', array($r['ok'], $r['code'], $r['current_lab']), array(false, 'other_lab', LABAID));
check('Popular also offers FBS and CBC: moving is possible', $r['can_switch'], true);
check('the refusal changed nothing', $C::get(U), $before);
check('an unknown choice is a refusal too', $C::add(U, 105, POPULAR, 1, 'whatever')['code'], 'other_lab');

$r = $C::add(U, 105, POPULAR, 1, 'switch');
check('switch: the whole cart moves to Popular and keeps its tests', array($r['ok'], $C::get(U)), array(true, array('provider_id' => POPULAR, 'items' => array(101 => 4, 89 => 1, 105 => 1))));

$r = $C::add(U, 101, LABAID, 1);
check('back to LabAid: it lacks the package, so it cannot take the cart', array($r['code'], $r['can_switch']), array('other_lab', false));
$r = $C::add(U, 101, LABAID, 1, 'switch');
check('...and "switch" is still refused, nothing moves', array($r['ok'], $C::get(U)['provider_id']), array(false, POPULAR));
$r = $C::add(U, 101, LABAID, 2, 'replace');
check('replace: the cart starts again with just this test', array($r['ok'], $C::get(U)), array(true, array('provider_id' => LABAID, 'items' => array(101 => 2))));

// ===========================================================================
echo "\n=== E. limits and removal ===\n";
// ===========================================================================
$C::clear(U);
for ($i = 1000; $i < 1000 + $C::MAX_ITEMS; $i++) { offer($i, POPULAR, 100); $C::add(U, $i, POPULAR, 1); }
check('30 tests fit', count($C::get(U)['items']), 30);
offer(2000, POPULAR, 100);
check('the 31st does not', $C::add(U, 2000, POPULAR, 1), array('ok' => false, 'code' => 'cart_full'));
check('a full cart can still change a count', $C::add(U, 1000, POPULAR, 3)['ok'], true);
$C::clear(U);
check('clear removes the meta row', isset($GLOBALS['umeta'][U]['_ecare_lab_cart']), false);

$C::add(U, 101, POPULAR, 1);
$C::add(U, 89, POPULAR, 1);
$C::remove(U, 101);
check('remove one', $C::get(U), array('provider_id' => POPULAR, 'items' => array(89 => 1)));
$left = $C::remove(U, 89);
check('removing the last test empties the cart and its lab', array($left, isset($GLOBALS['umeta'][U]['_ecare_lab_cart'])), array(array('provider_id' => 0, 'items' => array()), false));
check('after emptying, any lab may be chosen', $C::add(U, 101, LABAID, 1)['ok'], true);

// ===========================================================================
echo "\n=== F. pricing ===\n";
// ===========================================================================
$C::clear(U);
$C::add(U, 101, POPULAR, 2);   // 400 x2, no discount
$C::add(U, 89, POPULAR, 3);    // 450 x3 (MRP 500), material 50 each
$p = $C::priced(U);
check('subtotal at the sale price', $p['subtotal'], 2150.0);
check('subtotal at MRP', $p['subtotal_mrp'], 2300.0);
check('special discount = MRP - sale', $p['savings'], 150.0);
check('material cost counts each patient', $p['material'], 150.0);
check('two lines, both counted', array(count($p['lines']), $p['count']), array(2, 2));
check('line detail', $p['lines'][1], array('test_id' => 89, 'title' => 'Test 89', 'patients' => 3, 'available' => true, 'price' => 450.0, 'mrp' => 500.0, 'line_total' => 1350.0));

// A price change shows up at once - nothing was frozen in the cart.
$GLOBALS['rows'][2]->price = 420;
check('a new price applies straight away', $C::priced(U)['subtotal'], 2060.0);

// The lab stops offering CBC.
$GLOBALS['rows'][2]->status = 'inactive';
$p = $C::priced(U);
check('a test the lab dropped stays listed but unavailable', array($p['lines'][1]['available'], $p['lines'][1]['line_total']), array(false, 0.0));
check('...and is not charged', array($p['subtotal'], $p['subtotal_mrp'], $p['material'], $p['count']), array(800.0, 800.0, 0.0, 1));
$GLOBALS['active'][POPULAR] = false;
check('the whole lab switched off: nothing is charged', $C::priced(U)['subtotal'], 0.0);
check('...and nothing more can be added there', $C::add(U, 105, POPULAR, 1)['code'], 'not_available');
$GLOBALS['active'][POPULAR] = true;


// ===========================================================================
echo "\n=== G. the patient's area ===\n";
// ===========================================================================
$GLOBALS['umeta'] = array();
check('no area saved', $C::get_area(U), 0);
check('a district is not an area', array($C::set_area(U, 500), $C::get_area(U)), array(false, 0));
check('an unknown id is refused', $C::set_area(U, 999), false);
check('an area is saved', array($C::set_area(U, 501), $C::get_area(U)), array(true, 501));
unset($GLOBALS['terms'][501]);
check('an area deleted later reads as none', $C::get_area(U), 0);
$GLOBALS['terms'][501] = 'area';
$C::clear(U);
check('clearing the cart keeps the area', $C::get_area(U), 501);

// Popular collects in Dhanmondi only; LabAid in both.
$GLOBALS['coverage'] = array(POPULAR => array(501 => true), LABAID => array(501 => true, 502 => true));
check('adding from a lab that does not collect there is refused', $C::add(U, 101, POPULAR, 1, '', 502), array('ok' => false, 'code' => 'area'));
check('...and nothing was stored', isset($GLOBALS['umeta'][U]['_ecare_lab_cart']), false);
check('a lab that does collect there is fine', $C::add(U, 101, LABAID, 1, '', 502)['ok'], true);
check('no area given: no area check', $C::add(U, 89, LABAID, 1)['ok'], true);

// ===========================================================================
echo "\n=== H. the Change list ===\n";
// ===========================================================================
$C::clear(U);
check('empty cart: no labs to list', $C::vendors(U), array());
$C::add(U, 101, POPULAR, 2);    // Popular: 400 x2 ; LabAid 380 (MRP 450) x2
$C::add(U, 105, POPULAR, 1);    // package: Popular only, 650 (MRP 800)
$v = $C::vendors(U);
check('both labs that offer something are listed, the able one first', array_column($v, 'id'), array(POPULAR, LABAID));
check('Popular priced for the whole cart, patients counted', array($v[0]['total'], $v[0]['mrp'], $v[0]['savings'], $v[0]['available'], $v[0]['current']), array(1450.0, 1600.0, 150.0, true, true));
check('LabAid lacks the package: unavailable, and says which test', array($v[1]['available'], $v[1]['reason'], $v[1]['missing']), array(false, 'missing', array(105)));
check('no area chosen: serves_area is unknown', $v[0]['serves_area'], null);

$C::remove(U, 105);
$v = $C::vendors(U, 502);
check('Agrabad: LabAid collects there, Popular does not', array(array_column($v, 'id'), array_column($v, 'reason')), array(array(LABAID, POPULAR), array('', 'area')));
$v = $C::vendors(U, 501);
check('Dhanmondi: both can; cheaper LabAid first', array(array_column($v, 'id'), array_column($v, 'total')), array(array(LABAID, POPULAR), array(760.0, 800.0)));
$GLOBALS['rows'][1]->material_cost = 30;   // LabAid FBS: 380 + 30 material, x2 = 820 in all
$v = $C::vendors(U, 501);
check('material cost is counted per patient and decides the order', array(array_column($v, 'id'), array_column($v, 'material')), array(array(POPULAR, LABAID), array(0.0, 60.0)));
$GLOBALS['rows'][1]->material_cost = 0;

// ===========================================================================
echo "\n=== I. switching lab ===\n";
// ===========================================================================
$C::clear(U);
check('empty cart', $C::switch_lab(U, LABAID)['code'], 'empty');
$C::add(U, 101, POPULAR, 2);
$C::add(U, 105, POPULAR, 1);
check('LabAid lacks the package: refused', array($C::switch_lab(U, LABAID)['code'], $C::get(U)['provider_id']), array('missing', POPULAR));
$C::remove(U, 105);
$C::switch_lab(U, LABAID);
check('moved to LabAid, counts kept', $C::get(U), array('provider_id' => LABAID, 'items' => array(101 => 2)));
$r = $C::switch_lab(U, POPULAR, 502);
check('Popular does not collect in Agrabad: refused, cart stays', array($r, $C::get(U)['provider_id']), array(array('ok' => false, 'code' => 'area'), LABAID));
check('an unknown lab is refused', $C::switch_lab(U, 4242)['code'], 'missing');

// ===========================================================================
echo "
=== J. what blocks checkout ===
";
// ===========================================================================
$C::clear(U);
check('empty', $C::problems(U, 501), array('empty'));
$C::add(U, 101, LABAID, 1);
check('no area chosen', $C::problems(U, 0), array('no_area'));
check('the lab collects there: nothing blocks', $C::problems(U, 502), array());
$GLOBALS['coverage'][LABAID] = array(501 => true);
check('the lab stopped collecting there', $C::problems(U, 502), array('lab_area'));
$GLOBALS['rows'][1]->status = 'inactive';
check('a dropped test and the area, most urgent first', $C::problems(U, 502), array('unavailable', 'lab_area'));
$GLOBALS['rows'][1]->status = 'active';

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
