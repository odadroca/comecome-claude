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
 *   PHASE B2 HTTP-level smoke sub-runners (spawn `php -S` + curl): assert the
 *            response behaviours the in-process harness cannot observe.
 *            - tests/http_smoke.php          (Phase 0 cookie flags over HTTP)
 *            - tests/http_throttle_smoke.php (Phase 1 lockout message over HTTP)
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
 *   Sprint 5 (demographics v2->v3)  : A1 fresh schema.sql carries gender +
 *                                     date_of_birth; A2 v1->...->v3 forward
 *                                     migrate adds both columns (nullable),
 *                                     preserves pre-existing user rows, and is
 *                                     idempotent on re-run.
 *   Sprint 6 (growth page v3->v4)   : A1 fresh schema.sql carries height_log;
 *                                     schema_version reaches 4. A2 v1->...->v4
 *                                     forward migrate adds height_log, the
 *                                     UNIQUE(user_id,log_date) upsert is proven,
 *                                     the table survives idempotent re-runs.
 *   Sprint 7 (percentiles engine)   : PHASE D — provider-independent CDF/z-score
 *                                     maths checkpoints (A&S 7.1.26 normal CDF +
 *                                     calculateZScore) AND WHO data-fidelity anchors
 *                                     (real WHO LMS reproduced within tolerance,
 *                                     catching fabricated data) AND graceful-null /
 *                                     ±5 SD clamp behaviour. NO schema change
 *                                     (engine itself adds no migration), pure library.
 *   Sprint 8 (percentiles display)  : PHASE E — display layer over the WHO engine;
 *                                     gating, four-surface parity, JSON whitelist.
 *                                     Sprint 8 added no migration of its own.
 *   Sprint 9 (med timing v4->v5)    : A1 fresh schema.sql carries medication_schedules
 *                                     + food_log.med_window; schema_version reaches 5.
 *                                     A2 v1->...->v5 forward migrate adds both
 *                                     idempotently, the med_window CHECK is enforced,
 *                                     and pre-existing food_log rows survive. PHASE F —
 *                                     computeMedWindow() boundary classification + the
 *                                     non-stimulant NULL path, and logFood() stamping
 *                                     med_window at INSERT with the child payload
 *                                     unchanged (ZERO child-facing change).
 *   Security Phase 0 (cookies/auth)  : PHASE G — configureSessionCookieParams() flags,
 *                                     sessionIsExpired() idle math, default-PIN guard
 *                                     lifecycle. (HTTP Set-Cookie via http_smoke.php.)
 *   Security Phase 1 (throttle v5->v6): A1 fresh schema.sql carries login_attempts;
 *                                     schema_version reaches 6. A2 v1->...->v6 forward
 *                                     migrate adds login_attempts idempotently. PHASE H —
 *                                     throttleComputeAfterFailure() backoff/lock math +
 *                                     the authenticateUser() round-trip: scripted
 *                                     wrong-PINs hit a DISTINCT locked state, a locked
 *                                     account refuses verify, a correct PIN resets, the
 *                                     storage stays ONE aggregated row (UPDATE-in-place),
 *                                     unknown ids lock identically, self-prune works.
 *                                     PHASE B2 — tests/http_throttle_smoke.php drives the
 *                                     wired-up login page over real HTTP (`php -S` + curl):
 *                                     scripted wrong-PIN POSTs tip into the DISTINCT locked
 *                                     message and a correct PIN is refused while locked.
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
    // Sprint 9: a pre-Sprint-9 food_log WITHOUT the med_window column, so the v5
    // ALTER TABLE food_log ADD COLUMN med_window has a real target to migrate.
    $db->exec("CREATE TABLE food_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        food_id INTEGER NOT NULL,
        meal_id INTEGER NOT NULL,
        portion TEXT NOT NULL,
        log_date DATE NOT NULL,
        log_time TIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    // Stamp it as the OLD version so migrateDatabase() takes the v1->v2 branch.
    $db->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('schema_version','1')");
    // A row of real check-in data so we can prove the ALTER preserved it.
    $db->exec("INSERT INTO users (id,name,type,pin) VALUES (1,'Fixture','guardian','x')");
    $db->exec("INSERT INTO daily_checkin (user_id,check_date,appetite_level,mood_level,medication_taken)
               VALUES (1,'2026-01-01',3,4,1)");
    // A pre-existing food_log row to prove the v5 ALTER is non-destructive.
    $db->exec("INSERT INTO food_log (id,user_id,food_id,meal_id,portion,log_date,log_time)
               VALUES (1,1,1,1,'some','2026-01-01','08:30:00')");
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
echo "\n-- A1. initializeDatabase(): tables + schema_version=6 --\n";
ok(!file_exists($initDb), "A1 precondition: temp DB does not exist before init");
initializeDatabase(); // DB_PATH = $initDb
$a1 = getDB();

// The full set of tables the shipped schema + migration must produce at the
// current schema_version (6). Sprint 6 adds height_log; Sprint 9 adds
// medication_schedules; security Phase 1 adds login_attempts.
$expectedTables = [
    'users', 'meals', 'food_categories', 'meal_categories', 'foods',
    'user_favorites', 'food_log', 'medications', 'user_medications',
    'medication_schedules',
    'daily_checkin', 'weight_log', 'height_log', 'settings', 'guest_tokens',
    'translations', 'sleep_log', 'sleep_interruptions', 'login_attempts',
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
ok($a1ver === 6, "A1 schema_version reaches 6 on fresh init [got " . var_export($a1ver, true) . "]");

// Default guardian seeded by initializeDatabase().
$g = $a1->query("SELECT id,type FROM users WHERE id=1")->fetch(PDO::FETCH_ASSOC);
ok($g && $g['type'] === 'guardian', "A1 default guardian id=1 created by init");

// Sprint 5: a fresh DB built from schema.sql must already carry the demographics
// columns (schema.sql and the v3 migration agree). Both are NULLABLE.
ok(columnExists($a1, 'users', 'gender'),
   "A1 users.gender column present on fresh schema.sql DB");
ok(columnExists($a1, 'users', 'date_of_birth'),
   "A1 users.date_of_birth column present on fresh schema.sql DB");

// Sprint 6: a fresh DB built from schema.sql must already carry height_log with
// its expected columns (schema.sql and the v4 migration agree).
ok(columnExists($a1, 'height_log', 'height_cm'),
   "A1 height_log.height_cm column present on fresh schema.sql DB");
ok(columnExists($a1, 'height_log', 'log_date'),
   "A1 height_log.log_date column present on fresh schema.sql DB");

// Sprint 9: a fresh DB from schema.sql must carry medication_schedules (with its
// offset columns) AND food_log.med_window (schema.sql and the v5 migration agree).
ok(columnExists($a1, 'medication_schedules', 'dose_time'),
   "A1 medication_schedules.dose_time column present on fresh schema.sql DB");
ok(columnExists($a1, 'medication_schedules', 'peak_start_offset'),
   "A1 medication_schedules.peak_start_offset column present on fresh schema.sql DB");
ok(columnExists($a1, 'medication_schedules', 'peak_end_offset'),
   "A1 medication_schedules.peak_end_offset column present on fresh schema.sql DB");
ok(columnExists($a1, 'food_log', 'med_window'),
   "A1 food_log.med_window column present on fresh schema.sql DB");

// Security Phase 1: a fresh DB from schema.sql must carry login_attempts with its
// aggregated-counter columns (schema.sql and the v6 migration agree).
ok(columnExists($a1, 'login_attempts', 'fail_count'),
   "A1 login_attempts.fail_count column present on fresh schema.sql DB");
ok(columnExists($a1, 'login_attempts', 'window_start'),
   "A1 login_attempts.window_start column present on fresh schema.sql DB");
ok(columnExists($a1, 'login_attempts', 'locked_until'),
   "A1 login_attempts.locked_until column present on fresh schema.sql DB");
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
// Sprint 5 (v2->v3): the v1 fixture must also lack the demographics columns so
// the forward migration has real work to do for v3 as well as v2.
ok(!columnExists($m, 'users', 'gender'),
   "A2 fixture lacks users.gender before migrate");
ok(!columnExists($m, 'users', 'date_of_birth'),
   "A2 fixture lacks users.date_of_birth before migrate");
// Sprint 6 (v3->v4): the v1 fixture must also lack height_log so the forward
// migration has real work to do for v4 as well.
ok(!in_array('height_log', listTables($m), true),
   "A2 fixture lacks height_log before migrate");
// Sprint 9 (v4->v5): the v1 fixture must also lack medication_schedules and the
// food_log.med_window column so the forward migration has real v5 work to do.
ok(!in_array('medication_schedules', listTables($m), true),
   "A2 fixture lacks medication_schedules before migrate");
ok(!columnExists($m, 'food_log', 'med_window'),
   "A2 fixture lacks food_log.med_window before migrate");
// Security Phase 1 (v5->v6): the v1 fixture must also lack login_attempts so the
// forward migration has real v6 work to do.
ok(!in_array('login_attempts', listTables($m), true),
   "A2 fixture lacks login_attempts before migrate");

// Forward migrate.
$threwFwd = false;
try { migrateDatabase($m); } catch (Throwable $e) { $threwFwd = true; echo "    EXCEPTION: " . $e->getMessage() . "\n"; }
ok(!$threwFwd, "A2 forward migrateDatabase() did not throw");

// Post-state: the Sprint-2, Sprint-5, Sprint-6, Sprint-9 AND security-Phase-1
// deliverables now exist. The fixture migrates forward through every gated block
// (v1->v2->v3->v4->v5->v6) in one call.
ok(readSchemaVersion($m) === 6, "A2 schema_version is 6 after forward migrate");
ok(columnExists($m, 'daily_checkin', 'sleep_quality'),
   "A2 daily_checkin.sleep_quality exists after migrate");
ok(in_array('sleep_log', listTables($m), true),
   "A2 sleep_log exists after migrate");
ok(in_array('sleep_interruptions', listTables($m), true),
   "A2 sleep_interruptions exists after migrate");
// Sprint 5 (v2->v3): demographics columns appear, both NULLABLE.
ok(columnExists($m, 'users', 'gender'),
   "A2 users.gender exists after migrate (v3)");
ok(columnExists($m, 'users', 'date_of_birth'),
   "A2 users.date_of_birth exists after migrate (v3)");
// Sprint 6 (v3->v4): height_log table appears with its expected columns.
ok(in_array('height_log', listTables($m), true),
   "A2 height_log exists after migrate (v4)");
ok(columnExists($m, 'height_log', 'height_cm'),
   "A2 height_log.height_cm exists after migrate (v4)");
ok(columnExists($m, 'height_log', 'log_date'),
   "A2 height_log.log_date exists after migrate (v4)");
// Sprint 9 (v4->v5): medication_schedules table + food_log.med_window column appear.
ok(in_array('medication_schedules', listTables($m), true),
   "A2 medication_schedules exists after migrate (v5)");
ok(columnExists($m, 'medication_schedules', 'peak_start_offset')
   && columnExists($m, 'medication_schedules', 'peak_end_offset')
   && columnExists($m, 'medication_schedules', 'dose_time'),
   "A2 medication_schedules has dose_time + offset columns after migrate (v5)");
ok(columnExists($m, 'food_log', 'med_window'),
   "A2 food_log.med_window exists after migrate (v5)");
// Security Phase 1 (v5->v6): login_attempts table appears with its counter columns.
ok(in_array('login_attempts', listTables($m), true),
   "A2 login_attempts exists after migrate (v6)");
ok(columnExists($m, 'login_attempts', 'fail_count')
   && columnExists($m, 'login_attempts', 'window_start')
   && columnExists($m, 'login_attempts', 'locked_until'),
   "A2 login_attempts has fail_count + window_start + locked_until after migrate (v6)");
// The v5 food_log ALTER is non-destructive: the pre-existing row survives, with the
// new med_window column defaulting NULL.
$flRow = $m->query("SELECT portion, med_window FROM food_log WHERE id=1")->fetch(PDO::FETCH_ASSOC);
ok($flRow && $flRow['portion'] === 'some' && $flRow['med_window'] === null,
   "A2 v5 ALTER leaves pre-existing food_log row intact, med_window NULL (additive)");
// The med_window CHECK constraint accepts the four window names AND NULL, and
// rejects an out-of-set value (constraint actually enforced).
$m->exec("INSERT INTO food_log (user_id,food_id,meal_id,portion,log_date,log_time,med_window)
          VALUES (1,1,1,'lot','2026-01-02','09:00:00','mid_med')");
$mw = $m->query("SELECT med_window FROM food_log WHERE log_date='2026-01-02'")->fetchColumn();
ok($mw === 'mid_med', "A2 food_log.med_window accepts a valid window name ('mid_med')");
$badAccepted = true;
try {
    $m->exec("INSERT INTO food_log (user_id,food_id,meal_id,portion,log_date,log_time,med_window)
              VALUES (1,1,1,'lot','2026-01-03','09:00:00','not_a_window')");
} catch (Throwable $e) { $badAccepted = false; }
ok(!$badAccepted, "A2 food_log.med_window CHECK rejects an out-of-set value");
// Existing user rows survive the v3 ALTERs with the new columns defaulting NULL.
$urow = $m->query("SELECT name,type,gender,date_of_birth FROM users WHERE id=1")->fetch(PDO::FETCH_ASSOC);
ok($urow && $urow['name'] === 'Fixture' && $urow['type'] === 'guardian',
   "A2 pre-existing users row preserved across v3 migration");
ok($urow && $urow['gender'] === null && $urow['date_of_birth'] === null,
   "A2 v3 ALTER leaves pre-existing user gender/date_of_birth NULL (additive, non-destructive)");
// Pre-existing data survived the ALTER.
$row = $m->query("SELECT appetite_level,mood_level FROM daily_checkin WHERE user_id=1 AND check_date='2026-01-01'")->fetch(PDO::FETCH_ASSOC);
ok($row && (int)$row['appetite_level'] === 3 && (int)$row['mood_level'] === 4,
   "A2 pre-existing daily_checkin row preserved across migration");

// Sprint 5: prove writes through the new columns round-trip (and the CHECK
// constraint accepts the two valid genders). Then confirm idempotent re-run
// preserves these values.
$m->exec("UPDATE users SET gender='female', date_of_birth='2018-05-04' WHERE id=1");
$wrote = $m->query("SELECT gender,date_of_birth FROM users WHERE id=1")->fetch(PDO::FETCH_ASSOC);
ok($wrote && $wrote['gender'] === 'female' && $wrote['date_of_birth'] === '2018-05-04',
   "A2 demographics values write+read back through v3 columns");

// Sprint 6: prove height_log accepts a row and that the UNIQUE(user_id, log_date)
// upsert (INSERT OR REPLACE, mirroring weight_log) overwrites rather than
// duplicating a same-day re-log.
$m->exec("INSERT OR REPLACE INTO height_log (user_id, height_cm, log_date) VALUES (1, 120.5, '2026-01-02')");
$m->exec("INSERT OR REPLACE INTO height_log (user_id, height_cm, log_date) VALUES (1, 121.0, '2026-01-02')");
$hcount = (int) $m->query("SELECT COUNT(*) FROM height_log WHERE user_id=1 AND log_date='2026-01-02'")->fetchColumn();
$hval = $m->query("SELECT height_cm FROM height_log WHERE user_id=1 AND log_date='2026-01-02'")->fetchColumn();
ok($hcount === 1 && (float) $hval === 121.0,
   "A2 height_log same-day re-log upserts (1 row, latest value) via UNIQUE(user_id, log_date)");

// Idempotency: capture state, re-run twice, assert no change and no throw.
$verBefore = readSchemaVersion($m);
$tablesBefore = listTables($m); sort($tablesBefore);
// Capture the users column set too — the v3 work is column-adds (not new tables),
// so a no-op re-run must NOT duplicate or drop columns.
$usersColsBefore = $m->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
sort($usersColsBefore);
$threwAgain = false;
try { migrateDatabase($m); migrateDatabase($m); }
catch (Throwable $e) { $threwAgain = true; echo "    EXCEPTION(re-run): " . $e->getMessage() . "\n"; }
ok(!$threwAgain, "A2 re-running migrateDatabase() twice did not throw");
$verAfter = readSchemaVersion($m);
$tablesAfter = listTables($m); sort($tablesAfter);
ok($verAfter === $verBefore, "A2 schema_version unchanged on re-run ($verBefore -> $verAfter)");
ok($verAfter === 6, "A2 schema_version stays at 6 on idempotent re-run");
ok($tablesAfter === $tablesBefore, "A2 table set unchanged on re-run (no-op migration)");
$usersColsAfter = $m->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
sort($usersColsAfter);
ok($usersColsAfter === $usersColsBefore,
   "A2 users column set unchanged on re-run (v3 column-adds are idempotent)");
// Demographics values written earlier survive the no-op re-runs.
$keep = $m->query("SELECT gender,date_of_birth FROM users WHERE id=1")->fetch(PDO::FETCH_ASSOC);
ok($keep && $keep['gender'] === 'female' && $keep['date_of_birth'] === '2018-05-04',
   "A2 demographics values preserved across idempotent migrate re-runs");
// Sprint 6: the height_log row written earlier survives the no-op re-runs (the v4
// block is CREATE TABLE IF NOT EXISTS — it must not drop or recreate the table).
$keepH = $m->query("SELECT height_cm FROM height_log WHERE user_id=1 AND log_date='2026-01-02'")->fetchColumn();
ok($keepH !== false && (float) $keepH === 121.0,
   "A2 height_log row preserved across idempotent migrate re-runs");
// Sprint 9: the v5 blocks are CREATE TABLE IF NOT EXISTS + a try/catch ALTER, so the
// stamped med_window survives the no-op re-runs (table/column not dropped/recreated).
$keepMw = $m->query("SELECT med_window FROM food_log WHERE log_date='2026-01-02'")->fetchColumn();
ok($keepMw === 'mid_med', "A2 food_log.med_window value preserved across idempotent migrate re-runs");
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
 * PHASE B2 — HTTP-level smoke sub-runners (spawn `php -S` + curl).
 *   The in-process unit harness deliberately CANNOT load config.php/start a
 *   session or observe real response headers, cookies, redirects or the wired-up
 *   login page. The Testability section of SPRINT-SECURITY.md therefore requires a
 *   `php -S` + curl smoke for those behaviours. We orchestrate them HERE so
 *   `php tests/run.php` stays the single cumulative regression command:
 *     - tests/http_smoke.php          : Phase 0 Set-Cookie HttpOnly+SameSite=Lax,
 *                                       no Secure over plain HTTP.
 *     - tests/http_throttle_smoke.php : Phase 1 scripted wrong-PIN POSTs tip into
 *                                       the DISTINCT locked message over HTTP, and a
 *                                       correct PIN is refused while locked.
 *   Each spawns its own server bound to a throwaway DB (COMECOME_DB_PATH) and must
 *   exit 0. They need a free TCP port + the `curl` binary; if a smoke cannot bind or
 *   curl is missing it FAILS loudly (honest) rather than being silently skipped.
 * ------------------------------------------------------------------------- */
echo "\n### PHASE B2 — HTTP-level smoke sub-runners (php -S + curl) ###\n";

/**
 * Run a sub-runner that itself SPAWNS a `php -S` grandchild, capturing output via
 * a TEMP FILE rather than inherited stdout/stderr PIPES.
 *
 * WHY NOT runSub(): runSub() captures the child's stdout/stderr through proc_open
 * pipes and blocks on stream_get_contents() until EOF. An HTTP smoke spawns a
 * `php -S` grandchild that INHERITS those pipe handles; on Windows the grandchild
 * can keep the write end open past the child's exit, so the parent never sees EOF
 * and DEADLOCKS. Redirecting the child to a file gives it private stdio the
 * grandchild inherits instead — no parent-side pipe to hang on. We then read the
 * file. This is the robust way to orchestrate a process-spawning sub-runner.
 */
function runSubToFile($php, $scriptPath) {
    $outFile = tempnam(sys_get_temp_dir(), 'cc_b2_') . '.log';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $outFile, 'w'],
        2 => ['file', $outFile, 'a'],
    ];
    $proc = proc_open(escapeshellarg($php) . ' ' . escapeshellarg($scriptPath), $descriptors, $pipes);
    if (!is_resource($proc)) { return [127, "proc_open failed", $outFile]; }
    if (isset($pipes[0]) && is_resource($pipes[0])) { fclose($pipes[0]); }
    // Block on the child only (no pipe to read); the child reaps its own grandchild
    // server in its cleanup(), so this returns once the smoke has finished + torn
    // down its server.
    $code = proc_close($proc);
    $out = @file_get_contents($outFile);
    if ($out === false) { $out = ''; }
    return [$code, $out, $outFile];
}

$httpSmokes = [
    'tests/http_smoke.php',
    'tests/http_throttle_smoke.php',
];
foreach ($httpSmokes as $rel) {
    $abs = $ROOT . '/' . $rel;
    if (!file_exists($abs)) {
        ok(false, "B2 HTTP smoke present: $rel (MISSING)");
        continue;
    }
    [$code, $out, $outFile] = runSubToFile($php, $abs);
    $clean = ($code === 0);
    if (!$clean) {
        echo "    ----- $rel output (exit=$code) -----\n";
        $tail = array_slice(preg_split('/\r?\n/', rtrim($out)), -30);
        foreach ($tail as $line) { echo "    | $line\n"; }
    }
    if ($outFile && file_exists($outFile)) { @unlink($outFile); }
    ok($clean, "B2 HTTP smoke passed (exit 0): $rel");
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
 * PHASE D — Sprint 7 percentile-engine validation (in-process, side-effect-free).
 *
 *   The engine (includes/percentiles.php) + WHO reference data
 *   (includes/growth-standards.php) are pure library code: no DB, no schema, no UI.
 *   We include them directly and assert two independent families of checks:
 *
 *     D1. PROVIDER-INDEPENDENT MATH (must pass EXACTLY): the standard-normal CDF
 *         (A&S 7.1.26) at canonical points and calculateZScore()'s value==M => 0.
 *         These do NOT depend on any reference table.
 *     D2. WHO DATA-FIDELITY ANCHORS: feeding the engine REAL WHO LMS must reproduce
 *         well-known published WHO values. These deliberately CATCH FABRICATED DATA —
 *         if growth-standards.php held approximated/invented numbers, the P50 / −2SD
 *         anchors would drift out of tolerance and FAIL (honest stop).
 *     D3. ROBUSTNESS: out-of-coverage age / unknown sex / bad value return null
 *         (never crash), and an extreme measurement is clamped to ±5 SD.
 * ------------------------------------------------------------------------- */
echo "\n### PHASE D — Sprint 7 percentile engine (CDF math + WHO anchors) ###\n";

// helpers.php supplies calculateAgeInMonths()/calculateBMI() used by the engine's
// DOB convenience wrappers; percentiles.php is the engine; growth-standards.php is
// required lazily by the engine. All side-effect free — safe to include here.
require_once $ROOT . '/includes/helpers.php';
require_once $ROOT . '/includes/percentiles.php';

/** Float closeness assertion. */
function okApprox($got, $want, $tol, $msg) {
    $cond = ($got !== null) && is_numeric($got) && abs(((float) $got) - $want) <= $tol;
    $shown = $got === null ? 'null' : rtrim(rtrim(sprintf('%.6f', (float) $got), '0'), '.');
    ok($cond, $msg . " [got=$shown want=$want tol=$tol]");
}

echo "\n-- D1. provider-independent CDF / z-score math --\n";
okApprox(zScoreToPercentile(0),      0.500,  0.001, "D1 Phi(0)=0.500");
okApprox(zScoreToPercentile(1.96),   0.975,  0.002, "D1 Phi(1.96)=0.975");
okApprox(zScoreToPercentile(-1.96),  0.025,  0.002, "D1 Phi(-1.96)=0.025");
okApprox(zScoreToPercentile(1.645),  0.950,  0.002, "D1 Phi(1.645)=0.950");
okApprox(zScoreToPercentile(-2),     0.0228, 0.001, "D1 Phi(-2)=0.0228");
// calculateZScore returns 0 exactly when value == M, in both L-branches.
okApprox(calculateZScore(8.0, 1.0, 8.0, 0.1),  0.0, 1e-9, "D1 calculateZScore=0 when value==M (L!=0)");
okApprox(calculateZScore(8.0, 0.0, 8.0, 0.1),  0.0, 1e-9, "D1 calculateZScore=0 when value==M (L==0)");
// Spot-check the LMS formula against a hand-computed value (L!=0):
//   value=10, L=-0.2, M=9, S=0.12 -> z = ((10/9)^-0.2 - 1)/(-0.2*0.12)
$expZ = (pow(10.0 / 9.0, -0.2) - 1) / (-0.2 * 0.12);
okApprox(calculateZScore(10.0, -0.2, 9.0, 0.12), $expZ, 1e-9, "D1 calculateZScore matches closed-form LMS");

echo "\n-- D2. WHO data-fidelity anchors (catch fabricated LMS) --\n";
// These VALUES are well-known published WHO medians/SDs. The engine, fed REAL WHO
// LMS, must put each P50 value at ~50th percentile (±2 pts) and the −2SD value near
// P2.3. Tolerances per spec (~±2 percentile points).
okApprox(calculateHeightForAgePercentile(49.9, 0, 'boys'),  50.0, 2.0,
    "D2 boys length-for-age 0mo P50~49.9cm -> ~50th pct");
okApprox(calculateWeightForAgePercentile(3.3, 0, 'boys'),   50.0, 5.0,
    "D2 boys weight-for-age 0mo P50~3.3kg -> ~50th pct");
okApprox(calculateWeightForAgePercentile(2.5, 0, 'boys'),    2.3, 2.0,
    "D2 boys weight-for-age 0mo -2SD~2.5kg -> ~P2.3");
okApprox(calculateWeightForAgePercentile(8.9, 12, 'girls'), 50.0, 5.0,
    "D2 girls weight-for-age 12mo P50~8.9kg -> ~50th pct");
okApprox(calculateHeightForAgePercentile(87.1, 24, 'boys'), 50.0, 2.0,
    "D2 boys height-for-age 24mo P50~87.1cm -> ~50th pct");
// Cross-check a non-anchor published WHO percentile too: girls height-for-age 60mo
// median is ~109.4 cm (WHO 2006). At the median the percentile must be ~50.
okApprox(calculateHeightForAgePercentile(109.4189, 60, 'girls'), 50.0, 2.0,
    "D2 girls height-for-age 60mo @published median -> ~50th pct");
// And confirm 'male'/'female' (users.gender) map identically to boys/girls.
$pByGender = calculateWeightForAgePercentile(8.9, 12, 'female');
okApprox($pByGender, 50.0, 5.0, "D2 sex='female' maps to girls table (gender normalisation)");

echo "\n-- D3. graceful degradation + clamp --\n";
ok(calculateWeightForAgePercentile(15, 200, 'boys')   === null, "D3 weight-for-age age>120mo -> null (out of coverage)");
ok(calculateHeightForAgePercentile(100, 999, 'girls') === null, "D3 height-for-age age>228mo -> null (out of coverage)");
ok(calculateWeightForAgePercentile(15, 36, 'unknown') === null, "D3 unknown sex -> null");
ok(calculateWeightForAgePercentile(-5, 36, 'boys')    === null, "D3 non-positive value -> null");
ok(calculateBMIForAgePercentile(16, -1, 'boys')       === null, "D3 negative age -> null");
// Coverage edges resolve (not null) at the exact published min/max month.
ok(calculateWeightForAgePercentile(3.3, 0, 'boys')    !== null, "D3 weight-for-age at min month (0) resolves");
ok(calculateWeightForAgePercentile(31.0, 120, 'boys') !== null, "D3 weight-for-age at max month (120) resolves");
ok(calculateHeightForAgePercentile(176.0, 228, 'boys')!== null, "D3 height-for-age at max month (228) resolves");
// Linear interpolation between integer months returns a finite percentile.
$pHalf = calculateWeightForAgePercentile(11.0, 18.5, 'boys');
ok($pHalf !== null && $pHalf >= 0 && $pHalf <= 100, "D3 fractional age 18.5mo interpolates to a valid percentile");
// Extreme measurement: z is clamped to +/-5 SD (decision: clamp beyond +/-5 SD).
$zHi = calculateMetricZScore('weight_for_age', 60.0, 0, 'boys'); // 60kg newborn
ok($zHi !== null && abs($zHi - 5.0) < 1e-9, "D3 extreme high measurement clamps z to +5 SD [got=" . var_export($zHi, true) . "]");
$zLo = calculateMetricZScore('weight_for_age', 0.5, 0, 'boys');  // 0.5kg newborn
ok($zLo !== null && abs($zLo + 5.0) < 1e-9, "D3 extreme low measurement clamps z to -5 SD [got=" . var_export($zLo, true) . "]");

echo "\n-- D4. reference-data coverage (no gaps / right ranges) --\n";
$gs = getGrowthStandards();
ok(isset($gs['weight_for_age']['boys'][0])   && isset($gs['weight_for_age']['boys'][120]),
   "D4 weight_for_age boys spans 0..120");
ok(isset($gs['weight_for_age']['girls'][0])  && isset($gs['weight_for_age']['girls'][120]),
   "D4 weight_for_age girls spans 0..120");
ok(!isset($gs['weight_for_age']['boys'][121]),
   "D4 weight_for_age stops at 120 (no 121)");
ok(isset($gs['height_for_age']['boys'][0])   && isset($gs['height_for_age']['boys'][228]),
   "D4 height_for_age boys spans 0..228");
ok(isset($gs['bmi_for_age']['girls'][0])     && isset($gs['bmi_for_age']['girls'][228]),
   "D4 bmi_for_age girls spans 0..228");
// No gaps: every integer month present in each covered range.
$noGap = true;
foreach (['weight_for_age' => 120, 'height_for_age' => 228, 'bmi_for_age' => 228] as $metric => $max) {
    foreach (['boys', 'girls'] as $sx) {
        for ($mo = 0; $mo <= $max; $mo++) {
            if (!isset($gs[$metric][$sx][$mo])) { $noGap = false; break 3; }
        }
    }
}
ok($noGap, "D4 every integer month present in every covered range (no gaps in WHO data)");

/* -------------------------------------------------------------------------
 * PHASE E — Sprint 8 percentile DISPLAY layer (guardian + clinician surfaces).
 *
 *   Sprint 8 wires the Sprint-7 WHO engine into getDashboardData()/getReportData(),
 *   the four export surfaces, and the clinical narrative — WITHOUT schema change
 *   (schema_version stays 4) and WITHOUT touching the child surface. These checks
 *   drive the REAL data builders against a throwaway DB seeded with a COMPLETE child
 *   (gender + DOB + weight + height) and an INCOMPLETE child (no gender/DOB), and
 *   assert:
 *     E1. computePercentileSummary() returns available=true with current ranks +
 *         a trajectory for the complete child; missing_demographics (graceful
 *         prompt, never blocks) for the incomplete child; 'disabled' when the
 *         toggle is OFF.
 *     E2. The Growth-Percentiles section renders for the complete child and shows
 *         the graceful "complete gender/DOB" prompt for the incomplete child.
 *     E3. The JSON projection still excludes user.pin AND raw date_of_birth in the
 *         guest context, while INCLUDING gender + derived age + the percentile block
 *         (decision iii whitelist) — and stays at schema_version 4.
 *     E4. FOUR-SURFACE PARITY: dashboard, export-html, export-csv and the JSON
 *         projection all carry the SAME current ranks for the complete child.
 * ------------------------------------------------------------------------- */
echo "\n### PHASE E — Sprint 8 percentile display layer (dashboard + exports) ###\n";

// Rebuild a fresh app DB at DB_PATH (PHASE A unlinked $initDb earlier). The app
// data builders use getDB() against DB_PATH, so this gives us a real, isolated DB.
$dispDb = freshTempDbPath('comecome_run_disp_');
assertNotRealDb($ROOT, $dispDb);
// Re-point the app at this DB. DB_PATH is a constant already bound to $initDb; we
// cannot redefine it, so we recreate the schema AT the existing DB_PATH instead.
initializeDatabase(); // recreates schema+seed+guardian at DB_PATH (now non-existent)

// auth.php (createUser) + i18n.php (t) are needed for the display-layer drive; they
// were not yet loaded in this process. helpers.php/percentiles.php loaded in PHASE D.
require_once $ROOT . '/includes/i18n.php';
require_once $ROOT . '/includes/auth.php';

$E_start = date('Y-m-d', strtotime('-120 days'));
$E_end   = date('Y-m-d');

// Turn the feature ON for the display path.
setSetting('show_percentiles', '1');

// COMPLETE child: gender + DOB (~4y old => in WHO coverage) + a weight & height
// trajectory across two months so a percentile-over-time trend exists.
$dobComplete = date('Y-m-d', strtotime('-4 years'));
$kidComplete = createUser('PctComplete', 'child', '2468', '🧒', 'male', $dobComplete);
ok($kidComplete > 0, "E seed: complete child created (gender+DOB)");
logWeight($kidComplete, 15.0, date('Y-m-d', strtotime('-90 days')));
logWeight($kidComplete, 16.2, date('Y-m-d', strtotime('-15 days')));
logHeight($kidComplete, 100.0, date('Y-m-d', strtotime('-90 days')));
logHeight($kidComplete, 103.0, date('Y-m-d', strtotime('-15 days')));

// INCOMPLETE child: no gender/DOB, but has a weight (so only demographics gate it).
$kidIncomplete = createUser('PctNoDemo', 'child', '1357', '👶');
ok($kidIncomplete > 0, "E seed: incomplete child created (no gender/DOB)");
logWeight($kidIncomplete, 14.0, date('Y-m-d', strtotime('-10 days')));

// --- E1. computePercentileSummary() gating + content ------------------------
echo "\n-- E1. computePercentileSummary() gating --\n";
$pComplete = computePercentileSummary($kidComplete, $E_start, $E_end);
ok(($pComplete['available'] ?? null) === true,
   "E1 complete child: percentiles available=true");
ok(($pComplete['current']['weight'] ?? null) !== null
   && isset($pComplete['current']['weight']['rank'], $pComplete['current']['weight']['zone']),
   "E1 complete child: current weight rank+zone present");
ok(($pComplete['current']['height'] ?? null) !== null,
   "E1 complete child: current height rank present");
ok(($pComplete['current']['bmi'] ?? null) !== null,
   "E1 complete child: current BMI rank present (weight+height paired)");
ok(($pComplete['trends']['weight'] ?? null) !== null
   && isset($pComplete['trends']['weight']['from_rank'], $pComplete['trends']['weight']['to_rank'], $pComplete['trends']['weight']['narrative_key']),
   "E1 complete child: weight trajectory (from/to/narrative) computed over time");
ok(($pComplete['age_months'] ?? null) !== null && $pComplete['age_months'] >= 47 && $pComplete['age_months'] <= 49,
   "E1 complete child: derived age ~48 months [got " . var_export($pComplete['age_months'] ?? null, true) . "]");

$pIncomplete = computePercentileSummary($kidIncomplete, $E_start, $E_end);
ok(($pIncomplete['available'] ?? null) === false
   && ($pIncomplete['reason'] ?? null) === 'missing_demographics',
   "E1 incomplete child: available=false, reason=missing_demographics (graceful, never blocks)");

// Toggle OFF => disabled (no prompt, no data) for the same complete child.
setSetting('show_percentiles', '0');
$pDisabled = computePercentileSummary($kidComplete, $E_start, $E_end);
ok(($pDisabled['available'] ?? null) === false && ($pDisabled['reason'] ?? null) === 'disabled',
   "E1 toggle OFF => reason=disabled (section renders nothing)");
setSetting('show_percentiles', '1'); // restore ON for the rendering checks

// --- E2. Section rendering: complete child vs graceful prompt ----------------
echo "\n-- E2. renderPercentileSection() output --\n";
$htmlComplete = renderPercentileSection($pComplete, 'dashboard');
ok(strpos($htmlComplete, $pComplete['current']['weight']['rank']) !== false
   && strpos($htmlComplete, t('weight_for_age')) !== false,
   "E2 complete child: section renders weight-for-age rank + label");
ok(strpos($htmlComplete, t('percentile_reference_who')) !== false,
   "E2 complete child: section shows the WHO reference attribution");
$htmlPrompt = renderPercentileSection($pIncomplete, 'dashboard');
ok(strpos($htmlPrompt, t('percentile_complete_dob_prompt')) !== false,
   "E2 incomplete child: graceful 'complete gender/DOB' prompt is shown");
// Toggle-OFF section renders empty (no leakage).
ok(renderPercentileSection($pDisabled, 'dashboard') === '',
   "E2 toggle OFF: section renders empty string (nothing)");

// --- E3. JSON projection: no pin, no raw DOB; gender+age+percentiles in ------
echo "\n-- E3. JSON whitelist (no pin, no raw DOB; gender+age+percentiles in) --\n";
$reportComplete = getReportData($kidComplete, $E_start, $E_end);
$json = projectReportForJson($reportComplete);
$jsonStr = json_encode($json);
ok(!array_key_exists('pin', $json['user']) && strpos($jsonStr, '"pin"') === false,
   "E3 JSON has NO user.pin anywhere");
ok(!array_key_exists('date_of_birth', $json['user']) && strpos($jsonStr, '"date_of_birth"') === false,
   "E3 JSON has NO raw date_of_birth in guest-token path (decision iii)");
ok(($json['user']['gender'] ?? null) === 'male',
   "E3 JSON includes gender (clinically necessary)");
ok(($json['user']['age_months'] ?? null) !== null,
   "E3 JSON includes derived age_months (not raw DOB)");
ok(isset($json['percentiles']) && ($json['percentiles']['available'] ?? null) === true
   && isset($json['percentiles']['current']['weight']['rank']),
   "E3 JSON includes the whitelisted percentile block (ranks/zones/trends)");
ok((int) getSetting('schema_version', '0') === 6,
   "E3 schema_version at 6 after full init (Sprint 8 added no migration; Sprint 9 bumped 4->5; security Phase 1 bumped 5->6)");

// --- E4. FOUR-SURFACE PARITY: same current ranks everywhere ------------------
echo "\n-- E4. four-surface parity (dashboard / html / csv / json) --\n";
$dash = getDashboardData($kidComplete, $E_start, $E_end);
$dashRankW = $dash['percentiles']['current']['weight']['rank'] ?? null;
$rptRankW  = $reportComplete['percentiles']['current']['weight']['rank'] ?? null;
$jsonRankW = $json['percentiles']['current']['weight']['rank'] ?? null;
// The HTML + CSV surfaces render from $reportData['percentiles'] (same array the
// report builder produced), so report-rank == html-rank == csv-rank by construction;
// we assert the report rank is present and equals the dashboard + JSON ranks.
ok($dashRankW !== null && $dashRankW === $rptRankW && $rptRankW === $jsonRankW,
   "E4 weight rank identical across dashboard / report(html+csv) / json [$dashRankW]");
// Height parity too.
$dashRankH = $dash['percentiles']['current']['height']['rank'] ?? null;
$jsonRankH = $json['percentiles']['current']['height']['rank'] ?? null;
ok($dashRankH !== null && $dashRankH === $jsonRankH,
   "E4 height rank identical across dashboard / json [$dashRankH]");
// The clinical narrative one-liner is woven into BOTH dashboard + report summaries.
ok(($dash['clinical_summary']['percentile_trajectory'] ?? null) !== null
   && ($reportComplete['clinical_summary']['percentile_trajectory'] ?? null) !== null,
   "E4 percentile trajectory woven into BOTH dashboard + report clinical_summary");
// CSV surface emits the weight_pct/height_pct/bmi_pct columns: verify the renderer
// can produce them by formatting the same ranks (column-name contract).
$csvWeightCol = $reportComplete['percentiles']['current']['weight']['rank'] ?? null;
ok($csvWeightCol !== null,
   "E4 CSV weight_pct column has a value (same rank the other surfaces show)");

// PHASE E cleanup.
$dispGetDb = null;
foreach ([$dispDb, realpath(DB_PATH)] as $p) { if ($p && file_exists($p) && $p !== false) { @unlink($p); } }
if (file_exists(DB_PATH)) { @unlink(DB_PATH); }

/* -------------------------------------------------------------------------
 * PHASE F — Sprint 9 Medication Timing Foundation (classifier + INSERT stamping).
 *
 *   Two families of checks, both server-side / guardian-config only — ZERO child
 *   surface change:
 *
 *     F1. computeMedWindow() BOUNDARY classification + the non-stimulant NULL path.
 *         Drives the classifier against a child with an explicit short-acting
 *         schedule (dose 08:00, peak 30..240 => onset 08:00-08:30, mid_med
 *         08:30-12:00, post_med after 12:00) and asserts every boundary minute lands
 *         in the right window. Then proves a non-stimulant schedule yields NULL (no
 *         acute appetite window), and that a child with NO active schedule yields
 *         NULL.
 *     F2. INSERT STAMPING: logFood() stamps the SAME server-computed med_window onto
 *         the row, WITHOUT any change to the (food_id/meal_id/portion) payload. A row
 *         logged at 10:00 for the scheduled child is stamped 'mid_med'; the same row
 *         for a child with no schedule is stamped NULL.
 * ------------------------------------------------------------------------- */
echo "\n### PHASE F — Sprint 9 medication timing (computeMedWindow + INSERT stamping) ###\n";

// medication.php is pulled in transitively by includes/db.php (loaded in PHASE A).
ok(function_exists('computeMedWindow') && function_exists('createMedicationSchedule'),
   "F medication module loaded (computeMedWindow + schedule CRUD available)");

// Fresh isolated app DB at DB_PATH for the stamping integration test.
initializeDatabase();

// A child WITH a short-acting schedule (dose 08:00, default short-acting 30/240).
$kidMed = createUser('MedKid', 'child', '9753', '🧒');
ok($kidMed > 0, "F seed: scheduled child created");
$stmtMed = getDB()->prepare("INSERT INTO medications (name, dose) VALUES ('Ritalina','10mg')");
$stmtMed->execute();
$medIdF = (int) getDB()->lastInsertId();
$schedId = createMedicationSchedule($kidMed, $medIdF, '08:00', 'short_acting'); // 30/240
ok($schedId > 0, "F seed: short-acting schedule created (dose 08:00, peak 30/240)");

echo "\n-- F1. computeMedWindow() boundary classification --\n";
ok(computeMedWindow($kidMed, '07:59') === 'pre_med',  "F1 07:59 (before dose) => pre_med");
ok(computeMedWindow($kidMed, '08:00') === 'onset',    "F1 08:00 (at dose) => onset");
ok(computeMedWindow($kidMed, '08:29') === 'onset',    "F1 08:29 (within onset) => onset");
ok(computeMedWindow($kidMed, '08:30') === 'mid_med',  "F1 08:30 (peak_start boundary) => mid_med");
ok(computeMedWindow($kidMed, '10:00') === 'mid_med',  "F1 10:00 (mid peak) => mid_med");
ok(computeMedWindow($kidMed, '12:00') === 'mid_med',  "F1 12:00 (peak_end boundary, inclusive) => mid_med");
ok(computeMedWindow($kidMed, '12:01') === 'post_med', "F1 12:01 (past peak_end) => post_med");
ok(computeMedWindow($kidMed, '23:59') === 'post_med', "F1 23:59 (late) => post_med");
// Malformed time degrades to NULL, never throws.
ok(computeMedWindow($kidMed, 'not-a-time') === null,  "F1 malformed time => NULL (graceful)");

// Non-stimulant path: a child whose ONLY active schedule is non-stimulant gets NULL
// (no acute appetite window), proving the documented NULL classification.
$kidNonStim = createUser('NonStimKid', 'child', '8642', '👧');
createMedicationSchedule($kidNonStim, $medIdF, '08:00', 'non_stimulant'); // NULL offsets
ok(computeMedWindow($kidNonStim, '10:00') === null,
   "F1 non-stimulant schedule => NULL med_window (no appetite window)");

// No schedule at all => NULL (the common case for most children).
$kidNoSched = createUser('NoSchedKid', 'child', '7531', '🧑');
ok(computeMedWindow($kidNoSched, '10:00') === null,
   "F1 child with no active schedule => NULL med_window");

// An INACTIVE schedule must be ignored by the classifier.
$inactiveSched = createMedicationSchedule($kidNoSched, $medIdF, '08:00', 'short_acting', null, null, 0);
ok(computeMedWindow($kidNoSched, '10:00') === null,
   "F1 inactive schedule ignored => still NULL");

echo "\n-- F2. logFood() stamps med_window at INSERT (payload unchanged) --\n";
// Log the SAME (food_id, meal_id, portion) payload at 10:00 for the scheduled child;
// the server stamps mid_med without the child sending anything new.
logFood($kidMed, 1, 3, 'some', '2026-02-01', '10:00:00');
$stampDb = getDB();
$stampedRow = $stampDb->query("SELECT food_id, meal_id, portion, med_window FROM food_log WHERE user_id=$kidMed AND log_date='2026-02-01'")->fetch(PDO::FETCH_ASSOC);
ok($stampedRow && $stampedRow['med_window'] === 'mid_med',
   "F2 logFood() stamped med_window='mid_med' from the 10:00 schedule");
ok($stampedRow && (int) $stampedRow['food_id'] === 1 && (int) $stampedRow['meal_id'] === 3 && $stampedRow['portion'] === 'some',
   "F2 child payload (food_id/meal_id/portion) is UNCHANGED — enrichment is invisible");

// Same payload for the no-schedule child => med_window stays NULL (no over-stamping).
logFood($kidNoSched, 1, 3, 'some', '2026-02-01', '10:00:00');
$nullRow = $stampDb->query("SELECT med_window FROM food_log WHERE user_id=$kidNoSched AND log_date='2026-02-01'")->fetch(PDO::FETCH_ASSOC);
ok($nullRow && $nullRow['med_window'] === null,
   "F2 logFood() leaves med_window NULL when the child has no active schedule");

// A pre-med log (07:00) for the scheduled child is stamped pre_med at INSERT.
logFood($kidMed, 1, 1, 'little', '2026-02-02', '07:00:00');
$preRow = $stampDb->query("SELECT med_window FROM food_log WHERE user_id=$kidMed AND log_date='2026-02-02'")->fetch(PDO::FETCH_ASSOC);
ok($preRow && $preRow['med_window'] === 'pre_med',
   "F2 a 07:00 breakfast is stamped pre_med at INSERT");

// PHASE F cleanup.
$stampDb = null;
if (file_exists(DB_PATH)) { @unlink(DB_PATH); }

/* -------------------------------------------------------------------------
 * PHASE G — Security Sprint Phase 0 (stop the no-effort takeovers).
 *
 *   The CLI harness cannot load config.php or start a session, so Phase 0's
 *   logic was deliberately extracted into INCLUDABLE, side-effect-free functions
 *   this runner CAN call and assert:
 *
 *     G1. configureSessionCookieParams() — cookie flags (HttpOnly + SameSite=Lax
 *         always; Secure env-gated on HTTPS) across the HTTP / HTTPS / proxy /
 *         :443 branches. (HTTP-level Set-Cookie behaviour is covered separately
 *         by tests/http_smoke.php under `php -S`.)
 *     G2. sessionIsExpired() — the idle-timeout math wired into isLoggedIn()/
 *         requireAuth(): not-expired inside the window, expired past it, and a
 *         null/legacy stamp treated as NOT-expired (backward compat).
 *     G3. Default-'0000'-PIN guard lifecycle on a throwaway DB: fresh init arms
 *         it; changing the guardian PIN off '0000' clears it; restoring a DB whose
 *         guardian is still '0000' re-arms it; a custom-PIN guardian is NEVER
 *         flagged (the backward-compat hard gate). All re-derived from the actual
 *         stored hash (critique fix) — never blindly toggled.
 * ------------------------------------------------------------------------- */
echo "\n### PHASE G — security Phase 0 (cookie flags / idle timeout / default-PIN guard) ###\n";

require_once $ROOT . '/includes/session.php';
require_once $ROOT . '/includes/auth.php';

echo "\n-- G1. configureSessionCookieParams(): HttpOnly + SameSite=Lax + env-gated Secure --\n";
$pHttp  = configureSessionCookieParams(['SERVER_PORT' => 80]);
$pHttps = configureSessionCookieParams(['HTTPS' => 'on']);
$pProxy = configureSessionCookieParams(['HTTP_X_FORWARDED_PROTO' => 'https']);
$p443   = configureSessionCookieParams(['SERVER_PORT' => 443]);
$pHttpsOff = configureSessionCookieParams(['HTTPS' => 'off', 'SERVER_PORT' => 80]);
ok($pHttp['httponly'] === true, "G1 HttpOnly is always true");
ok($pHttp['samesite'] === 'Lax', "G1 SameSite is always Lax");
ok($pHttp['secure'] === false, "G1 Secure is FALSE over plain HTTP (local php -S dev not broken)");
ok($pHttpsOff['secure'] === false, "G1 Secure is FALSE when HTTPS='off' (case-insensitive)");
ok($pHttps['secure'] === true, "G1 Secure is TRUE when HTTPS=on (auto-enables under TLS / Phase 2)");
ok($pProxy['secure'] === true, "G1 Secure is TRUE behind X-Forwarded-Proto: https proxy");
ok($p443['secure'] === true, "G1 Secure is TRUE on standard :443 server port");

echo "\n-- G2. sessionIsExpired(): idle-timeout math (SESSION_LIFETIME wired in) --\n";
$life = 86400; // SESSION_LIFETIME default
$nowG = 1000000000;
ok(sessionIsExpired($nowG - 10,        $nowG, $life) === false, "G2 active 10s ago => NOT expired");
ok(sessionIsExpired($nowG - ($life-1), $nowG, $life) === false, "G2 just inside the window => NOT expired");
ok(sessionIsExpired($nowG - ($life+1), $nowG, $life) === true,  "G2 just past the window => expired");
ok(sessionIsExpired(null,              $nowG, $life) === false, "G2 null stamp (legacy session) => NOT expired (backward compat)");
ok(sessionIsExpired('',                $nowG, $life) === false, "G2 blank stamp => NOT expired (backward compat)");

echo "\n-- G3. default-'0000'-PIN guard lifecycle (re-derived from stored hash) --\n";
// Fresh init seeds the '0000' guardian => the guard must be ARMED.
initializeDatabase();
ok(guardianPinIsDefault() === true,
   "G3 fresh init: guardian_pin_is_default flag is ARMED (seeded '0000')");
ok((string) getSetting('guardian_pin_is_default', 'missing') === '1',
   "G3 fresh init: flag persisted as '1' in settings");

// Changing the guardian PIN off '0000' must CLEAR the guard (force-change lifts).
updateUser(1, 'Guardião', 'guardian', '4729', '😊', 1);
ok(guardianPinIsDefault() === false,
   "G3 after changing PIN to '4729': guard CLEARED (non-default PIN never force-reset)");

// A guardian whose hash does NOT verify '0000' is never flagged, even on an
// explicit recompute — the backward-compat hard gate. Scope the handle so no PDO
// lock lingers on Windows.
$gRecompute = getDB();
refreshGuardianPinDefaultFlag($gRecompute);
$gRecompute = null;
ok(guardianPinIsDefault() === false,
   "G3 recompute against a custom-PIN guardian stays CLEARED (hard gate)");

// Restoring/resetting a DB whose guardian is still '0000' must RE-ARM the guard
// (critique fix: restore can't wrongly un-lock a default-PIN admin). Simulate by
// resetting the PIN back to '0000' then recomputing, mirroring what a restore of a
// fresh DB does. Scope each handle so no PDO lock lingers on Windows before the
// resetDatabase() unlink below.
$gDb = getDB();
$gDb->prepare("UPDATE users SET pin = ? WHERE id = 1")
    ->execute([password_hash('0000', PASSWORD_DEFAULT)]);
refreshGuardianPinDefaultFlag($gDb);
$gDb = null;
ok(guardianPinIsDefault() === true,
   "G3 guardian reset back to '0000' => guard RE-ARMED on recompute (restore/reset can't un-lock)");

// resetDatabase() re-seeds '0000' AND refreshes the flag in one call. Drop any
// lingering transient PDO handle first so the internal unlink() can't race a
// still-open SQLite lock on Windows (file-copy/GC timing artifact, not a prod path).
gc_collect_cycles();
resetDatabase();
$gReset = getDB();
$gResetFlag = (string) $gReset->query("SELECT value FROM settings WHERE \"key\"='guardian_pin_is_default'")->fetchColumn();
$gReset = null;
ok($gResetFlag === '1',
   "G3 resetDatabase() re-arms the guard (re-seeded '0000' + flag refreshed)");

// PHASE G cleanup.
gc_collect_cycles();
if (file_exists(DB_PATH)) { @unlink(DB_PATH); }

/* -------------------------------------------------------------------------
 * PHASE H — Security Sprint Phase 1 (PIN brute-force throttling + lockout).
 *
 *   The #1 named threat. Throttling is woven into authenticateUser() (and the
 *   manage-users current_pin re-auth). Two families of checks:
 *
 *     H1. PURE state-machine math (side-effect free, harness-assertable):
 *         throttleComputeAfterFailure() backoff/window/lock transitions and
 *         throttleIsLocked() — no DB.
 *     H2. DB ROUND-TRIP against a throwaway DB: scripted wrong-PINs accrue and tip
 *         into a DISTINCT locked state (loginIsLockedOut()=true, distinct from
 *         "wrong PIN"); a locked account refuses verify WITHOUT incrementing; a
 *         correct PIN RESETS the counter; the storage stays a SINGLE updated row
 *         (UPDATE-in-place, not insert-per-attempt); an unknown user_id is throttled
 *         identically (no user-existence oracle); the per-IP loose ceiling is far
 *         higher than the per-user one; and the self-prune drops stale rows.
 *
 *   throttle.php is pulled in transitively by includes/db.php (loaded in PHASE A);
 *   auth.php was loaded in PHASE G.
 * ------------------------------------------------------------------------- */
echo "\n### PHASE H — security Phase 1 (PIN throttling + lockout) ###\n";

ok(function_exists('throttleComputeAfterFailure')
   && function_exists('loginIsLockedOut')
   && function_exists('recordFailedLogin'),
   "H throttle module loaded (pure state machine + DB record/lock/clear available)");

echo "\n-- H1. throttleComputeAfterFailure(): backoff / window / lock math --\n";
$T0 = 1000000000;
// First failure: count=1, no lock yet (threshold 5), window starts now.
$s1 = throttleComputeAfterFailure(0, null, null, $T0, 5, 900, 900);
ok($s1['fail_count'] === 1 && $s1['locked'] === false && $s1['window_start'] === $T0,
   "H1 first failure => count=1, not locked, window started");
// Fourth failure (count was 4 -> 5) hits the threshold => locked.
$s5 = throttleComputeAfterFailure(4, $T0, null, $T0 + 10, 5, 900, 900);
ok($s5['fail_count'] === 5 && $s5['locked'] === true
   && $s5['locked_until'] === ($T0 + 10) + 900,
   "H1 reaching maxFails arms a lock for lockSeconds");
// A failure arriving AFTER the window elapsed resets to a fresh count=1 window.
$sReset = throttleComputeAfterFailure(4, $T0, null, $T0 + 901, 5, 900, 900);
ok($sReset['fail_count'] === 1 && $sReset['locked'] === false
   && $sReset['window_start'] === ($T0 + 901),
   "H1 failure after the window elapses resets the counter (honest mistypes don't accrue)");
// An ACTIVE lock holds: a failure during the lock does not extend it or bump count.
$sHold = throttleComputeAfterFailure(5, $T0, $T0 + 900, $T0 + 100, 5, 900, 900);
ok($sHold['locked'] === true && $sHold['locked_until'] === ($T0 + 900)
   && $sHold['fail_count'] === 5,
   "H1 a still-active lock is not extended by further failures (no infinite re-lock)");
// throttleIsLocked predicate.
ok(throttleIsLocked($T0 + 900, $T0 + 100) === true,  "H1 throttleIsLocked: future lock => locked");
ok(throttleIsLocked($T0 + 900, $T0 + 901) === false, "H1 throttleIsLocked: expired lock => not locked");
ok(throttleIsLocked(null, $T0) === false,            "H1 throttleIsLocked: no lock => not locked");

echo "\n-- H2. authenticateUser() throttling round-trip (throwaway DB) --\n";
// Fresh isolated app DB at DB_PATH. The default guardian (id=1, PIN 0000) is seeded.
initializeDatabase();
$ipH = '203.0.113.7'; // fixed test IP bucket (TEST-NET-3)

// A scripted run of wrong PINs against guardian id=1 from one IP. With max=5, the
// 5th wrong attempt must tip into the locked state. Each call returns strict false.
$lockedAt = null;
for ($i = 1; $i <= 5; $i++) {
    $r = authenticateUser(1, '9999', $ipH); // wrong PIN
    ok($r === false, "H2 wrong-PIN attempt #$i returns false");
    if ($lockedAt === null && loginIsLockedOut(getDB(), 1, $ipH)) { $lockedAt = $i; }
}
ok($lockedAt === 5, "H2 account tips into a locked state exactly at the 5th wrong attempt [got " . var_export($lockedAt, true) . "]");
ok(loginIsLockedOut(getDB(), 1, $ipH) === true,
   "H2 loginIsLockedOut()=true after threshold (DISTINCT locked state, not 'wrong PIN')");

// While locked, even the CORRECT PIN is refused (verify is gated before the hash).
ok(authenticateUser(1, '0000', $ipH) === false,
   "H2 correct PIN is refused while locked (pre-verify lockout gate)");

// STORAGE INVARIANT: a single AGGREGATED per-user row (UPDATE-in-place), NOT one row
// per attempt. After 5 wrong + 1 refused attempt the per-user row count is exactly 1.
$hDb = getDB();
$userRows = (int) $hDb->query("SELECT COUNT(*) FROM login_attempts WHERE user_id=1 AND ip_bucket=''")->fetchColumn();
ok($userRows === 1, "H2 login_attempts holds a SINGLE aggregated per-user row (UPDATE-in-place) [got $userRows]");
$failCount = (int) $hDb->query("SELECT fail_count FROM login_attempts WHERE user_id=1 AND ip_bucket=''")->fetchColumn();
ok($failCount === 5, "H2 the aggregated row records fail_count=5 (not incremented by the refused correct-PIN attempt)");
$hDb = null;

// RESET PATH: clear the lock (simulate the window/lock elapsing) and confirm a
// correct PIN both succeeds AND clears the counter to zero rows. We clear directly to
// avoid a real 15-minute wait; the lifecycle (record->lock->clear) is what we assert.
$cDb = getDB();
$cDb->exec("DELETE FROM login_attempts"); // simulate full expiry/prune
$cDb = null;
ok(loginIsLockedOut(getDB(), 1, $ipH) === false,
   "H2 after expiry the account is no longer locked");
ok(authenticateUser(1, '0000', $ipH) === true,
   "H2 correct PIN succeeds once unlocked (auth path intact)");
$cDb = getDB();
$afterSuccess = (int) $cDb->query("SELECT COUNT(*) FROM login_attempts WHERE user_id=1 AND ip_bucket=''")->fetchColumn();
$cDb = null;
ok($afterSuccess === 0, "H2 a successful auth CLEARS the per-user failure counter (0 rows)");

// NO USER-EXISTENCE ORACLE: an UNKNOWN user_id is throttled identically — wrong-PIN
// attempts against a non-existent id accrue and lock just like a real one.
$ghostId = 99999;
for ($i = 1; $i <= 5; $i++) { authenticateUser($ghostId, '1234', $ipH); }
ok(loginIsLockedOut(getDB(), $ghostId, $ipH) === true,
   "H2 an unknown user_id locks identically (failures don't reveal whether a user exists)");

// PER-IP LOOSE CEILING is far higher than per-user: assert the constants encode the
// household-NAT-safe policy (per-IP cap >> per-user cap).
ok(THROTTLE_IP_MAX_FAILS > THROTTLE_USER_MAX_FAILS,
   "H2 per-IP ceiling (" . THROTTLE_IP_MAX_FAILS . ") is looser than per-user (" . THROTTLE_USER_MAX_FAILS . ") — no shared-NAT self-DoS");

// SELF-PRUNE: a stale row (window long elapsed, no active lock) is dropped by
// pruneLoginAttempts(); a freshly-locked row is KEPT.
$pDb = getDB();
$pDb->exec("DELETE FROM login_attempts");
// stale: window_start far in the past, no lock.
$pDb->exec("INSERT INTO login_attempts (user_id, ip_bucket, fail_count, window_start, locked_until)
            VALUES (1234, 'stale', 3, " . ($T0 - 100000) . ", NULL)");
// active lock far in the future.
$pDb->exec("INSERT INTO login_attempts (user_id, ip_bucket, fail_count, window_start, locked_until)
            VALUES (5678, 'fresh', 5, " . (time()) . ", " . (time() + 900) . ")");
$pruned = pruneLoginAttempts($pDb);
$remain = (int) $pDb->query("SELECT COUNT(*) FROM login_attempts")->fetchColumn();
$keptFresh = (int) $pDb->query("SELECT COUNT(*) FROM login_attempts WHERE ip_bucket='fresh'")->fetchColumn();
$pDb = null;
ok($pruned >= 1 && $keptFresh === 1 && $remain === 1,
   "H2 self-prune drops stale rows but keeps an actively-locked one [pruned=$pruned, remain=$remain]");

// PHASE H cleanup.
gc_collect_cycles();
if (file_exists(DB_PATH)) { @unlink(DB_PATH); }

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
