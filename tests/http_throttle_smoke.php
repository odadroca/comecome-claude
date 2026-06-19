<?php
/**
 * ComeCome — HTTP-level THROTTLE smoke test (Sprint security Phase 1).
 * ====================================================================
 *
 * WHY THIS EXISTS:
 *   tests/run.php (PHASE H) asserts the throttle state machine + DB round-trip
 *   IN-PROCESS (calling authenticateUser()/loginIsLockedOut() directly). What it
 *   CANNOT prove is that the wired-up LOGIN PAGE, driven over real HTTP, actually
 *   surfaces the DISTINCT `locked` state to a real client after a scripted flood of
 *   wrong-PIN POSTs. The spec's Testability section requires a `php -S` + curl smoke
 *   for exactly the response behaviours the in-process harness can't observe; for
 *   Phase 1 that behaviour is:
 *
 *     - a state-changing login POST with the WRONG pin returns the "wrong PIN"
 *       message while still under the threshold, and
 *     - once the per-user threshold (THROTTLE_USER_MAX_FAILS, default 5) is crossed,
 *       the SAME POST switches to the DISTINCT `locked` message (login_locked), and
 *     - even the CORRECT pin is then refused (the pre-verify lockout gate holds over
 *       HTTP), proving the lockout is enforced end-to-end, not just in unit code.
 *
 *   This is the HTTP-smoke deliverable the regression's step (4) mandates for the
 *   throttling phase.
 *
 * SAFETY (critical — learned the hard way):
 *   The spawned server runs with COMECOME_DB_PATH pointed at a GUARANTEED-non-empty
 *   throwaway temp DB (tempnam), so it never reads or writes the real db/data.db.
 *   An empty/blank path would make config.php fall back to the real DB and pollute
 *   (and possibly LOCK) the live guardian — so we assert the path is non-empty and
 *   that the real data.db is never the target before spawning.
 *
 * USAGE:   php tests/http_throttle_smoke.php
 * EXIT:    0 = all assertions passed, non-zero = a failure.
 *
 * NOTE: a SEPARATE entry point (not folded into run.php) because it spawns a process
 * + binds a port, which the in-process unit harness avoids. run.php orchestrates it
 * as a sub-runner so `php tests/run.php` stays the single regression command.
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
echo " ComeCome HTTP throttle smoke (php -S + scripted wrong-PINs)\n";
echo "==========================================================\n";

// --- Throwaway DB so we NEVER touch the real data.db ------------------------
$tmpDb = tempnam(sys_get_temp_dir(), 'comecome_thr_') . '.db';
@unlink($tmpDb); // let the app create it fresh on first request

// Hard safety gate: the throwaway path must be non-empty AND must not resolve to the
// real db/data.db. An empty COMECOME_DB_PATH would make config.php fall back to the
// real DB (precedence: env > in-tree default) and pollute the live guardian.
$realDb = realpath($ROOT . '/db/data.db');
if ($tmpDb === '' || $tmpDb === false) {
    fwrite(STDERR, "ABORT: empty throwaway DB path (would fall back to real data.db)\n");
    exit(2);
}
$resolvedTmpDir = realpath(dirname($tmpDb));
if ($resolvedTmpDir === false) {
    fwrite(STDERR, "ABORT: throwaway temp dir does not resolve\n");
    exit(2);
}
if ($realDb !== false && (realpath($tmpDb) === $realDb)) {
    fwrite(STDERR, "ABORT: throwaway path resolved to real data.db\n");
    exit(2);
}

// --- Pick a FREE ephemeral port ---------------------------------------------
// Fixed ports collide when an earlier `php -S` orphan (or a parallel regression
// run) still holds them, which would hang this smoke. Bind a throwaway socket to
// :0, read back the OS-assigned port, close it, and hand that port to `php -S`.
// (Tiny TOCTOU window between close and re-bind, acceptable for a local smoke.)
function pickFreePort($host) {
    $sock = @stream_socket_server("tcp://$host:0", $errno, $errstr);
    if (!$sock) { return 0; }
    $name = stream_socket_get_name($sock, false); // "127.0.0.1:54321"
    fclose($sock);
    $pos = strrpos($name, ':');
    return $pos === false ? 0 : (int) substr($name, $pos + 1);
}

// --- Spawn `php -S` with the throwaway DB path ------------------------------
$host = '127.0.0.1';
$port = pickFreePort($host);
if ($port <= 0) {
    fwrite(STDERR, "ABORT: could not allocate a free port\n");
    @unlink($tmpDb);
    exit(2);
}
$phpBin = PHP_BINARY;
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$env = $_ENV;
$env['COMECOME_DB_PATH'] = $tmpDb; // GUARANTEED non-empty (asserted above)
$cmd = escapeshellarg($phpBin) . ' -S ' . $host . ':' . $port . ' -t ' . escapeshellarg($ROOT);

$proc = proc_open($cmd, $descriptors, $pipes, $ROOT, $env);
if (!is_resource($proc)) {
    fwrite(STDERR, "ABORT: could not start php -S\n"); exit(2);
}
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$cleanup = function () use ($proc, $pipes, $tmpDb) {
    foreach ($pipes as $p) { if (is_resource($p)) { fclose($p); } }
    proc_terminate($proc);
    proc_close($proc);
    for ($i = 0; $i < 10 && file_exists($tmpDb); $i++) { if (@unlink($tmpDb)) break; usleep(30000); }
};

// --- Wait for the server to accept connections ------------------------------
$up = false;
for ($i = 0; $i < 50; $i++) {
    $fp = @fsockopen($host, $port, $errno, $errstr, 0.2);
    if ($fp) { fclose($fp); $up = true; break; }
    usleep(100000);
}
if (!$up) {
    echo "  [FAIL] php -S did not come up on $host:$port\n";
    $cleanup();
    exit(1);
}
ok(true, "php -S dev server is up on $host:$port (throwaway DB)");

// --- Helpers: curl GET and POST to the login endpoint -----------------------
$base = "http://$host:$port";
$loginUrl = "$base/index.php?page=login";

/** GET a URL, return the response body. */
function httpGet($url) {
    $cmd = 'curl -s ' . escapeshellarg($url);
    return (string) shell_exec($cmd);
}
/** POST user_id+pin to the login endpoint, return the response body. */
function httpLoginPost($url, $userId, $pin) {
    $cmd = 'curl -s -X POST '
         . '--data-urlencode ' . escapeshellarg('user_id=' . $userId) . ' '
         . '--data-urlencode ' . escapeshellarg('pin=' . $pin) . ' '
         . escapeshellarg($url);
    return (string) shell_exec($cmd);
}

