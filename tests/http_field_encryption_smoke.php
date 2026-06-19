<?php
/**
 * ComeCome — HTTP-level smoke test (Sprint security Phase 5: at-rest field encryption).
 * =====================================================================================
 *
 * WHY THIS EXISTS:
 *   tests/run.php PHASE L drives includes/crypto.php + includes/db.php accessors
 *   IN-PROCESS with an explicit key. What it CANNOT prove in-process is that the
 *   real, end-to-end HTTP boot path — config.php resolving the above-docroot key
 *   container, session_start(), initializeDatabase() seeding the default guardian
 *   through encryptField() — actually writes CIPHERTEXT to disk for the four scoped
 *   columns when a key is configured, and PLAINTEXT (opt-in OFF, zero-config) when
 *   no key is configured. This script boots the built-in `php -S` server against a
 *   THROWAWAY DB and a THROWAWAY key file and inspects BOTH the response headers and
 *   the on-disk SQLite bytes.
 *
 *   It asserts, through the live HTTP server:
 *     1. With a key configured (COMECOME_KEY_FILE -> a valid base64 32-byte key),
 *        GET / returns HTTP 200 with NO PHP fatal/warning, the session cookie still
 *        carries HttpOnly + SameSite=Lax (Phase 0/5 don't regress the cookie), and
 *        the seeded users.name on disk is stored as `enc:v1:` ciphertext (at-rest
 *        protection genuinely engaged via the real boot path — raw SQL peek).
 *     2. With NO key configured, the same boot stores the seeded name as PLAINTEXT
 *        'Guardião' (opt-in OFF / zero-config unchanged), proving encryption is
 *        strictly opt-in and a fresh download is unaffected.
 *     3. gender / date_of_birth columns (when present) stay cleartext by design.
 *
 * SAFETY:
 *   The spawned server runs with COMECOME_DB_PATH pointed at a throwaway temp DB and
 *   COMECOME_KEY_FILE pointed at a throwaway temp key file, so it never reads or
 *   writes the real db/data.db and never consults a real secret.
 *
 * USAGE:   php tests/http_field_encryption_smoke.php
 * EXIT:    0 = all assertions passed, non-zero = a failure.
 *
 * NOTE: a SEPARATE entry point (like the other tests/http_*_smoke.php) because it
 * spawns a process + binds a port, which the in-process unit harness avoids.
 * tests/run.php PHASE B2 orchestrates it as an isolated child process.
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
echo " ComeCome HTTP smoke — at-rest field encryption (Phase 5)\n";
echo "==========================================================\n";

/**
 * Pick a FREE ephemeral port (same approach as tests/http_smoke.php): bind :0, read
 * the OS-assigned port back, close it. Avoids collisions with orphaned `php -S` or a
 * parallel regression run.
 */
function pickFreePort($host) {
    $sock = @stream_socket_server("tcp://$host:0", $errno, $errstr);
    if (!$sock) { return 0; }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    $pos = strrpos($name, ':');
    return ($pos === false) ? 0 : (int) substr($name, $pos + 1);
}

/**
 * Boot `php -S` against a fresh throwaway DB (and optional key file), drive ONE
 * request to force the DB to be created/seeded, then return [rawHeaders, dbPath,
 * cleanup]. The caller peeks the on-disk DB and runs assertions, then calls cleanup.
 *
 * @param string      $ROOT     repo root
 * @param string|null $keyFile  path to a key container file, or null for no key
 * @return array{0:string,1:string,2:callable,3:bool} headers, dbPath, cleanup, up
 */
