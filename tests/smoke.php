<?php
/**
 * ComeCome Cumulative Smoke Test (dependency-free CLI).
 * =====================================================
 *
 * Run:  php tests/smoke.php
 *
 * This is the CUMULATIVE regression smoke for the project. EXTEND it each
 * sprint with new assertions so passing it confirms earlier sprints still work.
 *
 * It is intentionally dependency-free: no PHPUnit, no Composer. It boots the
 * real application includes against a THROWAWAY temp SQLite database (it NEVER
 * touches db/data.db) and asserts core flows:
 *
 *   - DB create + migrate reaches the shipped schema_version.
 *   - Guardian id=1 / pin=0000 authentication path works (and rejects bad PIN).
 *   - Key guardian + child pages render with NO PHP fatal / parse error.
 *
 * Page rendering is done in isolated child processes (one per page) so a fatal
 * in one page cannot mask others; the child reports FATAL on a real fatal and
 * the parent fails the corresponding check.
 *
 * Exit code: 0 = all pass, 1 = one or more failures.
 *
 * --- Sprint coverage log -------------------------------------------------
 *   Sprints 0-2 (shipped v0.9.1, schema_version=2): auth, toggles, sleep.
 *   Sprint 3 (clinical report hardening + correlations): dashboard / export /
 *            guest-report render without fatals (no schema change).
 *   (Add Sprint 4+ assertions here as they land.)
 * -------------------------------------------------------------------------
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);

/* =========================================================================
 * CHILD MODE: render a single page in isolation and report fatal-or-ok.
 * Invoked as:  php tests/smoke.php --render=<pageRelativePath> --as=<guardian|child>
 * ========================================================================= */
$renderArg = null;
$asArg = 'guardian';
$dbArg = null;
foreach ($argv as $a) {
    if (strpos($a, '--render=') === 0) $renderArg = substr($a, strlen('--render='));
    if (strpos($a, '--as=') === 0)     $asArg     = substr($a, strlen('--as='));
    if (strpos($a, '--db=') === 0)     $dbArg     = substr($a, strlen('--db='));
}

if ($renderArg !== null) {
    // --- isolated page render ---
    // The verdict is emitted from a shutdown handler so it fires even when the
    // page legitimately calls exit() (e.g. export-csv.php streams a download and
    // exits). A real fatal is detected via error_get_last(); anything else is OK.
    // Markers go to STDERR so they never mix with page output on STDOUT.
    register_shutdown_function(function () {
        // discard any page output buffers so they don't pollute STDOUT
        while (ob_get_level() > 0) { @ob_end_clean(); }
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            fwrite(STDERR, "RENDER_FATAL: {$e['message']} in {$e['file']}:{$e['line']}\n");
        } else {
            fwrite(STDERR, "RENDER_OK\n");
        }
    });

    define('DB_PATH', $dbArg);
    define('DB_SCHEMA', dirname(__DIR__) . '/db/schema.sql');
    define('DB_SEED', dirname(__DIR__) . '/db/seed.sql');
    define('APP_NAME', 'ComeCome');
    define('APP_VERSION', 'test');
    define('DEFAULT_LOCALE', 'pt');
    define('LOCALES_PATH', dirname(__DIR__) . '/locales');
    define('SESSION_LIFETIME', 86400);
    define('GUEST_TOKEN_LIFETIME', 604800);
    date_default_timezone_set('Europe/Lisbon');
    @session_start();

    $root = dirname(__DIR__);
    require_once $root . '/includes/db.php';
    require_once $root . '/includes/i18n.php';
    require_once $root . '/includes/auth.php';
    require_once $root . '/includes/helpers.php';

    // Establish identity exactly as the router would, then include the page
    // with CWD at repo root (mirrors index.php's include resolution).
    if ($asArg === 'guardian') {
        $_SESSION['user_id'] = 1;
    } else {
        // throwaway child user id is passed via env for child renders
        $_SESSION['user_id'] = (int)(getenv('SMOKE_CHILD_ID') ?: 0);
    }
    chdir($root);

    // Report surfaces (Sprint 3) need a prepared $reportData / token, just as
    // export.php / guest-report.php set up before including these templates.
    $childForReport = (int)(getenv('SMOKE_CHILD_ID') ?: 0);
    // export.php sets $startDate/$endDate before including these templates; mirror it.
    $startDate = date('Y-m-d', strtotime('-30 days'));
    $endDate   = date('Y-m-d');
    if (in_array($renderArg, ['pages/guardian/export-html.php', 'pages/guardian/export-csv.php'], true)) {
        $reportData = getReportData($childForReport, $startDate, $endDate);
    }
    if ($renderArg === 'pages/guest-report.php') {
        $tok = generateGuestToken($childForReport, 1);
        $_GET['token'] = $tok;
    }

    // Buffer page output (kept out of STDOUT); the shutdown handler decides the
    // verdict. Pages that call exit() still trigger the shutdown handler.
    ob_start();
    include $renderArg;          // e.g. pages/guardian/dashboard.php
    // Stop here so we never fall through into PARENT-mode bootstrap below. The
    // shutdown handler still runs on exit and emits RENDER_OK / RENDER_FATAL.
    exit(0);
}

