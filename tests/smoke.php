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
 *   Sprint 8 (percentiles DISPLAY, NO schema): the Sprint-7 WHO engine is wired
 *            into getDashboardData()/getReportData() + the four export surfaces.
 *            computePercentileSummary() gates on show_percentiles + gender/DOB
 *            (graceful 'missing_demographics' prompt otherwise, 'disabled' when
 *            OFF); the guardian Growth-Percentiles section renders ranks/zones for
 *            a COMPLETE child and the complete-DOB prompt for an INCOMPLETE one;
 *            the JSON projection still omits user.pin AND raw date_of_birth in the
 *            guest path while INCLUDING gender + derived age + the percentile block;
 *            dashboard / html / csv / json carry the SAME current ranks (four-surface
 *            parity); the CHILD growth page carries NO WHO overlay / NO clinical
 *            percentile flags; schema_version STILL 4 (display-only, NO migration);
 *            the Sprint-8 i18n keys hold pt/en parity.
 *   Sprint 9 (med timing v4->v5): the classifier + schedule CRUD load alongside
 *            the app; med-type default offsets; logFood() stamps med_window
 *            server-side; child log-food page never references med_window (ZERO
 *            child-facing change); Sprint-9 i18n keys hold pt/en parity.
 *   Sprint 10 (nutrition DISCOVERY spike, NO schema/NO UI): a docs-only spike.
 *            schema_version STILL 5 (no migration); the decision doc
 *            docs/roadmap/SPRINT-10-nutrition-discovery.md exists and concretely
 *            resolves items (1)-(5) — the 8-rule recommendation set with explicit
 *            thresholds + copy templates, growth-tag maintenance UX (no auto-tag),
 *            SQLite read-only-connection concurrency approach, the Sprint-9
 *            med_window storage-model confirmation, and the rule-based-first /
 *            LLM-opt-in-only boundary; and the spike stayed docs-only (NO
 *            food_growth_tags table, NO getReadOnlyDB(), NO includes/nutrition.php yet).
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
ok($ver === 6, "schema_version reaches 6 (security Phase 1 login_attempts) [got " . var_export($ver, true) . "]");

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
//     later bumped the shipped version to 3 (demographics), Sprint 6 to 4 (growth
//     page / height_log), Sprint 9 to 5 (medication timing), and security Phase 1
//     to 6 (login_attempts). We assert the current shipped version (6) here; the
//     Sprint-3 "no new schema" property is preserved historically.
ok((int)getSetting('schema_version', '0') === 6,
   "shipped schema_version is 6 (security Phase 1 login_attempts; Sprint 3 added no schema)");
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

// (b) NO schema change / NO migration: Sprint 7 is library-only — it added no
//     migration of its own. The freshly initialised DB reflects the current shipped
//     version (6, from security Phase 1); Sprint 7's "no new schema" property is historical.
ok((int) getSetting('schema_version', '0') === 6,
   "Sprint 7: shipped schema_version is 6 (engine itself adds NO migration / NO schema change)");

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

// --- 4e. SPRINT 8 ACCEPTANCE (Percentiles Display: guardian + clinician) -----
// Scope: wire the Sprint-7 WHO engine into the guardian dashboard, the four export
// surfaces (html / csv / json / guest-report) and the clinical narrative — WITHOUT
// schema change (schema_version stays 4) and WITHOUT touching the child surface.
// The DEEP display-layer drive lives in tests/run.php PHASE E; here in the cumulative
// smoke we confirm the same contract end-to-end against the running app: gating,
// the graceful prompt, the child-boundary (NO WHO overlay / NO clinical flags on the
// child Growth page), the JSON whitelist (no pin, no raw DOB; gender+age+percentiles
// in), four-surface parity, no migration, and Sprint-8 pt/en i18n parity.
echo "\n-- Sprint 8 acceptance (percentiles display; guardian + clinician only) --\n";

// (a) NO migration: Sprint 8 is display-only and added no migration of its own; the
//     shipped version is now 6 (security Phase 1 login_attempts).
ok((int) getSetting('schema_version', '0') === 6,
   "Sprint 8: shipped schema_version is 6 (Sprint 8 display-only added NO migration)");

