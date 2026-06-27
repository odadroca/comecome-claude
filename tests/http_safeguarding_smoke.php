<?php
/**
 * ComeCome — HTTP-level SAFEGUARDING GATE smoke (Launch Sprint 2, Task 5 / A4).
 * ===============================================================================
 *
 * WHY THIS EXISTS:
 *   Validates the guardian-only safeguarding page wired in index.php:
 *
 *   A. (Gate)    A child logged in → GET ?page=safeguarding → 302 to index.php.
 *                requireGuardian() bounces; children never see the page.
 *   B. (Render)  Guardian → GET ?page=safeguarding → 200; body contains the
 *                child's name AND the safeguarding_mark_reviewed button text.
 *   C. (CSRF)    Guardian → POST with child_id but NO CSRF token → rejected;
 *                follow-up GET still shows the flag (not cleared).
 *   D. (Flow)    Guardian → POST with the page's CSRF token + child_id → 302;
 *                follow-up GET shows safeguarding_none text (flag is cleared).
 *   E. (Toggle)  setSetting('show_safeguarding_alerts','0'); guardian GET
 *                ?page=dashboard does NOT contain the safeguarding_nav label;
 *                GET ?page=safeguarding is 200 but shows safeguarding_none.
 *
 * SAFETY:
 *   The spawned `php -S` runs with COMECOME_DB_PATH pointed at a THROWAWAY
 *   temp DB; it never touches the real db/data.db.
 *
 * USAGE:   php tests/http_safeguarding_smoke.php
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
echo " ComeCome HTTP SAFEGUARDING GATE smoke (php -S + curl)\n";
echo "==========================================================\n";

// --- Throwaway DB + seeded users --------------------------------------------
$tmpDb = tempnam(sys_get_temp_dir(), 'comecome_safeguarding_') . '.db';
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
date_default_timezone_set('Europe/Lisbon');
require_once $ROOT . '/includes/db.php';
require_once $ROOT . '/includes/auth.php';
initializeDatabase();

// The seeded default guardian (id=1) has a default PIN — the default-PIN gate
// fires before safeguarding is reachable. Change it to a non-default value so
// refreshGuardianPinDefaultFlag() clears the guardian_pin_is_default flag.
updateUser(1, 'DefaultGuardian', 'guardian', '9999', '🔐', 1);

// Create a guardian with a non-default PIN AND recorded consent.
// Without consent, the consent gate (Plan 1) redirects before safeguarding.
$guardianPin = '7777';
$guardianId  = createUser('SmokeGuardian', 'guardian', $guardianPin, '🧑');
ok($guardianId > 0, "seeded guardian (id=$guardianId, PIN=$guardianPin)");
setSetting('guardian_consent_version', (string) CONSENT_NOTICE_VERSION);
ok(getSetting('guardian_consent_version') === (string) CONSENT_NOTICE_VERSION,
   "consent recorded for SmokeGuardian");

// Create a child.
$childPin  = '1234';
$childName = 'SmokeChild';
$childId   = createUser($childName, 'child', $childPin, '🧒');
ok($childId > 0, "seeded child (id=$childId, name=$childName)");

// Insert a daily_checkin row with mood_level=1 (critical) dated TODAY so a flag
// is live immediately. SAFEGUARD_MOOD_CRITICAL=1; one critical row satisfies the
// hasCritical branch in computeSafeguardingFlags().
require_once $ROOT . '/includes/safeguarding.php';
$today = date('Y-m-d');
$dbSeed = getDB();
$dbSeed->prepare(
    "INSERT OR REPLACE INTO daily_checkin (user_id, check_date, mood_level, appetite_level) VALUES (?, ?, 1, 3)"
)->execute([$childId, $today]);
$rowCheck = $dbSeed->prepare(
    "SELECT COUNT(*) FROM daily_checkin WHERE user_id=? AND check_date=? AND mood_level=1"
);
$rowCheck->execute([$childId, $today]);
ok((int)$rowCheck->fetchColumn() === 1,
   "daily_checkin row with mood_level=1 seeded for today ($today)");

// Release the PDO handle so the spawned server gets a clean lock on the file.
$dbSeed  = null;
$rowCheck = null;
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
$guardianJar = tempnam(sys_get_temp_dir(), 'cc_sg_guardian_');
$childJar    = tempnam(sys_get_temp_dir(), 'cc_sg_child_');

/**
 * Run curl (no redirect follow by default), return [httpCode, body].
 * Identical to the pattern in http_consent_smoke.php.
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
 * Returns [httpCode, headers, body]. Used when we need to inspect Location headers.
 */
