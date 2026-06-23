<?php
/**
 * ComeCome — HTTP-level CONSENT GATE smoke (Launch Sprint 2, Tasks 3+4).
 * ========================================================================
 *
 * WHY THIS EXISTS:
 *   Validates the guardian consent gate wired in index.php:
 *
 *   A. A guardian who has never acknowledged the consent notice is redirected
 *      to ?page=consent when attempting any protected page (e.g. dashboard).
 *   B. The consent page itself loads and renders the consent_agree label.
 *   C. A consent POST WITHOUT a valid CSRF token does NOT clear the gate.
 *   D. A consent POST WITH a valid CSRF token clears the gate; the guardian
 *      can then reach the dashboard.
 *   E. A child who has no consent recorded (fresh DB) sees a neutral
 *      "isn't set up yet" page — NOT the consent form.
 *
 * SAFETY:
 *   The spawned `php -S` runs with COMECOME_DB_PATH pointed at a THROWAWAY
 *   temp DB; it never touches the real db/data.db.
 *
 * USAGE:   php tests/http_consent_smoke.php
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
echo " ComeCome HTTP CONSENT GATE smoke (php -S + curl)\n";
echo "==========================================================\n";

// --- Throwaway DB + seeded users --------------------------------------------
$tmpDb = tempnam(sys_get_temp_dir(), 'comecome_consent_') . '.db';
@unlink($tmpDb);

// Seed a fresh app DB with one guardian (PIN 5678, non-default) and one child.
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

// Change the seeded default guardian's PIN to a non-default value so
// refreshGuardianPinDefaultFlag() clears the guardian_pin_is_default flag.
// Without this, the default-PIN gate fires before the consent gate, which
// would make the consent test assertions impossible to reach.
updateUser(1, 'DefaultGuardian', 'guardian', '9999', '🔐', 1);

$guardianPin = '5678';
$guardianId = createUser('SmokeGuardian', 'guardian', $guardianPin, '🧑');
ok($guardianId > 0, "seeded a guardian user (id=$guardianId, PIN=$guardianPin) — no consent recorded");

$childPin = '1234';
$childId = createUser('SmokeChild', 'child', $childPin, '🧒');
ok($childId > 0, "seeded a child user (id=$childId, PIN=$childPin)");

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
$guardianJar = tempnam(sys_get_temp_dir(), 'cc_consent_guardian_');
$childJar    = tempnam(sys_get_temp_dir(), 'cc_consent_child_');

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

/**
 * Run curl with -D - (dump headers to stdout), return [httpCode, headers, body].
 * Do NOT follow redirects so we can inspect Location headers.
 */
function curlReqWithHeaders($args) {
    $out = shell_exec($args . ' -s -D - -w "\n__HTTP__%{http_code}"');
    if ($out === null) { return [0, '', '']; }
    $pos = strrpos($out, "\n__HTTP__");
    $raw  = ($pos !== false) ? substr($out, 0, $pos) : $out;
    $code = ($pos !== false) ? (int) substr($out, $pos + strlen("\n__HTTP__")) : 0;
    // Split headers from body on the blank line
    $parts   = preg_split('/\r?\n\r?\n/', $raw, 2);
    $headers = $parts[0] ?? '';
    $body    = $parts[1] ?? '';
    return [$code, $headers, $body];
}

// ==========================================================================
// GROUP A — Guardian blocked by consent gate
// ==========================================================================
echo "\n--- A. Guardian blocked by consent gate ---\n";

// First: GET the login page to get a CSRF token + session cookie
$loginUrl = "$base/index.php?page=login";
[$lc, $loginHtml] = curlReq('curl -c ' . escapeshellarg($guardianJar) . ' ' . escapeshellarg($loginUrl));
ok($lc === 200, "A: GET login page returns 200 [got $lc]");

$csrf = '';
if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $loginHtml, $m)) { $csrf = $m[1]; }
ok($csrf !== '', "A: login page exposes a CSRF token via <meta name=\"csrf-token\">");

