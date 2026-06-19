-- ComeCome - ADHD-Friendly Nutrition Tracking
-- SQLite Database Schema

-- Users table (children and guardians)
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    type TEXT NOT NULL CHECK(type IN ('child', 'guardian')),
    pin TEXT, -- Simple 4-digit PIN for child authentication
    avatar_emoji TEXT DEFAULT '😊',
    -- Sprint 5: Demographics Foundation. Guardian-entered identity fields, both
    -- NULLABLE. Mirrors the v3 migration so a fresh DB matches a migrated one.
    -- Privacy (decision iii): guardian/clinician-side only — never shown on any
    -- child page.
    gender TEXT CHECK(gender IN ('male', 'female')),
    date_of_birth DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    active INTEGER DEFAULT 1
);

-- Meals table (breakfast, morning snack, lunch, etc.)
CREATE TABLE IF NOT EXISTS meals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name_key TEXT NOT NULL UNIQUE, -- e.g., 'meal_breakfast' for i18n lookup
    sort_order INTEGER NOT NULL,
    time_start TEXT, -- e.g., '07:00'
    time_end TEXT,   -- e.g., '10:00'
    active INTEGER DEFAULT 1
);

-- Food categories table
CREATE TABLE IF NOT EXISTS food_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name_key TEXT NOT NULL UNIQUE, -- e.g., 'category_fruits' for i18n lookup
    sort_order INTEGER NOT NULL,
    active INTEGER DEFAULT 1
);

-- Meal-Category mapping (many-to-many)
CREATE TABLE IF NOT EXISTS meal_categories (
    meal_id INTEGER NOT NULL,
    category_id INTEGER NOT NULL,
    PRIMARY KEY (meal_id, category_id),
    FOREIGN KEY (meal_id) REFERENCES meals(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES food_categories(id) ON DELETE CASCADE
);

-- Foods table
CREATE TABLE IF NOT EXISTS foods (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name_key TEXT NOT NULL UNIQUE, -- e.g., 'food_apple' for i18n lookup
    emoji TEXT NOT NULL DEFAULT '🍽️',
    category_id INTEGER NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 999,
    active INTEGER DEFAULT 1,
    FOREIGN KEY (category_id) REFERENCES food_categories(id) ON DELETE CASCADE
);

-- User favorites (per child)
CREATE TABLE IF NOT EXISTS user_favorites (
    user_id INTEGER NOT NULL,
    food_id INTEGER NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, food_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (food_id) REFERENCES foods(id) ON DELETE CASCADE
);

-- Food log entries
CREATE TABLE IF NOT EXISTS food_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    food_id INTEGER NOT NULL,
    meal_id INTEGER NOT NULL,
    portion TEXT NOT NULL CHECK(portion IN ('little', 'some', 'lot', 'all')),
    log_date DATE NOT NULL,
    log_time TIME NOT NULL,
    -- Sprint 9: Medication Timing Foundation. Invisible food-log enrichment stamped
    -- SERVER-SIDE at INSERT (computeMedWindow) by comparing log_time to the child's
    -- active medication_schedules. NULL = no active appetite-affecting schedule (the
    -- common case) / non-stimulant. ZERO child-facing change. Mirrors the v5 ALTER in
    -- includes/db.php so a fresh DB matches a migrated one.
    med_window TEXT CHECK(med_window IN ('pre_med','onset','mid_med','post_med') OR med_window IS NULL),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (food_id) REFERENCES foods(id) ON DELETE CASCADE,
    FOREIGN KEY (meal_id) REFERENCES meals(id) ON DELETE CASCADE
);

-- Medications table
CREATE TABLE IF NOT EXISTS medications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    dose TEXT,
    active INTEGER DEFAULT 1
);

-- User medications (which child takes which medication)
CREATE TABLE IF NOT EXISTS user_medications (
    user_id INTEGER NOT NULL,
    medication_id INTEGER NOT NULL,
    PRIMARY KEY (user_id, medication_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (medication_id) REFERENCES medications(id) ON DELETE CASCADE
);

-- Medication schedules (Sprint 9: Medication Timing Foundation). Per child +
-- medication, the typical dose_time (HH:MM) and the peak-effect window as minute
-- offsets from the dose. med_type records the guardian's UI auto-fill choice
-- (short_acting / long_acting / non_stimulant); the offsets are the source of truth
-- read by computeMedWindow() at food-log INSERT. Offsets default to the documented
-- 60/240; the guardian overrides per child (defaults are approximations, not
-- prescriptions). Guardian-configured only — ZERO child-facing change. Mirrors the
-- v5 migration in includes/db.php so a fresh DB matches a migrated one.
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
);