function curlReqWithHeaders($args) {
    $out = shell_exec($args . ' -s -D - -w "\n__HTTP__%{http_code}"');
    if ($out === null) { return [0, '', '']; }
    $pos = strrpos($out, "\n__HTTP__");
    $raw  = ($pos !== false) ? substr($out, 0, $pos) : $out;
    $code = ($pos !== false) ? (int) substr($out, $pos + strlen("\n__HTTP__")) : 0;
    // Split headers from body on the blank line (HTTP uses CRLF).
    $parts   = preg_split('/\r?\n\r?\n/', $raw, 2);
    $headers = $parts[0] ?? '';
    $body    = $parts[1] ?? '';
    return [$code, $headers, $body];
}

$loginUrl = "$base/index.php?page=login";
$sgUrl    = "$base/index.php?page=safeguarding";
$dashUrl  = "$base/index.php?page=dashboard";

// ==========================================================================
// GROUP A — Gate: child → safeguarding page → 302 to index.php
// ==========================================================================
echo "\n--- A. Gate: child cannot reach safeguarding page ---\n";

// GET login page to obtain a session cookie + CSRF token (curlReq — mirrors
// the pattern in http_consent_smoke.php which successfully extracts the meta tag).
[$lc1, $lbody1] = curlReq('curl -c ' . escapeshellarg($childJar) . ' ' . escapeshellarg($loginUrl));
ok($lc1 === 200, "A: GET login page for child returns 200 [got $lc1]");
$csrfChild = '';
if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $lbody1, $m)) {
    $csrfChild = $m[1];
}
ok($csrfChild !== '', "A: login page exposes csrf-token for child session [got '$csrfChild']");

// POST login as child.
global $childId, $childPin;
$childLogin = 'curl -c ' . escapeshellarg($childJar) . ' -b ' . escapeshellarg($childJar)
    . ' --data-urlencode ' . escapeshellarg('csrf_token=' . $csrfChild)
    . ' --data-urlencode ' . escapeshellarg('user_id=' . $childId)
    . ' --data-urlencode ' . escapeshellarg('pin=' . $childPin)
    . ' ' . escapeshellarg($loginUrl);
[$clc, $clbody] = curlReq($childLogin);
ok($clc >= 200 && $clc < 400, "A: child login POST completes [got $clc]");

// GET safeguarding without following redirect — expect 302 to index.php.
[$sgc, $sgh, $sgbody] = curlReqWithHeaders(
    'curl -b ' . escapeshellarg($childJar) . ' ' . escapeshellarg($sgUrl)
);
ok($sgc === 302, "A: child GET ?page=safeguarding returns 302 (requireGuardian bounces) [got $sgc]");
// requireGuardian() -> Location: index.php (not ?page=... — it bounces to the root index).
$locationOk = (stripos($sgh, 'Location: index.php') !== false)
           || (stripos($sgh, 'location: index.php') !== false);
ok($locationOk,
   "A: Location header points to index.php [headers: "
   . substr(preg_replace('/\r?\n/', ' | ', $sgh), 0, 200) . "]");

// ==========================================================================
// GROUP B — Render: guardian → safeguarding → 200 with child name + button
// ==========================================================================
echo "\n--- B. Render: guardian sees child name + mark-reviewed button ---\n";

