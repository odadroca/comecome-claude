<?php
/**
 * Database Functions
 */

// Sprint 9 — Medication Timing Foundation. logFood() stamps an invisible
// food_log.med_window computed SERVER-SIDE from the guardian-configured schedule, so
// the medication module (computeMedWindow + schedule CRUD) must be available here.
// Side-effect free to include; the function bodies only touch the DB when called.
require_once __DIR__ . '/medication.php';

// Sprint security Phase 1 — PIN brute-force throttling/lockout. Side-effect free to
// include (defines functions + constants only); the login_attempts UPSERTs only run
// when the auth path calls them. cleanExpiredTokens() piggybacks its self-prune.
require_once __DIR__ . '/throttle.php';

// Sprint security Phase 5 — scoped libsodium field encryption. Side-effect free to
// include (defines functions only; reads NO key at include time). encryptField()/
// decryptField() are strictly OPT-IN: with no key configured they pass values
// through unchanged (plaintext columns), so the data layer is zero-config safe.
// secrets.php (the key container) is required by config.php before this; require it
// here too so includes/db.php works when pulled in by the test harness directly.
require_once __DIR__ . '/secrets.php';
require_once __DIR__ . '/crypto.php';

/**
 * Get database connection
 */
function getDB() {
    try {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // Wait briefly for a lock instead of failing immediately, so
        // concurrent writers (SQLite single-writer) don't throw "database is
        // locked" under normal load. See roadmap "SQLite Concurrency" risk.
        $db->exec('PRAGMA busy_timeout = 5000');
        return $db;
    } catch (PDOException $e) {
        // Sprint security Phase 0 — fail-safe: never echo the driver/DSN/path to
        // the client (the original message leaked DB_PATH + SQLite driver detail).
        // Log the real cause for the operator; show the visitor a generic message.
        error_log('ComeCome getDB() failed: ' . $e->getMessage());
        http_response_code(500);
        die('A database error occurred. Please try again later.');
    }
}

/**
 * Initialize database with schema and seed data
 */
function initializeDatabase() {
    // Create database directory if it doesn't exist
    $dbDir = dirname(DB_PATH);
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true);
    }

    $db = getDB();

    // Execute schema
    $schema = file_get_contents(DB_SCHEMA);
    $db->exec($schema);

    // Execute seed data
    $seed = file_get_contents(DB_SEED);
    $db->exec($seed);

    // Create default guardian user. Sprint security Phase 5 — encrypt the seeded
    // name when a key is configured (encryptField is a no-op passthrough with no
    // key, so the zero-config seed stays the literal 'Guardião').
    $stmt = $db->prepare("INSERT OR IGNORE INTO users (id, name, type, pin) VALUES (?, ?, ?, ?)");
    $stmt->execute([1, encryptField('Guardião'), 'guardian', password_hash('0000', PASSWORD_DEFAULT)]);

    // Run migrations for existing databases
    migrateDatabase($db);

    // Sprint security Phase 0 — seed/refresh the default-PIN guard. On a fresh
    // first boot the guardian PIN is the well-known '0000', so this flips the flag
    // ON and the app force-redirects to change it before the dashboard is reachable.
    refreshGuardianPinDefaultFlag($db);

    return true;
}

/**
 * Sprint security Phase 0 — recompute the guardian default-PIN guard flag.
 *
 * Sets the settings key `guardian_pin_is_default` to '1' ONLY when the stored
 * guardian hash STILL verifies the well-known default '0000', otherwise '0'.
 * The value is ALWAYS re-derived from the actual stored hash — never assumed —
 * so it is correct after a fresh init, a reset, AND a restore/upload of an
 * arbitrary DB (critique fix: a restore/reset can't wrongly re/un-lock). A
 * guardian who already changed their PIN to a non-default value is NEVER
 * force-reset, because their hash will not verify '0000'.
 *
 * Evaluated against the lowest-id active guardian (the seeded admin, id=1 on a
 * fresh DB). If no guardian row exists, the flag is cleared ('0').
 */