// Log in as the guardian (non-default PIN, default-PIN gate must not fire)
$loginPost = 'curl -c ' . escapeshellarg($guardianJar) . ' -b ' . escapeshellarg($guardianJar)
    . ' --data-urlencode ' . escapeshellarg('csrf_token=' . $csrf)
    . ' --data-urlencode ' . escapeshellarg('user_id=' . $guardianId)
    . ' --data-urlencode ' . escapeshellarg('pin=' . $guardianPin)
    . ' ' . escapeshellarg($loginUrl);
[$pc, $pbody] = curlReq($loginPost);
// Login redirects to the home/dashboard route; we just need the POST to succeed (3xx)
ok($pc >= 200 && $pc < 400, "A: guardian login POST completes [got $pc]");

// GET dashboard — expect 302 with Location containing page=consent
$dashUrl = "$base/index.php?page=dashboard";
[$dc, $dheaders, $dbody] = curlReqWithHeaders(
    'curl -b ' . escapeshellarg($guardianJar) . ' ' . escapeshellarg($dashUrl)
);
ok($dc === 302, "A: dashboard GET returns 302 (gate fires) [got $dc]");
ok(strpos($dheaders, 'page=consent') !== false,
   "A: Location header contains page=consent [headers snippet: " . substr(preg_replace('/\r?\n/', ' ', $dheaders), 0, 200) . "]");

// ==========================================================================
// GROUP B — Consent page is accessible (renders the form)
// ==========================================================================
echo "\n--- B. Consent page accessible ---\n";

$consentUrl = "$base/index.php?page=consent";
[$cc, $cbody] = curlReq('curl -b ' . escapeshellarg($guardianJar) . ' ' . escapeshellarg($consentUrl));
ok($cc === 200, "B: GET consent page returns 200 [got $cc]");

// The consent_agree label must be present (either locale)
$hasAgree = (strpos($cbody, 'I understand and consent') !== false)
         || (strpos($cbody, 'Compreendo e consinto') !== false);
ok($hasAgree, "B: consent page body contains the consent_agree label");

// ==========================================================================
// GROUP C — CSRF guard on consent POST
// ==========================================================================
echo "\n--- C. CSRF guard on consent POST ---\n";

// POST to consent WITHOUT a CSRF token — must be rejected
$noTokenPost = 'curl -c ' . escapeshellarg($guardianJar) . ' -b ' . escapeshellarg($guardianJar)
    . ' -X POST'
    . ' ' . escapeshellarg($consentUrl);
[$npc, $npheaders, $npbody] = curlReqWithHeaders($noTokenPost);
// verifyCsrf() returns false -> consent.php does: header('Location: ...?msg=csrf_error'); exit;
// So the no-token POST must itself be a redirect (3xx) back to the consent page with an error param.
ok(($npc >= 300 && $npc < 400) || strpos($npbody, 'csrf') !== false || strpos($npbody, 'error') !== false,
   "C: consent POST without CSRF token is rejected (3xx redirect or error in body) [got $npc]");

// After a bad CSRF POST, the gate must still be active (consent was NOT recorded)
[$dc2, $dh2, $db2] = curlReqWithHeaders(
    'curl -b ' . escapeshellarg($guardianJar) . ' ' . escapeshellarg($dashUrl)
);
ok($dc2 === 302 && strpos($dh2, 'page=consent') !== false,
   "C: after consent POST without CSRF, dashboard still 302->consent [got $dc2]");

// ==========================================================================
// GROUP E — Child sees neutral blocked page (not the consent form)
// Run BEFORE group D so the gate is still active (consent not yet recorded).
// ==========================================================================
echo "\n--- E. Child sees neutral blocked page (consent not yet recorded) ---\n";

// Log in as the child using a separate cookie jar
[$lc2, $loginHtml2] = curlReq('curl -c ' . escapeshellarg($childJar) . ' ' . escapeshellarg($loginUrl));
$csrf2 = '';
if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $loginHtml2, $m2)) { $csrf2 = $m2[1]; }

$childLogin = 'curl -c ' . escapeshellarg($childJar) . ' -b ' . escapeshellarg($childJar)
    . ' --data-urlencode ' . escapeshellarg('csrf_token=' . $csrf2)
    . ' --data-urlencode ' . escapeshellarg('user_id=' . $childId)
    . ' --data-urlencode ' . escapeshellarg('pin=' . $childPin)
    . ' ' . escapeshellarg($loginUrl);
