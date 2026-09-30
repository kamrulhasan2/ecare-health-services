<?php
/**
 * Guards the lab data migration planner (ECare_Lab_Migration::plan).
 *
 * plan() is pure: it reads a snapshot and says what would happen. The data
 * here is shaped like the live catalogue - the same test entered once per
 * location and provider, several providers in one box, old spellings and typos.
 *
 * What it protects:
 *   - same-named tests merge into one; the published one is kept
 *   - every provider on every row becomes a price on the kept test
 *   - two prices for one lab on one test: flagged, the lower is used
 *   - areas land under the right district, or are flagged when that is unclear
 *   - old spellings (Chittagong) resolve; typos are suggested, never guessed
 *   - corrections from the admin are applied everywhere
 */

define('ABSPATH', __DIR__ . '/');
function add_action() {} function add_filter() {}
function is_admin() { return false; }
function __($s, $d = null) { return $s; }

require_once __DIR__ . '/../includes/class-ecare-locations.php';
require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-lab-migration.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}
$M = 'ECare_Lab_Migration';
function has_issue($plan, $needle) {
    foreach ($plan['issues'] as $i) if (strpos($i[1], $needle) !== false) return true;
    return false;
}

$snap = array(
    'locations' => array(
        1  => array('name' => 'Dhaka',      'parent' => 0,  'level' => 'division'),
        2  => array('name' => 'Chattogram', 'parent' => 0,  'level' => 'division'),
        3  => array('name' => 'Rangpur',    'parent' => 0,  'level' => 'division'),
        10 => array('name' => 'Dhaka',      'parent' => 1,  'level' => 'district'),
        20 => array('name' => 'Chattogram', 'parent' => 2,  'level' => 'district'),
        30 => array('name' => 'Rangpur',    'parent' => 3,  'level' => 'district'),
        31 => array('name' => 'Dinajpur',   'parent' => 3,  'level' => 'district'),
        11 => array('name' => 'Farmgate',   'parent' => 10, 'level' => 'area'),
        12 => array('name' => 'Banani',     'parent' => 10, 'level' => 'area'),
    ),
    'providers'  => array(100 => 'Popular Diagnostic Centre', 101 => 'LabAid'),
    'categories' => array(200 => 'Diabetes', 201 => 'Hematology'),
    'tests' => array(
        // FBS entered twice: Dhaka and Chittagong (old spelling), same provider, different price.
        array('id' => 5, 'title' => 'FBS', 'status' => 'publish', 'provider' => 'Popular Diagnostic Centre', 'division' => 'Dhaka', 'district' => 'Dhaka',
              'area' => 'Banani, banani , Mirpur-1, Mirpur-10', 'price' => '400', 'category' => 'Diabetes'),
        array('id' => 6, 'title' => 'fbs ', 'status' => 'publish', 'provider' => 'Popular Diagnostic Centre', 'division' => 'Chittagong', 'district' => 'Chittagong',
              'area' => 'Agrabad', 'price' => '350', 'category' => ''),
        // CBC: two providers in one box, one new; three districts, one a typo; an area typo.
        array('id' => 7, 'title' => 'CBC', 'status' => 'draft', 'provider' => 'Ever Care,LabAid', 'division' => 'Dhaka, Rangpur', 'district' => 'Dhaka,Rangupr,Dinajpur',
              'area' => 'Mirpur,Dhanmondi, Farmget', 'price' => '300', 'category' => 'lab_test'),
        array('id' => 8, 'title' => 'CBC', 'status' => 'publish', 'provider' => 'LabAid', 'division' => 'Dhaka', 'district' => 'Dhaka',
              'area' => 'Banani', 'price' => '300', 'category' => 'Hematology'),
        // No price, and a provider name that is a typo of an existing one.
        array('id' => 9, 'title' => 'Lipid Profile', 'status' => 'publish', 'provider' => 'Lab Aid Ltd', 'division' => '', 'district' => '',
              'area' => '', 'price' => '', 'category' => ''),
    ),
);

// ===========================================================================
echo "\n=== A. helpers ===\n";
// ===========================================================================
check('split tidies and drops blanks', $M::split(' Dhaka,,  Rangpur , '), array('Dhaka', 'Rangpur'));
check('a typo is suggested', $M::suggest('Rangupr', array('Rangpur', 'Dinajpur', 'Dhaka')), 'Rangpur');
check('too far is not suggested', $M::suggest('Sylhet', array('Rangpur', 'Dhaka')), null);
check('short names are never guessed', $M::suggest('Ctg', array('Ctgx')), null);
check('two equally close candidates: no guess', $M::suggest('Mirpur-3', array('Mirpur-1', 'Mirpur-2')), null);
check('correction lines', $M::parse_map("Rangupr => Rangpur\nnoise\n Farmget=>Farmgate "), array('rangupr' => 'Rangpur', 'farmget' => 'Farmgate'));

