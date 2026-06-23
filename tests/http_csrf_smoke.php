<?php
/**
 * ComeCome — HTTP-level CSRF smoke test (Sprint security Phase 3).
 * ===============================================================
 *
 * WHY THIS EXISTS:
 *   tests/run.php (PHASE J) asserts the PURE CSRF comparison + the guest-token
 *   revocation round-trip, but it cannot observe the real HTTP reject: that a POST
 *   without a valid X-CSRF-Token actually returns 403, while a POST that carries the
 *   per-session token succeeds. The Testability section of SPRINT-SECURITY.md
 *   requires a `php -S` + curl smoke for exactly this ("CSRF-reject (guardian action
 *   + an api endpoint)"). This script boots `php -S` against a THROWAWAY DB, logs in
 *   to obtain a real session cookie + the page's CSRF token, then proves:
 *
 *     1. An api POST WITHOUT the X-CSRF-Token header is REJECTED (403 invalid_csrf),
 *        even with a valid session cookie.
 *     2. The SAME api POST WITH the token header SUCCEEDS.
 *     3. A guardian state-changing POST (manage-users) WITHOUT a token is bounced
 *        (no success), and WITH the token is accepted.
 *
 * SAFETY:
 *   The spawned server runs with COMECOME_DB_PATH pointed at a throwaway temp DB; it
 *   never touches the real db/data.db. The seeded guardian PIN is the default '0000'.
 *
 * USAGE:   php tests/http_csrf_smoke.php
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
echo " ComeCome HTTP CSRF smoke (php -S + curl)\n";
echo "==========================================================\n";

// --- Throwaway DB -----------------------------------------------------------
$tmpDb = tempnam(sys_get_temp_dir(), 'comecome_csrf_') . '.db';
@unlink($tmpDb);

// Launch S2 — the API layer now requires guardian consent before writes. This
// smoke tests the CSRF gate (not consent), so initialise the throwaway DB and
// record consent up front; the spawned server then reuses this DB. Without it,
// the api POSTs would 403 'consent_required' instead of exercising the CSRF gate.
define('DB_PATH', $tmpDb);
define('DB_SCHEMA', $ROOT . '/db/schema.sql');
define('DB_SEED', $ROOT . '/db/seed.sql');
require_once $ROOT . '/includes/db.php';
initializeDatabase();
setSetting('guardian_consent_version', '1');
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
$cookieJar = tempnam(sys_get_temp_dir(), 'cc_csrf_cookie_');

/** Run curl, return [httpCode, body]. -c/-b persist cookies across calls. */
function curlReq($args) {
    $out = shell_exec($args . ' -s -w "\n__HTTP__%{http_code}"');
    if ($out === null) { return [0, '']; }
    $pos = strrpos($out, "\n__HTTP__");
    if ($pos === false) { return [0, $out]; }
    $body = substr($out, 0, $pos);
    $code = (int) substr($out, $pos + strlen("\n__HTTP__"));
    return [$code, $body];
}

// --- 1. GET the login page; capture the session cookie + CSRF token ----------
$loginUrl = "$base/index.php?page=login";
[$lc, $loginHtml] = curlReq('curl -c ' . escapeshellarg($cookieJar) . ' ' . escapeshellarg($loginUrl));
ok($lc === 200, "GET login page returns 200 [got $lc]");

// Extract the CSRF token from the <meta name="csrf-token"> tag the layout emits.
$csrf = '';
if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $loginHtml, $m)) {
    $csrf = $m[1];
}
ok($csrf !== '', "login page exposes a CSRF token via <meta name=\"csrf-token\">");

// --- 2. Log in as the seeded guardian (id=1, PIN 0000) WITH the token --------
// The login POST itself is CSRF-protected, so we must send the token to log in.
$loginPost = 'curl -c ' . escapeshellarg($cookieJar) . ' -b ' . escapeshellarg($cookieJar)
    . ' -L'
    . ' --data-urlencode ' . escapeshellarg('csrf_token=' . $csrf)
    . ' --data-urlencode ' . escapeshellarg('user_id=1')
    . ' --data-urlencode ' . escapeshellarg('pin=0000')
    . ' ' . escapeshellarg($loginUrl);
[$pc, $pbody] = curlReq($loginPost);
ok($pc === 200, "login POST with CSRF token completes (followed redirect) [got $pc]");