-- Daily check-in (appetite, medication, mood)
CREATE TABLE IF NOT EXISTS daily_checkin (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    check_date DATE NOT NULL,
    appetite_level INTEGER CHECK(appetite_level BETWEEN 1 AND 5),
    mood_level INTEGER CHECK(mood_level BETWEEN 1 AND 5),
    medication_taken INTEGER DEFAULT 0, -- 0=no, 1=yes
    notes TEXT,
    -- Sprint 2: sleep quality 1–5. Mirrors the v2 migration so a fresh DB
    -- matches a migrated one (see includes/db.php migrateDatabase v<2 block).
    sleep_quality INTEGER CHECK(sleep_quality BETWEEN 1 AND 5),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, check_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Weight tracking
CREATE TABLE IF NOT EXISTS weight_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    weight_kg REAL NOT NULL,
    log_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, log_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Height tracking (Sprint 6: Growth Page Foundation). Mirrors weight_log: one
-- optional measurement per child per day (UNIQUE upsert). Mirrors the v4
-- migration in includes/db.php so a fresh DB matches a migrated one. Shown to the
-- child only when show_percentiles is ON (guardian opt-in, decision ii).
CREATE TABLE IF NOT EXISTS height_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    height_cm REAL NOT NULL,
    log_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, log_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- System settings
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT
);

-- Guest access tokens (for clinicians)
CREATE TABLE IF NOT EXISTS guest_tokens (
    token TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    -- Sprint security Phase 3: guest-token revocation. is_revoked=1 invalidates a
    -- shared clinician link before it naturally expires. Default 0 (active) so
    -- existing tokens keep working. Mirrors the v6 ALTER in includes/db.php.
    is_revoked INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Translations (for user-contributed translations)
CREATE TABLE IF NOT EXISTS translations (
    locale TEXT NOT NULL,
    key TEXT NOT NULL,
    value TEXT NOT NULL,
    modified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (locale, key)
);

-- Login throttling / lockout (Sprint security Phase 1: PIN brute-force defence).
-- A single AGGREGATED row per (user_id, ip_bucket) — fail_count + window_start +
-- locked_until — UPDATE-in-place (critique fix: NOT one insert per attempt, which
-- would write-storm SQLite's single writer under the flood it defends against). The
-- per-user row (primary, tight counter) uses ip_bucket=''; the loose per-IP ceiling
-- is keyed user_id=0 so it never collides with a real user's row.
-- UNIQUE(user_id, ip_bucket) backs the ON CONFLICT upsert. Mirrors the v6 migration
-- in includes/db.php so a fresh DB matches a migrated one.
CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    ip_bucket TEXT NOT NULL DEFAULT '',
    fail_count INTEGER NOT NULL DEFAULT 0,
    window_start INTEGER,
    locked_until INTEGER,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, ip_bucket)
);

-- Sleep tracking
CREATE TABLE IF NOT EXISTS sleep_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    log_date DATE NOT NULL,
    sleep_type TEXT NOT NULL CHECK(sleep_type IN ('night', 'nap')),
    sleep_start DATETIME,
    sleep_end DATETIME,
    quality INTEGER CHECK(quality BETWEEN 1 AND 5),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Sleep interruptions (0..N per sleep session)
CREATE TABLE IF NOT EXISTS sleep_interruptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sleep_log_id INTEGER NOT NULL,
    wake_time DATETIME NOT NULL,
    back_to_sleep_time DATETIME,
    reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sleep_log_id) REFERENCES sleep_log(id) ON DELETE CASCADE
);

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_food_log_user_date ON food_log(user_id, log_date);
CREATE INDEX IF NOT EXISTS idx_food_log_date ON food_log(log_date);
CREATE INDEX IF NOT EXISTS idx_weight_log_user_date ON weight_log(user_id, log_date);
CREATE INDEX IF NOT EXISTS idx_height_log_user_date ON height_log(user_id, log_date);
CREATE INDEX IF NOT EXISTS idx_daily_checkin_user_date ON daily_checkin(user_id, check_date);
CREATE INDEX IF NOT EXISTS idx_guest_tokens_expires ON guest_tokens(expires_at);
CREATE INDEX IF NOT EXISTS idx_sleep_log_user_date ON sleep_log(user_id, log_date);
CREATE INDEX IF NOT EXISTS idx_sleep_interruptions_log ON sleep_interruptions(sleep_log_id);
CREATE INDEX IF NOT EXISTS idx_foods_category ON foods(category_id);
CREATE INDEX IF NOT EXISTS idx_user_favorites_user ON user_favorites(user_id);
CREATE INDEX IF NOT EXISTS idx_medication_schedules_user ON medication_schedules(user_id, active);
CREATE INDEX IF NOT EXISTS idx_login_attempts_locked ON login_attempts(locked_until);
