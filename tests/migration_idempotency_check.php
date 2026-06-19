<?php
/**
 * ComeCome — standalone migration-idempotency check (regression utility).
 * ======================================================================
 *
 * A small, reusable, dependency-free check that exercises the FULL forward
 * migration (v1 -> current) against a THROWAWAY temp SQLite DB, captures the
 * resulting schema_version + table set, and proves a second migrate run is a
 * genuine NO-OP (version unchanged, table set unchanged, no throw).
 *
 * It deliberately NEVER touches db/data.db: it allocates its own temp file under
 * the system temp dir and hard-aborts if that path ever resolves to the real DB.
 *
 * USAGE:  php tests/migration_idempotency_check.php
 * EXIT:   0 = idempotent + at expected schema_version; non-zero = a failure.
 *
 * This complements tests/run.php PHASE A2 (which folds the same assertions into
 * the single regression entry point) with a focused, independently-runnable probe
 * an operator can point at a fresh temp DB during an end-of-phase regression.
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

/** The schema_version the current build must converge on. */
const EXPECTED_SCHEMA_VERSION = 6;

/**
 * The exact table set a FRESH initializeDatabase() (full schema.sql + seed.sql)
 * must hold at the current schema_version. This is the canonical 19-table set.
 */
$EXPECTED_TABLES = [
    'daily_checkin', 'food_categories', 'food_log', 'foods', 'guest_tokens',
    'height_log', 'login_attempts', 'meal_categories', 'meals', 'medication_schedules',
    'medications', 'settings', 'sleep_interruptions', 'sleep_log', 'translations',
    'user_favorites', 'user_medications', 'users', 'weight_log',
];

/**
 * The subset of tables the FORWARD MIGRATION (v1 -> v6) creates on top of the
 * hand-built v1 fixture. A v1-fixture-forward-migrate does NOT reproduce the full
 * 19-table set — schema.sql/seed.sql base tables (foods, meals, categories,
 * translations, weight_log, medications, ...) are only created by a fresh
 * initializeDatabase(), NOT by migrateDatabase(). So against the fixture we assert
 * the migration-ADDED tables appear; the canonical exact-set assertion runs
 * separately against a fresh init below.
 */
$MIGRATION_ADDED_TABLES = [
    'sleep_log', 'sleep_interruptions', 'height_log',
    'medication_schedules', 'login_attempts',
];

echo "==========================================================\n";
echo " ComeCome migration-idempotency check (throwaway temp DB)\n";
echo "==========================================================\n";

// --- Allocate a throwaway temp DB path (NEVER db/data.db) -------------------
$tmp = tempnam(sys_get_temp_dir(), 'comecome_migidem_') . '.db';
foreach ([substr($tmp, 0, -4), $tmp] as $p) { if (file_exists($p)) { @unlink($p); } }

$realDb = realpath($ROOT . '/db/data.db');
$resolved = realpath(dirname($tmp)) . DIRECTORY_SEPARATOR . basename($tmp);
if ($realDb !== false && $resolved === $realDb) {
    fwrite(STDERR, "ABORT: temp DB resolved to real data.db ($tmp)\n");
    exit(2);
}
ok(true, "throwaway temp DB allocated: $tmp (NOT db/data.db)");

// We point the app constants at the temp DB, then include db.php so getDB()/
// initializeDatabase()/migrateDatabase() operate on it.
define('DB_PATH', $tmp);
define('DB_SCHEMA', $ROOT . '/db/schema.sql');
define('DB_SEED', $ROOT . '/db/seed.sql');
define('APP_NAME', 'ComeCome');
define('APP_VERSION', 'test');
define('DEFAULT_LOCALE', 'pt');
define('LOCALES_PATH', $ROOT . '/locales');
define('SESSION_LIFETIME', 86400);
define('GUEST_TOKEN_LIFETIME', 604800);
date_default_timezone_set('Europe/Lisbon');

require_once $ROOT . '/includes/db.php';