function refreshGuardianPinDefaultFlag($db = null) {
    if ($db === null) { $db = getDB(); }

    $isDefault = '0';
    try {
        $stmt = $db->query(
            "SELECT pin FROM users WHERE type = 'guardian' AND active = 1 ORDER BY id LIMIT 1"
        );
        $row = $stmt ? $stmt->fetch() : false;
        if ($row && !empty($row['pin']) && password_verify('0000', $row['pin'])) {
            $isDefault = '1';
        }
    } catch (Exception $e) {
        // users/settings table may not exist yet during a partial init; treat as
        // "not default" so we never wrongly lock a half-built DB.
        $isDefault = '0';
    }

    try {
        $stmt = $db->prepare("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('guardian_pin_is_default', ?)");
        $stmt->execute([$isDefault]);
    } catch (Exception $e) {
        // settings table missing during a partial init — nothing to persist yet.
    }

    return $isDefault === '1';
}

/**
 * Sprint security Phase 0 — is the guardian still on the default '0000' PIN?
 *
 * Reads the cached `guardian_pin_is_default` flag (refreshed on init/reset/
 * restore and cleared the moment the PIN is changed). Defaults to NOT-default
 * ('0') when the flag is absent, so an upgraded existing install with a custom
 * PIN is never spuriously locked before the flag is first computed.
 */
function guardianPinIsDefault() {
    return getSetting('guardian_pin_is_default', '0') === '1';
}

/**
 * Run schema migrations for existing databases
 */