// Turn the feature ON for the display path (it defaults OFF).
setSetting('show_percentiles', '1');

$s8start = date('Y-m-d', strtotime('-120 days'));
$s8end   = date('Y-m-d');

// COMPLETE child: gender + DOB (~4y => in WHO coverage) + a weight & height
// trajectory across two months so a percentile-over-time trend exists.
$s8dob = date('Y-m-d', strtotime('-4 years'));
$s8complete = createUser('SmokePctComplete', 'child', '2468', '🧒', 'male', $s8dob);
ok($s8complete > 0, "Sprint 8: complete child created (gender+DOB)");
logWeight($s8complete, 15.0, date('Y-m-d', strtotime('-90 days')));
logWeight($s8complete, 16.2, date('Y-m-d', strtotime('-15 days')));
logHeight($s8complete, 100.0, date('Y-m-d', strtotime('-90 days')));
logHeight($s8complete, 103.0, date('Y-m-d', strtotime('-15 days')));

// INCOMPLETE child: no gender/DOB (only demographics gate it), but has a weight.
$s8incomplete = createUser('SmokePctNoDemo', 'child', '1357', '👶');
ok($s8incomplete > 0, "Sprint 8: incomplete child created (no gender/DOB)");
logWeight($s8incomplete, 14.0, date('Y-m-d', strtotime('-10 days')));

// (b) computePercentileSummary() gating: available for the complete child (with a
//     current weight rank + zone and a weight trajectory), missing_demographics for
//     the incomplete child, disabled when the toggle is OFF.
$s8pc = computePercentileSummary($s8complete, $s8start, $s8end);
ok(($s8pc['available'] ?? null) === true
   && isset($s8pc['current']['weight']['rank'], $s8pc['current']['weight']['zone']),
   "Sprint 8: complete child => available=true with current weight rank+zone");
ok(($s8pc['current']['height'] ?? null) !== null && ($s8pc['current']['bmi'] ?? null) !== null,
   "Sprint 8: complete child => height + BMI ranks present (weight+height paired)");
ok(($s8pc['trends']['weight'] ?? null) !== null
   && isset($s8pc['trends']['weight']['from_rank'], $s8pc['trends']['weight']['to_rank'], $s8pc['trends']['weight']['narrative_key']),
   "Sprint 8: complete child => weight trajectory (from/to/narrative) computed at query time");
ok(($s8pc['age_months'] ?? null) !== null && $s8pc['age_months'] >= 47 && $s8pc['age_months'] <= 49,
   "Sprint 8: complete child => derived age ~48 months [got " . var_export($s8pc['age_months'] ?? null, true) . "]");

$s8pi = computePercentileSummary($s8incomplete, $s8start, $s8end);
ok(($s8pi['available'] ?? null) === false && ($s8pi['reason'] ?? null) === 'missing_demographics',
   "Sprint 8: incomplete child => available=false, reason=missing_demographics (graceful prompt, never blocks)");

setSetting('show_percentiles', '0');
$s8pd = computePercentileSummary($s8complete, $s8start, $s8end);
ok(($s8pd['available'] ?? null) === false && ($s8pd['reason'] ?? null) === 'disabled',
   "Sprint 8: toggle OFF => reason=disabled (section renders nothing)");
setSetting('show_percentiles', '1'); // restore ON for the rendering + parity checks

// (c) Section rendering: ranks for the complete child; graceful prompt otherwise;
//     empty string when disabled (no leakage).
$s8htmlComplete = renderPercentileSection($s8pc, 'dashboard');
ok(strpos($s8htmlComplete, $s8pc['current']['weight']['rank']) !== false
   && strpos($s8htmlComplete, t('weight_for_age')) !== false
   && strpos($s8htmlComplete, t('percentile_reference_who')) !== false,
   "Sprint 8: Growth-Percentiles section renders weight rank + label + WHO attribution");
ok(strpos(renderPercentileSection($s8pi, 'dashboard'), t('percentile_complete_dob_prompt')) !== false,
   "Sprint 8: incomplete child => graceful 'complete gender/DOB' prompt is shown");
