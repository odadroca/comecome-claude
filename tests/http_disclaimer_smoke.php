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

// --- Cleanup ----------------------------------------------------------------
$cleanup();
@unlink($guardianJar);

echo "\n==========================================================\n";
echo " HTTP DISCLAIMER ATTESTATION smoke: $PASS passed, " . count($FAIL) . " failed\n";
echo "==========================================================\n";
if (empty($FAIL)) { echo "HTTP-DISCLAIMER-SMOKE: PASS\n"; exit(0); }
echo "HTTP-DISCLAIMER-SMOKE: FAIL\n";
foreach ($FAIL as $f) { echo "  - $f\n"; }
exit(1);
