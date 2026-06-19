<?php
/**
 * ComeCome — HTTP-level CHILD-FLOW CSRF smoke (Sprint security Phase 3).
 * =====================================================================
 *
 * WHY THIS EXISTS (acceptance bullet 3):
 *   Phase 3's acceptance requires that the CHILD log/celebrate flow re-smoke-passes
 *   after the inline fetch() JS was edited to attach the per-session CSRF token.
 *   tests/http_csrf_smoke.php proves the api gate via a GUARDIAN session against
 *   api/favorites.php. This script closes the loop on the actual CHILD path:
 *
 *     1. A CHILD logs in over real HTTP (own PIN), gets a session cookie.
 *     2. A CHILD page (log-food) injects the per-session CSRF token via
 *        <meta name="csrf-token"> + window.CSRF_TOKEN (the value the inline
 *        fetch() reads), proving the token reaches the child surface.
 *     3. A child food-log POST to api/food-log.php WITHOUT the X-CSRF-Token header
 *        is rejected 403 invalid_csrf (the log/celebrate AJAX would fail closed).
 *     4. The SAME POST WITH the token header SUCCEEDS ({"success":true}) — the
 *        real celebrate-on-log path works end to end with the token attached.
 *
 *   This makes the "child log/celebrate flow re-smoke-passes" acceptance an
 *   evidenced HTTP test, not just code inspection.
 *
 * SAFETY:
 *   The spawned `php -S` runs with COMECOME_DB_PATH pointed at a THROWAWAY temp DB;
 *   it never touches the real db/data.db. A child user is seeded into that temp DB
 *   before the server starts.
 *
 * USAGE:   php tests/http_csrf_child_smoke.php
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
echo " ComeCome HTTP CHILD-FLOW CSRF smoke (php -S + curl)\n";
echo "==========================================================\n";

// --- Throwaway DB + a seeded child ------------------------------------------
$tmpDb = tempnam(sys_get_temp_dir(), 'comecome_csrfchild_') . '.db';
@unlink($tmpDb);

// Seed a fresh app DB + a child user IN-PROCESS (same DB the server will use).
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
require_once $ROOT . '/includes/db.php';
require_once $ROOT . '/includes/auth.php';
initializeDatabase();
$childPin = '2580';
$childId = createUser('SmokeKid', 'child', $childPin, '🧒');
ok($childId > 0, "seeded a child user (id=$childId) into the throwaway DB");
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
$cookieJar = tempnam(sys_get_temp_dir(), 'cc_csrfchild_cookie_');

/** Run curl, return [httpCode, body]. */
function curlReq($args) {
    $out = shell_exec($args . ' -s -w "\n__HTTP__%{http_code}"');
    if ($out === null) { return [0, '']; }
    $pos = strrpos($out, "\n__HTTP__");
    if ($pos === false) { return [0, $out]; }
    $body = substr($out, 0, $pos);
    $code = (int) substr($out, $pos + strlen("\n__HTTP__"));
    return [$code, $body];
}

// --- 1. GET login page; capture session cookie + CSRF token ------------------
$loginUrl = "$base/index.php?page=login";
[$lc, $loginHtml] = curlReq('curl -c ' . escapeshellarg($cookieJar) . ' ' . escapeshellarg($loginUrl));
ok($lc === 200, "GET login page returns 200 [got $lc]");
$csrf = '';
if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $loginHtml, $m)) { $csrf = $m[1]; }
ok($csrf !== '', "login page exposes a CSRF token via <meta name=\"csrf-token\">");

// --- 2. Log in as the CHILD (with the CSRF token) ----------------------------
global $childId, $childPin;
$loginPost = 'curl -c ' . escapeshellarg($cookieJar) . ' -b ' . escapeshellarg($cookieJar)
    . ' -L'
    . ' --data-urlencode ' . escapeshellarg('csrf_token=' . $csrf)
    . ' --data-urlencode ' . escapeshellarg('user_id=' . $childId)
    . ' --data-urlencode ' . escapeshellarg('pin=' . $childPin)
    . ' ' . escapeshellarg($loginUrl);
[$pc, $pbody] = curlReq($loginPost);
ok($pc === 200, "child login POST with CSRF token completes (followed redirect) [got $pc]");

// --- 3. A CHILD page (log-food) injects the per-session CSRF token -----------
// The inline fetch() reads window.CSRF_TOKEN; confirm the child surface carries it.
$childPageUrl = "$base/index.php?page=log-food";
[$cpc, $childHtml] = curlReq('curl -b ' . escapeshellarg($cookieJar) . ' ' . escapeshellarg($childPageUrl));
ok($cpc === 200, "child log-food page loads for the logged-in child [got $cpc]");
$childCsrf = '';
if (preg_match('/window\.CSRF_TOKEN=("?)([a-f0-9]+)\1/', $childHtml, $mm)) { $childCsrf = $mm[2]; }
if ($childCsrf === '' && preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $childHtml, $mm2)) { $childCsrf = $mm2[1]; }
ok($childCsrf !== '', "child page injects the per-session CSRF token (window.CSRF_TOKEN) for inline fetch()");

// --- 4. child food-log POST WITHOUT the token => 403 invalid_csrf ------------
// Send the JSON body via a temp FILE (--data @file) so curl gets it byte-for-byte:
// inline --data '{"..."}' mangles the inner double-quotes under shell_exec on Windows
// (which is why the favorites smoke avoided asserting the business result). The file
// route lets us assert the REAL successful log below, not just "past the CSRF gate".
$flUrl = "$base/api/food-log.php";
$payload = json_encode(['food_id' => 1, 'meal_id' => 1, 'portion' => 'some']);
$payloadFile = tempnam(sys_get_temp_dir(), 'cc_flbody_') . '.json';
file_put_contents($payloadFile, $payload);

$apiNoToken = 'curl -b ' . escapeshellarg($cookieJar)
    . ' -X POST -H "Content-Type: application/json"'
    . ' --data ' . escapeshellarg('@' . $payloadFile)
    . ' ' . escapeshellarg($flUrl);
[$ac, $abody] = curlReq($apiNoToken);
ok($ac === 403 && strpos($abody, 'invalid_csrf') !== false,
   "child food-log POST WITHOUT X-CSRF-Token is rejected 403 invalid_csrf [got $ac, " . trim($abody) . "]");

// --- 5. child food-log POST WITH the token => REAL success -------------------
$apiWithToken = 'curl -b ' . escapeshellarg($cookieJar)
    . ' -X POST -H "Content-Type: application/json"'
    . ' -H ' . escapeshellarg('X-CSRF-Token: ' . $childCsrf)
    . ' --data ' . escapeshellarg('@' . $payloadFile)
    . ' ' . escapeshellarg($flUrl);
[$awc, $awbody] = curlReq($apiWithToken);
ok($awc !== 403 && strpos($awbody, 'invalid_csrf') === false && strpos($awbody, '"success":true') !== false,
   "child food-log POST WITH a valid X-CSRF-Token SUCCEEDS (log/celebrate flow works) [got $awc, " . trim($awbody) . "]");
@unlink($payloadFile);

$cleanup();
@unlink($cookieJar);

echo "\n==========================================================\n";
echo " HTTP CHILD CSRF smoke: $PASS passed, " . count($FAIL) . " failed\n";
echo "==========================================================\n";
if (empty($FAIL)) { echo "HTTP-CSRF-CHILD-SMOKE: PASS\n"; exit(0); }
echo "HTTP-CSRF-CHILD-SMOKE: FAIL\n";
foreach ($FAIL as $f) { echo "  - $f\n"; }
exit(1);
