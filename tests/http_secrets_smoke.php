<?php
/**
 * ComeCome — HTTP-level secrets / .env override smoke test (Sprint security Phase 4).
 * ==================================================================================
 *
 * WHY THIS EXISTS:
 *   tests/run.php (PHASE K) asserts the PURE key-container validation logic
 *   IN-PROCESS (loadEncryptionKeyContainer() / encryptionKey() across every
 *   branch). What it CANNOT prove is the Phase-4 acceptance bullet as it actually
 *   behaves over REAL HTTP through config.php's override loader:
 *
 *     (1) An ABOVE-DOCROOT config file (pointed at by the COMECOME_CONFIG env var)
 *         that `define()`s a constant OVERRIDES the hardcoded default — observed
 *         end-to-end via an HTTP-visible header the overridden constant drives
 *         (HSTS max-age, set by HSTS_MAX_AGE).
 *     (2) With NO override present, the app falls back to the hardcoded DEFAULT
 *         (zero-config first run intact) — the SAME header carries the default
 *         max-age, proving the override is genuinely additive/optional.
 *     (3) A secret key-CONTAINER file is never served as readable text: a direct
 *         HTTP GET of an in-tree `*.php` key container EXECUTES to empty output
 *         (PHP `return` files print nothing) and NEVER leaks the base64 key — the
 *         exact reason Phase 4 stores the key in a PHP `return` file, not a
 *         `.ini`/`.txt`. (The real key lives ABOVE docroot; this asserts the
 *         file-FORMAT's leak-resistance even if one strays into the tree.)
 *
 * HOW WE DRIVE THE OVERRIDE OVER `php -S`:
 *   config.php reads getenv('COMECOME_CONFIG'); if it points at an existing file
 *   it `require`s it BEFORE its own define()s. Because PHP define() is
 *   first-write-wins, a constant defined in that file wins. We point it at a
 *   throwaway file in the SYSTEM TEMP DIR (i.e. "above"/outside the web root the
 *   server is rooted at) that defines a DISTINCTIVE HSTS_MAX_AGE, then read the
 *   Strict-Transport-Security header back (request treated as HTTPS via
 *   X-Forwarded-Proto so the header is emitted without a redirect).
 *
 * SAFETY:
 *   Every spawned server runs with COMECOME_DB_PATH pointed at a throwaway temp DB
 *   (tempnam), so it never reads or writes the real db/data.db. The override config
 *   and the stray key container are throwaway temp files removed on cleanup.
 *
 * USAGE:   php tests/http_secrets_smoke.php
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
echo " ComeCome HTTP secrets/.env override smoke (php -S + curl)\n";
echo "==========================================================\n";

$host    = '127.0.0.1';
$phpBin  = PHP_BINARY;
$nullSink = (DIRECTORY_SEPARATOR === '\\') ? 'NUL' : '/dev/null';

// Distinctive non-default HSTS max-age the override config will set. The hardcoded
// default in config.php is 86400 (1 day); pick a value that is unmistakably NOT
// the default so observing it proves the override actually took effect.
$OVERRIDE_MAXAGE = 1234567;
$DEFAULT_MAXAGE  = 86400;

// Throwaway resources to clean up no matter where we exit.
$tmpFiles = [];
$procs    = [];

/** Allocate + register a throwaway temp DB path (never the real data.db). */
$newTmpDb = function () use (&$tmpFiles, $ROOT) {
    $p = tempnam(sys_get_temp_dir(), 'comecome_sec_') . '.db';
    @unlink($p);
    $realDb = realpath($ROOT . '/db/data.db');
    if ($realDb !== false && realpath($p) === $realDb) {
        fwrite(STDERR, "ABORT: throwaway DB resolved to the real data.db\n");
        exit(2);
    }
    $tmpFiles[] = $p;
    return $p;
};

/** Pick a free ephemeral port by binding :0 and reading it back. */
$freePort = function () use ($host) {
    $s = @stream_socket_server("tcp://$host:0", $e, $m);
    if (!$s) { return 0; }
    $name = stream_socket_get_name($s, false);
    fclose($s);
    $pos = strrpos($name, ':');
    return ($pos === false) ? 0 : (int) substr($name, $pos + 1);
};