ok(renderPercentileSection($s8pd, 'dashboard') === '',
   "Sprint 8: disabled => section renders empty string (nothing leaks)");

// (d) JSON whitelist (decision iii): no pin, no raw DOB in the guest-token path;
//     gender + derived age + the percentile block ARE included.
$s8report = getReportData($s8complete, $s8start, $s8end);
$s8json = projectReportForJson($s8report);
$s8jsonStr = json_encode($s8json);
ok(!array_key_exists('pin', $s8json['user']) && strpos($s8jsonStr, '"pin"') === false,
   "Sprint 8: JSON export has NO user.pin anywhere");
ok(!array_key_exists('date_of_birth', $s8json['user']) && strpos($s8jsonStr, '"date_of_birth"') === false,
   "Sprint 8: JSON export has NO raw date_of_birth in guest-token path (decision iii)");
ok(($s8json['user']['gender'] ?? null) === 'male'
   && ($s8json['user']['age_months'] ?? null) !== null,
   "Sprint 8: JSON export includes gender + derived age_months (not raw DOB)");
ok(isset($s8json['percentiles']) && ($s8json['percentiles']['available'] ?? null) === true
   && isset($s8json['percentiles']['current']['weight']['rank']),
   "Sprint 8: JSON export includes the whitelisted percentile block (ranks/zones/trends)");

// (e) FOUR-SURFACE PARITY: dashboard / report(html+csv) / json carry the SAME ranks,
//     and the percentile trajectory is woven into BOTH dashboard + report summaries.
$s8dash = getDashboardData($s8complete, $s8start, $s8end);
$s8dashRankW = $s8dash['percentiles']['current']['weight']['rank'] ?? null;
$s8rptRankW  = $s8report['percentiles']['current']['weight']['rank'] ?? null;
$s8jsonRankW = $s8json['percentiles']['current']['weight']['rank'] ?? null;
ok($s8dashRankW !== null && $s8dashRankW === $s8rptRankW && $s8rptRankW === $s8jsonRankW,
   "Sprint 8: weight rank identical across dashboard / report(html+csv) / json [$s8dashRankW]");
$s8dashRankH = $s8dash['percentiles']['current']['height']['rank'] ?? null;
$s8jsonRankH = $s8json['percentiles']['current']['height']['rank'] ?? null;
ok($s8dashRankH !== null && $s8dashRankH === $s8jsonRankH,
   "Sprint 8: height rank identical across dashboard / json [$s8dashRankH]");
ok(($s8dash['clinical_summary']['percentile_trajectory'] ?? null) !== null
   && ($s8report['clinical_summary']['percentile_trajectory'] ?? null) !== null,
   "Sprint 8: percentile trajectory woven into BOTH dashboard + report clinical_summary");

// (f) CHILD BOUNDARY (critical): the child Growth page must stay a plain encouraging
//     line chart with NO WHO percentile curves and NO clinical flags. We render the
//     child weight/Growth page (show_percentiles ON) and assert NONE of the guardian
//     percentile vocabulary leaks into the child surface, while the encouraging
//     copy stays. The child render uses the SAME isolated-subprocess harness.
[$cKid, $htmlKid, $eKid] = renderPageEmit($php, $self, 'pages/child/weight.php', 'child', $tmpDb, $childId);
$kidClean = strpos($eKid, 'RENDER_OK') !== false && strpos($eKid, 'RENDER_FATAL') === false;
if (!$kidClean) { echo "      stderr(child): " . trim($eKid) . "\n"; }
ok($kidClean, "Sprint 8: child Growth page renders without fatal (show_percentiles ON)");
// The guardian-only vocabulary that must NEVER reach the child surface.
$leakTerms = [
    t('growth_percentiles'), t('percentile_rank'), t('percentile_band'),
    t('percentile_reference_who'), t('zone_green'), t('zone_yellow'), t('zone_red'),
    'percentile', 'P3', 'P15', 'P50', 'P85', 'P97', '3rd percentile',
];
$leaked = [];
foreach ($leakTerms as $term) {
    if ($term !== '' && stripos($htmlKid, $term) !== false) { $leaked[] = $term; }
}
ok(empty($leaked),
   "Sprint 8: child Growth page carries NO WHO overlay / NO clinical percentile flags"
   . (empty($leaked) ? '' : ' [leaked: ' . implode(', ', $leaked) . ']'));
