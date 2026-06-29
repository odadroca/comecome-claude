<?php
/**
 * ComeCome — HTTP-level DISCLAIMER ATTESTATION smoke (Launch Sprint 2, A21 Task 2).
 * ==================================================================================
 *
 * WHY THIS EXISTS:
 *   Validates the attestation gate wired into the settings POST in
 *   pages/guardian/settings.php. Enabling show_nutrition_insights requires the
 *   guardian to post the medical disclaimer acknowledgement checkbox at the same time.
 *
 *   A. (Reject-no-checkbox) POST enable show_nutrition_insights WITHOUT the
 *      nutrition_attestation_acknowledge checkbox + valid CSRF → setting stays '0'
 *      AND nutrition_attestation_version is unset/empty (no attestation written).
 *   B. (Accept-with-checkbox) POST enable show_nutrition_insights WITH the checkbox
 *      + valid CSRF → setting becomes '1' AND guardianNutritionAttestationCurrent()
 *      is true (attestation stamped).
 *   C. (CSRF) POST enable with checkbox but MISSING/INVALID CSRF → rejected; setting
 *      unchanged (stays '0').
 *
 * SAFETY:
 *   The spawned `php -S` runs with COMECOME_DB_PATH pointed at a THROWAWAY
 *   temp DB; it never touches the real db/data.db.
 *
 * USAGE:   php tests/http_disclaimer_smoke.php
 * EXIT:    0 = all assertions passed, non-zero = a failure.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
$FAIL = [];
$PASS = 0;
function ok($cond, $msg) {
    global $FAIL, $PASS;
    if ($cond) { echo "  [PASS] $msg\n"; $PASS++; }
    else       { echo "  [FAIL] $msg\n"; $FAIL[] = $msg; }
}

echo "==========================================================\n";
echo " ComeCome HTTP DISCLAIMER ATTESTATION smoke (php -S + curl)\n";
echo "==========================================================\n";

// --- Throwaway DB + seeded users --------------------------------------------
$tmpDb = tempnam(sys_get_temp_dir(), 'comecome_disclaimer_') . '.db';
ok($tmpDb !== '' && $tmpDb !== false, 'temp DB path is non-empty (never falls back to real db/data.db)');
if ($tmpDb === '' || $tmpDb === false) { fwrite(STDERR, "no temp DB path\n"); exit(1); }
@unlink($tmpDb);

// Seed a fresh app DB.
define('DB_PATH', $tmpDb);
define('DB_SCHEMA', $ROOT . '/db/schema.sql');
define('DB_SEED', $ROOT . '/db/seed.sql');
define('APP_NAME', 'ComeCome');
define('APP_VERSION', 'test');
define('DEFAULT_LOCALE', 'pt');
define('LOCALES_PATH', $ROOT . '/locales');
define('SESSION_LIFETIME', 86400);
define('GUEST_TOKEN_LIFETIME', 604800);
define('CONSENT_NOTICE_VERSION', 1);
define('NUTRITION_ATTESTATION_VERSION', 1);
date_default_timezone_set('Europe/Lisbon');
require_once $ROOT . '/includes/db.php';
require_once $ROOT . '/includes/auth.php';
initializeDatabase();

// The seeded default guardian (id=1) has a default PIN — the default-PIN gate
// fires before settings is reachable. Change it to a non-default value so
// refreshGuardianPinDefaultFlag() clears the guardian_pin_is_default flag.
updateUser(1, 'DefaultGuardian', 'guardian', '9999', '🔐', 1);

// Create a guardian with a non-default PIN AND recorded consent.
// Without consent, the consent gate (Plan 1) redirects before settings.
$guardianPin = '7777';
$guardianId  = createUser('SmokeGuardian', 'guardian', $guardianPin, '🧑');
ok($guardianId > 0, "seeded guardian (id=$guardianId, PIN=$guardianPin)");
setSetting('guardian_consent_version', (string) CONSENT_NOTICE_VERSION);
ok(getSetting('guardian_consent_version') === (string) CONSENT_NOTICE_VERSION,
   "consent recorded for SmokeGuardian");

// Ensure show_nutrition_insights starts OFF and no attestation is stored.
setSetting('show_nutrition_insights', '0');
setSetting('nutrition_attestation_version', '');
ok(getSetting('show_nutrition_insights', '0') === '0', "show_nutrition_insights starts OFF");
ok(getSetting('nutrition_attestation_version', '') === '', "nutrition_attestation_version starts empty");

// --- D-preseed: child + food logs (done before server start to avoid DB-lock contention
// when writing while the server's singleton PDO connection is alive).
// show_nutrition_insights stays '0' here — Groups A/B/C test the settings POST gate.
// The child and logs just sit dormant until Group D sets the feature flag to '1'.
$dPreDb = getDB();
$dPreDb->exec("INSERT INTO users (name, type, pin, avatar_emoji, active) VALUES ('PanelKid', 'child', '0000', '🧒', 1)");
$panelKidId = (int) $dPreDb->lastInsertId();
// Use WAL journal mode so concurrent test-process writes work while the server's PDO
// singleton connection is alive (WAL allows one writer + any number of readers).
$dPreDb->exec("PRAGMA journal_mode=WAL");
$insPreD = $dPreDb->prepare(
    "INSERT INTO food_log (user_id, food_id, meal_id, portion, log_date, log_time)
     VALUES (?, 1, 1, 'some', ?, '08:00:00')"
);
for ($d = 1; $d <= 5; $d++) {
    $insPreD->execute([$panelKidId, date('Y-m-d', strtotime("-{$d} days"))]);
}
$dPreLogDays = (int) $dPreDb->query(
    "SELECT COUNT(DISTINCT log_date) FROM food_log WHERE user_id = $panelKidId"
)->fetchColumn();
ok($dPreLogDays >= 5, "D-preseed: child (id=$panelKidId) has $dPreLogDays food-log days (>= NI_MIN_LOG_DAYS=5)");
$dPreDb = null;

// Release the PDO handle so the spawned server gets a clean lock on the file.
gc_collect_cycles();

// --- Pick a free port -------------------------------------------------------
$host = '127.0.0.1';
$pickSock = @stream_socket_server("tcp://$host:0", $pErrno, $pErrstr);
if (!$pickSock) { fwrite(STDERR, "ABORT: could not allocate a free port\n"); @unlink($tmpDb); exit(2); }
$pickName = stream_socket_get_name($pickSock, false);
fclose($pickSock);
$pickPos = strrpos($pickName, ':');
$port = ($pickPos === false) ? 0 : (int) substr($pickName, $pickPos + 1);
if ($port <= 0) { fwrite(STDERR, "ABORT: could not parse a free port\n"); @unlink($tmpDb); exit(2); }

// --- Spawn php -S with the throwaway DB -------------------------------------
$phpBin = PHP_BINARY;
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$env = $_ENV;
$env['COMECOME_DB_PATH'] = $tmpDb;
$cmd = escapeshellarg($phpBin) . ' -S ' . $host . ':' . $port . ' -t ' . escapeshellarg($ROOT);
$proc = proc_open($cmd, $descriptors, $pipes, $ROOT, $env);
if (!is_resource($proc)) { fwrite(STDERR, "ABORT: could not start php -S\n"); @unlink($tmpDb); exit(2); }
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$cleanup = function () use ($proc, $pipes, $tmpDb) {
    foreach ($pipes as $p) { if (is_resource($p)) { fclose($p); } }
    proc_terminate($proc);
    proc_close($proc);
    for ($i = 0; $i < 5 && file_exists($tmpDb); $i++) { if (@unlink($tmpDb)) break; usleep(20000); }
};

// --- Wait for the server ----------------------------------------------------
$up = false;
for ($i = 0; $i < 50; $i++) {
    $fp = @fsockopen($host, $port, $errno, $errstr, 0.2);
    if ($fp) { fclose($fp); $up = true; break; }
    usleep(100000);
}
if (!$up) { echo "  [FAIL] php -S did not come up on $host:$port\n"; $cleanup(); exit(1); }
ok(true, "php -S dev server is up on $host:$port (throwaway DB)");

$base = "http://$host:$port";
$guardianJar = tempnam(sys_get_temp_dir(), 'cc_dis_guardian_');

/**
 * Run curl (no redirect follow by default), return [httpCode, body].
 */
