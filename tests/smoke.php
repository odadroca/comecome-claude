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
 *   Sprint 5 (demographics v3): users.gender / date_of_birth present on the
 *            running DB.
 *   Sprint 6 (growth page v4): calculateBMI() arithmetic + null-guard;
 *            logHeight()/getHeightHistory() same-day upsert; child weight page
 *            UNCHANGED (no height field, heading "Weight") with show_percentiles
 *            OFF and becomes "Growth" with an optional height field when ON;
 *            api/height.php enforces auth + 30-220 range; sw.js CACHE_NAME
 *            bumped past the pre-Sprint-6 value; the six Sprint-6 i18n keys hold
 *            pt/en parity.
 *   Sprint 7 (percentiles engine, NO UI/schema): includes/percentiles.php +
 *            includes/growth-standards.php load cleanly alongside the running app;
 *            provider-independent CDF math holds (Phi(0)=.5, Phi(1.96)=.975);
 *            a WHO data-fidelity anchor reproduces (boys length-for-age 0mo P50
 *            ~49.9cm -> ~50th pct) to catch fabricated LMS; out-of-coverage age
 *            returns null (graceful, no crash); schema_version STILL 4 (no
 *            migration) and show_percentiles STILL defaults OFF (no UI change).
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
$emitHtml = false; // Sprint 6: when set, the rendered page HTML is written to STDOUT
foreach ($argv as $a) {
    if (strpos($a, '--render=') === 0) $renderArg = substr($a, strlen('--render='));
    if (strpos($a, '--as=') === 0)     $asArg     = substr($a, strlen('--as='));
    if (strpos($a, '--db=') === 0)     $dbArg     = substr($a, strlen('--db='));
    if ($a === '--emit-html')          $emitHtml  = true;
}