// GET login page for guardian.
[$lc2, $lbody2] = curlReq('curl -c ' . escapeshellarg($guardianJar) . ' ' . escapeshellarg($loginUrl));
ok($lc2 === 200, "B: GET login page for guardian returns 200 [got $lc2]");
$csrfGuardian = '';
if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $lbody2, $m2)) {
    $csrfGuardian = $m2[1];
}
ok($csrfGuardian !== '', "B: login page exposes csrf-token for guardian session [got '$csrfGuardian']");

// POST login as guardian.
global $guardianId, $guardianPin;
$guardianLogin = 'curl -c ' . escapeshellarg($guardianJar) . ' -b ' . escapeshellarg($guardianJar)
    . ' --data-urlencode ' . escapeshellarg('csrf_token=' . $csrfGuardian)
    . ' --data-urlencode ' . escapeshellarg('user_id=' . $guardianId)
    . ' --data-urlencode ' . escapeshellarg('pin=' . $guardianPin)
    . ' ' . escapeshellarg($loginUrl);
[$glc, $glbody] = curlReq($guardianLogin);
ok($glc >= 200 && $glc < 400, "B: guardian login POST completes [got $glc]");

// GET safeguarding page (follow redirects — guardian should reach 200).
[$sgc2, $sgbody2] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L ' . escapeshellarg($sgUrl)
);
ok($sgc2 === 200, "B: guardian GET ?page=safeguarding returns 200 [got $sgc2]");

// Body must contain the child's name (decrypted on read even under field encryption).
$hasChildName = (strpos($sgbody2, $childName) !== false);
ok($hasChildName, "B: page body contains child name '$childName'");

// Body must contain the mark_reviewed button text (either locale).
$hasMark = (strpos($sgbody2, 'Mark reviewed') !== false)
        || (strpos($sgbody2, 'Marcar como revisto') !== false);
ok($hasMark, "B: page body contains safeguarding_mark_reviewed button text");

// Scrape the CSRF token from the safeguarding page for use in group D.
$pageCsrf = '';
if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $sgbody2, $mc)) {
    $pageCsrf = $mc[1];
}
ok($pageCsrf !== '', "B: safeguarding page exposes a CSRF token [got '$pageCsrf']");

// ==========================================================================
// GROUP C — CSRF: POST without token → rejected; flag not cleared
// ==========================================================================
echo "\n--- C. CSRF: POST without token leaves flag intact ---\n";

// POST to safeguarding with child_id but NO csrf_token.
// The page's verifyCsrf() will fail → redirect back to ?page=safeguarding.
[$noTokenC, $noTokenH, $noTokenBody] = curlReqWithHeaders(
    'curl -c ' . escapeshellarg($guardianJar) . ' -b ' . escapeshellarg($guardianJar)
    . ' -X POST'
    . ' --data-urlencode ' . escapeshellarg('child_id=' . $childId)
    . ' ' . escapeshellarg($sgUrl)
);
ok(($noTokenC >= 300 && $noTokenC < 400) || strpos($noTokenBody, 'csrf') !== false,
   "C: POST without CSRF token is rejected (3xx or csrf error in body) [got $noTokenC]");

// Follow-up GET must still show the flag (child name + mark-reviewed button still present).
[$sgcAfterBad, $sgbodyAfterBad] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L ' . escapeshellarg($sgUrl)
);
ok($sgcAfterBad === 200, "C: follow-up GET after bad POST is 200 [got $sgcAfterBad]");
$stillHasFlag = (strpos($sgbodyAfterBad, $childName) !== false)
             && ((strpos($sgbodyAfterBad, 'Mark reviewed') !== false)
                 || (strpos($sgbodyAfterBad, 'Marcar como revisto') !== false));
ok($stillHasFlag,
   "C: flag NOT cleared — child name + mark-reviewed button still present after bad CSRF POST");

// ==========================================================================
// GROUP D — Flow: POST with valid CSRF token + child_id → 302; flag cleared
// ==========================================================================
echo "\n--- D. Flow: valid POST clears the flag ---\n";