/* =========================================================================
 * PARENT MODE: bootstrap throwaway DB, run assertions.
 * ========================================================================= */
$failures = [];
$passes = 0;
function ok($cond, $msg) {
    global $failures, $passes;
    if ($cond) { echo "  [PASS] $msg\n"; $passes++; }
    else       { echo "  [FAIL] $msg\n"; $failures[] = $msg; }
}

echo "=== ComeCome Cumulative Smoke Test ===\n";

// --- Throwaway temp DB (NEVER db/data.db) ----------------------------------
$tmpDb = tempnam(sys_get_temp_dir(), 'comecome_smoke_') . '.db';
foreach ([substr($tmpDb, 0, -4), $tmpDb] as $p) { if (file_exists($p)) @unlink($p); }

define('DB_PATH', $tmpDb);
define('DB_SCHEMA', $ROOT . '/db/schema.sql');
define('DB_SEED', $ROOT . '/db/seed.sql');
define('APP_NAME', 'ComeCome');
define('APP_VERSION', 'test');
define('DEFAULT_LOCALE', 'pt');
define('LOCALES_PATH', $ROOT . '/locales');
define('SESSION_LIFETIME', 86400);
define('GUEST_TOKEN_LIFETIME', 604800);
date_default_timezone_set('Europe/Lisbon');
@session_start();

// Guard against ever resolving to the real DB.
$realDb = realpath($ROOT . '/db/data.db');
if ($realDb !== false && realpath($tmpDb) === $realDb) {
    fwrite(STDERR, "ABORT: smoke DB resolved to real data.db\n");
    exit(1);
}

// Include db.php first and create the schema BEFORE i18n.php (which calls
// getSetting() at include time) — mirrors config.php running
// initializeDatabase() before index.php includes i18n.php.
require_once $ROOT . '/includes/db.php';

// --- 1. DB create + migrate -------------------------------------------------
echo "\n-- DB bootstrap --\n";
initializeDatabase();

require_once $ROOT . '/includes/i18n.php';
require_once $ROOT . '/includes/auth.php';
require_once $ROOT . '/includes/helpers.php';

$ver = (int) getSetting('schema_version', '0');
ok($ver === 3, "schema_version reaches 3 (Sprint 5 demographics) [got " . var_export($ver, true) . "]");

// --- 2. Guardian id=1 / pin=0000 auth path (Sprint 0+) ----------------------
echo "\n-- Auth path (guardian id=1 / pin=0000) --\n";
$g = getUserById(1);
ok($g && $g['type'] === 'guardian', "guardian id=1 exists and is type 'guardian'");
ok(authenticateUser(1, '0000') === true, "authenticateUser(1,'0000') succeeds");
ok(isGuardian() === true, "session reflects guardian after auth");
// reset session, verify wrong PIN rejected
$_SESSION = [];
ok(authenticateUser(1, '9999') === false, "authenticateUser(1,'9999') (wrong PIN) is rejected");
ok(!isset($_SESSION['user_id']), "no session established on wrong PIN");

// --- 3. Create throwaway child for child-page renders -----------------------
echo "\n-- Seed throwaway child --\n";
// Look up a meal + food id on a short-lived connection that is fully closed
// (cursor freed + handle unset) BEFORE any write, so SQLite's single-writer
// lock is never contended within this single process.
$lookup = getDB();
$mealId = $lookup->query("SELECT id FROM meals WHERE active=1 ORDER BY sort_order LIMIT 1")->fetchColumn();
$foodId = $lookup->query("SELECT id FROM foods WHERE active=1 LIMIT 1")->fetchColumn();
$lookup = null;

