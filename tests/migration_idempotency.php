<?php
/**
 * Migration Idempotency Test (dependency-free CLI).
 *
 * Creates a THROWAWAY temp SQLite DB, lets the app create + migrate it,
 * captures schema_version + table list, runs migrateDatabase() again and
 * asserts it is a no-op (version unchanged, no errors, identical table set).
 *
 * NEVER touches db/data.db: it defines DB_PATH to a temp file and includes
 * only includes/db.php (not config.php, which hardcodes the real DB path).
 *
 * Exit code 0 on success, 1 on any failure.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);

// --- Throwaway temp DB ------------------------------------------------------
$tmpDb = tempnam(sys_get_temp_dir(), 'comecome_mig_') . '.db';
// tempnam created a 0-byte file at the prefix; remove it so the app sees a
// non-existent DB path and runs the full create path.
$tmpPrefix = substr($tmpDb, 0, -4);
if (file_exists($tmpPrefix)) { @unlink($tmpPrefix); }
if (file_exists($tmpDb)) { @unlink($tmpDb); }

define('DB_PATH', $tmpDb);
define('DB_SCHEMA', $root . '/db/schema.sql');
define('DB_SEED', $root . '/db/seed.sql');

// Guard: make sure we are NOT pointing at the real DB.
$realDb = realpath($root . '/db/data.db');
if ($realDb !== false && realpath_or_path(DB_PATH) === $realDb) {
    fwrite(STDERR, "ABORT: temp DB resolved to real data.db\n");
    exit(1);
}
function realpath_or_path($p) { $r = realpath($p); return $r === false ? $p : $r; }

require_once $root . '/includes/db.php';

$failures = [];
function check($cond, $msg) {
    global $failures;
    if ($cond) {
        echo "  [PASS] $msg\n";
    } else {
        echo "  [FAIL] $msg\n";
        $failures[] = $msg;
    }
}

echo "Temp DB: $tmpDb\n";
echo "DB exists before init: " . (file_exists(DB_PATH) ? 'yes' : 'no') . "\n";

// --- First create + migrate -------------------------------------------------
echo "\n== First create + migrate ==\n";
initializeDatabase(); // creates schema+seed, default guardian, then migrateDatabase()

$db = getDB();
function tableList($db) {
    $rows = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    return $rows;
}
function schemaVersion($db) {
    $stmt = $db->prepare("SELECT value FROM settings WHERE \"key\" = 'schema_version'");
    $stmt->execute();
    $r = $stmt->fetch();
    return $r ? (int)$r['value'] : null;
}

$ver1 = schemaVersion($db);
$tables1 = tableList($db);
echo "schema_version after first migrate: " . var_export($ver1, true) . "\n";
echo "tables (" . count($tables1) . "): " . implode(', ', $tables1) . "\n";

check($ver1 === 7, "schema_version is 7 after first migrate (Sprint 11 food_growth_tags)");
$mustHave = ['users','meals','foods','food_log','daily_checkin','weight_log','height_log','settings','guest_tokens','translations','sleep_log','sleep_interruptions','medication_schedules','login_attempts','food_growth_tags'];
foreach ($mustHave as $t) {
    check(in_array($t, $tables1, true), "table '$t' exists after first migrate");
}
// Sprint security Phase 1: login_attempts present with its aggregated-counter columns
// after the v6 migration.
$laCols1 = $db->query("PRAGMA table_info(login_attempts)")->fetchAll(PDO::FETCH_COLUMN, 1);
check(in_array('fail_count', $laCols1, true), "login_attempts.fail_count exists after first migrate (v6)");
check(in_array('locked_until', $laCols1, true), "login_attempts.locked_until exists after first migrate (v6)");
// Sprint 5: demographics columns present after the v3 migration.
$uCols1 = $db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
check(in_array('gender', $uCols1, true), "users.gender exists after first migrate (v3)");
check(in_array('date_of_birth', $uCols1, true), "users.date_of_birth exists after first migrate (v3)");
// Sprint 6: height_log present with its columns after the v4 migration.
$hCols1 = $db->query("PRAGMA table_info(height_log)")->fetchAll(PDO::FETCH_COLUMN, 1);
check(in_array('height_cm', $hCols1, true), "height_log.height_cm exists after first migrate (v4)");
// Sprint 9: food_log.med_window column present after the v5 migration.
$flCols1 = $db->query("PRAGMA table_info(food_log)")->fetchAll(PDO::FETCH_COLUMN, 1);
check(in_array('med_window', $flCols1, true), "food_log.med_window exists after first migrate (v5)");
// Default guardian present
$g = $db->query("SELECT id, name, type FROM users WHERE id = 1")->fetch();
check($g && $g['type'] === 'guardian', "default guardian id=1 created");

// --- Second migrate (idempotency) ------------------------------------------
echo "\n== Second migrate (must be no-op) ==\n";
$threw = false;
try {
    migrateDatabase($db);
} catch (Throwable $e) {
    $threw = true;
    echo "  EXCEPTION: " . $e->getMessage() . "\n";
}
check(!$threw, "second migrateDatabase() did not throw");

$ver2 = schemaVersion($db);
$tables2 = tableList($db);
echo "schema_version after second migrate: " . var_export($ver2, true) . "\n";
echo "tables (" . count($tables2) . "): " . implode(', ', $tables2) . "\n";

check($ver2 === $ver1, "schema_version unchanged after re-run ($ver1 -> $ver2)");
check($tables1 === $tables2, "table set unchanged after re-run");

// Run a 3rd time for good measure
$threw3 = false;
try { migrateDatabase($db); } catch (Throwable $e) { $threw3 = true; echo "  EXCEPTION(3): " . $e->getMessage() . "\n"; }
check(!$threw3, "third migrateDatabase() did not throw");
check(schemaVersion($db) === $ver1, "schema_version still $ver1 after third run");

// --- Cleanup ----------------------------------------------------------------
$db = null;
if (file_exists($tmpDb)) { @unlink($tmpDb); }
echo "\nTemp DB cleaned up: " . (file_exists($tmpDb) ? 'STILL EXISTS' : 'removed') . "\n";

// --- Verdict ----------------------------------------------------------------
echo "\n";
if (empty($failures)) {
    echo "MIGRATION_IDEMPOTENCY: PASS\n";
    exit(0);
} else {
    echo "MIGRATION_IDEMPOTENCY: FAIL (" . count($failures) . ")\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