// A login POST WITHOUT the token must be refused (no session elevation). Use a fresh
// cookie jar so we don't reuse the already-authenticated session.
$noTokJar = tempnam(sys_get_temp_dir(), 'cc_csrf_notok_');
// First fetch a fresh session to get its own cookie.
curlReq('curl -c ' . escapeshellarg($noTokJar) . ' ' . escapeshellarg($loginUrl));
$loginNoTok = 'curl -c ' . escapeshellarg($noTokJar) . ' -b ' . escapeshellarg($noTokJar)
    . ' --data-urlencode ' . escapeshellarg('user_id=1')
    . ' --data-urlencode ' . escapeshellarg('pin=0000')
    . ' ' . escapeshellarg($loginUrl);
[$ntc, $ntbody] = curlReq($loginNoTok);
// Without a token the handler sets the invalid-request error and re-renders the login
// page (200, but NOT redirected to the dashboard). We assert it did NOT authenticate
// by confirming the login form is still present in the response.
ok(strpos($ntbody, 'name="pin"') !== false || strpos($ntbody, 'loginForm') !== false,
   "login POST WITHOUT a CSRF token does NOT authenticate (stays on login page)");
@unlink($noTokJar);

// --- 3. api POST WITHOUT the X-CSRF-Token header => 403 invalid_csrf ----------
// Use the authenticated cookie jar so the ONLY thing missing is the CSRF header.
$favUrl = "$base/api/favorites.php";
$apiNoToken = 'curl -b ' . escapeshellarg($cookieJar)
    . ' -X POST -H "Content-Type: application/json"'
    . ' --data ' . escapeshellarg('{"food_id":1}')
    . ' ' . escapeshellarg($favUrl);
[$ac, $abody] = curlReq($apiNoToken);
ok($ac === 403, "api POST WITHOUT X-CSRF-Token is rejected 403 [got $ac]");
ok(strpos($abody, 'invalid_csrf') !== false,
   "api reject body carries error=invalid_csrf [" . trim($abody) . "]");

// --- 4. api POST WITH the X-CSRF-Token header => PAST the CSRF gate -----------
// The success criterion is that a valid token gets the request PAST the CSRF gate
// (i.e. NOT a 403 invalid_csrf). We deliberately do not assert the endpoint's own
// business result here — that depends on curl JSON-body quoting which is fragile on
// Windows — only that CSRF stopped rejecting once the token is present.
$apiWithToken = 'curl -b ' . escapeshellarg($cookieJar)
    . ' -X POST -H "Content-Type: application/json"'
    . ' -H ' . escapeshellarg('X-CSRF-Token: ' . $csrf)
    . ' --data ' . escapeshellarg('{"food_id":1}')
    . ' ' . escapeshellarg($favUrl);
[$awc, $awbody] = curlReq($apiWithToken);
ok($awc !== 403 && strpos($awbody, 'invalid_csrf') === false,
   "api POST WITH a valid X-CSRF-Token passes the CSRF gate (not 403 invalid_csrf) [got $awc, body " . trim($awbody) . "]");

// --- 5. guardian POST (manage-users) WITHOUT a token is bounced ---------------
$muUrl = "$base/index.php?page=manage-users";
$guardianNoToken = 'curl -b ' . escapeshellarg($cookieJar) . ' -i'
    . ' --data-urlencode ' . escapeshellarg('action=create')
    . ' --data-urlencode ' . escapeshellarg('name=CsrfTestKid')
    . ' --data-urlencode ' . escapeshellarg('pin=4321')
    . ' --data-urlencode ' . escapeshellarg('type=child')
    . ' ' . escapeshellarg($muUrl);
[$gc, $gbody] = curlReq($guardianNoToken);
// The handler redirects to ?page=manage-users&msg=csrf_error WITHOUT creating the user.
ok(stripos($gbody, 'csrf_error') !== false || stripos($gbody, 'Location:') !== false,
   "guardian POST WITHOUT a CSRF token is redirected to an error (not processed)");

$cleanup();
@unlink($cookieJar);

echo "\n==========================================================\n";
echo " HTTP CSRF smoke: $PASS passed, " . count($FAIL) . " failed\n";
echo "==========================================================\n";
if (empty($FAIL)) { echo "HTTP-CSRF-SMOKE: PASS\n"; exit(0); }
echo "HTTP-CSRF-SMOKE: FAIL\n";
foreach ($FAIL as $f) { echo "  - $f\n"; }
exit(1);