$childId = createUser('SmokeKid', 'child', '1234', '🧒');
ok($childId > 0, "throwaway child user created (id=$childId)");
// give the child a little data so pages exercise real query paths
if ($mealId && $foodId) {
    logFood($childId, $foodId, $mealId, 'some');
}
logWeight($childId, 22.5);
saveCheckIn($childId, date('Y-m-d'), 3, 4, 1, 'smoke note', 3);

// --- 4. Render key pages in isolated subprocesses ---------------------------
echo "\n-- Page render (isolated, no-fatal) --\n";
$php = PHP_BINARY;
$self = __FILE__;

function renderPage($php, $self, $page, $as, $db, $childId) {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($self)
         . ' --render=' . escapeshellarg($page)
         . ' --as=' . escapeshellarg($as)
         . ' --db=' . escapeshellarg($db);
    $descriptors = [1 => ['pipe','w'], 2 => ['pipe','w']];
    // Build a clean string-only env (proc_open cannot stringify array values
    // such as $_SERVER['argv']). Carry through only scalar env vars + child id.
    $childEnv = ['SMOKE_CHILD_ID' => (string)$childId];
    foreach ($_ENV as $k => $v) { if (is_scalar($v)) $childEnv[$k] = (string)$v; }
    if (empty($_ENV)) {
        foreach (['PATH','SystemRoot','TEMP','TMP','windir'] as $k) {
            $val = getenv($k);
            if ($val !== false) $childEnv[$k] = $val;
        }
    }
    $proc = proc_open($cmd, $descriptors, $pipes, null, $childEnv);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);
    return [$code, $out, $err];
}

$guardianPages = [
    'pages/guardian/dashboard.php',
    'pages/guardian/manage-users.php',
    'pages/guardian/manage-foods.php',
    'pages/guardian/manage-medications.php',
    'pages/guardian/manage-sleep.php',
    'pages/guardian/settings.php',
    'pages/guardian/export.php',
    // Sprint 3 report surfaces (all four must stay in parity):
    'pages/guardian/export-html.php',
    'pages/guardian/export-csv.php',
    'pages/guest-report.php',
];
$childPages = [
    'pages/child/log-food.php',
    'pages/child/check-in.php',
    'pages/child/weight.php',
    'pages/child/history.php',
];

$isClean = function ($err) {
    // Clean = shutdown reached with RENDER_OK and no RENDER_FATAL marker.
    return strpos($err, 'RENDER_OK') !== false && strpos($err, 'RENDER_FATAL') === false;
};
foreach ($guardianPages as $p) {
    [$code, $out, $err] = renderPage($php, $self, $p, 'guardian', $tmpDb, $childId);
    $clean = $isClean($err);
    if (!$clean) { echo "      stderr: " . trim($err) . "\n"; }
    ok($clean, "guardian page renders without fatal: $p");
}
foreach ($childPages as $p) {
    [$code, $out, $err] = renderPage($php, $self, $p, 'child', $tmpDb, $childId);
    $clean = $isClean($err);
    if (!$clean) { echo "      stderr: " . trim($err) . "\n"; }
    ok($clean, "child page renders without fatal: $p");
}

// --- 4b. SPRINT 3 ACCEPTANCE (Clinical Report Hardening + Correlations) ------
// Scope per .claude/SPRINT-PLAN_reconciled.md, Sprint 3: clinician-grade report
// + dashboard "Insights" + sleep->next-day correlation. "No schema / no migration."
echo "\n-- Sprint 3 acceptance (clinical report + correlations) --\n";
$s3start = date('Y-m-d', strtotime('-30 days'));
$s3end   = date('Y-m-d');

// (a) Sprint 3 added NO migration (it was schema_version 2 at the time). Sprint 5
//     later bumped the shipped version to 3 (demographics). We assert the current
//     shipped version (3) here; the Sprint-3 "no new schema" property is preserved
//     historically — Sprint 3 itself contributed none of these columns/tables.
ok((int)getSetting('schema_version', '0') === 3,
   "shipped schema_version is 3 (Sprint 5 demographics; Sprint 3 added no schema)");
// (a2) Sprint 5 positive check: demographics columns exist on the running DB.
$smokeDb = getDB();
$userCols = $smokeDb->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
ok(in_array('gender', $userCols, true), "Sprint 5: users.gender column present on running DB");
ok(in_array('date_of_birth', $userCols, true), "Sprint 5: users.date_of_birth column present on running DB");

