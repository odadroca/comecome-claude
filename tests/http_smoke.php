<?php
/**
 * ComeCome — HTTP-level smoke test (Sprint security Phase 0).
 * ===========================================================
 *
 * WHY THIS EXISTS:
 *   tests/run.php cannot load config.php or start a real session, so it can only
 *   assert the PURE cookie-param logic (configureSessionCookieParams). The actual
 *   Set-Cookie HEADER behaviour — that PHP really emits HttpOnly + SameSite=Lax on
 *   the session cookie — can only be observed over real HTTP. This script boots the
 *   built-in `php -S` dev server against a THROWAWAY DB and inspects the response
 *   headers, exactly as the spec's Testability section requires.
 *
 *   It also asserts the NEGATIVE-for-dev invariant: over plain HTTP (local dev) the
 *   Secure flag must be ABSENT, so `php -S` HTTP dev is never broken (the env-gated
 *   Secure flag only turns on under TLS in Phase 2).
 *
 * SAFETY:
 *   The spawned server runs with COMECOME_DB_PATH pointed at a throwaway temp DB,
 *   so it never reads or writes the real db/data.db.
 *
 * USAGE:   php tests/http_smoke.php
 * EXIT:    0 = all header assertions passed, non-zero = a failure.
 *
 * NOTE: this is a SEPARATE entry point (not folded into run.php) because it needs
 * to spawn a process + bind a port, which the in-process unit harness avoids.
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
echo " ComeCome HTTP smoke (php -S + header inspection)\n";
echo "==========================================================\n";

// --- Throwaway DB so we never touch the real data.db ------------------------
$tmpDb = tempnam(sys_get_temp_dir(), 'comecome_http_') . '.db';
@unlink($tmpDb); // let the app create it fresh
$realDb = realpath($ROOT . '/db/data.db');
if ($realDb !== false && realpath(dirname($tmpDb)) === false) {
    fwrite(STDERR, "ABORT: bad temp path\n"); exit(2);
}

// --- Pick a free-ish port ---------------------------------------------------
$port = 8099;
$host = '127.0.0.1';

// --- Spawn `php -S` with the throwaway DB path ------------------------------
$phpBin = PHP_BINARY;
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$env = $_ENV;
$env['COMECOME_DB_PATH'] = $tmpDb;
$cmd = escapeshellarg($phpBin) . ' -S ' . $host . ':' . $port . ' -t ' . escapeshellarg($ROOT);

$proc = proc_open($cmd, $descriptors, $pipes, $ROOT, $env);
if (!is_resource($proc)) {
    fwrite(STDERR, "ABORT: could not start php -S\n"); exit(2);
}
// Don't block on the server's stderr/stdout pipes.
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

// --- Wait for the server to accept connections ------------------------------
$up = false;
for ($i = 0; $i < 50; $i++) {
    $fp = @fsockopen($host, $port, $errno, $errstr, 0.2);
    if ($fp) { fclose($fp); $up = true; break; }
    usleep(100000); // 100ms
}

$cleanup = function () use ($proc, $pipes, $tmpDb) {
    foreach ($pipes as $p) { if (is_resource($p)) { fclose($p); } }
    proc_terminate($proc);
    proc_close($proc);
    // Best-effort temp DB removal (Windows lock-tolerant).
    for ($i = 0; $i < 5 && file_exists($tmpDb); $i++) { if (@unlink($tmpDb)) break; usleep(20000); }
};

if (!$up) {
    echo "  [FAIL] php -S did not come up on $host:$port\n";
    $cleanup();
    exit(1);
}
ok(true, "php -S dev server is up on $host:$port (throwaway DB)");

// --- Fetch the login page headers via curl ----------------------------------
$url = "http://$host:$port/index.php?page=login";
// -D - dumps response headers to stdout; portable null body sink (NUL on Windows,
// /dev/null on POSIX).
$nullSink = (DIRECTORY_SEPARATOR === '\\') ? 'NUL' : '/dev/null';
$cmdCurl = 'curl -s -D - -o ' . $nullSink . ' ' . escapeshellarg($url);
$headers = shell_exec($cmdCurl);

if ($headers === null || $headers === '') {
    echo "  [FAIL] curl returned no headers from $url\n";
    $cleanup();
    exit(1);
}

echo "\n-- raw response headers --\n";
echo $headers . "\n";

// --- Assertions on the Set-Cookie header ------------------------------------
// Find the PHPSESSID Set-Cookie line (case-insensitive).
$setCookieLine = '';
foreach (preg_split('/\r?\n/', $headers) as $line) {
    if (stripos($line, 'Set-Cookie:') === 0 && stripos($line, 'PHPSESSID') !== false) {
        $setCookieLine = $line;
        break;
    }
}

ok($setCookieLine !== '', "Set-Cookie for PHPSESSID is present");
ok(stripos($setCookieLine, 'HttpOnly') !== false,
   "Session cookie carries HttpOnly");
ok(preg_match('/SameSite=Lax/i', $setCookieLine) === 1,
   "Session cookie carries SameSite=Lax");
// Over plain HTTP (this dev server), Secure MUST be absent so local dev isn't broken.
ok(stripos($setCookieLine, 'Secure') === false,
   "Session cookie has NO Secure flag over plain HTTP (local php -S dev not broken)");

$cleanup();

echo "\n==========================================================\n";
echo " HTTP smoke: $PASS passed, " . count($FAIL) . " failed\n";
echo "==========================================================\n";
if (empty($FAIL)) { echo "HTTP-SMOKE: PASS\n"; exit(0); }
echo "HTTP-SMOKE: FAIL\n";
foreach ($FAIL as $f) { echo "  - $f\n"; }
exit(1);