ok($pageCsrf !== '', "D: have a CSRF token from the safeguarding page (group B)");

// POST with the CSRF token scraped from the page.
[$flowC, $flowH, $flowBody] = curlReqWithHeaders(
    'curl -c ' . escapeshellarg($guardianJar) . ' -b ' . escapeshellarg($guardianJar)
    . ' --data-urlencode ' . escapeshellarg('csrf_token=' . $pageCsrf)
    . ' --data-urlencode ' . escapeshellarg('child_id=' . $childId)
    . ' ' . escapeshellarg($sgUrl)
);
ok($flowC === 302, "D: POST with valid CSRF token + child_id returns 302 [got $flowC]");

// Follow-up GET must show safeguarding_none text (flag is cleared).
[$sgcAfterGood, $sgbodyAfterGood] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L ' . escapeshellarg($sgUrl)
);
ok($sgcAfterGood === 200, "D: follow-up GET after valid POST is 200 [got $sgcAfterGood]");
$hasNone = (strpos($sgbodyAfterGood, 'No wellbeing flags right now') !== false)
        || (strpos($sgbodyAfterGood, 'Sem alertas de bem-estar') !== false);
ok($hasNone, "D: safeguarding_none text present after mark-reviewed POST (flag cleared)");

// ==========================================================================
// GROUP E — Toggle off = fully off: no nav item on dashboard; page shows none
// ==========================================================================
echo "\n--- E. Toggle off = fully off ---\n";

// Write show_safeguarding_alerts=0 directly into the throwaway DB.
// The server process reads COMECOME_DB_PATH = $tmpDb on each request, so
// a direct write here is visible to subsequent HTTP requests immediately.
$dbToggle = new PDO('sqlite:' . $tmpDb);
$dbToggle->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbToggle->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('show_safeguarding_alerts', '0')");
$toggleVal = $dbToggle->query(
    "SELECT value FROM settings WHERE \"key\"='show_safeguarding_alerts'"
)->fetchColumn();
ok($toggleVal === '0', "E: set show_safeguarding_alerts=0 in throwaway DB [got '$toggleVal']");
$dbToggle = null;

// Guardian GET ?page=dashboard — body must NOT contain safeguarding_nav label.
[$dashC, $dashBody] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L ' . escapeshellarg($dashUrl)
);
ok($dashC === 200, "E: guardian GET ?page=dashboard returns 200 after toggle off [got $dashC]");
$hasNavLabel = (strpos($dashBody, 'Wellbeing') !== false)
            || (strpos($dashBody, 'Bem-estar') !== false);
ok(!$hasNavLabel,
   "E: dashboard body does NOT contain safeguarding_nav label (nav item hidden when toggle off)");

// Guardian GET ?page=safeguarding — must still be 200 but show safeguarding_none.
[$sgcToggle, $sgbodyToggle] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L ' . escapeshellarg($sgUrl)
);
ok($sgcToggle === 200,
   "E: guardian GET ?page=safeguarding with toggle off returns 200 [got $sgcToggle]");
$hasNoneToggle = (strpos($sgbodyToggle, 'No wellbeing flags right now') !== false)
              || (strpos($sgbodyToggle, 'Sem alertas de bem-estar') !== false);
ok($hasNoneToggle,
   "E: safeguarding page shows safeguarding_none when toggle is off (computeSafeguardingFlags returns [])");

// --- Cleanup ----------------------------------------------------------------
$cleanup();
@unlink($guardianJar);
@unlink($childJar);

echo "\n==========================================================\n";
echo " HTTP SAFEGUARDING smoke: $PASS passed, " . count($FAIL) . " failed\n";
echo "==========================================================\n";
if (empty($FAIL)) { echo "HTTP-SAFEGUARDING-SMOKE: PASS\n"; exit(0); }
echo "HTTP-SAFEGUARDING-SMOKE: FAIL\n";
foreach ($FAIL as $f) { echo "  - $f\n"; }
exit(1);