function curlReq($args) {
    $out = shell_exec($args . ' -s -w "\n__HTTP__%{http_code}"');
    if ($out === null) { return [0, '']; }
    $pos = strrpos($out, "\n__HTTP__");
    if ($pos === false) { return [0, $out]; }
    $body = substr($out, 0, $pos);
    $code = (int) substr($out, $pos + strlen("\n__HTTP__"));
    return [$code, $body];
}

/**
 * Run curl, dump response headers to stdout (-D -), do NOT follow redirects.
 * Returns [httpCode, headers, body].
 */
function curlReqWithHeaders($args) {
    $out = shell_exec($args . ' -s -D - -w "\n__HTTP__%{http_code}"');
    if ($out === null) { return [0, '', '']; }
    $pos = strrpos($out, "\n__HTTP__");
    $raw  = ($pos !== false) ? substr($out, 0, $pos) : $out;
    $code = ($pos !== false) ? (int) substr($out, $pos + strlen("\n__HTTP__")) : 0;
    $parts   = preg_split('/\r?\n\r?\n/', $raw, 2);
    $headers = $parts[0] ?? '';
    $body    = $parts[1] ?? '';
    return [$code, $headers, $body];
}

$loginUrl    = "$base/index.php?page=login";
$settingsUrl = "$base/index.php?page=settings";

// ==========================================================================
// LOGIN as guardian
// ==========================================================================
echo "\n--- Login: guardian ---\n";

[$lc, $lbody] = curlReq('curl -c ' . escapeshellarg($guardianJar) . ' ' . escapeshellarg($loginUrl));
ok($lc === 200, "GET login page returns 200 [got $lc]");
$csrfLogin = '';
if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $lbody, $m)) {
    $csrfLogin = $m[1];
}
ok($csrfLogin !== '', "login page exposes csrf-token [got '$csrfLogin']");

$loginCmd = 'curl -c ' . escapeshellarg($guardianJar) . ' -b ' . escapeshellarg($guardianJar)
    . ' --data-urlencode ' . escapeshellarg('csrf_token=' . $csrfLogin)
    . ' --data-urlencode ' . escapeshellarg('user_id=' . $guardianId)
    . ' --data-urlencode ' . escapeshellarg('pin=' . $guardianPin)
    . ' ' . escapeshellarg($loginUrl);
[$glc, $glbody] = curlReq($loginCmd);
ok($glc >= 200 && $glc < 400, "guardian login POST completes [got $glc]");

// GET settings page — follow redirect to reach 200.
[$sc, $sbody] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L ' . escapeshellarg($settingsUrl)
);
ok($sc === 200, "guardian GET ?page=settings returns 200 [got $sc]");

// Scrape CSRF token from the settings page for subsequent POSTs.
$pageCsrf = '';
if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $sbody, $mc)) {
    $pageCsrf = $mc[1];
}
ok($pageCsrf !== '', "settings page exposes a CSRF token [got '$pageCsrf']");

// ==========================================================================
// GROUP A — Reject: enable WITHOUT the acknowledgement checkbox
// ==========================================================================
echo "\n--- A. Reject: POST enable without attestation checkbox → setting stays '0', no attestation ---\n";

[$ac, $ah, $abody] = curlReqWithHeaders(
    'curl -c ' . escapeshellarg($guardianJar) . ' -b ' . escapeshellarg($guardianJar)
    . ' --data-urlencode ' . escapeshellarg('csrf_token=' . $pageCsrf)
    . ' --data-urlencode ' . escapeshellarg('show_nutrition_insights=1')
    // Note: nutrition_attestation_acknowledge is intentionally omitted
    . ' ' . escapeshellarg($settingsUrl)
);
ok($ac >= 200 && $ac < 400, "A: POST without checkbox completes [got $ac]");

// Body assertions: rejection message present, no false success indication.
// The server renders the full settings page (no redirect on reject), so follow
// redirects (-L) to land on the final HTML and read the body.
[$arf, $arbody] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L'
    . ' --data-urlencode ' . escapeshellarg('csrf_token=' . $pageCsrf)
    . ' --data-urlencode ' . escapeshellarg('show_nutrition_insights=1')
    // Note: nutrition_attestation_acknowledge intentionally omitted
    . ' ' . escapeshellarg($settingsUrl)
);
// The app locale defaults to 'pt'; test against the pt string.
ok(
    strpos($arbody, 'É necessário reconhecer o aviso') !== false
    || strpos($arbody, 'acknowledge the disclaimer') !== false
    || strpos($arbody, 'nutrition_attestation_required') !== false,
    "A: response body contains 'must acknowledge' message on reject"
);
ok(
    strpos($arbody, 'changes_saved') === false
    && strpos($arbody, 'Changes saved') === false
    && strpos($arbody, 'alterações guardadas') === false
    && strpos($arbody, 'Alterações guardadas') === false,
    "A: response body does NOT contain success/saved indicator on reject"
);

// Read DB state directly (the spawned server writes to $tmpDb).
$dbA = new PDO('sqlite:' . $tmpDb);
$dbA->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$niA = $dbA->query("SELECT value FROM settings WHERE \"key\"='show_nutrition_insights'")->fetchColumn();
ok($niA === '0' || $niA === false,
   "A: show_nutrition_insights stays '0' after enable without checkbox [got '" . ($niA === false ? 'unset' : $niA) . "']");
$attA = $dbA->query("SELECT value FROM settings WHERE \"key\"='nutrition_attestation_version'")->fetchColumn();
ok($attA === '' || $attA === false,
   "A: nutrition_attestation_version unset/empty after enable without checkbox [got '" . ($attA === false ? 'unset' : $attA) . "']");
$dbA = null;

// Re-scrape CSRF from the settings page for group B.
[$sc2, $sbody2] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L ' . escapeshellarg($settingsUrl)
);
ok($sc2 === 200, "A: re-GET settings returns 200 [got $sc2]");
$pageCsrf2 = '';
if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $sbody2, $mc2)) {
    $pageCsrf2 = $mc2[1];
}
ok($pageCsrf2 !== '', "A: re-scraped CSRF token for group B [got '$pageCsrf2']");