/** Spawn `php -S` rooted at $ROOT with the given extra env; wait until it accepts. */
$spawn = function (array $extraEnv) use ($host, $phpBin, $ROOT, $freePort, &$procs) {
    $port = $freePort();
    if ($port <= 0) { return [null, 0]; }
    $env = $_ENV;
    foreach ($extraEnv as $k => $v) { $env[$k] = $v; }
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cmd = escapeshellarg($phpBin) . ' -S ' . $host . ':' . $port . ' -t ' . escapeshellarg($ROOT);
    $proc = proc_open($cmd, $descriptors, $pipes, $ROOT, $env);
    if (!is_resource($proc)) { return [null, 0]; }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $procs[] = ['proc' => $proc, 'pipes' => $pipes];
    $up = false;
    for ($i = 0; $i < 50; $i++) {
        $fp = @fsockopen($host, $port, $errno, $errstr, 0.2);
        if ($fp) { fclose($fp); $up = true; break; }
        usleep(100000);
    }
    return [$up ? $proc : null, $port];
};

/** curl: dump response headers (no redirect-follow) with optional request headers. */
$curlHeaders = function ($port, $path, array $extraHeaders = []) use ($host, $nullSink) {
    $url = "http://$host:$port$path";
    $cmd = 'curl -s -D - -o ' . $nullSink;
    foreach ($extraHeaders as $h) { $cmd .= ' -H ' . escapeshellarg($h); }
    $cmd .= ' ' . escapeshellarg($url);
    return (string) shell_exec($cmd);
};

/** curl: fetch the full BODY of a URL (to assert a secret never leaks). */
$curlBody = function ($port, $path) use ($host) {
    $url = "http://$host:$port$path";
    return (string) shell_exec('curl -s ' . escapeshellarg($url));
};

$cleanupAll = function () use (&$procs, &$tmpFiles) {
    foreach ($procs as $h) {
        foreach ($h['pipes'] as $p) { if (is_resource($p)) { fclose($p); } }
        @proc_terminate($h['proc']);
        @proc_close($h['proc']);
    }
    foreach ($tmpFiles as $f) {
        for ($i = 0; $i < 5 && file_exists($f); $i++) { if (@unlink($f)) break; usleep(20000); }
    }
};

/** Extract the Strict-Transport-Security header line from a raw header block. */
$hstsLineOf = function ($headers) {
    foreach (preg_split('/\r?\n/', (string) $headers) as $line) {
        if (stripos($line, 'Strict-Transport-Security:') === 0) { return trim($line); }
    }
    return '';
};

$reqPath = '/index.php?page=login';

// ----------------------------------------------------------------------------
// (1) ABOVE-DOCROOT CONFIG OVERRIDE: COMECOME_CONFIG file defines a non-default
//     HSTS_MAX_AGE -> the override wins -> the HSTS header carries that value.
// ----------------------------------------------------------------------------
echo "\n-- (1) above-docroot COMECOME_CONFIG override wins over the hardcoded default --\n";

$overrideCfg = tempnam(sys_get_temp_dir(), 'comecome_cfg_') . '.php';
$tmpFiles[] = $overrideCfg;
file_put_contents(
    $overrideCfg,
    "<?php\n"
    . "// Throwaway above-docroot override config for the Phase 4 HTTP secrets smoke.\n"
    . "// config.php require()s this BEFORE its own define()s, so this value wins.\n"
    . "define('HSTS_MAX_AGE', " . $OVERRIDE_MAXAGE . ");\n"
);

$dbOv = $newTmpDb();
[$procOv, $portOv] = $spawn([
    'COMECOME_DB_PATH'    => $dbOv,
    'COMECOME_CONFIG'     => $overrideCfg,
    'COMECOME_FORCE_HTTPS' => '1', // ensures the transport-security path is active
]);
ok($procOv !== null, "php -S up with COMECOME_CONFIG override (throwaway DB)");
if ($procOv !== null) {
    // Treat the request as HTTPS so HSTS is emitted WITHOUT a redirect.
    $hOv = $curlHeaders($portOv, $reqPath, ['X-Forwarded-Proto: https']);
    echo "\n-- raw headers (override active) --\n$hOv\n";
    $hstsOv = $hstsLineOf($hOv);
    ok($hstsOv !== '', "HSTS header present over (simulated) TLS with override config");
    ok(preg_match('/max-age=' . $OVERRIDE_MAXAGE . '\b/', $hstsOv) === 1,
       "HSTS max-age reflects the OVERRIDE value ($OVERRIDE_MAXAGE) [$hstsOv]");
    ok(strpos($hstsOv, (string) $DEFAULT_MAXAGE) === false,
       "HSTS max-age is NOT the hardcoded default ($DEFAULT_MAXAGE) when overridden");
}