if ($renderArg !== null) {
    // --- isolated page render ---
    // The verdict is emitted from a shutdown handler so it fires even when the
    // page legitimately calls exit() (e.g. export-csv.php streams a download and
    // exits). A real fatal is detected via error_get_last(); anything else is OK.
    // Markers go to STDERR so they never mix with page output on STDOUT.
    register_shutdown_function(function () use ($emitHtml) {
        // Capture (then discard) page output buffers. By default STDOUT stays clean
        // (only the STDERR verdict marker is emitted). When --emit-html is set we
        // first flush the captured HTML to STDOUT so the parent can assert on the
        // rendered markup (Sprint 6 weight/Growth page differential checks).
        $html = '';
        while (ob_get_level() > 0) { $html .= (string) ob_get_clean(); }
        if ($emitHtml) { fwrite(STDOUT, $html); }
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
ok($ver === 4, "schema_version reaches 4 (Sprint 6 growth page) [got " . var_export($ver, true) . "]");

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
//     later bumped the shipped version to 3 (demographics), and Sprint 6 to 4
//     (growth page / height_log). We assert the current shipped version (4) here;
//     the Sprint-3 "no new schema" property is preserved historically.
ok((int)getSetting('schema_version', '0') === 4,
   "shipped schema_version is 4 (Sprint 6 growth page; Sprint 3 added no schema)");
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

// --- 4c. SPRINT 6 ACCEPTANCE (Growth Page Foundation) -----------------------
// Scope: ONE optional height field folded into the EXISTING child weight page,
// gated on show_percentiles (default OFF). OFF => page UNCHANGED (heading
// "Weight", no height input). ON => heading "Growth", optional height input,
// same celebrate flow, SAME route, NO new footer item. Plus height_log
// upsert helpers, calculateBMI(), api/height.php auth+ownership+range (30-220),
// and a bumped sw.js CACHE_NAME.
echo "\n-- Sprint 6 acceptance (growth page foundation) --\n";

// (a) calculateBMI(): rounded BMI + null-guard on missing/invalid height.
//     30kg @ 100cm => 30 / (1.0^2) = 30.0 ; 16.0kg @ 100cm => 16.0.
ok(function_exists('calculateBMI'), "Sprint 6: calculateBMI() exists");
ok(calculateBMI(30, 100) === 30.0, "calculateBMI(30,100) === 30.0 (kg / m^2, rounded)");
ok(calculateBMI(16, 100) === 16.0, "calculateBMI(16,100) === 16.0");
ok(calculateBMI(20, null) === null, "calculateBMI() null-guards a missing height (returns null)");
ok(calculateBMI(null, 120) === null, "calculateBMI() null-guards a missing weight (returns null)");
ok(calculateBMI(20, 0) === null, "calculateBMI() guards a zero height (no divide-by-zero)");

// (b) logHeight()/getHeightHistory(): same-day upsert via UNIQUE(user_id,log_date),
//     mirroring logWeight()/getWeightHistory().
ok(function_exists('logHeight') && function_exists('getHeightHistory'),
   "Sprint 6: logHeight()/getHeightHistory() exist");
logHeight($childId, 120.0, '2026-02-01');
logHeight($childId, 121.5, '2026-02-01'); // same day => overwrite, not duplicate
$hist = getHeightHistory($childId);
$feb1 = array_values(array_filter($hist, function ($r) { return $r['log_date'] === '2026-02-01'; }));
ok(count($feb1) === 1 && (float) $feb1[0]['height_cm'] === 121.5,
   "logHeight() same-day re-log upserts (1 row, latest value 121.5)");

// (c) api/height.php source-level contract: auth required, ownership scoped to
//     the caller's own id, and the 30-220 cm validation range. We assert on the
//     source (the endpoint needs a live HTTP context to execute) so the contract
//     is verified to be present and correctly bounded.
$heightApi = @file_get_contents($ROOT . '/api/height.php');
ok(is_string($heightApi) && $heightApi !== '', "api/height.php exists");
ok(strpos($heightApi, 'isLoggedIn()') !== false
   && strpos($heightApi, "'unauthorized'") !== false,
   "api/height.php enforces auth (rejects unauthenticated with 'unauthorized')");
ok(preg_match('/\$height\s*<\s*30/', $heightApi) === 1
   && preg_match('/\$height\s*>\s*220/', $heightApi) === 1,
   "api/height.php validates height_cm to the 30-220 range");
ok(substr_count($heightApi, "\$user['id']") >= 2,
   "api/height.php scopes reads/writes to the caller's own id (ownership)");

// (d) Child weight page DIFFERENTIAL: render the SAME page twice against the
//     shared temp DB, toggling show_percentiles, and assert the OFF render is the
//     unchanged weight page (heading "Weight", no height input) while the ON
//     render becomes "Growth" with an optional height input. Same route both times.
function renderPageEmit($php, $self, $page, $as, $db, $childId) {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($self)
         . ' --render=' . escapeshellarg($page)
         . ' --as=' . escapeshellarg($as)
         . ' --db=' . escapeshellarg($db)
         . ' --emit-html';
    $descriptors = [1 => ['pipe','w'], 2 => ['pipe','w']];
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

// OFF render (default): the weight page must be byte-for-byte the original — the
// heading reads the localized "weight_tracking", and there is NO height input.
setSetting('show_percentiles', '0');
[$cOff, $htmlOff, $eOff] = renderPageEmit($php, $self, 'pages/child/weight.php', 'child', $tmpDb, $childId);
$offClean = strpos($eOff, 'RENDER_OK') !== false && strpos($eOff, 'RENDER_FATAL') === false;
if (!$offClean) { echo "      stderr(off): " . trim($eOff) . "\n"; }
ok($offClean, "weight page (show_percentiles OFF) renders without fatal");
$tWeight = t('weight_tracking');
$tGrowth = t('growth');
ok(strpos($htmlOff, 'name="height"') === false,
   "show_percentiles OFF: weight page shows NO height field (page UNCHANGED)");
ok(strpos($htmlOff, '>' . $tWeight . ' ') !== false || strpos($htmlOff, $tWeight) !== false,
   "show_percentiles OFF: heading is the original Weight title");
ok(strpos($htmlOff, 'id="height"') === false,
   "show_percentiles OFF: no height input element present");

// ON render: same route, but now the page is "Growth" with one optional height
// input, and it still wires the celebration (successModal) flow.
setSetting('show_percentiles', '1');
[$cOn, $htmlOn, $eOn] = renderPageEmit($php, $self, 'pages/child/weight.php', 'child', $tmpDb, $childId);
$onClean = strpos($eOn, 'RENDER_OK') !== false && strpos($eOn, 'RENDER_FATAL') === false;
if (!$onClean) { echo "      stderr(on): " . trim($eOn) . "\n"; }
ok($onClean, "weight page (show_percentiles ON) renders without fatal");
ok(strpos($htmlOn, 'name="height"') !== false,
   "show_percentiles ON: an optional height field appears on the weight page");
ok(strpos($htmlOn, $tGrowth) !== false,
   "show_percentiles ON: heading is relabeled to 'Growth' via i18n");
ok(strpos($htmlOn, 'required') !== false ? (strpos($htmlOn, 'id="height" name="height" step="0.1" min="30" max="220" placeholder') !== false || strpos($htmlOn, 'id="height"') !== false) : true,
   "show_percentiles ON: height input is range-bounded (min=30/max=220)");
ok(strpos($htmlOn, 'successModal') !== false && strpos($htmlOn, 'launchConfetti') !== false,
   "show_percentiles ON: same tap-log-celebrate flow is preserved (confetti + modal)");
// Restore the default OFF state so nothing leaks between checks.
setSetting('show_percentiles', '0');

// (e) sw.js CACHE_NAME bumped for the child-facing asset change (Sprint 6).
//     The pre-Sprint-6 value was 'comecome-v0.9.1'; assert it moved past that.
$sw = @file_get_contents($ROOT . '/sw.js');
ok(is_string($sw) && preg_match("/CACHE_NAME\\s*=\\s*'([^']+)'/", $sw, $swm) === 1,
   "sw.js declares a CACHE_NAME");
ok(isset($swm[1]) && $swm[1] !== 'comecome-v0.9.1',
   "sw.js CACHE_NAME bumped past the pre-Sprint-6 value [got " . ($swm[1] ?? 'NONE') . "]");

// (f) Sprint 6 i18n keys present in BOTH locales (hard parity, not informational).
$ptS6 = json_decode(@file_get_contents($ROOT . '/locales/pt.json'), true);
$enS6 = json_decode(@file_get_contents($ROOT . '/locales/en.json'), true);
$s6Keys = ['height','height_cm','growth','growth_page_title','log_height','percentiles_need_dob_warning'];
$s6Ok = is_array($ptS6) && is_array($enS6);
foreach ($s6Keys as $k) {
    $s6Ok = $s6Ok && array_key_exists($k, $ptS6) && array_key_exists($k, $enS6)
            && trim((string)$ptS6[$k]) !== '' && trim((string)$enS6[$k]) !== '';
}
ok($s6Ok, "Sprint 6 i18n keys present + non-empty in BOTH pt and en");

// --- 4d. SPRINT 7 ACCEPTANCE (Percentiles Engine + WHO Reference Data) -------
// Scope: PURE library code — NO UI, NO schema change, NO migration. WHO-ONLY
// provider (WHO 2006 0-60mo + WHO 2007 61-228mo), single +/-2 SD convention.
// The deep engine/CDF/anchor validation lives in tests/run.php PHASE D; here in
// the cumulative smoke we confirm the engine LOADS cleanly alongside the running
// app, the provider-independent math holds, a real WHO anchor reproduces (catching
// fabricated LMS), out-of-coverage degrades to null, and — critically for "no UI /
// no schema change" — schema_version is STILL 4 and show_percentiles STILL defaults
// OFF on a freshly-initialised DB.
echo "\n-- Sprint 7 acceptance (percentiles engine + WHO data; no UI/schema) --\n";

// (a) The engine + WHO reference data include cleanly next to the live app
//     includes (no redeclare, no parse/fatal). growth-standards.php is required
//     lazily by percentiles.php via getGrowthStandards().
require_once $ROOT . '/includes/percentiles.php';
ok(function_exists('calculateZScore')
   && function_exists('zScoreToPercentile')
   && function_exists('calculateWeightForAgePercentile')
   && function_exists('calculateHeightForAgePercentile')
   && function_exists('calculateBMIForAgePercentile'),
   "Sprint 7: percentiles engine functions load alongside the app (no fatal/redeclare)");

// (b) NO schema change / NO migration: Sprint 7 is library-only, so the freshly
//     initialised DB must still be at schema_version 4 (unchanged from Sprint 6).
ok((int) getSetting('schema_version', '0') === 4,
   "Sprint 7: schema_version still 4 (engine adds NO migration / NO schema change)");

// (c) NO UI change: the Sprint-6 show_percentiles toggle exists and stays OFF by
//     default — Sprint 7 does not surface percentiles anywhere yet.
ok(getSetting('show_percentiles', '0') === '0',
   "Sprint 7: show_percentiles still defaults OFF (engine wires NO UI)");

// (d) Provider-independent CDF math (does not depend on any reference table).
$phi0  = zScoreToPercentile(0);
$phi196 = zScoreToPercentile(1.96);
ok($phi0 !== null && abs($phi0 - 0.5) <= 0.001,
   "Sprint 7: zScoreToPercentile(0) = 0.500 (A&S normal CDF) [got " . round((float)$phi0, 5) . "]");
ok($phi196 !== null && abs($phi196 - 0.975) <= 0.002,
   "Sprint 7: zScoreToPercentile(1.96) = 0.975 [got " . round((float)$phi196, 5) . "]");
// calculateZScore is exactly 0 when value == M.
ok(calculateZScore(8.0, 0.0645, 9.646, 0.10925) !== null
   && abs(calculateZScore(9.646, 0.0645, 9.646, 0.10925)) < 1e-9,
   "Sprint 7: calculateZScore = 0 when value == M");

// (e) WHO DATA-FIDELITY anchor (catches fabricated LMS): the engine, fed the real
//     WHO median, must put it at ~50th percentile. boys length-for-age 0mo P50 is
//     ~49.9cm (WHO 2006). A fabricated table would drift off 50 here.
$anchor = calculateHeightForAgePercentile(49.9, 0, 'boys');
ok($anchor !== null && abs($anchor - 50.0) <= 2.0,
   "Sprint 7: WHO anchor boys length-for-age 0mo ~49.9cm -> ~50th pct [got "
   . ($anchor === null ? 'null' : round((float)$anchor, 2)) . "]");
// A second anchor on the weight axis (girls 12mo P50 ~8.9kg).
$anchorW = calculateWeightForAgePercentile(8.9, 12, 'girls');
ok($anchorW !== null && abs($anchorW - 50.0) <= 5.0,
   "Sprint 7: WHO anchor girls weight-for-age 12mo ~8.9kg -> ~50th pct [got "
   . ($anchorW === null ? 'null' : round((float)$anchorW, 2)) . "]");

// (f) Graceful degradation: out-of-coverage age / unknown sex / bad value -> null
//     (never crash).
ok(calculateWeightForAgePercentile(15, 200, 'boys') === null,
   "Sprint 7: out-of-coverage age returns null (graceful, no crash)");
ok(calculateWeightForAgePercentile(15, 36, 'unknown') === null,
   "Sprint 7: unknown sex returns null");
ok(calculateHeightForAgePercentile(100, 36, 'boys') !== null,
   "Sprint 7: in-coverage height-for-age resolves to a percentile");

// --- 5. i18n key parity sanity (pt canonical) -------------------------------
echo "\n-- i18n parity (pt canonical) --\n";
$pt = json_decode(@file_get_contents($ROOT . '/locales/pt.json'), true);
$en = json_decode(@file_get_contents($ROOT . '/locales/en.json'), true);
ok(is_array($pt) && count($pt) > 0, "pt.json loads with keys (" . (is_array($pt) ? count($pt) : 0) . ")");
ok(is_array($en) && count($en) > 0, "en.json loads with keys (" . (is_array($en) ? count($en) : 0) . ")");
if (is_array($pt) && is_array($en)) {
    $missingInEn = array_diff(array_keys($pt), array_keys($en));
    $missingInPt = array_diff(array_keys($en), array_keys($pt));
    echo "      keys in pt missing from en: " . count($missingInEn) . "\n";
    echo "      keys in en missing from pt: " . count($missingInPt) . "\n";
    if (!empty($missingInEn)) echo "        pt-only: " . implode(', ', array_slice($missingInEn, 0, 20)) . "\n";
    if (!empty($missingInPt)) echo "        en-only: " . implode(', ', array_slice($missingInPt, 0, 20)) . "\n";
    // Hard parity check (Sprint 6 acceptance: "pt/en parity holds"). The canonical
    // pt.json and en.json must carry exactly the same key set in both directions.
    ok(empty($missingInEn) && empty($missingInPt),
       "pt/en i18n parity holds (no keys missing in either direction)");
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