function bootAndHit($ROOT, $keyFile) {
    $host = '127.0.0.1';
    $tmpDb = tempnam(sys_get_temp_dir(), 'comecome_p5http_') . '.db';
    @unlink($tmpDb); // let the app create it fresh

    $port = pickFreePort($host);
    if ($port <= 0) {
        return ['', $tmpDb, function () use ($tmpDb) { @unlink($tmpDb); }, false];
    }

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = $_ENV;
    $env['COMECOME_DB_PATH'] = $tmpDb;
    if ($keyFile !== null) {
        $env['COMECOME_KEY_FILE'] = $keyFile;
    } else {
        unset($env['COMECOME_KEY_FILE']);
    }

    $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d log_errors=1'
         . ' -S ' . $host . ':' . $port . ' -t ' . escapeshellarg($ROOT);
    $proc = proc_open($cmd, $descriptors, $pipes, $ROOT, $env);
    if (!is_resource($proc)) {
        return ['', $tmpDb, function () use ($tmpDb) { @unlink($tmpDb); }, false];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $cleanup = function () use ($proc, $pipes, $tmpDb) {
        foreach ($pipes as $p) { if (is_resource($p)) { fclose($p); } }
        proc_terminate($proc);
        proc_close($proc);
        // Windows lock-tolerant best-effort removal of the throwaway DB.
        for ($i = 0; $i < 10 && file_exists($tmpDb); $i++) { if (@unlink($tmpDb)) break; usleep(30000); }
    };

    // Wait for the server to accept connections.
    $up = false;
    for ($i = 0; $i < 50; $i++) {
        $fp = @fsockopen($host, $port, $errno, $errstr, 0.2);
        if ($fp) { fclose($fp); $up = true; break; }
        usleep(100000);
    }
    if (!$up) {
        return ['', $tmpDb, $cleanup, false];
    }

    // Drive GET / so the app boots config.php (resolves the key), starts the session,
    // and runs initializeDatabase() which seeds the default guardian through
    // encryptField(). -D - dumps headers; body is discarded (portable null sink).
    $url = "http://$host:$port/";
    $nullSink = (DIRECTORY_SEPARATOR === '\\') ? 'NUL' : '/dev/null';
    $headers = shell_exec('curl -s -D - -o ' . $nullSink . ' ' . escapeshellarg($url));

    // Give SQLite a moment to flush the seed write to disk before we peek it.
    for ($i = 0; $i < 20 && !file_exists($tmpDb); $i++) { usleep(30000); }

    return [$headers === null ? '' : $headers, $tmpDb, $cleanup, true];
}

/** Open a SQLite DB read-only and return the seeded guardian's stored name (id=1). */
function peekSeededName($dbPath) {
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $row = $pdo->query("SELECT name FROM users WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        $pdo = null;
        return $row ? $row['name'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** gender/date_of_birth peek for id=1 (may be NULL — that's fine, we only assert no ciphertext). */
function peekGenderDob($dbPath) {
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $row = $pdo->query("SELECT gender, date_of_birth FROM users WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        $pdo = null;
        return $row ?: ['gender' => null, 'date_of_birth' => null];
    } catch (Throwable $e) {
        return ['gender' => null, 'date_of_birth' => null];
    }
}

function headerHasStatus200($headers) {
    foreach (preg_split('/\r?\n/', (string) $headers) as $line) {
        if (preg_match('#^HTTP/\d(?:\.\d)?\s+200\b#', $line)) { return true; }
    }
    return false;
}
function findSessionCookie($headers) {
    foreach (preg_split('/\r?\n/', (string) $headers) as $line) {
        if (stripos($line, 'Set-Cookie:') === 0 && stripos($line, 'PHPSESSID') !== false) {
            return $line;
        }
    }
    return '';
}

$ENC_PREFIX = 'enc:v1:';
$GUARDIAO   = 'Guardi' . "\xC3\xA3" . 'o'; // UTF-8 'Guardião'

// ---------------------------------------------------------------------------
// CASE 1 — key CONFIGURED: the seeded name must be stored as ciphertext on disk.
// ---------------------------------------------------------------------------
echo "\n-- CASE 1: key configured => seeded users.name is CIPHERTEXT on disk --\n";

// Throwaway key container: a PHP file returning a valid base64 32-byte key.
require_once $ROOT . '/includes/secrets.php';
$keyDir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cc_p5http_key_' . getmypid();
if (!is_dir($keyDir)) { @mkdir($keyDir, 0700, true); }
$keyFile = $keyDir . DIRECTORY_SEPARATOR . 'encryption-key.php';
file_put_contents($keyFile, "<?php return '" . generateEncryptionKeyBase64() . "';\n");

list($h1, $db1, $cleanup1, $up1) = bootAndHit($ROOT, $keyFile);
ok($up1, "CASE1 php -S came up (key configured, throwaway DB)");
if ($up1) {
    echo "\n-- CASE1 raw response headers --\n" . $h1 . "\n";
    ok(headerHasStatus200($h1), "CASE1 GET / => HTTP 200 with encryption ON");
    $sc1 = findSessionCookie($h1);
    ok($sc1 !== '' && stripos($sc1, 'HttpOnly') !== false,
       "CASE1 session cookie still carries HttpOnly (no Phase-5 cookie regression)");
    ok($sc1 !== '' && preg_match('/SameSite=Lax/i', $sc1) === 1,
       "CASE1 session cookie still carries SameSite=Lax");

    $name1 = peekSeededName($db1);
    ok($name1 !== null, "CASE1 seeded guardian row (id=1) exists on disk");
    ok(is_string($name1) && strncmp($name1, $ENC_PREFIX, strlen($ENC_PREFIX)) === 0,
       "CASE1 on-disk users.name carries the enc:v1: sentinel (CIPHERTEXT) [got "
       . var_export(is_string($name1) ? substr($name1, 0, 16) . '...' : $name1, true) . "]");
    ok(is_string($name1) && strpos($name1, $GUARDIAO) === false,
       "CASE1 on-disk users.name does NOT contain the plaintext 'Guardião'");

    // gender/DOB excluded from encryption by design — must never be ciphertext.
    $gd1 = peekGenderDob($db1);
    ok(($gd1['gender'] === null || strncmp((string) $gd1['gender'], $ENC_PREFIX, strlen($ENC_PREFIX)) !== 0)
       && ($gd1['date_of_birth'] === null || strncmp((string) $gd1['date_of_birth'], $ENC_PREFIX, strlen($ENC_PREFIX)) !== 0),
       "CASE1 gender + date_of_birth are NOT ciphertext (excluded by design — percentile engine)");
}
$cleanup1();

// ---------------------------------------------------------------------------
// CASE 2 — NO key: the seeded name must be PLAINTEXT on disk (opt-in OFF).
// ---------------------------------------------------------------------------
echo "\n-- CASE 2: no key => seeded users.name is PLAINTEXT on disk (zero-config) --\n";
list($h2, $db2, $cleanup2, $up2) = bootAndHit($ROOT, null);
ok($up2, "CASE2 php -S came up (no key, throwaway DB)");
if ($up2) {
    ok(headerHasStatus200($h2), "CASE2 GET / => HTTP 200 with encryption OFF (opt-in)");
    $name2 = peekSeededName($db2);
    ok($name2 === $GUARDIAO,
       "CASE2 on-disk users.name is PLAINTEXT 'Guardião' (zero-config / opt-in OFF) [got "
       . var_export($name2, true) . "]");
    ok(is_string($name2) && strncmp($name2, $ENC_PREFIX, strlen($ENC_PREFIX)) !== 0,
       "CASE2 on-disk users.name does NOT carry the enc:v1: sentinel");
}
$cleanup2();

// Cleanup the throwaway key dir.
foreach (glob($keyDir . DIRECTORY_SEPARATOR . '*') as $f) { @unlink($f); }
@rmdir($keyDir);

echo "\n==========================================================\n";
echo " HTTP field-encryption smoke: $PASS passed, " . count($FAIL) . " failed\n";
echo "==========================================================\n";
if (empty($FAIL)) { echo "HTTP-FIELDENC-SMOKE: PASS\n"; exit(0); }
echo "HTTP-FIELDENC-SMOKE: FAIL\n";
foreach ($FAIL as $f) { echo "  - $f\n"; }
exit(1);