function migrateDatabase($db) {
    $version = 1;
    try {
        $stmt = $db->prepare("SELECT value FROM settings WHERE \"key\" = 'schema_version'");
        $stmt->execute();
        $result = $stmt->fetch();
        if ($result) $version = (int) $result['value'];
    } catch (Exception $e) {
        // settings table may not exist yet
    }

    if ($version < 2) {
        // Sprint 2: Add sleep_quality to daily_checkin
        try {
            $db->exec("ALTER TABLE daily_checkin ADD COLUMN sleep_quality INTEGER CHECK(sleep_quality BETWEEN 1 AND 5)");
        } catch (Exception $e) {
            // Column may already exist
        }

        // Sprint 2: Create sleep tables
        $db->exec("
            CREATE TABLE IF NOT EXISTS sleep_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                log_date DATE NOT NULL,
                sleep_type TEXT NOT NULL DEFAULT 'night' CHECK(sleep_type IN ('night', 'nap')),
                sleep_start TEXT,
                sleep_end TEXT,
                quality INTEGER CHECK(quality BETWEEN 1 AND 5),
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS sleep_interruptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sleep_log_id INTEGER NOT NULL,
                wake_time TEXT,
                back_to_sleep_time TEXT,
                reason TEXT,
                FOREIGN KEY (sleep_log_id) REFERENCES sleep_log(id) ON DELETE CASCADE
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_sleep_log_user_date ON sleep_log(user_id, log_date)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_sleep_interruptions_log ON sleep_interruptions(sleep_log_id)");

        $db->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('schema_version', '2')");
    }

    if ($version < 3) {
        // Sprint 5: Demographics Foundation — guardian-entered identity fields on
        // the users row. Both NULLABLE so existing children keep working untouched
        // (graceful degradation, decision iv). Privacy: guardian/clinician-side only,
        // never shown on any child page (decision iii). Each ALTER is wrapped in
        // try/catch like the Sprint-2 sleep_quality ALTER so a partially-migrated DB
        // (column already present) re-runs idempotently without throwing.
        try {
            $db->exec("ALTER TABLE users ADD COLUMN gender TEXT CHECK(gender IN ('male', 'female'))");
        } catch (Exception $e) {
            // Column may already exist
        }
        try {
            $db->exec("ALTER TABLE users ADD COLUMN date_of_birth DATE");
        } catch (Exception $e) {
            // Column may already exist
        }

        $db->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('schema_version', '3')");
    }

    if ($version < 4) {
        // Sprint 6: Growth Page Foundation — height_log. Mirrors weight_log exactly
        // (one optional measurement per child per day, upserted on UNIQUE(user_id,
        // log_date)). Guardian opt-in via show_percentiles; the child only ever sees
        // it when that toggle is ON (decision ii). CREATE TABLE IF NOT EXISTS + a
        // guarded index keep this block idempotent: a partially-migrated DB re-runs
        // without throwing. Mirrored in db/schema.sql so a fresh DB matches a
        // migrated one.
        $db->exec("
            CREATE TABLE IF NOT EXISTS height_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                height_cm REAL NOT NULL,
                log_date DATE NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_id, log_date),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_height_log_user_date ON height_log(user_id, log_date)");

        $db->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('schema_version', '4')");
    }

    if ($version < 5) {
        // Sprint 9: Medication Timing Foundation. Two additive pieces, both server-
        // side / guardian-configured — ZERO child-facing change.
        //
        //   (1) medication_schedules: per child + medication, the typical dose_time
        //       and the peak-effect window as minute offsets from the dose. med_type
        //       records the UI auto-fill choice (short/long/non-stimulant); the
        //       offsets are the source of truth the classifier reads. Offsets default
        //       to the documented 60/240 at the storage layer; the guardian UI
        //       auto-fills med-type defaults and allows per-child overrides.
        //   (2) food_log.med_window: invisible enrichment stamped SERVER-SIDE at
        //       INSERT by comparing log_time to the child's active schedules
        //       (computeMedWindow). CHECK-constrained to the four window names OR
        //       NULL (NULL = no active appetite-affecting schedule / non-stimulant).
        //
        // CREATE TABLE IF NOT EXISTS + a guarded index keep the table idempotent; the
        // ALTER is wrapped in try/catch like the Sprint-2/5 ALTERs so a partially-
        // migrated DB (column already present) re-runs without throwing. Both are
        // mirrored in db/schema.sql so a fresh DB matches a migrated one.
        $db->exec("
            CREATE TABLE IF NOT EXISTS medication_schedules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                medication_id INTEGER NOT NULL,
                dose_time TEXT NOT NULL,
                med_type TEXT DEFAULT 'short_acting',
                peak_start_offset INTEGER DEFAULT 60,
                peak_end_offset INTEGER DEFAULT 240,
                active INTEGER DEFAULT 1,
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY(medication_id) REFERENCES medications(id) ON DELETE CASCADE
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_medication_schedules_user ON medication_schedules(user_id, active)");

        try {
            $db->exec("ALTER TABLE food_log ADD COLUMN med_window TEXT CHECK(med_window IN ('pre_med','onset','mid_med','post_med') OR med_window IS NULL)");
        } catch (Exception $e) {
            // Column may already exist
        }

        $db->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('schema_version', '5')");
    }

    if ($version < 6) {
        // Sprint security Phase 1 — PIN brute-force throttling/lockout. ONE additive
        // table: a single AGGREGATED row per (user_id, ip_bucket) holding fail_count
        // + window_start + locked_until, UPDATE-in-place (critique fix: NOT one
        // insert per attempt, which would write-storm SQLite's single writer under
        // the very flood it defends against). The per-user row uses ip_bucket=''
        // (primary, tight counter); the loose per-IP ceiling is keyed user_id=0 so
        // it never collides with a real user's row. UNIQUE(user_id, ip_bucket) backs
        // the ON CONFLICT upsert. Default-safe (brand-new table, no existing rows to
        // migrate). Mirrored in db/schema.sql so a fresh DB matches a migrated one.
        $db->exec("
            CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                ip_bucket TEXT NOT NULL DEFAULT '',
                fail_count INTEGER NOT NULL DEFAULT 0,
                window_start INTEGER,
                locked_until INTEGER,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_id, ip_bucket)
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_login_attempts_locked ON login_attempts(locked_until)");

        // Sprint security Phase 3 — guest-token revocation. Additive is_revoked flag
        // on guest_tokens (default 0 = active), so a guardian can invalidate a shared
        // clinician link BEFORE it naturally expires. validateGuestToken() now checks
        // it. Wrapped in try/catch like the other additive ALTERs so a partially-
        // migrated DB (column already present) re-runs idempotently. Default-safe:
        // existing tokens get is_revoked=0 and keep working. Mirrored in db/schema.sql
        // so a fresh DB matches a migrated one. NO separate version bump — this is
        // ADDED to the existing v5->v6 block (the whole security sprint bumps once).
        try {
            $db->exec("ALTER TABLE guest_tokens ADD COLUMN is_revoked INTEGER NOT NULL DEFAULT 0");
        } catch (Exception $e) {
            // Column may already exist
        }

        $db->exec("INSERT OR REPLACE INTO settings (\"key\", value) VALUES ('schema_version', '6')");
    }

    // Sprint security Phase 3 — reconcile the additive guest_tokens.is_revoked column
    // for installs that ALREADY reached schema_version 6 under an INTERMEDIATE build
    // of this same security sprint (Phase 1 bumped 5->6 before Phase 3 added this
    // column to the v6 block). Such a DB would skip the gated block above (version is
    // not < 6) yet still lack the column that validateGuestToken() now references. We
    // therefore ensure the column exists whenever guest_tokens does — keyed on the
    // column's ABSENCE so it is a no-op on every subsequent request and never a second
    // schema bump. This is column-presence reconciliation, not a new migration: a
    // fresh DB (built from schema.sql) and a 5->6 migrate already have the column, so
    // this only fires for the narrow intermediate-build case. Mirrors the Phase 0
    // default-PIN backfill pattern.
    try {
        $hasGuestTokens = $db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='guest_tokens'"
        )->fetchColumn();
        if ($hasGuestTokens) {
            $hasRevoked = false;
            foreach ($db->query("PRAGMA table_info(guest_tokens)") as $col) {
                if (isset($col['name']) && $col['name'] === 'is_revoked') { $hasRevoked = true; break; }
            }
            if (!$hasRevoked) {
                $db->exec("ALTER TABLE guest_tokens ADD COLUMN is_revoked INTEGER NOT NULL DEFAULT 0");
            }
        }
    } catch (Exception $e) {
        // guest_tokens not present yet during an early/partial migrate — the gated v6
        // block / schema.sql adds the column once the table exists.
    }

    // Sprint security Phase 0 — one-time backfill of the default-'0000'-PIN guard
    // for EXISTING installs (which were created before this flag existed). This is
    // NOT a schema change (no new table/column, no version bump) — it only seeds a
    // settings row when absent, by re-deriving it from the actual stored guardian
    // hash. Keyed on the flag's absence so it is a no-op on every subsequent
    // request (migrateDatabase() runs per request) and never overrides a value the
    // app has since maintained (e.g. after a PIN change clears it).
    try {
        $flagStmt = $db->prepare("SELECT value FROM settings WHERE \"key\" = 'guardian_pin_is_default'");
        $flagStmt->execute();
        $hasFlag = $flagStmt->fetch();
        if ($hasFlag === false) {
            refreshGuardianPinDefaultFlag($db);
        }
    } catch (Exception $e) {
        // settings table not ready during an early/partial migrate — the explicit
        // refresh in initializeDatabase() will seed it once the schema exists.
    }
}

/**
 * Backup database.
 *
 * Sprint security Phase 5 (cheap win — at-rest data protection): the backup target
 * is now configurable so an operator can write backups ABOVE public_html rather
 * than into the web tree. The critique flagged that the old hard-coded
 * `__DIR__/../db/backup_*.db` writes a full PLAINTEXT copy of the DB inside the
 * served tree, protected only by the `.htaccess` FilesMatch deny — which evaporates
 * under nginx/litespeed. Resolution order:
 *
 *   1. define('BACKUP_DIR', '/abs/above/docroot/backups')  (config.local.php / the
 *      above-docroot COMECOME_CONFIG file) — RECOMMENDED for production.
 *   2. getenv('COMECOME_BACKUP_DIR') — same, via the environment.
 *   3. the in-tree db/ dir — the zero-config default (unchanged behaviour for a
 *      fresh download; the deploy doc tells operators to move it out + encrypt
 *      off-host).
 *
 * The directory is created if missing. Returns the backup path, or false on failure.
 */
function backupDatabase() {
    $dir = null;
    if (defined('BACKUP_DIR') && BACKUP_DIR !== '') {
        $dir = BACKUP_DIR;
    } else {
        $envDir = getenv('COMECOME_BACKUP_DIR');
        $dir = ($envDir !== false && $envDir !== '') ? $envDir : (__DIR__ . '/../db');
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    $backupPath = rtrim($dir, "/\\") . '/backup_' . date('Y-m-d_H-i-s') . '.db';
    if (copy(DB_PATH, $backupPath)) {
        return $backupPath;
    }
    return false;
}

/**
 * Restore database from backup
 */
function restoreDatabase($backupPath) {
    if (file_exists($backupPath)) {
        if (copy($backupPath, DB_PATH)) {
            // Sprint security Phase 0 — the restored DB may carry a different
            // guardian PIN (default OR custom). Re-derive the default-PIN guard
            // from the NEWLY-restored hash so a restore can't wrongly lock a
            // custom-PIN admin out, nor un-lock a default-PIN one (critique fix).
            refreshGuardianPinDefaultFlag(getDB());
            return true;
        }
        return false;
    }
    return false;
}

/**
 * Delete all data and reinitialize
 */
function resetDatabase() {
    if (file_exists(DB_PATH)) {
        // On Windows a just-closed SQLite handle can hold the file lock for a few
        // ms; retry briefly so a legitimate reset never aborts on a transient
        // "Resource temporarily unavailable". @-suppressed because a final failure
        // surfaces via the subsequent initializeDatabase() rather than a warning.
        for ($i = 0; $i < 5 && file_exists(DB_PATH); $i++) {
            if (@unlink(DB_PATH)) { break; }
            usleep(20000);
        }
    }
    // initializeDatabase() re-seeds the '0000' guardian AND calls
    // refreshGuardianPinDefaultFlag(), so a reset correctly re-locks behind the
    // default-PIN change form (critique fix: reset can't leave it un-locked).
    return initializeDatabase();
}

/**
 * Get setting value
 */
function getSetting($key, $default = null) {
    $db = getDB();
    $stmt = $db->prepare("SELECT value FROM settings WHERE \"key\" = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetch();
    return $result ? $result['value'] : $default;
}

/**
 * Set setting value
 */
function setSetting($key, $value) {
    $db = getDB();
    $stmt = $db->prepare("INSERT OR REPLACE INTO settings (\"key\", value) VALUES (?, ?)");
    return $stmt->execute([$key, $value]);
}

/**
 * Get current meal based on time of day
 */
function getCurrentMeal() {
    $db = getDB();
    $currentTime = date('H:i');

    $stmt = $db->prepare("
        SELECT * FROM meals
        WHERE active = 1
        AND time_start <= ?
        AND time_end >= ?
        ORDER BY sort_order
        LIMIT 1
    ");
    $stmt->execute([$currentTime, $currentTime]);

    return $stmt->fetch();
}

/**
 * Get foods for a specific meal (filtered by categories)
 */
function getFoodsForMeal($mealId) {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT DISTINCT f.*, fc.name_key as category_name_key
        FROM foods f
        JOIN food_categories fc ON f.category_id = fc.id
        JOIN meal_categories mc ON fc.id = mc.category_id
        WHERE mc.meal_id = ? AND f.active = 1 AND fc.active = 1
        ORDER BY f.sort_order, f.id
    ");
    $stmt->execute([$mealId]);

    return $stmt->fetchAll();
}

/**
 * Get user favorites
 */
function getUserFavorites($userId) {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT f.*, fc.name_key as category_name_key
        FROM foods f
        JOIN food_categories fc ON f.category_id = fc.id
        JOIN user_favorites uf ON f.id = uf.food_id
        WHERE uf.user_id = ? AND f.active = 1
        ORDER BY uf.created_at DESC
    ");
    $stmt->execute([$userId]);

    return $stmt->fetchAll();
}

/**
 * Toggle favorite
 */
function toggleFavorite($userId, $foodId) {
    $db = getDB();
    $db->beginTransaction();

    try {
        // Check if already favorite
        $stmt = $db->prepare("SELECT 1 FROM user_favorites WHERE user_id = ? AND food_id = ?");
        $stmt->execute([$userId, $foodId]);

        if ($stmt->fetch()) {
            // Remove favorite
            $stmt = $db->prepare("DELETE FROM user_favorites WHERE user_id = ? AND food_id = ?");
            $stmt->execute([$userId, $foodId]);
            $db->commit();
            return false;
        } else {
            // Add favorite
            $stmt = $db->prepare("INSERT INTO user_favorites (user_id, food_id) VALUES (?, ?)");
            $stmt->execute([$userId, $foodId]);
            $db->commit();
            return true;
        }
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Log food entry
 */
function logFood($userId, $foodId, $mealId, $portion, $logDate = null, $logTime = null) {
    $db = getDB();

    if (!$logDate) $logDate = date('Y-m-d');
    if (!$logTime) $logTime = date('H:i:s');

    // Sprint 9: invisible food-log enrichment. Compute the medication window
    // SERVER-SIDE from the child's guardian-configured active schedules and the log
    // time. NULL when the child has no active appetite-affecting schedule (the common
    // case) — a perfectly valid value for the CHECK-constrained column. The child
    // request payload is NOT involved: med_window is derived purely from $userId +
    // $logTime, so there is ZERO child-facing change.
    $medWindow = computeMedWindow($userId, $logTime);

    $stmt = $db->prepare("
        INSERT INTO food_log (user_id, food_id, meal_id, portion, log_date, log_time, med_window)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    return $stmt->execute([$userId, $foodId, $mealId, $portion, $logDate, $logTime, $medWindow]);
}

/**
 * Get food log for a specific date
 */
function getFoodLogByDate($userId, $date) {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT fl.*, f.name_key as food_name_key, f.emoji,
               m.name_key as meal_name_key, fc.name_key as category_name_key
        FROM food_log fl
        JOIN foods f ON fl.food_id = f.id
        JOIN meals m ON fl.meal_id = m.id
        JOIN food_categories fc ON f.category_id = fc.id
        WHERE fl.user_id = ? AND fl.log_date = ?
        ORDER BY fl.log_time DESC
    ");
    $stmt->execute([$userId, $date]);

    return $stmt->fetchAll();
}

/**
 * Save or update daily check-in
 */
function saveCheckIn($userId, $date, $appetite, $mood, $medication, $notes = null, $sleepQuality = null) {
    $db = getDB();

    // Sprint security Phase 5 — encrypt the free-text notes on write (no-op
    // passthrough when no key is configured). appetite/mood/medication/sleep stay
    // cleartext: they are numeric/coded and feed dashboard aggregations/correlations.
    $notes = encryptField($notes);

    $stmt = $db->prepare("
        INSERT OR REPLACE INTO daily_checkin
        (user_id, check_date, appetite_level, mood_level, medication_taken, notes, sleep_quality)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    return $stmt->execute([$userId, $date, $appetite, $mood, $medication, $notes, $sleepQuality]);
}

/**
 * Get check-in for a specific date
 */
function getCheckIn($userId, $date) {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT * FROM daily_checkin
        WHERE user_id = ? AND check_date = ?
    ");
    $stmt->execute([$userId, $date]);

    // Sprint security Phase 5 — decrypt-on-read: transparently returns plaintext
    // for not-yet-backfilled rows (no sentinel) AND decrypted text for encrypted
    // rows. This is the central accessor the child check-in + history pages use, so
    // the child sees their own notes with ZERO visible change.
    return decryptRowFields($stmt->fetch(), ['notes']);
}

/**
 * Log weight
 */
function logWeight($userId, $weight, $date = null) {
    $db = getDB();

    if (!$date) $date = date('Y-m-d');

    $stmt = $db->prepare("
        INSERT OR REPLACE INTO weight_log (user_id, weight_kg, log_date)
        VALUES (?, ?, ?)
    ");

    return $stmt->execute([$userId, $weight, $date]);
}

/**
 * Get weight history
 */
function getWeightHistory($userId, $days = null) {
    $db = getDB();

    $sql = "SELECT * FROM weight_log WHERE user_id = ? ORDER BY log_date DESC";
    if ($days) {
        $sql .= " LIMIT ?";
    }

    $stmt = $db->prepare($sql);
    if ($days) {
        $stmt->execute([$userId, $days]);
    } else {
        $stmt->execute([$userId]);
    }

    return $stmt->fetchAll();
}

/**
 * Log height (Sprint 6 — Growth Page Foundation).
 *
 * Mirrors logWeight() exactly: one height per child per day, upserted on the
 * UNIQUE(user_id, log_date) constraint so re-logging the same day overwrites
 * rather than duplicating. height_cm is stored as REAL (cm).
 */
function logHeight($userId, $heightCm, $date = null) {
    $db = getDB();

    if (!$date) $date = date('Y-m-d');

    $stmt = $db->prepare("
        INSERT OR REPLACE INTO height_log (user_id, height_cm, log_date)
        VALUES (?, ?, ?)
    ");

    return $stmt->execute([$userId, $heightCm, $date]);
}

/**
 * Get height history (Sprint 6 — Growth Page Foundation).
 *
 * Mirrors getWeightHistory(): newest-first; optional $days arg caps the row count.
 */
function getHeightHistory($userId, $days = null) {
    $db = getDB();

    $sql = "SELECT * FROM height_log WHERE user_id = ? ORDER BY log_date DESC";
    if ($days) {
        $sql .= " LIMIT ?";
    }

    $stmt = $db->prepare($sql);
    if ($days) {
        $stmt->execute([$userId, $days]);
    } else {
        $stmt->execute([$userId]);
    }

    return $stmt->fetchAll();
}

/**
 * Generate guest token
 */
function generateGuestToken($userId, $hours = 168) {
    $db = getDB();

    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + ($hours * 3600));

    $stmt = $db->prepare("
        INSERT INTO guest_tokens (token, user_id, expires_at)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$token, $userId, $expiresAt]);

    return $token;
}

/**
 * Validate guest token
 *
 * Sprint security Phase 3 — a token is valid only when it is NOT expired AND NOT
 * revoked. is_revoked is checked with `COALESCE(is_revoked, 0) = 0` so a row that
 * predates the v6 column (NULL) is treated as active — backward compatible with any
 * token created before the migration ran.
 */
function validateGuestToken($token) {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT user_id FROM guest_tokens
        WHERE token = ?
          AND expires_at > datetime('now')
          AND COALESCE(is_revoked, 0) = 0
    ");
    $stmt->execute([$token]);

    $result = $stmt->fetch();
    return $result ? $result['user_id'] : false;
}

/**
 * Sprint security Phase 3 — list a child's guest tokens for the guardian revoke UI.
 * Newest first. Includes the revoked + expired state so the UI can show status and
 * offer a revoke control on the still-active ones.
 */
function getGuestTokensForUser($userId) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT token, expires_at, created_at, COALESCE(is_revoked, 0) AS is_revoked,
               (expires_at > datetime('now')) AS not_expired
        FROM guest_tokens
        WHERE user_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Sprint security Phase 3 — revoke a single guest token (set is_revoked=1). After
 * this, validateGuestToken() refuses it even though it has not yet expired. Returns
 * true if a row was changed. Idempotent: revoking an already-revoked token is a
 * harmless no-op.
 */
function revokeGuestToken($token) {
    $db = getDB();
    // Scope the UPDATE to still-active rows so re-revoking an already-revoked token
    // matches 0 rows (a harmless no-op) rather than re-touching it. (SQLite's
    // rowCount() reports rows MATCHED by the WHERE, so the COALESCE guard is what
    // makes the second revoke a true no-op.)
    $stmt = $db->prepare(
        "UPDATE guest_tokens SET is_revoked = 1 WHERE token = ? AND COALESCE(is_revoked, 0) = 0"
    );
    $stmt->execute([$token]);
    return $stmt->rowCount() > 0;
}

/**
 * Sprint security Phase 3 — revoke ALL of a child's currently-active guest tokens
 * (revoke-all control). Used by the "regenerate" path: revoke every outstanding link
 * for the child, then issue a fresh one. Returns the number of tokens revoked.
 */
function revokeAllGuestTokensForUser($userId) {
    $db = getDB();
    $stmt = $db->prepare("
        UPDATE guest_tokens SET is_revoked = 1
        WHERE user_id = ? AND COALESCE(is_revoked, 0) = 0
    ");
    $stmt->execute([$userId]);
    return $stmt->rowCount();
}

/**
 * Clean expired tokens
 */
function cleanExpiredTokens() {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM guest_tokens WHERE expires_at < datetime('now')");
    $result = $stmt->execute();

    // Sprint security Phase 1 — piggyback the login_attempts self-prune here (no
    // cron): drop rows whose window has fully elapsed and whose lock has expired, so
    // the throttle table stays tiny. Guarded so an install mid-migration (table not
    // yet created) never fatals the per-request cleanup.
    if (function_exists('pruneLoginAttempts')) {
        try { pruneLoginAttempts($db); } catch (Exception $e) { /* table may predate v6 */ }
    }

    return $result;
}

/**
 * Save sleep log entry
 */
function saveSleepLog($userId, $date, $type, $start = null, $end = null, $quality = null, $notes = null) {
    $db = getDB();

    $stmt = $db->prepare("
        INSERT INTO sleep_log (user_id, log_date, sleep_type, sleep_start, sleep_end, quality, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([$userId, $date, $type, $start, $end, $quality, $notes]);
    return $db->lastInsertId();
}

/**
 * Update sleep log entry
 */
function updateSleepLog($id, $start = null, $end = null, $quality = null, $notes = null) {
    $db = getDB();

    $stmt = $db->prepare("
        UPDATE sleep_log SET sleep_start = ?, sleep_end = ?, quality = ?, notes = ?
        WHERE id = ?
    ");

    return $stmt->execute([$start, $end, $quality, $notes, $id]);
}

/**
 * Delete sleep log entry and its interruptions
 */
function deleteSleepLog($id) {
    $db = getDB();
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM sleep_interruptions WHERE sleep_log_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM sleep_log WHERE id = ?")->execute([$id]);
        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Save sleep interruption
 */
function saveSleepInterruption($sleepLogId, $wakeTime, $backToSleepTime = null, $reason = null) {
    $db = getDB();

    $stmt = $db->prepare("
        INSERT INTO sleep_interruptions (sleep_log_id, wake_time, back_to_sleep_time, reason)
        VALUES (?, ?, ?, ?)
    ");

    $stmt->execute([$sleepLogId, $wakeTime, $backToSleepTime, $reason]);
    return $db->lastInsertId();
}

/**
 * Delete sleep interruption
 */
function deleteSleepInterruption($id) {
    $db = getDB();
    return $db->prepare("DELETE FROM sleep_interruptions WHERE id = ?")->execute([$id]);
}

/**
 * Get sleep data for a specific date (entries + interruptions)
 */
function getSleepByDate($userId, $date) {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT * FROM sleep_log
        WHERE user_id = ? AND log_date = ?
        ORDER BY sleep_type DESC, sleep_start
    ");
    $stmt->execute([$userId, $date]);
    $entries = $stmt->fetchAll();

    foreach ($entries as &$entry) {
        $stmt = $db->prepare("
            SELECT * FROM sleep_interruptions
            WHERE sleep_log_id = ?
            ORDER BY wake_time
        ");
        $stmt->execute([$entry['id']]);
        $entry['interruptions'] = $stmt->fetchAll();
    }
    unset($entry);

    return $entries;
}

/**
 * Get sleep history for dashboard/reports
 */
function getSleepHistory($userId, $startDate, $endDate) {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT
            sl.log_date,
            sl.sleep_type,
            sl.sleep_start,
            sl.sleep_end,
            sl.quality,
            (SELECT COUNT(*) FROM sleep_interruptions si WHERE si.sleep_log_id = sl.id) as interruption_count
        FROM sleep_log sl
        WHERE sl.user_id = ? AND sl.log_date BETWEEN ? AND ?
        ORDER BY sl.log_date DESC, sl.sleep_type DESC
    ");
    $stmt->execute([$userId, $startDate, $endDate]);

    return $stmt->fetchAll();
}

/**
 * Get sleep quality history from daily check-ins
 */
function getSleepQualityHistory($userId, $startDate, $endDate) {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT check_date, sleep_quality
        FROM daily_checkin
        WHERE user_id = ? AND check_date BETWEEN ? AND ? AND sleep_quality IS NOT NULL
        ORDER BY check_date
    ");
    $stmt->execute([$userId, $startDate, $endDate]);

    return $stmt->fetchAll();
}
