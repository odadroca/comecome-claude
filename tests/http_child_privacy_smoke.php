<?php
/**
 * ComeCome — HTTP-level CHILD PRIVACY NOTE smoke (Launch Sprint 2, A27).
 * =======================================================================
 *
 * WHY THIS EXISTS:
 *   Validates the one-time child privacy-note modal and its dismissal handler
 *   wired in index.php (?page=ack-privacy-note). Proves the full show-once cycle:
 *
 *   A. (Show)     Child logs in (no seen flag) → GET landing → body contains
 *                 the child_privacy_note_body text (modal is shown).
 *   B. (Dismiss)  POST "OK!" to ?page=ack-privacy-note with valid CSRF →
 *                 child_privacy_note_seen_<uid> is set in the DB; redirect/200.
 *   C. (Once)     Child GETs landing again → note body text is ABSENT (shown once).
 *   D. (CSRF)     POST without CSRF token → rejected; flag is unchanged.
 *   E. (Guardian) Guardian logs in → landing does NOT contain child note text
 *                 (child-only).
 *
 * SAFETY:
 *   The spawned `php -S` runs with COMECOME_DB_PATH pointed at a THROWAWAY
 *   temp DB; it never touches the real db/data.db.
 *
 * USAGE:   php tests/http_child_privacy_smoke.php
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
echo " ComeCome HTTP CHILD PRIVACY NOTE smoke (php -S + curl)\n";
echo "==========================================================\n";

// --- Throwaway DB + seeded users --------------------------------------------
$tmpDb = tempnam(sys_get_temp_dir(), 'comecome_childprivacy_') . '.db';
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
date_default_timezone_set('Europe/Lisbon');
require_once $ROOT . '/includes/db.php';
require_once $ROOT . '/includes/auth.php';
initializeDatabase();

// The seeded default guardian (id=1) has a default PIN — change it to avoid
// the default-PIN gate intercepting guardian logins in this smoke.
updateUser(1, 'DefaultGuardian', 'guardian', '9999', '🔐', 1);

// Create a guardian with a non-default PIN AND recorded consent.
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

// Confirm no seen flag yet.
ok(getSetting("child_privacy_note_seen_$childId", '') === '',
   "child_privacy_note_seen_$childId is unset before smoke");

// Release the PDO handle so the spawned server gets a clean lock on the file.
$dbSeed = null;
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

$base     = "http://$host:$port";
$childJar    = tempnam(sys_get_temp_dir(), 'cc_cpn_child_');
$guardianJar = tempnam(sys_get_temp_dir(), 'cc_cpn_guardian_');

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

$loginUrl = "$base/index.php?page=login";
$ackUrl   = "$base/index.php?page=ack-privacy-note";
// First enabled child task page (log-food is enabled by default).
$landingUrl = "$base/index.php?page=log-food";

// Helper: log in a user (GET login page → scrape CSRF → POST login).
// Returns the scraped CSRF token from the login page (reusable for later POSTs).
function loginUser($jar, $loginUrl, $userId, $pin) {
    [$lc, $lbody] = curlReq('curl -c ' . escapeshellarg($jar) . ' ' . escapeshellarg($loginUrl));
    $csrf = '';
    if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $lbody, $m)) {
        $csrf = $m[1];
    }
    $postCmd = 'curl -c ' . escapeshellarg($jar) . ' -b ' . escapeshellarg($jar)
        . ' --data-urlencode ' . escapeshellarg('csrf_token=' . $csrf)
        . ' --data-urlencode ' . escapeshellarg('user_id=' . $userId)
        . ' --data-urlencode ' . escapeshellarg('pin=' . $pin)
        . ' ' . escapeshellarg($loginUrl);
    curlReq($postCmd); // follow-through; we only need the session cookie in $jar
    return $csrf;
}

// ==========================================================================
// GROUP A — Show: child (no seen flag) → GET landing → note body present
// ==========================================================================
echo "\n--- A. Show: child sees privacy note on first landing ---\n";

global $childId, $childPin;
$childCsrf = loginUser($childJar, $loginUrl, $childId, $childPin);
ok($childCsrf !== '', "A: login page exposes csrf-token for child session");

// GET log-food (the first child landing) — follow redirects; expect 200 with note body.
[$lfc, $lfbody] = curlReq(
    'curl -b ' . escapeshellarg($childJar) . ' -L ' . escapeshellarg($landingUrl)
);
ok($lfc === 200, "A: child GET log-food returns 200 [got $lfc]");

// Modal must contain the child_privacy_note_body text (either locale).
$noteBody_en = "Your grown-up can see what you log here";
$noteBody_pt = "O teu adulto pode ver o que registas aqui";
$hasNote = (strpos($lfbody, $noteBody_en) !== false)
        || (strpos($lfbody, $noteBody_pt) !== false);
ok($hasNote,
   "A: landing body contains child_privacy_note_body text (modal shown, flag unset)");

// Stacking-fix regression guard: the modal must render as a fixed, high-z-index overlay
// (a plain <dialog open> rendered inline behind the food cards — the bug fixed here).
$modalOverlay = preg_match('/<dialog[^>]*id="privacyNoteModal"[^>]*style="[^"]*position:\s*fixed[^"]*z-index:\s*\d/s', $lfbody) === 1;
ok($modalOverlay,
   "A: privacy-note dialog is a fixed, z-indexed overlay (renders above the food grid, not inline behind it)");

// Scrape the CSRF token from the log-food page — this is the token to use in the POST.
$pageCsrf = '';
if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $lfbody, $mc)) {
    $pageCsrf = $mc[1];
}
ok($pageCsrf !== '', "A: log-food page exposes a CSRF token [got '$pageCsrf']");

// ==========================================================================
// GROUP B — Dismiss: POST with valid CSRF → flag set in DB; redirect
// ==========================================================================
echo "\n--- B. Dismiss: valid POST sets flag in DB ---\n";

ok($pageCsrf !== '', "B: have CSRF token from log-food page (group A)");

[$ackC, $ackH, $ackBody] = curlReqWithHeaders(
    'curl -c ' . escapeshellarg($childJar) . ' -b ' . escapeshellarg($childJar)
    . ' --data-urlencode ' . escapeshellarg('csrf_token=' . $pageCsrf)
    . ' ' . escapeshellarg($ackUrl)
);
ok($ackC === 302, "B: POST to ack-privacy-note with valid CSRF returns 302 [got $ackC]");

// Read the DB directly to confirm the flag was persisted.
$dbCheck = new PDO('sqlite:' . $tmpDb);
$dbCheck->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$seenVal = $dbCheck->query(
    "SELECT value FROM settings WHERE \"key\"='child_privacy_note_seen_$childId'"
)->fetchColumn();
$dbCheck = null;
ok($seenVal !== false && $seenVal !== '',
   "B: child_privacy_note_seen_$childId is set in DB after dismiss POST [got '$seenVal']");

// ==========================================================================
// GROUP C — Once: child GETs landing again → note absent
// ==========================================================================
echo "\n--- C. Once: privacy note absent after dismissal ---\n";

[$lfc2, $lfbody2] = curlReq(
    'curl -b ' . escapeshellarg($childJar) . ' -L ' . escapeshellarg($landingUrl)
);
ok($lfc2 === 200, "C: child GET log-food after dismiss returns 200 [got $lfc2]");
$noteGone = (strpos($lfbody2, $noteBody_en) === false)
         && (strpos($lfbody2, $noteBody_pt) === false);
ok($noteGone, "C: privacy note body text is ABSENT on second landing (shown once)");

// ==========================================================================
// GROUP D — CSRF: POST without token → rejected; flag unchanged
// ==========================================================================
echo "\n--- D. CSRF: POST without token leaves flag unchanged ---\n";

// Create a fresh child with no seen flag to test CSRF rejection without
// touching the already-dismissed flag from group B.
global $childPin;
$childPin2 = '5678';
$childId2  = createUser('SmokeChild2', 'child', $childPin2, '🧒');
ok($childId2 > 0, "D: seeded second child (id=$childId2)");

$child2Jar = tempnam(sys_get_temp_dir(), 'cc_cpn_child2_');
loginUser($child2Jar, $loginUrl, $childId2, $childPin2);

// POST to ack-privacy-note WITHOUT csrf_token.
[$noTokenC, $noTokenH, $noTokenBody] = curlReqWithHeaders(
    'curl -c ' . escapeshellarg($child2Jar) . ' -b ' . escapeshellarg($child2Jar)
    . ' -X POST'
    . ' ' . escapeshellarg($ackUrl)
);
ok($noTokenC >= 300 && $noTokenC < 400,
   "D: POST without CSRF token is rejected (3xx redirect) [got $noTokenC]");

// Read the DB directly — flag must still be unset.
$dbCheck2 = new PDO('sqlite:' . $tmpDb);
$dbCheck2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$seenVal2 = $dbCheck2->query(
    "SELECT value FROM settings WHERE \"key\"='child_privacy_note_seen_$childId2'"
)->fetchColumn();
$dbCheck2 = null;
ok($seenVal2 === false || $seenVal2 === '',
   "D: child_privacy_note_seen_$childId2 is NOT set after bad CSRF POST [got '$seenVal2']");

// ==========================================================================
// GROUP E — Guardian: landing does NOT contain child privacy note
// ==========================================================================
echo "\n--- E. Guardian: privacy note is child-only ---\n";

global $guardianId, $guardianPin;
loginUser($guardianJar, $loginUrl, $guardianId, $guardianPin);

// Guardian landing is the dashboard.
$dashUrl = "$base/index.php?page=dashboard";
[$dashC, $dashBody] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L ' . escapeshellarg($dashUrl)
);
ok($dashC === 200, "E: guardian GET dashboard returns 200 [got $dashC]");
$guardianHasNote = (strpos($dashBody, $noteBody_en) !== false)
               || (strpos($dashBody, $noteBody_pt) !== false);
ok(!$guardianHasNote,
   "E: guardian dashboard does NOT contain child_privacy_note_body (child-only modal)");

// --- Cleanup ----------------------------------------------------------------
$cleanup();
@unlink($childJar);
@unlink($guardianJar);
@unlink($child2Jar);

echo "\n==========================================================\n";
echo " HTTP CHILD PRIVACY NOTE smoke: $PASS passed, " . count($FAIL) . " failed\n";
echo "==========================================================\n";
if (empty($FAIL)) { echo "HTTP-CHILD-PRIVACY-NOTE-SMOKE: PASS\n"; exit(0); }
echo "HTTP-CHILD-PRIVACY-NOTE-SMOKE: FAIL\n";
foreach ($FAIL as $f) { echo "  - $f\n"; }
exit(1);