// (b) Correlation engine: sparse data must report enough=false (graceful), and
//     with >=5 paired days it must return the documented lag-1 structure.
$corrSparse = computeCorrelations($childId, $s3start, $s3end);
ok(is_array($corrSparse) && ($corrSparse['enough'] ?? null) === false,
   "computeCorrelations() reports enough=false on sparse data (graceful)");

// Seed 8 consecutive days of check-ins so the lag-1 engine can actually compute.
$corrKid = createUser('SmokeCorr', 'child', '5678', '📈');
for ($d = 10; $d >= 1; $d--) {
    $date = date('Y-m-d', strtotime("-$d days"));
    // alternate good/poor sleep so good and poor buckets both populate
    $sleep = ($d % 2 === 0) ? 5 : 1;
    $appetite = ($d % 2 === 0) ? 5 : 2; // appetite tracks prior-night sleep
    saveCheckIn($corrKid, $date, $appetite, 3, 1, null, $sleep);
}
$corrRich = computeCorrelations($corrKid, date('Y-m-d', strtotime('-30 days')), date('Y-m-d'));
ok(is_array($corrRich)
   && ($corrRich['enough'] ?? null) === true
   && isset($corrRich['sleep_vs_next_appetite']['direction'], $corrRich['sleep_vs_next_appetite']['note_key'])
   && isset($corrRich['sleep_vs_next_mood']['direction'], $corrRich['sleep_vs_next_mood']['note_key']),
   "computeCorrelations() returns sleep_vs_next_appetite/mood with direction+note_key on rich data");

// (c) Clinical summary aggregates the surfaces (insights panel + all exports).
$clin = computeClinicalSummary($childId, $s3start, $s3end);
ok(is_array($clin)
   && array_key_exists('med_adherence_pct', $clin)
   && array_key_exists('appetite_trend', $clin)
   && array_key_exists('mood_trend', $clin)
   && isset($clin['sleep']) && array_key_exists('avg_quality', $clin['sleep'])
   && array_key_exists('correlations', $clin),
   "computeClinicalSummary() returns med/appetite/mood/sleep/correlations");

// (d) Graceful degradation: a child with NO data must not fatal; the summary
//     must still build with null-ish fields and enough=false correlations.
$emptyChild = createUser('SmokeEmpty', 'child', '4321', '👶');
$corrEmpty = computeCorrelations($emptyChild, $s3start, $s3end);
$clinEmpty = computeClinicalSummary($emptyChild, $s3start, $s3end);
ok(($corrEmpty['enough'] ?? null) === false
   && is_array($clinEmpty) && array_key_exists('correlations', $clinEmpty),
   "no-data child degrades gracefully (enough=false, summary still builds, no fatal)");

// (e) JSON export whitelist (decision iii): pin must NEVER be serialized.
$report = getReportData($childId, $s3start, $s3end);
$json = projectReportForJson($report);
$jsonStr = json_encode($json);
ok(is_array($json) && isset($json['user']) && !array_key_exists('pin', $json['user']),
   "projectReportForJson() omits user.pin from JSON export");
ok(strpos($jsonStr, '"pin"') === false,
   "serialized JSON export contains no 'pin' field anywhere");

// --- 5. i18n key parity sanity (pt canonical) -------------------------------
echo "\n-- i18n parity (pt canonical) --\n";
$pt = json_decode(@file_get_contents($ROOT . '/locales/pt.json'), true);
$en = json_decode(@file_get_contents($ROOT . '/locales/en.json'), true);
ok(is_array($pt) && count($pt) > 0, "pt.json loads with keys (" . (is_array($pt) ? count($pt) : 0) . ")");
ok(is_array($en) && count($en) > 0, "en.json loads with keys (" . (is_array($en) ? count($en) : 0) . ")");
if (is_array($pt) && is_array($en)) {
    $missingInEn = array_diff(array_keys($pt), array_keys($en));
    // Only informational — do not hard-fail on translation gaps, but surface count.
    echo "      keys in pt missing from en: " . count($missingInEn) . "\n";
}

// --- cleanup ----------------------------------------------------------------
$db = null;
if (file_exists($tmpDb)) @unlink($tmpDb);

// --- verdict ----------------------------------------------------------------
echo "\n=== Result: $passes passed, " . count($failures) . " failed ===\n";
if (empty($failures)) {
    echo "SMOKE: PASS\n";
    exit(0);
}
echo "SMOKE: FAIL\n";
foreach ($failures as $f) echo "  - $f\n";
exit(1);