// ===========================================================================
echo "\n=== B. the plan, before any corrections ===\n";
// ===========================================================================
$p = $M::plan($snap);
check('five old tests', $p['summary']['tests'], 5);
check('merged by name into three', array_keys($p['groups']), array('fbs', 'cbc', 'lipidprofile'));
check('FBS keeps #5; #6 would be drafted', array($p['groups']['fbs']['master'], count($p['groups']['fbs']['members'])), array(5, 2));
check('CBC keeps the published #8 over the draft #7', $p['groups']['cbc']['master'], 8);
check('FBS at Popular: two prices flagged, lower used', array($p['groups']['fbs']['offers']['populardiagnosticcentre'], has_issue($p, 'has two prices (400 and 350)')), array(350.0, true));
check('CBC gets both labs from the shared box', array_keys($p['groups']['cbc']['offers']), array('evercare', 'labaid'));
check('Chittagong resolves to Chattogram (no issue raised)', has_issue($p, 'Chittagong'), false);
check('Rangupr is flagged with a suggestion', has_issue($p, 'district "Rangupr" is not a known district - did you mean "Rangpur"?'), true);
check('Farmget is flagged as looking like Farmgate', has_issue($p, 'area "Farmget" looks like "Farmgate"'), true);
check('suggestions collected for the corrections box', array_values(array_map(function ($s) { return $s[0] . '=>' . $s[1]; }, $p['suggestions'])),
    array('Rangupr=>Rangpur', 'Farmget=>Farmgate'));
check('CBC areas with two districts need a home', has_issue($p, 'area "Mirpur" - which district?'), true);
check('Banani is matched, not created twice', isset($p['new_areas']['10|banani']), false);
check('new Dhaka areas from the single-district FBS row', array_keys($p['new_areas']), array('10|mirpur1', '10|mirpur10', '20|agrabad'));
check('Popular covers Banani (existing) and the new Dhaka and Chattogram areas',
    $p['coverage']['populardiagnosticcentre'], array('id:12', 'new:10|mirpur1', 'new:10|mirpur10', 'new:20|agrabad'));
check('Ever Care is new', $p['providers']['evercare'][1], 0);
check('LabAid is existing', $p['providers']['labaid'][1], 101);
check('Lab Aid Ltd is 3 edits from LabAid: listed as new, not guessed', array($p['providers']['labaidltd'][1], $p['providers']['labaidltd'][2]), array(0, null));
check('Mirpur is not a typo of Mirpur-1 (seen on real data)', $M::suggest('Mirpur', array('Mirpur-1', 'Mirpur-10', 'Banani')), null);
check('a distance of exactly 3 is never suggested', $M::suggest('abcdefg', array('abcdxyz')), null);
check('Lipid Profile has no price: flagged, no offer', array($p['groups']['lipidprofile']['offers'], has_issue($p, 'Lipid Profile: no price')), array(array(), true));
check('unknown category "lab_test" is left alone', has_issue($p, 'category "lab_test" matches no category'), true);
check('known categories are mapped', $p['groups']['cbc']['categories'], array(201));

// ===========================================================================
echo "\n=== C. with the admin's corrections ===\n";
// ===========================================================================
$p = $M::plan($snap,
    $M::parse_map("Rangupr => Rangpur\nFarmget => Farmgate\nLab Aid Ltd => LabAid\nlab_test => Hematology"),
    $M::parse_map("Mirpur => Dhaka\nDhanmondi => Dhaka"));
check('district typo fixed', has_issue($p, 'Rangupr'), false);
check('area typo mapped onto the existing Farmgate', in_array('id:11', $p['coverage']['evercare'], true), true);
check('Mirpur and Dhanmondi placed in Dhaka via area districts', array_keys($p['new_areas']), array('10|mirpur1', '10|mirpur10', '20|agrabad', '10|mirpur', '10|dhanmondi'));
check('Lipid Profile now points at LabAid', array_keys($p['providers']), array('populardiagnosticcentre', 'evercare', 'labaid'));
check('category corrected', $p['groups']['cbc']['categories'], array(201));
$p2 = $M::plan($snap, $M::parse_map("Rangupr => Rangpur\nFarmget => Farmgate"), $M::parse_map("Farmget => Dhaka\nMirpur => Dhaka\nDhanmondi => Dhaka"));
check('an area district written with the typed spelling still applies after a correction', has_issue($p2, 'area "Farmget" - which district?'), false);
check('what is left to review', array_values(array_map(function ($i) { return $i[0]; }, $p['issues'])), array('conflict', 'test'));
check('summary', $p['summary'], array('tests' => 5, 'merged_tests' => 3, 'drafted' => 2, 'new_providers' => 1, 'new_areas' => 5, 'offerings' => 3, 'issues' => 2));

// ===========================================================================
echo "\n=== D. nothing to do ===\n";
// ===========================================================================
$empty = $M::plan(array('tests' => array()) + $snap);
check('no old tests, empty plan', $empty['summary'], array('tests' => 0, 'merged_tests' => 0, 'drafted' => 0, 'new_providers' => 0, 'new_areas' => 0, 'offerings' => 0, 'issues' => 0));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