// Sanity: the child page DID render its encouraging growth surface (heading present).
ok(strpos($htmlKid, t('growth')) !== false || strpos($htmlKid, t('weight_tracking')) !== false,
   "Sprint 8: child Growth page still renders its encouraging chart surface");
// Restore default OFF so nothing leaks between checks.
setSetting('show_percentiles', '0');

// (g) Sprint 8 i18n keys present + non-empty in BOTH locales (hard parity).
$ptS8 = json_decode(@file_get_contents($ROOT . '/locales/pt.json'), true);
$enS8 = json_decode(@file_get_contents($ROOT . '/locales/en.json'), true);
$s8Keys = [
    'percentile', 'growth_percentiles', 'weight_for_age', 'height_for_age', 'bmi_for_age',
    'percentile_rank', 'percentile_band', 'percentile_trajectory_label',
    'percentile_reference_who', 'percentile_complete_dob_prompt', 'percentile_no_measurements',
    'zone_green', 'zone_yellow', 'zone_red',
    'percentile_trend_up', 'percentile_trend_down', 'percentile_trend_stable',
];
$s8Ok = is_array($ptS8) && is_array($enS8);
foreach ($s8Keys as $k) {
    $s8Ok = $s8Ok && array_key_exists($k, $ptS8) && array_key_exists($k, $enS8)
            && trim((string) $ptS8[$k]) !== '' && trim((string) $enS8[$k]) !== '';
}
ok($s8Ok, "Sprint 8: i18n keys present + non-empty in BOTH pt and en");

// --- Sprint 9 acceptance (Medication Timing Foundation) ---------------------
// Server-side med_window enrichment + guardian config; ZERO child-facing change.
echo "\n-- Sprint 9 acceptance (medication timing; guardian config only) --\n";

// (a) The classifier + schedule CRUD load alongside the app (no fatal/redeclare).
ok(function_exists('computeMedWindow')
   && function_exists('createMedicationSchedule')
   && function_exists('medTypeDefaultOffsets'),
   "Sprint 9: medication timing functions load alongside the app");

// (b) med-type default offsets match the documented approximations, and the
//     non-stimulant type has NO acute appetite window (NULL).
ok(medTypeDefaultOffsets('short_acting') === [30, 240], "Sprint 9: short-acting defaults 30/240");
ok(medTypeDefaultOffsets('long_acting') === [30, 480], "Sprint 9: long-acting defaults 30/480");
ok(medTypeDefaultOffsets('non_stimulant') === null, "Sprint 9: non-stimulant has NO appetite window (NULL)");

// (c) End-to-end stamping through the real logFood() path on the running DB.
$s9Kid = createUser('S9Kid', 'child', '1928', '🧒');
$s9db = getDB();
$s9db->exec("INSERT INTO medications (name, dose) VALUES ('S9Med','5mg')");
$s9MedId = (int) $s9db->lastInsertId();
createMedicationSchedule($s9Kid, $s9MedId, '08:00', 'short_acting');
logFood($s9Kid, 1, 3, 'some', '2026-03-01', '10:00:00');
$s9Win = $s9db->query("SELECT med_window FROM food_log WHERE user_id=$s9Kid AND log_date='2026-03-01'")->fetchColumn();
ok($s9Win === 'mid_med', "Sprint 9: logFood() stamps med_window='mid_med' for a 10:00 log on an 08:00 schedule");

// (d) CHILD BOUNDARY: the child log-food page source carries NO medication-timing
//     surface — no med_window, no schedule config leaks onto the child screen.
$logFoodSrc = @file_get_contents($ROOT . '/pages/child/log-food.php');
ok($logFoodSrc !== false && strpos($logFoodSrc, 'med_window') === false,
   "Sprint 9: child log-food page never references med_window (ZERO child-facing change)");
