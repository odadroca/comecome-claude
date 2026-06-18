<?php
/**
 * ComeCome — Single Regression Entry Point (dependency-free CLI test runner).
 * ===========================================================================
 *
 * Run:   php tests/run.php
 * CI/exit: 0 = every check passed, non-zero = one or more failures.
 *
 * WHY THIS FILE EXISTS (Sprint 4 — Security & Deployment Foundations, Pt 1):
 *   This is the project's SINGLE regression command. It formalizes and EXTENDS
 *   the Sprint-3 smoke harness into one runnable entry point so coverage stays
 *   cumulative across Sprints 0–4 (and forward). It is intentionally
 *   dependency-free: NO Composer, NO PHPUnit — honoring the project's
 *   no-build-step ethos (vanilla PHP + SQLite).
 *
 * ENCRYPTION-TIMING PREREQUISITE (read this):
 *   Per DECISIONS.md decision (v), the Security & Deployment Foundations track
 *   "also unblocks the deferred encryption". The deferred SQLCipher at-rest
 *   encryption review requires a 'tests' safety net to exist FIRST, so that the
 *   migrate/backup/restore paths can be re-validated under encryption (the
 *   encryption timing review must be able to prove getDB()/initializeDatabase()/
 *   migrateDatabase()/backupDatabase()/restoreDatabase() still behave identically
 *   once the driver changes). THIS HARNESS ('php tests/run.php') is that
 *   prerequisite. Do not schedule SQLCipher before this runs green.
 *
 * SAFETY:
 *   Every database this runner touches is a THROWAWAY temp SQLite file under the
 *   system temp dir. It NEVER opens, reads, writes, or deletes db/data.db. A hard
 *   guard aborts if any temp path ever resolves to the real data.db.
 *
 * STRUCTURE:
 *   PHASE A  In-process unit checks (this process):
 *            A1. initializeDatabase() on a fresh temp DB -> every expected table
 *                exists and schema_version reaches 2.
 *            A2. migrateDatabase() forward from a SYNTHETIC older-version (v1)
 *                fixture -> Sprint-2 columns/tables appear; re-running migrate is
 *                a no-op (version unchanged, no error). [idempotency]
 *            A3. backup/restore round-trip: backupDatabase()/restoreDatabase()
 *                exist -> write -> backup -> mutate -> restore -> assert match.
 *   PHASE B  Sub-runner orchestration (isolated child processes):
 *            B1. tests/migration_idempotency.php  (exit 0 expected)
 *            B2. tests/smoke.php                  (exit 0 expected; cumulative
 *                                                  Sprint 0–3 coverage)
 *   PHASE C  Negative self-test: re-invoke THIS file in --selftest-negative mode
 *            (which deliberately fails an assertion) and assert it exits NON-zero.
 *            Proves the runner actually catches a broken case.
 *
 * HONESTY: assertions here are never weakened or skipped to make them pass. If a
 * check cannot be evaluated, it FAILS loudly rather than being silently skipped.
 *
 * --- Sprint coverage log ---------------------------------------------------
 *   Sprint 0 (bug fixes)            : via smoke.php auth + render paths.
 *   Sprint 1 (feature toggles)      : via smoke.php settings/footer renders.
 *   Sprint 2 (sleep tracking)       : A1 tables + A2 forward migration columns.
 *   Sprint 3 (clinical report)      : via smoke.php correlations + JSON whitelist.
 *   Sprint 4 (this safety net)      : A1/A2/A3 + B1/B2 orchestration + C negative.
 * ---------------------------------------------------------------------------
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);

/* =========================================================================
 * Argument parsing.
 *   --selftest-negative : run the deliberately-broken self-test (PHASE C body),
 *                         used by the parent to prove failures cause non-zero exit.
 * ========================================================================= */
$selftestNegative = in_array('--selftest-negative', $argv, true);

/* =========================================================================
 * Tiny assertion harness (shared by both modes).
 * ========================================================================= */
$FAILURES = [];
$PASSES = 0;
function ok($cond, $msg) {
    global $FAILURES, $PASSES;
    if ($cond) { echo "  [PASS] $msg\n"; $PASSES++; }
    else       { echo "  [FAIL] $msg\n"; $FAILURES[] = $msg; }
}