// ==========================================================================
// GROUP B — Accept: POST enable WITH the acknowledgement checkbox
// ==========================================================================
echo "\n--- B. Accept: POST enable WITH attestation checkbox + valid CSRF → setting '1', attestation stamped ---\n";

[$bc, $bh, $bbody] = curlReqWithHeaders(
    'curl -c ' . escapeshellarg($guardianJar) . ' -b ' . escapeshellarg($guardianJar)
    . ' --data-urlencode ' . escapeshellarg('csrf_token=' . $pageCsrf2)
    . ' --data-urlencode ' . escapeshellarg('show_nutrition_insights=1')
    . ' --data-urlencode ' . escapeshellarg('nutrition_attestation_acknowledge=1')
    . ' ' . escapeshellarg($settingsUrl)
);
ok($bc >= 200 && $bc < 400, "B: POST with checkbox completes [got $bc]");

// Read DB state directly.
$dbB = new PDO('sqlite:' . $tmpDb);
$dbB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$niB = $dbB->query("SELECT value FROM settings WHERE \"key\"='show_nutrition_insights'")->fetchColumn();
ok($niB === '1',
   "B: show_nutrition_insights is '1' after enable with checkbox [got '" . ($niB === false ? 'unset' : $niB) . "']");
$attB = $dbB->query("SELECT value FROM settings WHERE \"key\"='nutrition_attestation_version'")->fetchColumn();
ok($attB === (string) NUTRITION_ATTESTATION_VERSION,
   "B: nutrition_attestation_version = current version after enable [got '" . ($attB === false ? 'unset' : $attB) . "', expected '" . NUTRITION_ATTESTATION_VERSION . "']");
$attAtB = $dbB->query("SELECT value FROM settings WHERE \"key\"='nutrition_attestation_at'")->fetchColumn();
ok($attAtB !== false && $attAtB !== '',
   "B: nutrition_attestation_at timestamp written [got '" . ($attAtB === false ? 'unset' : $attAtB) . "']");
$dbB = null;

// ==========================================================================
// GROUP C — CSRF: POST enable with checkbox but MISSING CSRF → rejected
// ==========================================================================
echo "\n--- C. CSRF: POST enable with checkbox but missing CSRF → rejected; setting unchanged ---\n";

// First reset the setting to '0' directly so this group has something to prove.
$dbPre = new PDO('sqlite:' . $tmpDb);
$dbPre->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbPre->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('show_nutrition_insights', '0')");
$dbPre->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('nutrition_attestation_version', '')");
$niPre = $dbPre->query("SELECT value FROM settings WHERE \"key\"='show_nutrition_insights'")->fetchColumn();
ok($niPre === '0', "C: reset show_nutrition_insights to '0' for CSRF test [got '$niPre']");
$dbPre = null;

[$cc, $ch, $cbody] = curlReqWithHeaders(
    'curl -c ' . escapeshellarg($guardianJar) . ' -b ' . escapeshellarg($guardianJar)
    . ' -X POST'
    . ' --data-urlencode ' . escapeshellarg('show_nutrition_insights=1')
    . ' --data-urlencode ' . escapeshellarg('nutrition_attestation_acknowledge=1')
    // Note: csrf_token intentionally omitted
    . ' ' . escapeshellarg($settingsUrl)
);
ok($cc >= 300 && $cc < 400, "C: POST without CSRF token returns 3xx [got $cc]");

// DB must still show '0'.
$dbC = new PDO('sqlite:' . $tmpDb);
$dbC->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$niC = $dbC->query("SELECT value FROM settings WHERE \"key\"='show_nutrition_insights'")->fetchColumn();
ok($niC === '0' || $niC === false,
   "C: show_nutrition_insights still '0' after CSRF-less POST [got '" . ($niC === false ? 'unset' : $niC) . "']");
$attC = $dbC->query("SELECT value FROM settings WHERE \"key\"='nutrition_attestation_version'")->fetchColumn();
ok($attC === '' || $attC === false,
   "C: nutrition_attestation_version still empty after CSRF-less POST [got '" . ($attC === false ? 'unset' : $attC) . "']");
$dbC = null;

// ==========================================================================
// GROUP D — Panel: persistent banner + soft re-ack notice
//
// Drives the guardian dashboard as a logged-in guardian with:
//   - show_nutrition_insights = '1' (feature on, set directly in DB)
//   - A child with 5+ distinct food-log days so the nutrition section renders
//     (NI_MIN_LOG_DAYS = 5; panel only renders when available=true)
//   - nutrition_attestation_version = current  → banner present, notice absent
//   - nutrition_attestation_version = stale    → banner present, notice + Review link present
// ==========================================================================
echo "\n--- D. Panel: persistent disclaimer banner + soft re-ack notice ---\n";

// --- D-setup: enable nutrition insights + stamp a CURRENT attestation via direct DB
// write. Child + food logs were pre-seeded before server start (see D-preseed above).
// Use WAL + busy_timeout so the write succeeds even if the server's singleton PDO
// connection is holding an open read transaction.
$dbD = new PDO('sqlite:' . $tmpDb);
$dbD->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbD->exec("PRAGMA journal_mode=WAL");
$dbD->exec("PRAGMA busy_timeout=5000");
$dbD->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('show_nutrition_insights', '1')");
$dbD->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('nutrition_attestation_version', '" . NUTRITION_ATTESTATION_VERSION . "')");
ok($dbD->query("SELECT value FROM settings WHERE \"key\"='show_nutrition_insights'")->fetchColumn() === '1',
   "D: show_nutrition_insights set to '1' for panel test");
$kidId = $panelKidId; // pre-seeded before server start
ok($kidId > 0, "D: pre-seeded child (id=$kidId)");
$logDaysD = (int) $dbD->query(
    "SELECT COUNT(DISTINCT log_date) FROM food_log WHERE user_id = $kidId"
)->fetchColumn();
ok($logDaysD >= 5, "D: child has $logDaysD distinct log days (>= NI_MIN_LOG_DAYS=5)");
$dbD = null;

// GET the dashboard as the already-authenticated guardian for the seeded child.
// Use period=all (1-year window) so the seeded recent dates are always in range.
$dashUrl = "$base/index.php?page=dashboard&child_id=$kidId&period=all";

// ---- D1: current attestation — banner present, re-ack notice absent ----------
[$d1code, $d1body] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L ' . escapeshellarg($dashUrl)
);
ok($d1code === 200, "D1: GET dashboard with current attestation returns 200 [got $d1code]");

// Panel renders (full-insights path emits nutrition-disclaimer-banner; not the
// not-enough-data stub class 'nutrition-prompt').
$d1HasPanel = strpos($d1body, 'nutrition-disclaimer-banner') !== false
    || strpos($d1body, 'nutrition_intelligence') !== false
    || strpos($d1body, t_smoke('nutrition_intelligence')) !== false;
ok($d1HasPanel, "D1: nutrition panel renders (section present in HTML)");