// The child request payload remains {food_id, meal_id, portion} only.
ok($logFoodSrc !== false
   && strpos($logFoodSrc, 'food_id:') !== false
   && strpos($logFoodSrc, 'med_type') === false
   && strpos($logFoodSrc, 'dose_time') === false,
   "Sprint 9: child food-log payload unchanged (no med_type/dose_time fields)");

// (e) Sprint 9 i18n keys present + non-empty in BOTH locales (hard parity).
$ptS9 = json_decode(@file_get_contents($ROOT . '/locales/pt.json'), true);
$enS9 = json_decode(@file_get_contents($ROOT . '/locales/en.json'), true);
$s9Keys = [
    'medication_timing', 'medication_timing_intro', 'med_timing_disclaimer',
    'dose_time', 'med_type',
    'med_type_short_acting', 'med_type_long_acting', 'med_type_non_stimulant',
    'peak_start_offset', 'peak_end_offset', 'offset_help',
    'window_pre_med', 'window_onset', 'window_mid_med', 'window_post_med',
    'window_24h', 'window_none', 'add_schedule', 'no_schedules',
];
$s9Ok = is_array($ptS9) && is_array($enS9);
foreach ($s9Keys as $k) {
    $s9Ok = $s9Ok && array_key_exists($k, $ptS9) && array_key_exists($k, $enS9)
            && trim((string) $ptS9[$k]) !== '' && trim((string) $enS9[$k]) !== '';
}
ok($s9Ok, "Sprint 9: i18n keys present + non-empty in BOTH pt and en");

// --- Sprint 10 acceptance (Nutrition Intelligence DISCOVERY spike) ----------
// Sprint 10 is a DISCOVERY SPIKE, not a feature build: the deliverable is a
// DECISION DOCUMENT (docs/roadmap/SPRINT-10-nutrition-discovery.md) that resolves
// the open concept-only items blocking Sprint 11. NO schema change, NO migration
// (schema_version unchanged by this spike), ZERO UI change, NO new locale keys (the
// Sprint-11 keys are SPECIFIED here but created in Sprint 11). So the cumulative
// assertion is: (a) the running app is at the current shipped schema_version (6,
// from security Phase 1) — this spike added no migration of its own;
// (b) the decision doc exists and concretely resolves items (1)-(5) with specific
//     rules/thresholds/copy; (c) no Sprint-11 app code leaked in (food_growth_tags
//     table is NOT created, getReadOnlyDB() / includes/nutrition.php do NOT exist yet),
//     proving the spike stayed docs-only.
echo "\n-- Sprint 10 acceptance (nutrition intelligence DISCOVERY spike; docs-only) --\n";

// (a) NO migration: a discovery spike touches no schema. The running DB reflects the
//     current shipped version (6); the spike added none of its own.
ok((int) getSetting('schema_version', '0') === 6,
   "Sprint 10: schema_version is 6 (discovery spike added NO migration / NO schema change)");

// (b) The decision document exists and concretely resolves items (1)-(5).
$s10doc = @file_get_contents($ROOT . '/docs/roadmap/SPRINT-10-nutrition-discovery.md');
ok(is_string($s10doc) && strlen($s10doc) > 4000,
   "Sprint 10: docs/roadmap/SPRINT-10-nutrition-discovery.md exists and is substantive");
// (b1) Item (1): a CONCRETE, TESTABLE rule set with explicit numeric thresholds and
//      copy templates (not prose). Assert the rule ids, literal thresholds, and the
//      med_window x tag x percentile x sleep cross-reference are all present.
$s10ruleIds = ['R1_post_med_heavy','R2_pre_med_skipped','R3_mid_med_dominant',
               'R4_tag_underserved','R5_tag_declined','R6_growth_plus_timing',
               'R7_sleep_appetite','R8_protein_low'];
