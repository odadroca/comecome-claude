<?php
/**
 * ComeCome — HTTP-level ERASURE smoke (Launch Sprint 2, S2/A15).
 * ============================================================================
 *
 * WHY THIS EXISTS:
 *   Validates the danger-zone whole-child erasure wired in manage-users.php:
 *
 *   A. (Render)   Guardian GET ?page=manage-users → 200; body contains the
 *                 danger_zone section + erase_child_button text.
 *   B. (Mismatch) POST action=erase_child with right child_id but WRONG
 *                 confirm_name (correct word) → redirect …msg=erase_mismatch;
 *                 child still exists (follow-up GET still lists them).
 *   C. (Mismatch) POST with right child_id, right name, but WRONG/empty
 *                 confirm_word → mismatch; child still exists.
 *   D. (Success)  POST with right child_id + right name + right word + valid
 *                 CSRF → redirect …msg=erased; follow-up GET no longer lists
 *                 the child; DB check shows 0 daily_checkin/food_log rows for
 *                 that id; exactly one data_deletion_log row with scope='child',
 *                 target_user_id = childId, and no name substring in
 *                 record_counts.
 *   E. (CSRF)     POST action=erase_child WITHOUT a CSRF token → rejected
 *                 (redirect to …msg=csrf_error or back to page); child still
 *                 exists.
 *
 * SAFETY:
 *   The spawned `php -S` runs with COMECOME_DB_PATH pointed at a THROWAWAY
 *   temp DB; it never touches the real db/data.db.
 *
 * USAGE:   php tests/http_erasure_smoke.php
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
echo " ComeCome HTTP ERASURE smoke (php -S + curl)\n";
echo "==========================================================\n";

// --- Throwaway DB + seeded users --------------------------------------------
$tmpDb = tempnam(sys_get_temp_dir(), 'comecome_erasure_') . '.db';
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
// fires before manage-users is fully reachable. Change it to a non-default value
// so refreshGuardianPinDefaultFlag() clears the guardian_pin_is_default flag.
updateUser(1, 'DefaultGuardian', 'guardian', '9999', '🔐', 1);

// Create a guardian with a non-default PIN AND recorded consent.
// Without consent, the consent gate (Plan 1) redirects before manage-users.
$guardianPin = '7777';
$guardianId  = createUser('SmokeGuardian', 'guardian', $guardianPin, '🧑');
ok($guardianId > 0, "seeded guardian (id=$guardianId, PIN=$guardianPin)");
setSetting('guardian_consent_version', (string) CONSENT_NOTICE_VERSION);
ok(getSetting('guardian_consent_version') === (string) CONSENT_NOTICE_VERSION,
   "consent recorded for SmokeGuardian");

// Create a child with some daily_checkin and food_log rows.
$childPin  = '1234';
$childName = 'EraseSmoke';
$childId   = createUser($childName, 'child', $childPin, '🧒');
ok($childId > 0, "seeded child (id=$childId, name=$childName)");

// Seed daily_checkin and food_log rows so the cascade has real data to wipe.
$dbSeed = getDB();
$today = date('Y-m-d');
$dbSeed->prepare(
    "INSERT OR REPLACE INTO daily_checkin (user_id, check_date, mood_level, appetite_level) VALUES (?, ?, 3, 4)"
)->execute([$childId, $today]);
$dbSeed->prepare(
    "INSERT OR REPLACE INTO daily_checkin (user_id, check_date, mood_level, appetite_level) VALUES (?, ?, 4, 3)"
)->execute([$childId, date('Y-m-d', strtotime('-1 day'))]);

// food_log requires food_id and meal_id — use id=1 which seed.sql always plants.
// portion must be one of: 'little','some','lot','all' (CHECK constraint).
$dbSeed->prepare(
    "INSERT OR IGNORE INTO food_log (user_id, food_id, meal_id, portion, log_date, log_time) VALUES (?, 1, 1, 'some', ?, '08:00:00')"
)->execute([$childId, $today]);

$checkinCount = (int) $dbSeed->prepare(
    "SELECT COUNT(*) FROM daily_checkin WHERE user_id=?"
)->execute([$childId]) ? (int) $dbSeed->query("SELECT COUNT(*) FROM daily_checkin WHERE user_id=$childId")->fetchColumn() : 0;
$foodCount = (int) $dbSeed->query("SELECT COUNT(*) FROM food_log WHERE user_id=$childId")->fetchColumn();
ok($checkinCount >= 1, "seeded at least 1 daily_checkin row for child (got $checkinCount)");
ok($foodCount >= 1,    "seeded at least 1 food_log row for child (got $foodCount)");

// Determine the confirm word (t('erase_child_word')) for the pt locale.
// The running server uses DEFAULT_LOCALE='pt', so we read from the locale file.
$ptLocale = json_decode(file_get_contents($ROOT . '/locales/pt.json'), true);
$eraseWord = $ptLocale['erase_child_word'] ?? 'ELIMINAR';
ok($eraseWord !== '', "erase confirm word resolved from pt locale: '$eraseWord'");

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

$base = "http://$host:$port";
$guardianJar = tempnam(sys_get_temp_dir(), 'cc_er_guardian_');

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

$loginUrl    = "$base/index.php?page=login";
$manageUrl   = "$base/index.php?page=manage-users";

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

$loginCmd = 'curl -c ' . escapeshellarg($guardianJar) . ' -b ' . escapeshellarg($guardianJar)
    . ' --data-urlencode ' . escapeshellarg('csrf_token=' . $csrfLogin)
    . ' --data-urlencode ' . escapeshellarg('user_id=' . $guardianId)
    . ' --data-urlencode ' . escapeshellarg('pin=' . $guardianPin)
    . ' ' . escapeshellarg($loginUrl);
[$glc, $glbody] = curlReq($loginCmd);
ok($glc >= 200 && $glc < 400, "guardian login POST completes [got $glc]");

// ==========================================================================
// GROUP A — Render: manage-users contains danger_zone + erase_child_button
// ==========================================================================
echo "\n--- A. Render: danger_zone + erase_child_button present ---\n";

[$muc, $mubody] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L ' . escapeshellarg($manageUrl)
);
ok($muc === 200, "A: guardian GET ?page=manage-users returns 200 [got $muc]");

// danger_zone appears as text in either locale.
$hasDangerZone = (stripos($mubody, 'danger') !== false && stripos($mubody, 'zone') !== false)
              || (stripos($mubody, 'danger-zone') !== false)
              || (stripos($mubody, 'Zona de perigo') !== false)
              || (stripos($mubody, 'Danger zone') !== false);
ok($hasDangerZone, "A: page body contains danger_zone text/section");

// erase_child_button text in either locale.
$hasEraseBtn = (stripos($mubody, 'Permanently delete') !== false)
            || (stripos($mubody, 'Eliminar permanentemente') !== false);
ok($hasEraseBtn, "A: page body contains erase_child_button text");

// Scrape CSRF token for subsequent POSTs.
$pageCsrf = '';
if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $mubody, $mc)) {
    $pageCsrf = $mc[1];
}
ok($pageCsrf !== '', "A: manage-users page exposes a CSRF token [got '$pageCsrf']");

// ==========================================================================
// GROUP B — Mismatch: right child_id, WRONG confirm_name (correct word)
// ==========================================================================
echo "\n--- B. Mismatch: wrong confirm_name → erase_mismatch; child still exists ---\n";

global $eraseWord, $childId, $childName;
[$bc, $bh, $bbody] = curlReqWithHeaders(
    'curl -c ' . escapeshellarg($guardianJar) . ' -b ' . escapeshellarg($guardianJar)
    . ' --data-urlencode ' . escapeshellarg('csrf_token=' . $pageCsrf)
    . ' --data-urlencode ' . escapeshellarg('action=erase_child')
    . ' --data-urlencode ' . escapeshellarg('child_id=' . $childId)
    . ' --data-urlencode ' . escapeshellarg('confirm_name=WRONG_NAME')
    . ' --data-urlencode ' . escapeshellarg('confirm_word=' . $eraseWord)
    . ' ' . escapeshellarg($manageUrl)
);
ok($bc >= 300 && $bc < 400, "B: POST with wrong confirm_name returns 3xx [got $bc]");
ok(stripos($bh, 'erase_mismatch') !== false,
   "B: redirect Location contains erase_mismatch [headers excerpt: " . substr(preg_replace('/\r?\n/',' | ',$bh),0,200) . "]");

// Refresh CSRF token via a new GET (the POST has consumed/invalidated the old one).
[$muc2, $mubody2] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L ' . escapeshellarg($manageUrl)
);
ok($muc2 === 200, "B: follow-up GET after mismatch is 200");
$hasChildAfterB = (stripos($mubody2, $childName) !== false);
ok($hasChildAfterB, "B: child '$childName' still listed after wrong-name mismatch POST");

// Re-scrape CSRF for group C.
$pageCsrf2 = '';
if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $mubody2, $mc2)) {
    $pageCsrf2 = $mc2[1];
}
ok($pageCsrf2 !== '', "B: re-scraped CSRF token for next group [got '$pageCsrf2']");

// ==========================================================================
// GROUP C — Mismatch: right name, WRONG/empty confirm_word
// ==========================================================================
echo "\n--- C. Mismatch: wrong confirm_word → erase_mismatch; child still exists ---\n";

[$cc, $ch, $cbody] = curlReqWithHeaders(
    'curl -c ' . escapeshellarg($guardianJar) . ' -b ' . escapeshellarg($guardianJar)
    . ' --data-urlencode ' . escapeshellarg('csrf_token=' . $pageCsrf2)
    . ' --data-urlencode ' . escapeshellarg('action=erase_child')
    . ' --data-urlencode ' . escapeshellarg('child_id=' . $childId)
    . ' --data-urlencode ' . escapeshellarg('confirm_name=' . $childName)
    . ' --data-urlencode ' . escapeshellarg('confirm_word=WRONG_WORD')
    . ' ' . escapeshellarg($manageUrl)
);
ok($cc >= 300 && $cc < 400, "C: POST with wrong confirm_word returns 3xx [got $cc]");
ok(stripos($ch, 'erase_mismatch') !== false,
   "C: redirect Location contains erase_mismatch");

[$muc3, $mubody3] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L ' . escapeshellarg($manageUrl)
);
ok($muc3 === 200, "C: follow-up GET after wrong-word mismatch is 200");
$hasChildAfterC = (stripos($mubody3, $childName) !== false);
ok($hasChildAfterC, "C: child '$childName' still listed after wrong-word mismatch POST");

// Re-scrape CSRF for group D.
$pageCsrf3 = '';
if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $mubody3, $mc3)) {
    $pageCsrf3 = $mc3[1];
}
ok($pageCsrf3 !== '', "C: re-scraped CSRF token for success group [got '$pageCsrf3']");

// ==========================================================================
// GROUP D — Success: right child_id + right name + right word + valid CSRF
// ==========================================================================
echo "\n--- D. Success: valid POST erases child + DB audit; child gone ---\n";

[$dc, $dh, $dbody] = curlReqWithHeaders(
    'curl -c ' . escapeshellarg($guardianJar) . ' -b ' . escapeshellarg($guardianJar)
    . ' --data-urlencode ' . escapeshellarg('csrf_token=' . $pageCsrf3)
    . ' --data-urlencode ' . escapeshellarg('action=erase_child')
    . ' --data-urlencode ' . escapeshellarg('child_id=' . $childId)
    . ' --data-urlencode ' . escapeshellarg('confirm_name=' . $childName)
    . ' --data-urlencode ' . escapeshellarg('confirm_word=' . $eraseWord)
    . ' ' . escapeshellarg($manageUrl)
);
ok($dc >= 300 && $dc < 400, "D: valid erase POST returns 3xx [got $dc]");
ok(stripos($dh, 'msg=erased') !== false,
   "D: redirect Location contains msg=erased [headers: " . substr(preg_replace('/\r?\n/',' | ',$dh),0,200) . "]");

// Follow-up GET must no longer list the child.
[$muc4, $mubody4] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L ' . escapeshellarg($manageUrl)
);
ok($muc4 === 200, "D: follow-up GET after erase is 200");
$childGoneFromPage = (stripos($mubody4, $childName) === false);
ok($childGoneFromPage, "D: child '$childName' no longer listed on manage-users after erase");

// Direct DB check (open the temp DB via PDO).
$dbCheck = new PDO('sqlite:' . $tmpDb);
$dbCheck->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$remainingCheckins = (int) $dbCheck->prepare("SELECT COUNT(*) FROM daily_checkin WHERE user_id=?")
    ->execute([$childId]) ? (int) $dbCheck->query("SELECT COUNT(*) FROM daily_checkin WHERE user_id=$childId")->fetchColumn() : -1;
ok($remainingCheckins === 0,
   "D: 0 daily_checkin rows remain for child id=$childId after erase [got $remainingCheckins]");

$remainingFood = (int) $dbCheck->query("SELECT COUNT(*) FROM food_log WHERE user_id=$childId")->fetchColumn();
ok($remainingFood === 0,
   "D: 0 food_log rows remain for child id=$childId after erase [got $remainingFood]");

// Audit row: exactly one data_deletion_log row with scope='child' and target_user_id=$childId.
$auditRows = $dbCheck->prepare(
    "SELECT * FROM data_deletion_log WHERE scope='child' AND target_user_id=?"
);
$auditRows->execute([$childId]);
$auditAll = $auditRows->fetchAll(PDO::FETCH_ASSOC);
ok(count($auditAll) === 1,
   "D: exactly 1 data_deletion_log row with scope='child' and target_user_id=$childId [got " . count($auditAll) . "]");

if (count($auditAll) === 1) {
    $auditRow = $auditAll[0];
    ok($auditRow['scope'] === 'child', "D: audit row scope='child'");
    $auditTarget = (int) ($auditRow['target_user_id'] ?? -1);
    ok($auditTarget === (int) $childId,
       "D: audit row target_user_id matches childId [got $auditTarget, expected $childId]");
    // PII-free: record_counts must not contain the child's name.
    $counts = $auditRow['record_counts'] ?? '';
    $nameInCounts = (stripos($counts, $childName) !== false);
    ok(!$nameInCounts,
       "D: record_counts does not contain child name '$childName' (PII-free) [got: $counts]");
}

$dbCheck = null;

// ==========================================================================
// GROUP E — CSRF: POST without token → rejected; child still exists
// (We use a DIFFERENT child for this, created post-erasure. But since the
//  erased child is gone, we check using any child in the DB. The important
//  assertion is that the CSRF-less POST is rejected. We verify this by
//  checking the redirect location — it must not be msg=erased.)
// ==========================================================================
echo "\n--- E. CSRF: POST without token is rejected ---\n";

// Re-scrape a fresh CSRF token from the page (needed to confirm the page loads).
[$muc5, $mubody5] = curlReq(
    'curl -b ' . escapeshellarg($guardianJar) . ' -L ' . escapeshellarg($manageUrl)
);
ok($muc5 === 200, "E: GET manage-users for CSRF test is 200");

// Create a second child to attempt erasure on (the first is already gone).
// We write directly to the DB — the server process sees COMECOME_DB_PATH=$tmpDb
// on every request, so a direct write here is visible immediately.
$dbDirect = new PDO('sqlite:' . $tmpDb);
$dbDirect->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// Re-use the auth function by inserting directly (createUser requires the include
// chain which is already loaded in this process).
$hashedPin = password_hash('4321', PASSWORD_DEFAULT);
$dbDirect->prepare(
    "INSERT INTO users (name, type, pin, avatar_emoji, active) VALUES (?, 'child', ?, '🧒', 1)"
)->execute(['CsrfTestChild', $hashedPin]);
$csrfTestChildId = (int) $dbDirect->lastInsertId();
$dbDirect = null;
ok($csrfTestChildId > 0, "E: seeded CsrfTestChild (id=$csrfTestChildId) directly into DB");

// POST without csrf_token — the verifyCsrf() gate must reject this.
[$ec, $eh, $ebody] = curlReqWithHeaders(
    'curl -c ' . escapeshellarg($guardianJar) . ' -b ' . escapeshellarg($guardianJar)
    . ' -X POST'
    . ' --data-urlencode ' . escapeshellarg('action=erase_child')
    . ' --data-urlencode ' . escapeshellarg('child_id=' . $csrfTestChildId)
    . ' --data-urlencode ' . escapeshellarg('confirm_name=CsrfTestChild')
    . ' --data-urlencode ' . escapeshellarg('confirm_word=' . $eraseWord)
    . ' ' . escapeshellarg($manageUrl)
);
ok($ec >= 300 && $ec < 400, "E: POST without CSRF token returns 3xx redirect [got $ec]");
// Must NOT be msg=erased (must be csrf_error or similar rejection).
$notErased = (stripos($eh, 'msg=erased') === false);
ok($notErased, "E: redirect is NOT msg=erased (CSRF rejection fires before handler)");

// The child must still exist in the DB.
$dbFinal = new PDO('sqlite:' . $tmpDb);
$dbFinal->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$csrfChildStmt = $dbFinal->prepare("SELECT COUNT(*) FROM users WHERE id=? AND type='child'");
$csrfChildStmt->execute([$csrfTestChildId]);
$csrfChildExists = (int) $csrfChildStmt->fetchColumn();
ok($csrfChildExists === 1, "E: CsrfTestChild still exists in DB after CSRF-less POST [got $csrfChildExists]");
$dbFinal = null;

// --- Cleanup ----------------------------------------------------------------
$cleanup();
@unlink($guardianJar);

echo "\n==========================================================\n";
echo " HTTP ERASURE smoke: $PASS passed, " . count($FAIL) . " failed\n";
echo "==========================================================\n";
if (empty($FAIL)) { echo "HTTP-ERASURE-SMOKE: PASS\n"; exit(0); }
echo "HTTP-ERASURE-SMOKE: FAIL\n";
foreach ($FAIL as $f) { echo "  - $f\n"; }
exit(1);