// Persistent disclaimer banner: medical_disclaimer_short text always present.
$disclaimerShortEn = 'not medical advice';
$disclaimerShortPt = 'não constitui aconselhamento médico';
ok(
    strpos($d1body, $disclaimerShortEn) !== false || strpos($d1body, $disclaimerShortPt) !== false,
    "D1: medical_disclaimer_short banner is present in rendered panel"
);

// Re-ack notice must be ABSENT when attestation is current.
$reackEn = 'updated our medical disclaimer';
$reackPt = 'Atualizámos o nosso aviso';
ok(
    strpos($d1body, $reackEn) === false && strpos($d1body, $reackPt) === false,
    "D1: nutrition_reack_notice is ABSENT when attestation is current"
);

// ---- D2: stale attestation — banner + notice + Review link all present -------
// Stamp a stale version directly in the DB.
// Use WAL + busy_timeout so the write waits for the server to release any read lock.
$dbD2 = new PDO('sqlite:' . $tmpDb);
$dbD2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbD2->exec("PRAGMA journal_mode=WAL");
$dbD2->exec("PRAGMA busy_timeout=5000");
$dbD2->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('nutrition_attestation_version', '0')");
// show_nutrition_insights stays '1'.
$niD2 = $dbD2->query("SELECT value FROM settings WHERE \"key\"='show_nutrition_insights'")->fetchColumn();
ok($niD2 === '1', "D2: show_nutrition_insights still '1' after stale attestation write");
$attD2 = $dbD2->query("SELECT value FROM settings WHERE \"key\"='nutrition_attestation_version'")->fetchColumn();
ok($attD2 === '0', "D2: nutrition_attestation_version set to stale ('0')");
$dbD2 = null;

[$d2code, $d2body] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L ' . escapeshellarg($dashUrl)
);
ok($d2code === 200, "D2: GET dashboard with stale attestation returns 200 [got $d2code]");

// Panel STILL renders (soft model). Use the same full-insights marker as D1.
$d2HasPanel = strpos($d2body, 'nutrition-disclaimer-banner') !== false
    || strpos($d2body, 'nutrition_intelligence') !== false
    || strpos($d2body, t_smoke('nutrition_intelligence')) !== false;
ok($d2HasPanel, "D2: nutrition panel STILL renders with stale attestation (soft model)");

// Persistent banner still present.
ok(
    strpos($d2body, $disclaimerShortEn) !== false || strpos($d2body, $disclaimerShortPt) !== false,
    "D2: medical_disclaimer_short banner is STILL present with stale attestation"
);

// Re-ack notice now present.
ok(
    strpos($d2body, $reackEn) !== false || strpos($d2body, $reackPt) !== false,
    "D2: nutrition_reack_notice text is present with stale attestation"
);

// Review link routing to settings page.
ok(
    strpos($d2body, 'page=settings') !== false,
    "D2: Review link routes to ?page=settings"
);

// Helper: render a locale key via the app's i18n (used only for panel-render check above).
// Defined after its first use (PHP hoists functions in script scope).
function t_smoke($key) {
    static $locale = null;
    if ($locale === null) {
        $f = dirname(__DIR__) . '/locales/pt.json';
        $locale = json_decode(file_get_contents($f), true) ?: [];
    }
    return $locale[$key] ?? $key;
}

// ==========================================================================
// GROUP E — A21 Task 4: disclaimer present in HTML and CSV export endpoints,
//           unconditionally (regardless of show_nutrition_insights toggle).
//
// E1: HTML export (format=html) with toggle ON  → medical_disclaimer_full text present
// E2: CSV  export (format=csv)  with toggle ON  → medical_disclaimer_short text present
// E3: HTML export with toggle OFF               → medical_disclaimer_full still present
// E4: CSV  export with toggle OFF               → medical_disclaimer_short still present
// ==========================================================================
echo "\n--- E. A21 Task 4: disclaimer in HTML + CSV exports (unconditional) ---\n";

// Seed a child + some data for the export endpoint.
// Use WAL so this write co-exists with the running server.
$dbE = new PDO('sqlite:' . $tmpDb);
$dbE->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbE->exec("PRAGMA journal_mode=WAL");
$dbE->exec("PRAGMA busy_timeout=5000");
$dbE->exec("INSERT INTO users (name, type, pin, avatar_emoji, active) VALUES ('ExportKid', 'child', '0000', '🧒', 1)");
$exportKid = (int) $dbE->lastInsertId();
ok($exportKid > 0, "E: seeded export child (id=$exportKid)");
// A weight log entry so the export has something to render.
$dbE->prepare("INSERT INTO weight_log (user_id, weight_kg, log_date) VALUES (?,?,?)")
    ->execute([$exportKid, 20.5, date('Y-m-d', strtotime('-7 days'))]);
$dbE = null;

// Load the pt locale disclaimer texts so we can check for them.
$eLocale = json_decode(file_get_contents($ROOT . '/locales/pt.json'), true) ?: [];
$eEnLocale = json_decode(file_get_contents($ROOT . '/locales/en.json'), true) ?: [];
$eDisclaimerFull  = $eLocale['medical_disclaimer_full']  ?? ($eEnLocale['medical_disclaimer_full']  ?? '');
$eDisclaimerShort = $eLocale['medical_disclaimer_short'] ?? ($eEnLocale['medical_disclaimer_short'] ?? '');
// Use a substring that will be recognizable in both locales.
$eFullSubstr  = 'not a clinical assessment';       // in en full
$eShortSubstr = 'not medical advice';              // in en short
// pt equivalents:
$eFullSubstrPt  = 'não constituem uma avaliação clínica';
$eShortSubstrPt = 'não constitui aconselhamento médico';

$startE = date('Y-m-d', strtotime('-30 days'));
$endE   = date('Y-m-d');

// Locale-resolution guard: prove t() resolved to real text, not the bare key name.
// If $eDisclaimerFull === 'medical_disclaimer_full' the locale load silently failed,
// and any substring match below would be a false positive.
ok(
    $eDisclaimerFull !== '' && $eDisclaimerFull !== 'medical_disclaimer_full',
    "E: medical_disclaimer_full resolved (not equal to its own key name) [got '" . substr($eDisclaimerFull, 0, 60) . "']"
);
ok(
    $eDisclaimerShort !== '' && $eDisclaimerShort !== 'medical_disclaimer_short',
    "E: medical_disclaimer_short resolved (not equal to its own key name) [got '" . substr($eDisclaimerShort, 0, 60) . "']"
);

// The guardian is already logged in ($guardianJar has the session cookie from above).
// Build export URL helpers.
$htmlExportUrl = "$base/index.php?page=export&child_id=$exportKid&format=html"
    . "&start_date=$startE&end_date=$endE&generate=1";
$csvExportUrl  = "$base/index.php?page=export&child_id=$exportKid&format=csv"
    . "&start_date=$startE&end_date=$endE&generate=1";

// --- E1: HTML export, toggle ON ---
$dbE1 = new PDO('sqlite:' . $tmpDb);
$dbE1->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbE1->exec("PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000");
$dbE1->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('show_nutrition_insights', '1')");
$dbE1 = null;

