<?php
/**
 * ComeCome — HTTP-level TLS/HSTS smoke test (Sprint security Phase 2).
 * ====================================================================
 *
 * WHY THIS EXISTS:
 *   tests/run.php (PHASE I) asserts the PURE transport-security decision logic
 *   IN-PROCESS (httpsRedirectTarget() / hstsHeaderValue() across branches). What
 *   it CANNOT prove is that the wired-up app, driven over real HTTP, actually
 *     - 301-redirects HTTP -> HTTPS when HTTPS enforcement is turned on, and
 *     - emits the HSTS header when the request is (treated as) HTTPS, and
 *     - does NEITHER on a plain `php -S` HTTP dev request with enforcement off
 *       (the ordering invariant: local dev must never be broken).
 *
 *   The `.htaccess` 301 + HSTS rule is the PRIMARY mechanism, but `.htaccess`
 *   needs Apache (mod_rewrite/mod_headers) and `php -S` does not run it. So the
 *   only HTTP-observable surface the Testability section's "301 redirect + HSTS
 *   fire when the TLS env flag is set" smoke can exercise is the PHP backstop in
 *   includes/session.php (enforceTransportSecurity()), invoked from config.php.
 *
 * HOW WE SIMULATE TLS UNDER `php -S` (which has no real certificate):
 *   - To exercise the REDIRECT branch: spawn the server with the deployment flag
 *     COMECOME_FORCE_HTTPS=1 and send a plain-HTTP request. requestIsHttps() is
 *     false (no TLS), enforcement is on, so the app must 301 to the https:// URL.
 *   - To exercise the HSTS branch WITHOUT a redirect loop: send a request the app
 *     treats as already-secure via the `X-Forwarded-Proto: https` header (the same
 *     proxy-TLS signal Phase 0's HTTPS detection honours). requestIsHttps() is then
 *     true, so the app does NOT redirect and DOES emit Strict-Transport-Security.
 *
 * SAFETY:
 *   The spawned server runs with COMECOME_DB_PATH pointed at a GUARANTEED-non-empty
 *   throwaway temp DB (tempnam), so it never reads or writes the real db/data.db.
 *
 * USAGE:   php tests/http_tls_smoke.php
 * EXIT:    0 = all assertions passed, non-zero = a failure.
 *
 * NOTE: a SEPARATE entry point (not folded into run.php) because it spawns a
 * process + binds a port, which the in-process unit harness avoids. run.php
 * orchestrates it as a sub-runner so `php tests/run.php` stays the single command.
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
echo " ComeCome HTTP TLS/HSTS smoke (php -S + header inspection)\n";
echo "==========================================================\n";

// --- Throwaway DB so we never touch the real data.db ------------------------
$tmpDb = tempnam(sys_get_temp_dir(), 'comecome_tls_') . '.db';
@unlink($tmpDb); // let the app create it fresh
if ($tmpDb === '' || $tmpDb === false) {
    fwrite(STDERR, "ABORT: could not allocate a throwaway DB path\n"); exit(2);
}
$realDb = realpath($ROOT . '/db/data.db');
if ($realDb !== false && realpath($tmpDb) === $realDb) {
    fwrite(STDERR, "ABORT: throwaway DB resolved to the real data.db\n"); exit(2);
}

// --- Pick a FREE ephemeral port (avoid collisions with orphaned servers) ----
$host = '127.0.0.1';
$pickSock = @stream_socket_server("tcp://$host:0", $pErrno, $pErrstr);
if (!$pickSock) {
    fwrite(STDERR, "ABORT: could not allocate a free port\n");
    @unlink($tmpDb);
    exit(2);
}
$pickName = stream_socket_get_name($pickSock, false);
fclose($pickSock);
$pickPos = strrpos($pickName, ':');
$port = ($pickPos === false) ? 0 : (int) substr($pickName, $pickPos + 1);
if ($port <= 0) {
    fwrite(STDERR, "ABORT: could not parse a free port\n");
    @unlink($tmpDb);
    exit(2);
}

// --- Spawn `php -S` with the throwaway DB + HTTPS enforcement turned ON ------
// COMECOME_FORCE_HTTPS=1 makes enforceTransportSecurity() redirect plain HTTP to
// HTTPS, which is exactly the "TLS env flag is set" scenario the spec names.
$phpBin = PHP_BINARY;
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$env = $_ENV;
$env['COMECOME_DB_PATH']    = $tmpDb;
$env['COMECOME_FORCE_HTTPS'] = '1';
$cmd = escapeshellarg($phpBin) . ' -S ' . $host . ':' . $port . ' -t ' . escapeshellarg($ROOT);

$proc = proc_open($cmd, $descriptors, $pipes, $ROOT, $env);
if (!is_resource($proc)) {
    fwrite(STDERR, "ABORT: could not start php -S\n"); @unlink($tmpDb); exit(2);
}
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
    for ($i = 0; $i < 5 && file_exists($tmpDb); $i++) { if (@unlink($tmpDb)) break; usleep(20000); }
};

if (!$up) {
    echo "  [FAIL] php -S did not come up on $host:$port\n";
    $cleanup();
    exit(1);
}
ok(true, "php -S dev server is up on $host:$port (throwaway DB, COMECOME_FORCE_HTTPS=1)");

// Helper: fetch ONLY the response headers via curl WITHOUT following redirects
// (we want to SEE the 301), optionally adding extra request headers.
$nullSink = (DIRECTORY_SEPARATOR === '\\') ? 'NUL' : '/dev/null';
$curlHeaders = function ($path, array $extraHeaders = []) use ($host, $port, $nullSink) {
    $url = "http://$host:$port$path";
    $cmd = 'curl -s -D - -o ' . $nullSink;
    foreach ($extraHeaders as $h) {
        $cmd .= ' -H ' . escapeshellarg($h);
    }
    $cmd .= ' ' . escapeshellarg($url);
    return shell_exec($cmd);
};

// ----------------------------------------------------------------------------
// (1) REDIRECT branch: plain HTTP + enforcement ON => 301 to the https:// URL.
// ----------------------------------------------------------------------------
echo "\n-- (1) plain HTTP with COMECOME_FORCE_HTTPS=1 => 301 -> https:// --\n";
$reqPath = '/index.php?page=login';
$h1 = $curlHeaders($reqPath);
if ($h1 === null || $h1 === '') {
    echo "  [FAIL] curl returned no headers for the redirect request\n";
    $cleanup();
    exit(1);
}
echo "\n-- raw headers (request 1) --\n$h1\n";

$statusLine1 = strtok($h1, "\n");
ok(preg_match('#HTTP/\S+\s+301#', $h1) === 1,
   "plain-HTTP request returns 301 [" . trim((string) $statusLine1) . "]");

$location1 = '';
foreach (preg_split('/\r?\n/', $h1) as $line) {
    if (stripos($line, 'Location:') === 0) { $location1 = trim(substr($line, strlen('Location:'))); break; }
}
$expectedTarget = "https://$host:$port$reqPath";
ok($location1 === $expectedTarget,
   "Location points at the https:// equivalent preserving path+query [$location1]");
// The 301 response itself must NOT carry HSTS (it is still a plain-HTTP response).
ok(stripos($h1, 'Strict-Transport-Security') === false,
   "the plain-HTTP 301 response does NOT carry HSTS (RFC 6797: HSTS only over TLS)");

// ----------------------------------------------------------------------------
// (2) HSTS branch: request treated as HTTPS (X-Forwarded-Proto) => no redirect,
//     HSTS header present.
// ----------------------------------------------------------------------------
echo "\n-- (2) request treated as HTTPS (X-Forwarded-Proto: https) => no redirect + HSTS --\n";
$h2 = $curlHeaders($reqPath, ['X-Forwarded-Proto: https']);
if ($h2 === null || $h2 === '') {
    echo "  [FAIL] curl returned no headers for the HTTPS-simulated request\n";
    $cleanup();
    exit(1);
}
echo "\n-- raw headers (request 2) --\n$h2\n";

ok(preg_match('#HTTP/\S+\s+200#', $h2) === 1,
   "HTTPS-treated request is served (200, NOT redirected — no proxy redirect loop)");

$hstsLine = '';
foreach (preg_split('/\r?\n/', $h2) as $line) {
    if (stripos($line, 'Strict-Transport-Security:') === 0) { $hstsLine = trim($line); break; }
}
ok($hstsLine !== '', "Strict-Transport-Security header is present over (simulated) TLS");
ok(preg_match('/max-age=\d+/i', $hstsLine) === 1, "HSTS carries a max-age directive [$hstsLine]");
// Conservative posture (spec): no preload token.
ok(stripos($hstsLine, 'preload') === false,
   "HSTS does NOT carry the preload token (conservative posture)");

$cleanup();

// ----------------------------------------------------------------------------
// (3) DEV-SAFE branch: a SECOND server with enforcement OFF (the zero-config
//     default) must NOT redirect and must NOT emit HSTS over plain HTTP.
//     This is the ordering-invariant guarantee: local `php -S` dev is untouched.
// ----------------------------------------------------------------------------
echo "\n-- (3) plain HTTP with enforcement OFF (default) => no redirect, no HSTS (dev-safe) --\n";

$tmpDb2 = tempnam(sys_get_temp_dir(), 'comecome_tls2_') . '.db';
@unlink($tmpDb2);
$pickSock2 = @stream_socket_server("tcp://$host:0", $e2, $s2);
if (!$pickSock2) { echo "  [FAIL] could not allocate a 2nd free port\n"; @unlink($tmpDb2); goto finish; }
$pn2 = stream_socket_get_name($pickSock2, false); fclose($pickSock2);
$pp2 = strrpos($pn2, ':'); $port2 = ($pp2 === false) ? 0 : (int) substr($pn2, $pp2 + 1);
if ($port2 <= 0) { echo "  [FAIL] could not parse a 2nd free port\n"; @unlink($tmpDb2); goto finish; }

$env2 = $_ENV;
$env2['COMECOME_DB_PATH'] = $tmpDb2;
unset($env2['COMECOME_FORCE_HTTPS']); // enforcement OFF (default)
$cmd2 = escapeshellarg($phpBin) . ' -S ' . $host . ':' . $port2 . ' -t ' . escapeshellarg($ROOT);
$proc2 = proc_open($cmd2, $descriptors, $pipes2, $ROOT, $env2);
if (!is_resource($proc2)) { echo "  [FAIL] could not start 2nd php -S\n"; @unlink($tmpDb2); goto finish; }
stream_set_blocking($pipes2[1], false);
stream_set_blocking($pipes2[2], false);

$up2 = false;
for ($i = 0; $i < 50; $i++) {
    $fp = @fsockopen($host, $port2, $errno, $errstr, 0.2);
    if ($fp) { fclose($fp); $up2 = true; break; }
    usleep(100000);
}
$cleanup2 = function () use ($proc2, $pipes2, $tmpDb2) {
    foreach ($pipes2 as $p) { if (is_resource($p)) { fclose($p); } }
    proc_terminate($proc2);
    proc_close($proc2);
    for ($i = 0; $i < 5 && file_exists($tmpDb2); $i++) { if (@unlink($tmpDb2)) break; usleep(20000); }
};
if (!$up2) { echo "  [FAIL] 2nd php -S did not come up\n"; $cleanup2(); goto finish; }

// $curlHeaders is bound to the FIRST port, so issue this request against port2
// explicitly (the 2nd server, enforcement OFF).
$url3 = "http://$host:$port2$reqPath";
$cmd3 = 'curl -s -D - -o ' . $nullSink . ' ' . escapeshellarg($url3);
$h3 = shell_exec($cmd3);
echo "\n-- raw headers (request 3, enforcement OFF) --\n" . (string) $h3 . "\n";

ok($h3 !== null && $h3 !== '' && preg_match('#HTTP/\S+\s+200#', (string) $h3) === 1,
   "plain HTTP with enforcement OFF is served normally (200, NOT redirected — dev untouched)");
ok(stripos((string) $h3, 'Strict-Transport-Security') === false,
   "no HSTS over plain HTTP when enforcement is off (RFC 6797 + dev-safe)");

$cleanup2();

finish:
echo "\n==========================================================\n";
echo " HTTP TLS/HSTS smoke: $PASS passed, " . count($FAIL) . " failed\n";
echo "==========================================================\n";
if (empty($FAIL)) { echo "HTTP-TLS-SMOKE: PASS\n"; exit(0); }
echo "HTTP-TLS-SMOKE: FAIL\n";
foreach ($FAIL as $f) { echo "  - $f\n"; }
exit(1);
