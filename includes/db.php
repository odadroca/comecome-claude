<?php
/**
 * Database Functions
 */

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
        die('Database connection failed: ' . $e->getMessage());
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

    // Create default guardian user
    $stmt = $db->prepare("INSERT OR IGNORE INTO users (id, name, type, pin) VALUES (?, ?, ?, ?)");
    $stmt->execute([1, 'Guardião', 'guardian', password_hash('0000', PASSWORD_DEFAULT)]);

    // Run migrations for existing databases
    migrateDatabase($db);

    return true;
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
}

/**
 * Backup database
 */
function backupDatabase() {
    $backupPath = __DIR__ . '/../db/backup_' . date('Y-m-d_H-i-s') . '.db';
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
        return copy($backupPath, DB_PATH);
    }
    return false;
}

/**
 * Delete all data and reinitialize
 */
function resetDatabase() {
    if (file_exists(DB_PATH)) {
        unlink(DB_PATH);
    }
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

    $stmt = $db->prepare("
        INSERT INTO food_log (user_id, food_id, meal_id, portion, log_date, log_time)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    return $stmt->execute([$userId, $foodId, $mealId, $portion, $logDate, $logTime]);
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

    return $stmt->fetch();
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
 */
function validateGuestToken($token) {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT user_id FROM guest_tokens
        WHERE token = ? AND expires_at > datetime('now')
    ");
    $stmt->execute([$token]);

    $result = $stmt->fetch();
    return $result ? $result['user_id'] : false;
}

/**
 * Clean expired tokens
 */
function cleanExpiredTokens() {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM guest_tokens WHERE expires_at < datetime('now')");
    return $stmt->execute();
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