[$e1code, $e1body] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L ' . escapeshellarg($htmlExportUrl)
);
ok($e1code === 200, "E1: HTML export (toggle ON) returns 200 [got $e1code]");
ok(
    strpos($e1body, $eFullSubstr) !== false
    || strpos($e1body, $eFullSubstrPt) !== false,
    "E1: HTML export (toggle ON) contains medical_disclaimer_full text"
);

// --- E2: CSV export, toggle ON ---
[$e2code, $e2body] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L ' . escapeshellarg($csvExportUrl)
);
ok($e2code === 200, "E2: CSV export (toggle ON) returns 200 [got $e2code]");
ok(
    strpos($e2body, $eShortSubstr) !== false
    || strpos($e2body, $eShortSubstrPt) !== false,
    "E2: CSV export (toggle ON) contains medical_disclaimer_short text"
);

// --- E3: HTML export, toggle OFF (unconditional requirement) ---
$dbE3 = new PDO('sqlite:' . $tmpDb);
$dbE3->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbE3->exec("PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000");
$dbE3->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('show_nutrition_insights', '0')");
$dbE3 = null;

[$e3code, $e3body] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L ' . escapeshellarg($htmlExportUrl)
);
ok($e3code === 200, "E3: HTML export (toggle OFF) returns 200 [got $e3code]");
ok(
    strpos($e3body, $eFullSubstr) !== false
    || strpos($e3body, $eFullSubstrPt) !== false,
    "E3: HTML export (toggle OFF) STILL contains medical_disclaimer_full text (unconditional)"
);

// --- E4: CSV export, toggle OFF (unconditional requirement) ---
[$e4code, $e4body] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L ' . escapeshellarg($csvExportUrl)
);
ok($e4code === 200, "E4: CSV export (toggle OFF) returns 200 [got $e4code]");
ok(
    strpos($e4body, $eShortSubstr) !== false
    || strpos($e4body, $eShortSubstrPt) !== false,
    "E4: CSV export (toggle OFF) STILL contains medical_disclaimer_short text (unconditional)"
);

// ==========================================================================
// GROUP F — A21 Task 5a: API data-write surface NOT gated by nutrition attestation.
//
// Core invariant: A21 added ZERO gating to the data-write surface.
// api/food-log.php must accept a POST with (login + consent + valid CSRF) regardless
// of whether show_nutrition_insights or nutrition_attestation_version are set.
//
// F1 (regression, positive): guardian logged in, consent given, nutrition attestation
//    UNSET/empty, show_nutrition_insights='0' (defaults) → POST to api/food-log.php
//    succeeds (HTTP 200 / success:true / row written to DB).
//
// F2 (negative control, to prove F1 is meaningful): clear consent, POST to the same
//    endpoint → rejected 403 consent_required. This proves the test would catch a real
//    gate (the existing consent gate fires), distinguishing gated from ungated state.
// ==========================================================================
echo "\n--- F. A21 Task 5a: api/food-log.php NOT gated by nutrition attestation ---\n";

// --- F-setup: ensure guardian is logged in with consent, no attestation -----
// Re-scrape a fresh CSRF token from the settings page (post-E the session is intact).
[$fsc, $fsbody] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L ' . escapeshellarg($settingsUrl)
);
ok($fsc === 200, "F: GET settings page returns 200 for CSRF scrape [got $fsc]");
$fCsrf = '';
if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $fsbody, $fmc)) {
    $fCsrf = $fmc[1];
}
ok($fCsrf !== '', "F: scraped CSRF token for food-log API call [got '$fCsrf']");

// Ensure DB state: consent given, nutrition attestation unset, insights off.
// Use WAL + busy_timeout so this write succeeds while the server is running.
$dbF = new PDO('sqlite:' . $tmpDb);
$dbF->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbF->exec("PRAGMA journal_mode=WAL");
$dbF->exec("PRAGMA busy_timeout=5000");
$dbF->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('guardian_consent_version', '1')");
$dbF->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('show_nutrition_insights', '0')");
$dbF->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('nutrition_attestation_version', '')");
$niF  = $dbF->query("SELECT value FROM settings WHERE \"key\"='show_nutrition_insights'")->fetchColumn();
$attF = $dbF->query("SELECT value FROM settings WHERE \"key\"='nutrition_attestation_version'")->fetchColumn();
$conF = $dbF->query("SELECT value FROM settings WHERE \"key\"='guardian_consent_version'")->fetchColumn();
ok($conF === '1',   "F: consent is recorded (guardian_consent_version='1')");
ok($niF  === '0',   "F: show_nutrition_insights is '0' (insights OFF, no attestation scenario)");
ok($attF === '' || $attF === false,
   "F: nutrition_attestation_version is unset/empty [got '" . ($attF === false ? 'unset' : $attF) . "']");
$dbF = null;

// Count food_log rows before the F1 POST so we can prove a new row was written.
$dbFPre = new PDO('sqlite:' . $tmpDb);
$dbFPre->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbFPre->exec("PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000");
$flCountBefore = (int) $dbFPre->query(
    "SELECT COUNT(*) FROM food_log WHERE user_id = $guardianId"
)->fetchColumn();
$dbFPre = null;

// --- F1: POST to api/food-log.php WITH consent and NO attestation → success ---
// Payload via temp file (avoids shell quoting issues on Windows, same pattern as csrf_child_smoke).
$flPayload = json_encode(['food_id' => 1, 'meal_id' => 1, 'portion' => 'some']);
$flPayloadFile = tempnam(sys_get_temp_dir(), 'cc_f1body_') . '.json';
file_put_contents($flPayloadFile, $flPayload);

$flUrl = "$base/api/food-log.php";
$f1Cmd = 'curl -b ' . escapeshellarg($guardianJar)
    . ' -X POST -H "Content-Type: application/json"'
    . ' -H ' . escapeshellarg('X-CSRF-Token: ' . $fCsrf)
    . ' --data ' . escapeshellarg('@' . $flPayloadFile)
    . ' ' . escapeshellarg($flUrl);
[$f1code, $f1body] = curlReq($f1Cmd);
@unlink($flPayloadFile);

ok($f1code === 200,
   "F1: food-log POST (consent given, no attestation) returns 200 [got $f1code]");
ok(strpos($f1body, '"success":true') !== false,
   "F1: food-log POST returns success:true (not gated by A21 attestation) [got: " . trim($f1body) . "]");

// Prove a new row was actually written (not just a 200 with no write).
$dbF1 = new PDO('sqlite:' . $tmpDb);
$dbF1->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbF1->exec("PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000");
$flCountAfter = (int) $dbF1->query(
    "SELECT COUNT(*) FROM food_log WHERE user_id = $guardianId"
)->fetchColumn();
$dbF1 = null;
ok($flCountAfter === $flCountBefore + 1,
   "F1: food_log row count increased by 1 (row actually written) [$flCountBefore -> $flCountAfter]");

// --- F2: negative control — clear consent, same POST → 403 consent_required ---
// This proves the test is meaningful: the existing consent gate fires, so F1's pass
// is not a false positive. We are NOT changing the consent gate, just exercising it
// to demonstrate the test CAN distinguish a gated from an ungated state.
$dbF2Pre = new PDO('sqlite:' . $tmpDb);
$dbF2Pre->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbF2Pre->exec("PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000");
$dbF2Pre->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('guardian_consent_version', '0')");
$conF2 = $dbF2Pre->query("SELECT value FROM settings WHERE \"key\"='guardian_consent_version'")->fetchColumn();
ok($conF2 === '0', "F2: consent cleared (guardian_consent_version='0') for negative control");
$dbF2Pre = null;

