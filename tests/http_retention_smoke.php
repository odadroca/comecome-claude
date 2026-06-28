<?php
/**
 * ComeCome — HTTP-level RETENTION PURGE smoke (Launch Sprint 2, S2/A15 — Task 5).
 * ================================================================================
 *
 * WHY THIS EXISTS:
 *   Proves the dashboard-triggered auto-purge end-to-end, including:
 *
 *   A. (Off default) With data_retention_months='0' (default): guardian GET
 *      ?page=dashboard → 200; a direct PDO check shows the OLD daily_checkin row
 *      STILL PRESENT (off = no purge).
 *
 *   B. (Purge on)   Set data_retention_months='12' (via temp DB / setSetting):
 *      guardian GET ?page=dashboard → 200; the OLD row is now GONE, the RECENT
 *      row REMAINS, and exactly one data_deletion_log row with scope='retention_purge'
 *      exists.
 *
 *   C. (Throttle)   Immediate SECOND GET ?page=dashboard same day → NO additional
 *      retention_purge audit row (throttle holds at once/day) — count stays 1.
 *
 * SAFETY:
 *   The spawned `php -S` runs with COMECOME_DB_PATH pointed at a THROWAWAY
 *   temp DB; it never touches the real db/data.db.
 *
 * USAGE:   php tests/http_retention_smoke.php
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
echo " ComeCome HTTP RETENTION PURGE smoke (php -S + curl)\n";
echo "==========================================================\n";

// --- Throwaway DB + seeded users --------------------------------------------
$tmpDb = tempnam(sys_get_temp_dir(), 'comecome_retention_') . '.db';
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

// The seeded default guardian (id=1) has a default PIN — the default-PIN gate
// fires before the dashboard is reachable. Change it to a non-default value so
// refreshGuardianPinDefaultFlag() clears the guardian_pin_is_default flag.
updateUser(1, 'DefaultGuardian', 'guardian', '9999', '🔐', 1);

// Create a guardian with a non-default PIN AND recorded consent.
// Without consent, the consent gate (Plan 1) redirects before the dashboard.
$guardianPin = '7777';
$guardianId  = createUser('SmokeGuardian', 'guardian', $guardianPin, '🧑');
ok($guardianId > 0, "seeded guardian (id=$guardianId, PIN=$guardianPin)");
setSetting('guardian_consent_version', (string) CONSENT_NOTICE_VERSION);
ok(getSetting('guardian_consent_version') === (string) CONSENT_NOTICE_VERSION,
   "consent recorded for SmokeGuardian");

// Create a child.
$childPin  = '1234';
$childName = 'RetentionChild';
$childId   = createUser($childName, 'child', $childPin, '🧒');
ok($childId > 0, "seeded child (id=$childId, name=$childName)");

// Seed two daily_checkin rows:
//   - RECENT: today — must survive the 12-month retention window.
//   - OLD: 3 years ago — must be purged when retention is enabled.
$dbSeed = getDB();
$today    = date('Y-m-d');
$oldDate  = date('Y-m-d', strtotime('-3 years'));

$dbSeed->prepare(
    "INSERT OR REPLACE INTO daily_checkin (user_id, check_date, mood_level, appetite_level) VALUES (?, ?, 3, 4)"
)->execute([$childId, $today]);
$dbSeed->prepare(
    "INSERT OR REPLACE INTO daily_checkin (user_id, check_date, mood_level, appetite_level) VALUES (?, ?, 4, 3)"
)->execute([$childId, $oldDate]);

$recentCount = (int) $dbSeed->query(
    "SELECT COUNT(*) FROM daily_checkin WHERE user_id=$childId AND check_date='$today'"
)->fetchColumn();
$oldCount = (int) $dbSeed->query(
    "SELECT COUNT(*) FROM daily_checkin WHERE user_id=$childId AND check_date='$oldDate'"
)->fetchColumn();
ok($recentCount === 1, "seeded RECENT daily_checkin row for today ($today)");
ok($oldCount === 1,    "seeded OLD daily_checkin row ($oldDate — 3 years ago)");

// Confirm data_retention_months defaults to '0' (off).
$retentionSetting = getSetting('data_retention_months', '0');
ok($retentionSetting === '0', "data_retention_months is '0' (default off) [got '$retentionSetting']");

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

$base       = "http://$host:$port";
$guardianJar = tempnam(sys_get_temp_dir(), 'cc_ret_guardian_');

/**
 * Run curl (no redirect follow by default), return [httpCode, body].
 * Mirrors the pattern in http_safeguarding_smoke.php.
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

$loginUrl = "$base/index.php?page=login";
$dashUrl  = "$base/index.php?page=dashboard";

// ==========================================================================
// LOGIN as guardian
// ==========================================================================
echo "\n--- Login: guardian ---\n";

[$lc, $lbody] = curlReq('curl -c ' . escapeshellarg($guardianJar) . ' ' . escapeshellarg($loginUrl));
ok($lc === 200, "GET login page returns 200 [got $lc]");
$csrfLogin = '';
if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $lbody, $m)) {
    $csrfLogin = $m[1];
}
ok($csrfLogin !== '', "login page exposes csrf-token [got '$csrfLogin']");

global $guardianId, $guardianPin;
$loginCmd = 'curl -c ' . escapeshellarg($guardianJar) . ' -b ' . escapeshellarg($guardianJar)
    . ' --data-urlencode ' . escapeshellarg('csrf_token=' . $csrfLogin)
    . ' --data-urlencode ' . escapeshellarg('user_id=' . $guardianId)
    . ' --data-urlencode ' . escapeshellarg('pin=' . $guardianPin)
    . ' ' . escapeshellarg($loginUrl);
[$glc, $glbody] = curlReq($loginCmd);
ok($glc >= 200 && $glc < 400, "guardian login POST completes [got $glc]");

// ==========================================================================
// GROUP A — Off default: data_retention_months='0' → dashboard 200; old row PRESENT
// ==========================================================================
echo "\n--- A. Off (default): retention disabled — old row still present ---\n";

[$dashC, $dashBody] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L ' . escapeshellarg($dashUrl)
);
ok($dashC === 200,
   "A: guardian GET ?page=dashboard returns 200 with retention OFF [got $dashC]");

// Direct PDO check: old row must STILL be present (no purge happened).
$dbCheckA = new PDO('sqlite:' . $tmpDb);
$dbCheckA->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$oldStillPresent = (int) $dbCheckA->query(
    "SELECT COUNT(*) FROM daily_checkin WHERE user_id=$childId AND check_date='$oldDate'"
)->fetchColumn();
ok($oldStillPresent === 1,
   "A: OLD row (check_date=$oldDate) STILL PRESENT after dashboard load with retention OFF [got $oldStillPresent]");

// Confirm no audit row was created.
$auditCountA = (int) $dbCheckA->query(
    "SELECT COUNT(*) FROM data_deletion_log WHERE scope='retention_purge'"
)->fetchColumn();
ok($auditCountA === 0,
   "A: 0 retention_purge audit rows in data_deletion_log when OFF [got $auditCountA]");
$dbCheckA = null;

// ==========================================================================
// GROUP B — Purge on: set data_retention_months='12'; dashboard → old row GONE,
//            recent row REMAINS, exactly 1 audit row with scope='retention_purge'
// ==========================================================================
echo "\n--- B. Retention ON (12 months): dashboard purges old row, recent survives ---\n";

// Enable retention by writing directly to the throwaway DB.
// The server reads COMECOME_DB_PATH=$tmpDb on every request, so this is
// immediately visible to the next dashboard request.
$dbEnable = new PDO('sqlite:' . $tmpDb);
$dbEnable->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbEnable->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('data_retention_months', '12')");
$enabledVal = $dbEnable->query(
    "SELECT value FROM settings WHERE \"key\"='data_retention_months'"
)->fetchColumn();
ok($enabledVal === '12',
   "B: set data_retention_months='12' in throwaway DB [got '$enabledVal']");
$dbEnable = null;

// Guardian GET ?page=dashboard — triggers maybeRunRetentionPurge() server-side.
[$dashC2, $dashBody2] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L ' . escapeshellarg($dashUrl)
);
ok($dashC2 === 200,
   "B: guardian GET ?page=dashboard returns 200 with retention ON [got $dashC2]");

// Direct PDO check: OLD row must be GONE, RECENT row must REMAIN.
$dbCheckB = new PDO('sqlite:' . $tmpDb);
$dbCheckB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$oldGone = (int) $dbCheckB->query(
    "SELECT COUNT(*) FROM daily_checkin WHERE user_id=$childId AND check_date='$oldDate'"
)->fetchColumn();
ok($oldGone === 0,
   "B: OLD row (check_date=$oldDate) GONE after dashboard load with retention=12 months [got $oldGone]");

$recentRemains = (int) $dbCheckB->query(
    "SELECT COUNT(*) FROM daily_checkin WHERE user_id=$childId AND check_date='$today'"
)->fetchColumn();
ok($recentRemains === 1,
   "B: RECENT row (check_date=$today) REMAINS after purge [got $recentRemains]");

// Exactly 1 retention_purge audit row in data_deletion_log.
$auditCountB = (int) $dbCheckB->query(
    "SELECT COUNT(*) FROM data_deletion_log WHERE scope='retention_purge'"
)->fetchColumn();
ok($auditCountB === 1,
   "B: exactly 1 retention_purge audit row after first dashboard hit [got $auditCountB]");

// Confirm the audit row is PII-free (no child name in record_counts).
$auditRowB = $dbCheckB->query(
    "SELECT record_counts FROM data_deletion_log WHERE scope='retention_purge' LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if ($auditRowB) {
    $nameInCounts = (stripos($auditRowB['record_counts'], $childName) !== false);
    ok(!$nameInCounts,
       "B: retention_purge audit record_counts does not contain child name (PII-free) [got: " . $auditRowB['record_counts'] . "]");
}
$dbCheckB = null;

// ==========================================================================
// GROUP C — Throttle: second GET same day → NO additional audit row (count stays 1)
// ==========================================================================
echo "\n--- C. Throttle: second dashboard load same day → no additional purge audit ---\n";

[$dashC3, $dashBody3] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L ' . escapeshellarg($dashUrl)
);
ok($dashC3 === 200,
   "C: immediate second GET ?page=dashboard returns 200 [got $dashC3]");

// The throttle (once/day via retention_last_purge_at) must prevent a second purge.
$dbCheckC = new PDO('sqlite:' . $tmpDb);
$dbCheckC->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$auditCountC = (int) $dbCheckC->query(
    "SELECT COUNT(*) FROM data_deletion_log WHERE scope='retention_purge'"
)->fetchColumn();
ok($auditCountC === 1,
   "C: audit row count still 1 after second dashboard hit (throttle held) [got $auditCountC]");

// Confirm retention_last_purge_at is set to today.
$lastPurge = $dbCheckC->query(
    "SELECT value FROM settings WHERE \"key\"='retention_last_purge_at'"
)->fetchColumn();
ok(is_string($lastPurge) && substr($lastPurge, 0, 10) === $today,
   "C: retention_last_purge_at is set to today ($today) [got '$lastPurge']");
$dbCheckC = null;

// --- Cleanup ----------------------------------------------------------------
$cleanup();
@unlink($guardianJar);

echo "\n==========================================================\n";
echo " HTTP RETENTION PURGE smoke: $PASS passed, " . count($FAIL) . " failed\n";
echo "==========================================================\n";
if (empty($FAIL)) { echo "HTTP-RETENTION-SMOKE: PASS\n"; exit(0); }
echo "HTTP-RETENTION-SMOKE: FAIL\n";
foreach ($FAIL as $f) { echo "  - $f\n"; }
exit(1);
