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

-- Daily check-in (appetite, medication, mood)
CREATE TABLE IF NOT EXISTS daily_checkin (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    check_date DATE NOT NULL,
    appetite_level INTEGER CHECK(appetite_level BETWEEN 1 AND 5),
    mood_level INTEGER CHECK(mood_level BETWEEN 1 AND 5),
    medication_taken INTEGER DEFAULT 0, -- 0=no, 1=yes
    notes TEXT,
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
CREATE INDEX IF NOT EXISTS idx_daily_checkin_user_date ON daily_checkin(user_id, check_date);
CREATE INDEX IF NOT EXISTS idx_guest_tokens_expires ON guest_tokens(expires_at);
CREATE INDEX IF NOT EXISTS idx_sleep_log_user_date ON sleep_log(user_id, log_date);
CREATE INDEX IF NOT EXISTS idx_sleep_interruptions_log ON sleep_interruptions(sleep_log_id);
CREATE INDEX IF NOT EXISTS idx_foods_category ON foods(category_id);
CREATE INDEX IF NOT EXISTS idx_user_favorites_user ON user_favorites(user_id);