// Need a fresh CSRF token — re-scrape from the login page (the session is still alive
// even with consent cleared; CSRF is auth-session-scoped, not consent-scoped).
[$f2lc, $f2lbody] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' ' . escapeshellarg($settingsUrl)
);
$f2Csrf = '';
if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $f2lbody, $f2m)) {
    $f2Csrf = $f2m[1];
}
if ($f2Csrf === '') { $f2Csrf = $fCsrf; } // fall back to existing token if scrape fails

$flPayload2File = tempnam(sys_get_temp_dir(), 'cc_f2body_') . '.json';
file_put_contents($flPayload2File, json_encode(['food_id' => 1, 'meal_id' => 1, 'portion' => 'some']));

$f2Cmd = 'curl -b ' . escapeshellarg($guardianJar)
    . ' -X POST -H "Content-Type: application/json"'
    . ' -H ' . escapeshellarg('X-CSRF-Token: ' . $f2Csrf)
    . ' --data ' . escapeshellarg('@' . $flPayload2File)
    . ' ' . escapeshellarg($flUrl);
[$f2code, $f2body] = curlReq($f2Cmd);
@unlink($flPayload2File);

ok($f2code === 403 && strpos($f2body, 'consent_required') !== false,
   "F2 (negative control): food-log POST WITHOUT consent → 403 consent_required [got $f2code: " . trim($f2body) . "]");

// Restore consent so the guardian session is left in a consistent state.
$dbF2Post = new PDO('sqlite:' . $tmpDb);
$dbF2Post->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbF2Post->exec("PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000");
$dbF2Post->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('guardian_consent_version', '1')");
$dbF2Post = null;

// --- Cleanup A-F server -------------------------------------------------------
// Groups G and H need fresh server instances to avoid SQLite WAL contention
// that builds up over A-F. Terminate the A-F server here; each GH sub-block
// spawns its own throwaway server with a clean DB.
$cleanup();
@unlink($guardianJar);

// ============================================================================
// HELPER: spawn a fresh php -S dev server on a throwaway DB, log in as a
// guardian (non-default PIN + consent recorded), and return
// [$ghProc, $ghPipes, $ghTmpDb, $ghJar, $ghBase, $ghCleanup, $ghGuardianId, $ghGuardianPin].
// ============================================================================
function spawnFreshServer($ROOT, $phpBin) {
    $ghTmpDb = tempnam(sys_get_temp_dir(), 'comecome_gh_') . '.db';
    if (!$ghTmpDb) { return null; }
    @unlink($ghTmpDb);

    // Bootstrap DB in the TEST process (not the server) so we can seed settings.
    $dbBootstrap = new PDO('sqlite:' . $ghTmpDb);
    $dbBootstrap->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $schema = file_get_contents($ROOT . '/db/schema.sql');
    $seed   = file_get_contents($ROOT . '/db/seed.sql');
    foreach (array_filter(array_map('trim', preg_split('/;\s*$/m', $schema))) as $stmt) {
        if ($stmt !== '') { try { $dbBootstrap->exec($stmt); } catch (\Exception $e) {} }
    }
    foreach (array_filter(array_map('trim', preg_split('/;\s*$/m', $seed))) as $stmt) {
        if ($stmt !== '') { try { $dbBootstrap->exec($stmt); } catch (\Exception $e) {} }
    }
    // Fix default guardian so the default-PIN gate clears (must be hashed).
    $fixPin = password_hash('9999', PASSWORD_DEFAULT);
    $stmtFix = $dbBootstrap->prepare("UPDATE users SET pin=? WHERE id=1");
    $stmtFix->execute([$fixPin]);
    $dbBootstrap->exec("PRAGMA journal_mode=WAL");
    $dbBootstrap = null;
    gc_collect_cycles();

    $host = '127.0.0.1';
    $pickSock = @stream_socket_server("tcp://$host:0", $pe, $ps);
    if (!$pickSock) { @unlink($ghTmpDb); return null; }
    $pickName = stream_socket_get_name($pickSock, false);
    fclose($pickSock);
    $pickPos = strrpos($pickName, ':');
    $port = ($pickPos === false) ? 0 : (int) substr($pickName, $pickPos + 1);
    if ($port <= 0) { @unlink($ghTmpDb); return null; }

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $ghEnv = $_ENV;
    $ghEnv['COMECOME_DB_PATH'] = $ghTmpDb;
    $cmd = escapeshellarg($phpBin) . ' -S ' . $host . ':' . $port . ' -t ' . escapeshellarg($ROOT);
    $ghProc = proc_open($cmd, $descriptors, $ghPipes, $ROOT, $ghEnv);
    if (!is_resource($ghProc)) { @unlink($ghTmpDb); return null; }
    stream_set_blocking($ghPipes[1], false);
    stream_set_blocking($ghPipes[2], false);

    $ghUp = false;
    for ($i = 0; $i < 50; $i++) {
        $fp = @fsockopen($host, $port, $errno, $errstr, 0.2);
        if ($fp) { fclose($fp); $ghUp = true; break; }
        usleep(100000);
    }

    $ghCleanup = function () use ($ghProc, $ghPipes, $ghTmpDb) {
        foreach ($ghPipes as $p) { if (is_resource($p)) { fclose($p); } }
        proc_terminate($ghProc);
        proc_close($ghProc);
        for ($i = 0; $i < 5 && file_exists($ghTmpDb); $i++) { if (@unlink($ghTmpDb)) break; usleep(20000); }
    };

    if (!$ghUp) { $ghCleanup(); return null; }

    $ghBase = "http://$host:$port";
    $ghJar  = tempnam(sys_get_temp_dir(), 'cc_gh_jar_');

    return [$ghProc, $ghPipes, $ghTmpDb, $ghJar, $ghBase, $ghCleanup];
}