/* =========================================================================
 * Helpers for building/inspecting a throwaway DB.
 * ========================================================================= */

/** Allocate a fresh, NON-existent throwaway DB path under the system temp dir. */
function freshTempDbPath($tag) {
    $tmp = tempnam(sys_get_temp_dir(), $tag) . '.db';
    // tempnam created a 0-byte file at the prefix; remove both so the app sees a
    // non-existent DB path and runs the full create path.
    foreach ([substr($tmp, 0, -4), $tmp] as $p) {
        if (file_exists($p)) { @unlink($p); }
    }
    return $tmp;
}

/** Hard guard: abort if a temp path ever resolves to the real db/data.db. */
function assertNotRealDb($root, $path) {
    $realDb = realpath($root . '/db/data.db');
    $resolved = realpath($path);
    if ($resolved === false) { $resolved = $path; }
    if ($realDb !== false && $resolved === $realDb) {
        fwrite(STDERR, "ABORT: temp DB resolved to real data.db ($path)\n");
        exit(2);
    }
}

/** List user tables (sorted), on a given PDO handle. */
function listTables(PDO $db) {
    return $db->query(
        "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);
}

/** Read schema_version (int) from a given PDO handle, or null if absent. */
function readSchemaVersion(PDO $db) {
    try {
        $stmt = $db->prepare("SELECT value FROM settings WHERE \"key\" = 'schema_version'");
        $stmt->execute();
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ? (int) $r['value'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Does a column exist on a table? (PRAGMA table_info) */
function columnExists(PDO $db, $table, $column) {
    $cols = $db->query("PRAGMA table_info(" . $table . ")")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        if (isset($c['name']) && $c['name'] === $column) { return true; }
    }
    return false;
}

/**
 * Build a SYNTHETIC "older-version" (schema_version=1, pre-Sprint-2) fixture DB
 * by hand — WITHOUT the sleep tables and WITHOUT daily_checkin.sleep_quality, so
 * migrateDatabase() has real forward work to do. This deliberately reproduces the
 * v1 shape the app shipped before Sprint 2, exercising the migration end to end.
 *
 * NOTE: we do not call initializeDatabase() here (that already migrates to v2).
 * We hand-craft only the tables the migration depends on, then stamp version=1.
 */
function buildV1Fixture($dbPath) {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Minimal v1 users + settings + the pre-Sprint-2 daily_checkin (NO sleep_quality).
    $db->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        type TEXT NOT NULL CHECK(type IN ('child','guardian')),
        pin TEXT,
        avatar_emoji TEXT DEFAULT '😊',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        active INTEGER DEFAULT 1
    )");
    $db->exec("CREATE TABLE daily_checkin (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        check_date DATE NOT NULL,
        appetite_level INTEGER CHECK(appetite_level BETWEEN 1 AND 5),
        mood_level INTEGER CHECK(mood_level BETWEEN 1 AND 5),
        medication_taken INTEGER DEFAULT 0,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id, check_date),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    $db->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)");
    // Stamp it as the OLD version so migrateDatabase() takes the v1->v2 branch.
    $db->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('schema_version','1')");
    // A row of real check-in data so we can prove the ALTER preserved it.
    $db->exec("INSERT INTO users (id,name,type,pin) VALUES (1,'Fixture','guardian','x')");
    $db->exec("INSERT INTO daily_checkin (user_id,check_date,appetite_level,mood_level,medication_taken)
               VALUES (1,'2026-01-01',3,4,1)");
    $db = null;
}

/* =========================================================================
 * PHASE C body (negative self-test): runs when --selftest-negative is passed.
 *   This block INTENTIONALLY asserts a falsehood so the process exits non-zero.
 *   The parent runner invokes this in a child process to PROVE the harness
 *   actually catches broken cases. This is the only place an assertion is
 *   expected to fail — and it must, by design.
 * ========================================================================= */
if ($selftestNegative) {
    echo "=== ComeCome Runner — NEGATIVE SELF-TEST (expected to FAIL) ===\n";
    // Deliberately broken assertion. Honest: this proves the runner reports
    // failures and exits non-zero; it is NOT a weakened real check.
    ok(1 === 2, "deliberate failure: 1 === 2 (negative self-test sentinel)");
    echo "\n=== Negative self-test result: $PASSES passed, " . count($FAILURES) . " failed ===\n";
    // Exit non-zero precisely because the sentinel failed.
    exit(empty($FAILURES) ? 0 : 1);
}

/* =========================================================================
 * Normal run starts here.
 * ========================================================================= */
echo "==========================================================\n";
echo " ComeCome Regression Runner  (php tests/run.php)\n";
echo " Single, dependency-free entry point. Throwaway temp DBs.\n";
echo "==========================================================\n";

/* -------------------------------------------------------------------------
 * PHASE A — In-process unit checks against throwaway temp DBs.
 * ------------------------------------------------------------------------- */
echo "\n### PHASE A — initialize / migrate / backup-restore (in-process) ###\n";

// We must define the app's DB constants ONCE in this process, then include
// includes/db.php. getDB() reads DB_PATH, so for A1 we point it at a fresh temp
// file. For A2/A3 we open additional temp DBs via raw PDO (db.php's migrate/backup
// functions that take an explicit handle/path), so the single DB_PATH constant is
// fine. NEVER db/data.db.
$initDb = freshTempDbPath('comecome_run_init_');
assertNotRealDb($ROOT, $initDb);

define('DB_PATH', $initDb);
define('DB_SCHEMA', $ROOT . '/db/schema.sql');
define('DB_SEED', $ROOT . '/db/seed.sql');
// i18n.php (pulled transitively by nothing here, but define for safety/parity).
define('APP_NAME', 'ComeCome');
define('APP_VERSION', 'test');
define('DEFAULT_LOCALE', 'pt');
define('LOCALES_PATH', $ROOT . '/locales');
define('SESSION_LIFETIME', 86400);
define('GUEST_TOKEN_LIFETIME', 604800);
date_default_timezone_set('Europe/Lisbon');

require_once $ROOT . '/includes/db.php';

// --- A1. initializeDatabase() on a fresh temp DB ----------------------------
echo "\n-- A1. initializeDatabase(): tables + schema_version=2 --\n";
ok(!file_exists($initDb), "A1 precondition: temp DB does not exist before init");
initializeDatabase(); // DB_PATH = $initDb
$a1 = getDB();

// The full set of tables the shipped schema + migration must produce at v2.
$expectedTables = [
    'users', 'meals', 'food_categories', 'meal_categories', 'foods',
    'user_favorites', 'food_log', 'medications', 'user_medications',
    'daily_checkin', 'weight_log', 'settings', 'guest_tokens', 'translations',
    'sleep_log', 'sleep_interruptions',
];
$gotTables = listTables($a1);
foreach ($expectedTables as $t) {
    ok(in_array($t, $gotTables, true), "A1 table exists after init: $t");
}
// No accidental extra/missing: assert exact set equality (sorted compare).
$expSorted = $expectedTables; sort($expSorted);
$gotSorted = $gotTables;      sort($gotSorted);
ok($expSorted === $gotSorted,
   "A1 table set exactly matches expected (" . count($expectedTables) . " tables)");

$a1ver = readSchemaVersion($a1);
ok($a1ver === 2, "A1 schema_version reaches 2 on fresh init [got " . var_export($a1ver, true) . "]");

// Default guardian seeded by initializeDatabase().
$g = $a1->query("SELECT id,type FROM users WHERE id=1")->fetch(PDO::FETCH_ASSOC);
ok($g && $g['type'] === 'guardian', "A1 default guardian id=1 created by init");
$a1 = null;

// --- A2. migrateDatabase() forward from synthetic v1 + idempotency ----------
echo "\n-- A2. migrateDatabase(): v1 fixture forward + idempotent re-run --\n";
$migDb = freshTempDbPath('comecome_run_mig_');
assertNotRealDb($ROOT, $migDb);
buildV1Fixture($migDb);

$m = new PDO('sqlite:' . $migDb);
$m->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Pre-state assertions: prove the fixture is genuinely OLD (real forward work).
ok(readSchemaVersion($m) === 1, "A2 fixture starts at schema_version=1 (pre-Sprint-2)");
ok(!columnExists($m, 'daily_checkin', 'sleep_quality'),
   "A2 fixture lacks daily_checkin.sleep_quality before migrate");
ok(!in_array('sleep_log', listTables($m), true),
   "A2 fixture lacks sleep_log before migrate");
ok(!in_array('sleep_interruptions', listTables($m), true),
   "A2 fixture lacks sleep_interruptions before migrate");

// Forward migrate.
$threwFwd = false;
try { migrateDatabase($m); } catch (Throwable $e) { $threwFwd = true; echo "    EXCEPTION: " . $e->getMessage() . "\n"; }
ok(!$threwFwd, "A2 forward migrateDatabase() did not throw");

// Post-state: the Sprint-2 deliverables now exist.
ok(readSchemaVersion($m) === 2, "A2 schema_version is 2 after forward migrate");
ok(columnExists($m, 'daily_checkin', 'sleep_quality'),
   "A2 daily_checkin.sleep_quality exists after migrate");
ok(in_array('sleep_log', listTables($m), true),
   "A2 sleep_log exists after migrate");
ok(in_array('sleep_interruptions', listTables($m), true),
   "A2 sleep_interruptions exists after migrate");
// Pre-existing data survived the ALTER.
$row = $m->query("SELECT appetite_level,mood_level FROM daily_checkin WHERE user_id=1 AND check_date='2026-01-01'")->fetch(PDO::FETCH_ASSOC);
ok($row && (int)$row['appetite_level'] === 3 && (int)$row['mood_level'] === 4,
   "A2 pre-existing daily_checkin row preserved across migration");

// Idempotency: capture state, re-run twice, assert no change and no throw.
$verBefore = readSchemaVersion($m);
$tablesBefore = listTables($m); sort($tablesBefore);
$threwAgain = false;
try { migrateDatabase($m); migrateDatabase($m); }
catch (Throwable $e) { $threwAgain = true; echo "    EXCEPTION(re-run): " . $e->getMessage() . "\n"; }
ok(!$threwAgain, "A2 re-running migrateDatabase() twice did not throw");
$verAfter = readSchemaVersion($m);
$tablesAfter = listTables($m); sort($tablesAfter);
ok($verAfter === $verBefore, "A2 schema_version unchanged on re-run ($verBefore -> $verAfter)");
ok($tablesAfter === $tablesBefore, "A2 table set unchanged on re-run (no-op migration)");
$m = null;

// --- A3. backup / restore round-trip ----------------------------------------
echo "\n-- A3. backup / restore round-trip --\n";
// Sprint-4 spec: "if backupDatabase()/restoreDatabase() exist, test
// write->backup->mutate->restore->assert-match". They DO exist in includes/db.php
// (file-copy based). backupDatabase() copies DB_PATH; restoreDatabase($path)
// copies $path back over DB_PATH. We drive them through a temp DB_PATH only.
$haveBackup  = function_exists('backupDatabase');
$haveRestore = function_exists('restoreDatabase');
ok($haveBackup && $haveRestore,
   "A3 backupDatabase()/restoreDatabase() exist (file-copy backup path)");

if ($haveBackup && $haveRestore) {
    // DB_PATH is currently $initDb (a fully initialized v2 DB). Use it as the
    // live DB for the round-trip so we operate on a realistic schema.
    $live = getDB();
    // write a known marker row
    $live->exec("INSERT OR REPLACE INTO settings (\"key\",value) VALUES ('roundtrip_marker','ORIGINAL')");
    $markerBefore = $live->query("SELECT value FROM settings WHERE \"key\"='roundtrip_marker'")->fetchColumn();
    $live = null;
    ok($markerBefore === 'ORIGINAL', "A3 wrote marker=ORIGINAL into live temp DB");

    // backup (file copy of DB_PATH -> db/backup_<timestamp>.db)
    $backupPath = backupDatabase();
    ok($backupPath !== false && file_exists($backupPath),
       "A3 backupDatabase() produced a backup file");
    assertNotRealDb($ROOT, $backupPath); // backup must not be data.db

    // mutate the live DB AFTER the backup
    $live = getDB();
    $live->exec("UPDATE settings SET value='MUTATED' WHERE \"key\"='roundtrip_marker'");
    $markerMutated = $live->query("SELECT value FROM settings WHERE \"key\"='roundtrip_marker'")->fetchColumn();
    $live = null;
    ok($markerMutated === 'MUTATED', "A3 mutated live marker to MUTATED after backup");

    // restore from backup over DB_PATH
    $restored = restoreDatabase($backupPath);
    ok($restored === true, "A3 restoreDatabase() returned true");

    // assert the restored DB matches the ORIGINAL (round-trip integrity)
    $live = getDB();
    $markerAfter = $live->query("SELECT value FROM settings WHERE \"key\"='roundtrip_marker'")->fetchColumn();
    $live = null;
    ok($markerAfter === 'ORIGINAL',
       "A3 restore round-trip: marker is ORIGINAL again (backup overrode mutation)");

    // tidy the backup artifact so it doesn't linger in db/.
    if ($backupPath && file_exists($backupPath)) { @unlink($backupPath); }
}

// PHASE A cleanup of temp DBs.
foreach ([$initDb, $migDb] as $p) { if ($p && file_exists($p)) { @unlink($p); } }

/* -------------------------------------------------------------------------
 * PHASE B — Orchestrate existing sub-runners as isolated child processes.
 *   Folding them in keeps coverage cumulative (Sprints 0–3 + this) and makes
 *   'php tests/run.php' the single regression command. Each sub-runner uses its
 *   own throwaway temp DB internally and must exit 0.
 * ------------------------------------------------------------------------- */
echo "\n### PHASE B — sub-runners (isolated) ###\n";
$php = PHP_BINARY;

function runSub($php, $scriptPath) {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($scriptPath);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) { return [127, '', 'proc_open failed']; }
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);
    return [$code, $out, $err];
}