// ----------------------------------------------------------------------------
// (2) ABSENT OVERRIDE => hardcoded DEFAULT (zero-config first run intact).
// ----------------------------------------------------------------------------
echo "\n-- (2) NO COMECOME_CONFIG => falls back to the hardcoded default (zero-config) --\n";

$dbDef = $newTmpDb();
// Explicitly clear COMECOME_CONFIG so an ambient value can't mask the default.
[$procDef, $portDef] = $spawn([
    'COMECOME_DB_PATH'    => $dbDef,
    'COMECOME_CONFIG'     => '',  // unset/empty => loader skips it
    'COMECOME_FORCE_HTTPS' => '1',
]);
ok($procDef !== null, "php -S up with NO override config (zero-config, throwaway DB)");
if ($procDef !== null) {
    $hDef = $curlHeaders($portDef, $reqPath, ['X-Forwarded-Proto: https']);
    echo "\n-- raw headers (default, no override) --\n$hDef\n";
    $hstsDef = $hstsLineOf($hDef);
    ok($hstsDef !== '', "HSTS header present over (simulated) TLS with default config");
    ok(preg_match('/max-age=' . $DEFAULT_MAXAGE . '\b/', $hstsDef) === 1,
       "HSTS max-age is the hardcoded DEFAULT ($DEFAULT_MAXAGE) when unconfigured [$hstsDef]");
    ok(strpos($hstsDef, (string) $OVERRIDE_MAXAGE) === false,
       "the override value never appears without the override config (no leakage between servers)");
}

// ----------------------------------------------------------------------------
// (3) A KEY-CONTAINER `*.php` file is never served as readable text.
//     We drop a REAL container inside the web root (a deliberately worst case —
//     the real key lives ABOVE docroot) and GET it directly: the PHP `return`
//     file executes to EMPTY output and the base64 key never appears in the body.
// ----------------------------------------------------------------------------
echo "\n-- (3) a stray in-tree key container never leaks its base64 key over HTTP --\n";

require_once $ROOT . '/includes/secrets.php';
$secretB64 = generateEncryptionKeyBase64();           // the value that must NOT leak
$keyName   = 'cc_smoke_stray_encryption-key.php';     // matches *-encryption-key.php gitignore
$keyPath   = $ROOT . '/' . $keyName;
file_put_contents($keyPath, "<?php\nreturn '" . $secretB64 . "';\n");
$tmpFiles[] = $keyPath; // ensure removal even on early exit

$dbKey = $newTmpDb();
[$procKey, $portKey] = $spawn(['COMECOME_DB_PATH' => $dbKey]);
ok($procKey !== null, "php -S up for the key-leak probe (throwaway DB)");
if ($procKey !== null) {
    $body = $curlBody($portKey, '/' . $keyName);
    // The container `return`s a string and prints nothing; the secret must be absent.
    ok(strpos($body, $secretB64) === false,
       "direct GET of the *.php key container does NOT leak the base64 key");
    ok(strpos($body, "return '") === false && stripos($body, '<?php') === false,
       "the GET does NOT serve the raw PHP source of the key container either");
    echo "    (key-container GET body length: " . strlen($body) . " bytes; secret absent)\n";
}

// Drop the stray key container immediately (don't wait for cleanup) so it can
// never be committed, mirroring the gitignore intent.
if (file_exists($keyPath)) { @unlink($keyPath); }

$cleanupAll();

echo "\n==========================================================\n";
echo " HTTP secrets smoke: $PASS passed, " . count($FAIL) . " failed\n";
echo "==========================================================\n";
if (empty($FAIL)) { echo "HTTP-SECRETS-SMOKE: PASS\n"; exit(0); }
echo "HTTP-SECRETS-SMOKE: FAIL\n";
foreach ($FAIL as $f) { echo "  - $f\n"; }
exit(1);