// ============================================================================
// Helper: log in to a fresh GH server as a guardian, record consent, set
// key settings, return [$guardianId, $csrfToken] or null on failure.
// The cookie jar $ghJar is written to.
// ============================================================================
function ghLogin($ghBase, $ghJar, $phpBin, $ghTmpDb, $ROOT, &$PASS, &$FAIL) {
    // Create guardian in test process (direct DB write before server handles any auth).
    // PIN must be hashed so the server's password_verify() check passes.
    $pin = '7777';
    $hashedPin = password_hash($pin, PASSWORD_DEFAULT);
    $db  = new PDO('sqlite:' . $ghTmpDb);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000");
    $stmt = $db->prepare("INSERT INTO users (name, type, pin, avatar_emoji, active) VALUES ('GHGuardian','guardian',?,'🧑',1)");
    $stmt->execute([$hashedPin]);
    $gid = (int) $db->lastInsertId();
    $db->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('guardian_consent_version','1')");
    $db = null; gc_collect_cycles();

    $loginUrl = "$ghBase/index.php?page=login";
    [$lc, $lbody] = curlReq('curl -c ' . escapeshellarg($ghJar) . ' ' . escapeshellarg($loginUrl));
    $csrf = '';
    if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $lbody, $m)) { $csrf = $m[1]; }
    if ($lc !== 200 || $csrf === '') { return null; }

    $loginCmd = 'curl -c ' . escapeshellarg($ghJar) . ' -b ' . escapeshellarg($ghJar)
        . ' --data-urlencode ' . escapeshellarg('csrf_token=' . $csrf)
        . ' --data-urlencode ' . escapeshellarg('user_id=' . $gid)
        . ' --data-urlencode ' . escapeshellarg('pin=' . $pin)
        . ' ' . escapeshellarg($loginUrl);
    [$plc] = curlReq($loginCmd);
    if ($plc < 200 || $plc >= 400) { return null; }

    // Scrape CSRF from settings page.
    $settingsUrl = "$ghBase/index.php?page=settings";
    [$sc, $sbody] = curlReq('curl -b ' . escapeshellarg($ghJar) . ' -L ' . escapeshellarg($settingsUrl));
    $pageCsrf = '';
    if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $sbody, $mc)) { $pageCsrf = $mc[1]; }
    if ($sc !== 200 || $pageCsrf === '') { return null; }

    return [$gid, $pageCsrf, $sbody];
}

// ==========================================================================
// GROUP G — Defect A regression: unrelated settings save must NOT require attestation
//
// Insights are OFF. POST the form changing a different setting (default_language)
// WITHOUT enabling insights and WITHOUT the attestation checkbox.
// Expected: POST succeeds (saved indicator), show_nutrition_insights stays '0',
// no attestation recorded, and the settings page GET does NOT contain `required`
// on the nutrition_attestation_acknowledge input.
//
// Uses a FRESH server+DB to avoid WAL contention from A-F.
// ==========================================================================
echo "\n--- G. Defect A: unrelated settings save without attestation checkbox must succeed ---\n";

$ghG = spawnFreshServer($ROOT, $phpBin);
if (!$ghG) {
    ok(false, "G: could not spawn fresh server for group G");
} else {
    [, , $gTmpDb, $gJar, $gBase, $gCleanup] = $ghG;
    $gSettingsUrl = "$gBase/index.php?page=settings";

    // Set up: insights OFF, no attestation (already the default state from fresh DB).
    $dbG = new PDO('sqlite:' . $gTmpDb);
    $dbG->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dbG->exec("PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000");
    $dbG->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('show_nutrition_insights', '0')");
    $dbG->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('nutrition_attestation_version', '')");
    $dbG = null; gc_collect_cycles();

    $gLogin = ghLogin($gBase, $gJar, $phpBin, $gTmpDb, $ROOT, $PASS, $FAIL);
    if (!$gLogin) {
        ok(false, "G: could not log in to fresh server");
    } else {
        [, $gCsrf, $gSettingsBody] = $gLogin;
        ok(true, "G: logged in to fresh server, scraped CSRF [got '$gCsrf']");

        // G1: GET settings page, insights OFF → nutrition_attestation_acknowledge input must NOT have `required`.
        ok(
            strpos($gSettingsBody, 'name="nutrition_attestation_acknowledge"') !== false,
            "G1: settings page contains nutrition_attestation_acknowledge input when insights OFF"
        );
        $niInputMatch = '';
        if (preg_match('/<input[^>]*name="nutrition_attestation_acknowledge"[^>]*>/', $gSettingsBody, $gInputM)) {
            $niInputMatch = $gInputM[0];
        }
        ok(
            strpos($niInputMatch, 'required') === false,
            "G1: nutrition_attestation_acknowledge input does NOT have `required` attribute [got: $niInputMatch]"
        );

        // G2: POST changing default_language only — no show_nutrition_insights, no checkbox.
        [$g2c, $g2body] = curlReq(
            'curl -c ' . escapeshellarg($gJar) . ' -b ' . escapeshellarg($gJar) . ' -L'
            . ' --data-urlencode ' . escapeshellarg('csrf_token=' . $gCsrf)
            . ' --data-urlencode ' . escapeshellarg('default_language=en')
            // Intentionally: no show_nutrition_insights, no nutrition_attestation_acknowledge
            . ' ' . escapeshellarg($gSettingsUrl)
        );
        ok($g2c === 200, "G2: POST with language change only returns 200 [got $g2c]");
        ok(
            strpos($g2body, 'changes_saved') !== false
            || strpos($g2body, 'Alterações guardadas') !== false
            || strpos($g2body, 'Changes saved') !== false,
            "G2: response contains 'changes saved' indicator (POST succeeded)"
        );
        ok(
            strpos($g2body, 'nutrition_attestation_required') === false
            && strpos($g2body, 'É necessário reconhecer') === false,
            "G2: response does NOT contain attestation-required error message"
        );

        // G3: DB state — show_nutrition_insights must still be '0', no attestation written.
        $dbG3 = new PDO('sqlite:' . $gTmpDb);
        $dbG3->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $dbG3->exec("PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000");
        $niG3  = $dbG3->query("SELECT value FROM settings WHERE \"key\"='show_nutrition_insights'")->fetchColumn();
        $attG3 = $dbG3->query("SELECT value FROM settings WHERE \"key\"='nutrition_attestation_version'")->fetchColumn();
        $dbG3 = null;
        ok($niG3 === '0' || $niG3 === false,
            "G3: show_nutrition_insights is still '0' after unrelated save [got '" . ($niG3 === false ? 'unset' : $niG3) . "']");
        ok($attG3 === '' || $attG3 === false,
            "G3: nutrition_attestation_version still empty after unrelated save [got '" . ($attG3 === false ? 'unset' : $attG3) . "']");
    }
    $gCleanup();
    @unlink($gJar);
}

// ==========================================================================
// GROUP H — Defect B: re-ack path while insights are ON (stale-on-deploy install)
//
// Seeds: show_nutrition_insights='1', nutrition_attestation_version='' (stale).
// H1: GET settings → disclaimer block + checkbox ARE visible (stale install sees them).
// H2: POST with show_nutrition_insights=1 + checkbox → attestation recorded, no longer stale.
// H3: POST insights=1 + no checkbox + change another setting → other setting saved,
//     still stale (soft: no hard rejection, notice persists).
//
// Uses a FRESH server+DB to avoid WAL contention from A-F.
// ==========================================================================
echo "\n--- H. Defect B: re-ack path for stale already-on install ---\n";