[$clc, $clbody] = curlReq($childLogin);
ok($clc >= 200 && $clc < 400, "E: child login POST completes [got $clc]");

// GET a child page — should be blocked with a neutral message (consent gate fires for child)
$checkinUrl = "$base/index.php?page=check-in";
[$chc, $chbody] = curlReq('curl -b ' . escapeshellarg($childJar) . ' -L ' . escapeshellarg($checkinUrl));
ok($chc === 200, "E: child GET check-in returns 200 (neutral block page rendered) [got $chc]");

// Body must contain "isn't set up yet" (or the PT equivalent)
$hasBlocked = (stripos($chbody, "isn't set up yet") !== false)
           || (stripos($chbody, 'ainda não está configurado') !== false)
           || (stripos($chbody, 'Almost ready') !== false)
           || (stripos($chbody, 'Quase pronto') !== false);
ok($hasBlocked, "E: child block page contains the 'not set up yet' message");

// Body must NOT contain the consent_agree label
$hasAgreeInChild = (strpos($chbody, 'I understand and consent') !== false)
                || (strpos($chbody, 'Compreendo e consinto') !== false);
ok(!$hasAgreeInChild, "E: child block page does NOT contain the consent_agree label");

// ==========================================================================
// GROUP F — manage-users bypass is CLOSED (non-default PIN + no consent)
// ==========================================================================
echo "\n--- F. manage-users bypass is closed ---\n";

// The guardian already has a non-default PIN and no consent recorded yet.
// GETting manage-users must 302 redirect to page=consent, not render the page.
$manageUsersUrl = "$base/index.php?page=manage-users";
[$muc, $muheaders, $mubody] = curlReqWithHeaders(
    'curl -b ' . escapeshellarg($guardianJar) . ' ' . escapeshellarg($manageUsersUrl)
);
ok($muc === 302, "F: manage-users GET returns 302 (bypass blocked) [got $muc]");
ok(strpos($muheaders, 'page=consent') !== false,
   "F: manage-users Location header contains page=consent [headers: " . substr(preg_replace('/\r?\n/', ' ', $muheaders), 0, 200) . "]");

// ==========================================================================
// GROUP D — Valid consent POST clears the gate
// Run AFTER group E so the gate is still active when child is tested.
// ==========================================================================
echo "\n--- D. Valid consent POST clears gate ---\n";

// Scrape the CSRF token from the consent page body captured in group B
$consentCsrf = '';
if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $cbody, $cm)) {
    $consentCsrf = $cm[1];
}
if ($consentCsrf === '' && preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $cbody, $cm2)) {
    $consentCsrf = $cm2[1];
}
ok($consentCsrf !== '', "D: scraped CSRF token from the consent page [got '$consentCsrf']");

// POST with the CSRF token
$validPost = 'curl -c ' . escapeshellarg($guardianJar) . ' -b ' . escapeshellarg($guardianJar)
    . ' --data-urlencode ' . escapeshellarg('csrf_token=' . $consentCsrf)
    . ' ' . escapeshellarg($consentUrl);
[$vpc, $vpbody] = curlReq($validPost);
// Consent page POSTs redirect to index.php (3xx)
ok($vpc >= 300 && $vpc < 400, "D: valid consent POST redirects (3xx) [got $vpc]");

// Now dashboard should load (200)
[$dc3, $dh3, $db3] = curlReqWithHeaders(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L ' . escapeshellarg($dashUrl)
);
ok($dc3 === 200, "D: after valid consent, dashboard GET returns 200 (gate cleared) [got $dc3]");

// --- Cleanup ----------------------------------------------------------------
$cleanup();
@unlink($guardianJar);
@unlink($childJar);

echo "\n==========================================================\n";
echo " HTTP CONSENT GATE smoke: $PASS passed, " . count($FAIL) . " failed\n";
echo "==========================================================\n";
if (empty($FAIL)) { echo "HTTP-CONSENT-SMOKE: PASS\n"; exit(0); }
echo "HTTP-CONSENT-SMOKE: FAIL\n";
foreach ($FAIL as $f) { echo "  - $f\n"; }
exit(1);