// --- Prime the app: first request creates+seeds the throwaway DB ------------
$home = httpGet("$base/");
ok($home !== '' , "priming GET / returned a body (throwaway DB created+seeded)");

// The default guardian (id=1, PIN '0000') is seeded by initializeDatabase(). The
// login page renders the distinct messages from the pt locale by default:
//   wrong PIN  -> login_error  ("PIN incorreto")
//   locked     -> login_locked ("... bloqueada ...")
// We assert on stable substrings that distinguish the two states. To be locale-robust
// we accept either the pt OR en wording.
$WRONG_MARKERS  = ['PIN incorreto', 'Incorrect PIN'];
$LOCKED_MARKERS = ['bloqueada', 'temporarily locked'];

function bodyHasAny($body, array $markers) {
    foreach ($markers as $m) { if (stripos($body, $m) !== false) { return true; } }
    return false;
}

// --- The scripted wrong-PIN flood against guardian id=1 ---------------------
// THROTTLE_USER_MAX_FAILS defaults to 5: attempts 1..4 must show the wrong-PIN
// message; attempt 5 must TIP into the distinct locked message.
echo "\n-- scripted wrong-PIN POSTs (threshold = 5) --\n";
$states = [];
for ($i = 1; $i <= 6; $i++) {
    $body = httpLoginPost($loginUrl, 1, '9999'); // deliberately wrong PIN
    if (bodyHasAny($body, $LOCKED_MARKERS))      { $states[$i] = 'locked'; }
    elseif (bodyHasAny($body, $WRONG_MARKERS))   { $states[$i] = 'wrong'; }
    else                                         { $states[$i] = 'other'; }
    echo "    attempt #$i => {$states[$i]}\n";
}

// Pre-threshold attempts (1..4) are "wrong PIN", not yet locked.
$preOk = ($states[1] === 'wrong' && $states[2] === 'wrong'
       && $states[3] === 'wrong' && $states[4] === 'wrong');
ok($preOk, "attempts #1-#4 return the distinct WRONG-PIN message (under threshold, not locked)");

// The 5th wrong attempt crosses the per-user threshold => DISTINCT locked message.
ok($states[5] === 'locked',
   "attempt #5 crosses the threshold and returns the DISTINCT locked message (not 'wrong PIN')");

// A continued attempt stays locked (the lock holds; hammering doesn't revert it).
ok($states[6] === 'locked',
   "attempt #6 (post-threshold) stays locked over HTTP");

// --- The CORRECT PIN is refused while locked (end-to-end lockout gate) -------
echo "\n-- correct PIN is refused while locked --\n";
$correctBody = httpLoginPost($loginUrl, 1, '0000'); // the real default guardian PIN
// A successful login would 302-redirect to index.php and NOT render the login error
// block; here, while locked, the page must re-render with the locked message and the
// session must NOT be authenticated. We assert the locked message is shown AND that
// the body is still the login page (the correct PIN did not get us in).
ok(bodyHasAny($correctBody, $LOCKED_MARKERS),
   "correct PIN while locked => still shows the locked message (pre-verify gate holds over HTTP)");
ok(!bodyHasAny($correctBody, ['dashboard', 'guardian-dashboard']) ,
   "correct PIN while locked => did NOT reach an authenticated page");

$cleanup();

echo "\n==========================================================\n";
echo " HTTP throttle smoke: $PASS passed, " . count($FAIL) . " failed\n";
echo "==========================================================\n";
if (empty($FAIL)) { echo "HTTP-THROTTLE-SMOKE: PASS\n"; exit(0); }
echo "HTTP-THROTTLE-SMOKE: FAIL\n";
foreach ($FAIL as $f) { echo "  - $f\n"; }
exit(1);