function listTables(PDO $db) {
    return $db->query(
        "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);
}
function schemaVersion(PDO $db) {
    $r = $db->query("SELECT value FROM settings WHERE \"key\"='schema_version'")->fetch(PDO::FETCH_ASSOC);
    return $r ? (int) $r['value'] : null;
}

// --- 1. CREATE + MIGRATE: build a v1 fixture, migrate forward ----------------
// Build a genuine pre-Sprint-2 (v1) DB by hand so migrateDatabase() has real
// forward work, then migrate. (initializeDatabase() would already be at current.)
echo "\n-- 1. create v1 fixture + forward-migrate to current --\n";
$db = new PDO('sqlite:' . $tmp);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// Minimal v1 surface the migration touches.
$db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, type TEXT NOT NULL CHECK(type IN ('child','guardian')), pin TEXT, avatar_emoji TEXT DEFAULT '😊', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, active INTEGER DEFAULT 1)");
$db->exec("CREATE TABLE daily_checkin (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, check_date DATE NOT NULL, appetite_level INTEGER, mood_level INTEGER, medication_taken INTEGER DEFAULT 0, notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE(user_id, check_date))");
$db->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)");
$db->exec("CREATE TABLE food_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, food_id INTEGER NOT NULL, meal_id INTEGER NOT NULL, portion TEXT NOT NULL, log_date DATE NOT NULL, log_time TIME NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
// guest_tokens must pre-exist so the v6 is_revoked ALTER has a real target.
$db->exec("CREATE TABLE guest_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, token TEXT NOT NULL UNIQUE, user_id INTEGER NOT NULL, expires_at TIMESTAMP NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$db->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('schema_version','1')");
$db = null;

$m = new PDO('sqlite:' . $tmp);
$m->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ok(schemaVersion($m) === 1, "fixture starts at schema_version=1");

$threw = false;
try { migrateDatabase($m); } catch (Throwable $e) { $threw = true; echo "    EXCEPTION: " . $e->getMessage() . "\n"; }
ok(!$threw, "forward migrateDatabase() did not throw");

// --- 2. CAPTURE schema_version + table set -----------------------------------
echo "\n-- 2. capture post-migrate state --\n";
$verAfterMigrate = schemaVersion($m);
$tablesAfterMigrate = listTables($m); sort($tablesAfterMigrate);
ok($verAfterMigrate === EXPECTED_SCHEMA_VERSION,
   "schema_version is " . EXPECTED_SCHEMA_VERSION . " after migrate [got " . var_export($verAfterMigrate, true) . "]");
// The forward migration must have ADDED every v2..v6 table on top of the v1 fixture.
$missingAdded = array_values(array_diff($MIGRATION_ADDED_TABLES, $tablesAfterMigrate));
ok($missingAdded === [],
   "forward migration created all v2..v6 tables (" . implode(', ', $MIGRATION_ADDED_TABLES) . ")"
   . ($missingAdded ? " [missing: " . implode(', ', $missingAdded) . "]" : ""));
echo "    schema_version = $verAfterMigrate\n";
echo "    tables (" . count($tablesAfterMigrate) . "): " . implode(', ', $tablesAfterMigrate) . "\n";
// Phase-3 column must be present.
$hasRevoked = false;
foreach ($m->query("PRAGMA table_info(guest_tokens)") as $c) { if ($c['name'] === 'is_revoked') { $hasRevoked = true; } }
ok($hasRevoked, "guest_tokens.is_revoked present after migrate (Phase 3 additive column)");

// --- 3. RE-RUN = NO-OP -------------------------------------------------------
echo "\n-- 3. re-run migrate twice => no-op (idempotent) --\n";
$threwAgain = false;
try { migrateDatabase($m); migrateDatabase($m); }
catch (Throwable $e) { $threwAgain = true; echo "    EXCEPTION(re-run): " . $e->getMessage() . "\n"; }
ok(!$threwAgain, "re-running migrateDatabase() twice did not throw");

$verAfterReRun = schemaVersion($m);
$tablesAfterReRun = listTables($m); sort($tablesAfterReRun);
ok($verAfterReRun === $verAfterMigrate,
   "schema_version unchanged on re-run ($verAfterMigrate -> $verAfterReRun)");
ok($tablesAfterReRun === $tablesAfterMigrate,
   "table set unchanged on re-run (true no-op migration)");
$m = null;

// --- 4. CANONICAL exact-table-set against a FRESH init -----------------------
// A fresh initializeDatabase() (full schema.sql + seed.sql) is what produces the
// canonical 19-table set. We rebuild at the SAME DB_PATH (the fixture file, now
// unlinked) so getDB()/initializeDatabase() — which read DB_PATH — operate on a
// fresh throwaway DB and we can assert the exact set + a no-op re-init.
echo "\n-- 4. fresh initializeDatabase(): canonical 19-table set + idempotent --\n";
gc_collect_cycles();
if (file_exists($tmp)) { @unlink($tmp); }
$threwInit = false;
try { initializeDatabase(); } catch (Throwable $e) { $threwInit = true; echo "    EXCEPTION(init): " . $e->getMessage() . "\n"; }
ok(!$threwInit, "fresh initializeDatabase() did not throw");
$fresh = getDB();
$freshTables = listTables($fresh); sort($freshTables);
$expSorted = $EXPECTED_TABLES; sort($expSorted);
ok($freshTables === $expSorted,
   "fresh init table set EXACTLY matches the canonical " . count($EXPECTED_TABLES) . "-table set");
ok(schemaVersion($fresh) === EXPECTED_SCHEMA_VERSION,
   "fresh init reaches schema_version " . EXPECTED_SCHEMA_VERSION);
echo "    fresh tables (" . count($freshTables) . "): " . implode(', ', $freshTables) . "\n";
// Re-migrate a fresh, already-current DB => no-op.
$threwReInit = false;
try { migrateDatabase($fresh); } catch (Throwable $e) { $threwReInit = true; }
ok(!$threwReInit, "migrate on an already-current fresh DB is a no-op (no throw)");
$freshTables2 = listTables($fresh); sort($freshTables2);
ok($freshTables2 === $freshTables, "fresh DB table set unchanged after a redundant migrate");
$fresh = null;

// --- cleanup ----------------------------------------------------------------
gc_collect_cycles();
if (file_exists($tmp)) { @unlink($tmp); }

echo "\n==========================================================\n";
echo " migration-idempotency check: $PASS passed, " . count($FAIL) . " failed\n";
echo "==========================================================\n";
if (empty($FAIL)) { echo "MIG-IDEM-CHECK: PASS\n"; exit(0); }
echo "MIG-IDEM-CHECK: FAIL\n";
foreach ($FAIL as $f) { echo "  - $f\n"; }
exit(1);