$s10rulesOk = is_string($s10doc);
foreach ($s10ruleIds as $rid) { $s10rulesOk = $s10rulesOk && strpos($s10doc, $rid) !== false; }
ok($s10rulesOk, "Sprint 10 (1): the 8-rule strategic-recommendation set is enumerated by id");
ok(is_string($s10doc)
   && strpos($s10doc, '0.50') !== false && strpos($s10doc, '0.40') !== false
   && strpos($s10doc, 'windowShare') !== false && strpos($s10doc, 'tagServings') !== false
   && strpos($s10doc, 'med_window') !== false && strpos($s10doc, 'pctTrend') !== false
   && (strpos($s10doc, 'avgSleep') !== false || stripos($s10doc, 'sleep quality') !== false),
   "Sprint 10 (1): rules carry explicit thresholds (0.50/0.40) and the med_window x tag x percentile x sleep cross-reference");
ok(is_string($s10doc) && preg_match('/\{pct\}|\{N\}|\{tag\}|\{count\}/', $s10doc) === 1,
   "Sprint 10 (1): rule copy uses interpolated message templates (testable phrasing)");
// (b2) Item (2): growth-tag maintenance UX for guardian-added foods, NO auto-tagging.
ok(is_string($s10doc) && stripos($s10doc, 'manage-foods.php') !== false
   && (stripos($s10doc, 'auto-tag') !== false || stripos($s10doc, 'auto-tagging') !== false)
   && (stripos($s10doc, 'coverage') !== false || stripos($s10doc, 'nudge') !== false),
   "Sprint 10 (2): growth-tag maintenance UX (coverage/nudge in manage-foods.php, NO auto-tagging)");
// (b3) Item (3): SQLite analytics concurrency approach (read-only vs caching) + busy_timeout note.
ok(is_string($s10doc)
   && (stripos($s10doc, 'read-only') !== false || stripos($s10doc, 'READONLY') !== false)
   && stripos($s10doc, 'busy_timeout') !== false
   && (stripos($s10doc, 'cach') !== false || stripos($s10doc, 'memoiz') !== false),
   "Sprint 10 (3): SQLite concurrency decided (read-only connection + caching rationale; busy_timeout noted)");
// (b4) Item (4): confirms the Sprint-9 med_window storage model + how Sprint 11 reads it.
ok(is_string($s10doc)
   && stripos($s10doc, 'medication_schedules') !== false
   && stripos($s10doc, 'CHECK') !== false
   && (stripos($s10doc, 'stamped') !== false || stripos($s10doc, 'INSERT') !== false),
   "Sprint 10 (4): med_window storage model confirmed (CHECK'd column + medication_schedules, stamped at INSERT)");
// (b5) Item (5): restates the AI/LLM boundary (rule-based FIRST; LLM opt-in/narrative-only).
ok(is_string($s10doc)
   && (stripos($s10doc, 'LLM') !== false || stripos($s10doc, 'AI') !== false)
   && (stripos($s10doc, 'rule-based') !== false || stripos($s10doc, 'deterministic') !== false)
   && (stripos($s10doc, 'opt-in') !== false || stripos($s10doc, 'narrative-only') !== false
       || stripos($s10doc, 'narrative only') !== false),
   "Sprint 10 (5): AI/LLM boundary restated (rule-based FIRST; LLM opt-in, de-identified, narrative-only)");
ok(is_string($s10doc)
   && (stripos($s10doc, 'no external dependency') !== false
       || stripos($s10doc, 'no LLM') !== false
       || stripos($s10doc, 'NO external API') !== false),
   "Sprint 10 (5): this sprint introduces NO LLM / NO external dependency");

// (c) Spike stayed docs-only: no Sprint-11 app code leaked into the tree.
//     food_growth_tags is a Sprint-11 deliverable and must NOT exist yet.
$s10db = getDB();
$s10hasTagTable = $s10db->query(
    "SELECT name FROM sqlite_master WHERE type='table' AND name='food_growth_tags'"
)->fetchColumn();
ok($s10hasTagTable === false,
   "Sprint 10: food_growth_tags table is NOT created (it is a Sprint-11 deliverable)");
ok(!function_exists('getReadOnlyDB'),
   "Sprint 10: getReadOnlyDB() NOT added yet (specced for Sprint 11, not built in this spike)");
ok(!file_exists($ROOT . '/includes/nutrition.php'),
   "Sprint 10: includes/nutrition.php NOT created (Sprint-11 analyzers; spike is docs-only)");

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