$ghH = spawnFreshServer($ROOT, $phpBin);
if (!$ghH) {
    ok(false, "H: could not spawn fresh server for group H");
} else {
    [, , $hTmpDb, $hJar, $hBase, $hCleanup] = $ghH;
    $hSettingsUrl = "$hBase/index.php?page=settings";

    // Seed stale state: insights ON, attestation version empty (stale).
    $dbH = new PDO('sqlite:' . $hTmpDb);
    $dbH->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dbH->exec("PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000");
    $dbH->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('show_nutrition_insights', '1')");
    $dbH->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('nutrition_attestation_version', '')");
    $niH  = $dbH->query("SELECT value FROM settings WHERE \"key\"='show_nutrition_insights'")->fetchColumn();
    $attH = $dbH->query("SELECT value FROM settings WHERE \"key\"='nutrition_attestation_version'")->fetchColumn();
    ok($niH === '1', "H: seeded insights ON [got '$niH']");
    ok($attH === '' || $attH === false,
        "H: seeded attestation EMPTY/stale [got '" . ($attH === false ? 'unset' : $attH) . "']");
    $dbH = null; gc_collect_cycles();

    $hLogin = ghLogin($hBase, $hJar, $phpBin, $hTmpDb, $ROOT, $PASS, $FAIL);
    if (!$hLogin) {
        ok(false, "H: could not log in to fresh server");
    } else {
        [, $hCsrf, $hSettingsBody] = $hLogin;
        ok(true, "H: logged in to fresh server, scraped CSRF [got '$hCsrf']");

        // H1: GET settings page when stale → disclaimer block + checkbox must be visible.
        ok(
            strpos($hSettingsBody, 'name="nutrition_attestation_acknowledge"') !== false,
            "H1: settings page renders disclaimer + attestation checkbox when stale (insights ON)"
        );
        ok(
            strpos($hSettingsBody, 'medical_disclaimer_short') !== false
            || strpos($hSettingsBody, 'medical_disclaimer_full') !== false
            || strpos($hSettingsBody, 'not medical advice') !== false
            || strpos($hSettingsBody, 'não constitui aconselhamento médico') !== false,
            "H1: settings page renders the medical disclaimer text when stale"
        );

        // H2: POST insights=1 + checkbox → re-ack recorded, no longer stale.
        [$h2c, $h2body] = curlReq(
            'curl -c ' . escapeshellarg($hJar) . ' -b ' . escapeshellarg($hJar) . ' -L'
            . ' --data-urlencode ' . escapeshellarg('csrf_token=' . $hCsrf)
            . ' --data-urlencode ' . escapeshellarg('show_nutrition_insights=1')
            . ' --data-urlencode ' . escapeshellarg('nutrition_attestation_acknowledge=1')
            . ' ' . escapeshellarg($hSettingsUrl)
        );
        ok($h2c === 200, "H2: POST re-ack (insights ON + checkbox) returns 200 [got $h2c]");
        ok(
            strpos($h2body, 'changes_saved') !== false
            || strpos($h2body, 'Alterações guardadas') !== false
            || strpos($h2body, 'Changes saved') !== false,
            "H2: response contains 'changes saved' (re-ack POST succeeded, not rejected)"
        );

        // DB: attestation must now be current version.
        $dbH2 = new PDO('sqlite:' . $hTmpDb);
        $dbH2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $dbH2->exec("PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000");
        $attH2 = $dbH2->query("SELECT value FROM settings WHERE \"key\"='nutrition_attestation_version'")->fetchColumn();
        $niH2  = $dbH2->query("SELECT value FROM settings WHERE \"key\"='show_nutrition_insights'")->fetchColumn();
        $dbH2 = null;
        ok($niH2 === '1',
            "H2: show_nutrition_insights stays '1' after re-ack [got '" . ($niH2 === false ? 'unset' : $niH2) . "']");
        ok($attH2 === (string) NUTRITION_ATTESTATION_VERSION,
            "H2: nutrition_attestation_version is now current (" . NUTRITION_ATTESTATION_VERSION . ") after re-ack [got '" . ($attH2 === false ? 'unset' : $attH2) . "']");

        // H3: seed stale again, then POST insights=1 + NO checkbox + change language.
        // Expected: other setting saved, still stale (soft), not rejected.
        $dbH3Pre = new PDO('sqlite:' . $hTmpDb);
        $dbH3Pre->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $dbH3Pre->exec("PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000");
        $dbH3Pre->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('nutrition_attestation_version', '')");
        $dbH3Pre->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('default_language', 'pt')");
        $dbH3Pre = null; gc_collect_cycles();

        // Re-scrape CSRF (session still active on H server).
        [$h3sc, $h3sbody] = curlReq(
            'curl -b ' . escapeshellarg($hJar) . ' -L ' . escapeshellarg($hSettingsUrl)
        );
        $h3Csrf = '';
        if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $h3sbody, $h3mc)) {
            $h3Csrf = $h3mc[1];
        }
        if ($h3Csrf === '') { $h3Csrf = $hCsrf; }

        [$h3c, $h3body] = curlReq(
            'curl -c ' . escapeshellarg($hJar) . ' -b ' . escapeshellarg($hJar) . ' -L'
            . ' --data-urlencode ' . escapeshellarg('csrf_token=' . $h3Csrf)
            . ' --data-urlencode ' . escapeshellarg('show_nutrition_insights=1')
            . ' --data-urlencode ' . escapeshellarg('default_language=en')
            // Intentionally: no nutrition_attestation_acknowledge
            . ' ' . escapeshellarg($hSettingsUrl)
        );
        ok($h3c === 200, "H3: POST insights-on + no checkbox + language change returns 200 [got $h3c]");
        ok(
            strpos($h3body, 'changes_saved') !== false
            || strpos($h3body, 'Alterações guardadas') !== false
            || strpos($h3body, 'Changes saved') !== false,
            "H3: response contains 'changes saved' (soft — already-on + no checkbox is NOT rejected)"
        );
        ok(
            strpos($h3body, 'nutrition_attestation_required') === false
            && strpos($h3body, 'É necessário reconhecer') === false,
            "H3: response does NOT contain attestation-required error (soft re-ack)"
        );

        // DB: insights still ON, language changed, attestation still empty/stale.
        $dbH3 = new PDO('sqlite:' . $hTmpDb);
        $dbH3->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $dbH3->exec("PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000");
        $niH3   = $dbH3->query("SELECT value FROM settings WHERE \"key\"='show_nutrition_insights'")->fetchColumn();
        $attH3  = $dbH3->query("SELECT value FROM settings WHERE \"key\"='nutrition_attestation_version'")->fetchColumn();
        $langH3 = $dbH3->query("SELECT value FROM settings WHERE \"key\"='default_language'")->fetchColumn();
        $dbH3 = null;
        ok($niH3 === '1',
            "H3: show_nutrition_insights is still '1' (stays on) [got '" . ($niH3 === false ? 'unset' : $niH3) . "']");
        ok($attH3 === '' || $attH3 === false,
            "H3: nutrition_attestation_version still empty/stale (no re-ack recorded) [got '" . ($attH3 === false ? 'unset' : $attH3) . "']");
        ok($langH3 === 'en',
            "H3: default_language was saved to 'en' (other setting DID save) [got '" . ($langH3 === false ? 'unset' : $langH3) . "']");
    }
    $hCleanup();
    @unlink($hJar);
}

echo "\n==========================================================\n";
echo " HTTP DISCLAIMER ATTESTATION smoke: $PASS passed, " . count($FAIL) . " failed\n";
echo "==========================================================\n";
if (empty($FAIL)) { echo "HTTP-DISCLAIMER-SMOKE: PASS\n"; exit(0); }
echo "HTTP-DISCLAIMER-SMOKE: FAIL\n";
foreach ($FAIL as $f) { echo "  - $f\n"; }
exit(1);