$subRunners = [
    'tests/migration_idempotency.php',
    'tests/smoke.php',
];
foreach ($subRunners as $rel) {
    $abs = $ROOT . '/' . $rel;
    if (!file_exists($abs)) {
        ok(false, "B sub-runner present: $rel (MISSING)");
        continue;
    }
    [$code, $out, $err] = runSub($php, $abs);
    $clean = ($code === 0);
    if (!$clean) {
        // Surface the tail of the sub-runner output for diagnosis.
        echo "    ----- $rel output (exit=$code) -----\n";
        $tail = array_slice(preg_split('/\r?\n/', rtrim($out)), -25);
        foreach ($tail as $line) { echo "    | $line\n"; }
        if (trim($err) !== '') { echo "    | stderr: " . trim($err) . "\n"; }
    }
    ok($clean, "B sub-runner passed (exit 0): $rel");
}

/* -------------------------------------------------------------------------
 * PHASE C — Negative self-test: prove the runner catches a broken case.
 *   Re-invoke THIS file with --selftest-negative (which fails by design) and
 *   assert it exits NON-zero. If the harness could not detect a failure, this
 *   check itself would fail.
 * ------------------------------------------------------------------------- */
echo "\n### PHASE C — negative self-test (runner must catch failures) ###\n";
// Call proc_open directly here (rather than runSub) so we can pass the extra
// --selftest-negative flag alongside the script path.
$cmd = escapeshellarg($php) . ' ' . escapeshellarg(__FILE__) . ' --selftest-negative';
$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open($cmd, $descriptors, $pipes);
$nout = is_resource($proc) ? stream_get_contents($pipes[1]) : '';
if (is_resource($proc)) { fclose($pipes[1]); $nerr = stream_get_contents($pipes[2]); fclose($pipes[2]); $ncode = proc_close($proc); }
else { $ncode = 127; }

ok($ncode !== 0,
   "C negative self-test exits NON-zero (runner detects a deliberately broken case) [exit=$ncode]");
ok(strpos($nout, '[FAIL]') !== false,
   "C negative self-test output contains a [FAIL] marker (failure surfaced)");

/* -------------------------------------------------------------------------
 * VERDICT.
 * ------------------------------------------------------------------------- */
echo "\n==========================================================\n";
echo " Result: $PASSES passed, " . count($FAILURES) . " failed\n";
echo "==========================================================\n";
if (empty($FAILURES)) {
    echo "RUN: PASS\n";
    exit(0);
}
echo "RUN: FAIL\n";
foreach ($FAILURES as $f) { echo "  - $f\n"; }
exit(1);
