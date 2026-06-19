<?php
/**
 * ComeCome Configuration
 * ADHD-friendly food tracking application
 */

// Per-deployment overrides (git-ignored; never committed). Lets a host point DB_PATH
// outside the web root — or set any other constant — WITHOUT editing this tracked file.
// Optional: absent by default, so a fresh download still runs zero-config (DB at db/data.db).
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

// Database configuration.
// DB_PATH precedence: config.local.php define()  >  COMECOME_DB_PATH env var  >  in-tree default.
if (!defined('DB_PATH')) {
    $cc_env_db = getenv('COMECOME_DB_PATH');
    define('DB_PATH', ($cc_env_db !== false && $cc_env_db !== '') ? $cc_env_db : __DIR__ . '/db/data.db');
}
define('DB_SCHEMA', __DIR__ . '/db/schema.sql');
define('DB_SEED', __DIR__ . '/db/seed.sql');

// Application configuration
define('APP_NAME', 'ComeCome');
define('APP_VERSION', '0.9.1');
define('DEFAULT_LOCALE', 'pt');
define('LOCALES_PATH', __DIR__ . '/locales');

// Session configuration
define('SESSION_LIFETIME', 60 * 60 * 24); // 24 hours

// Guest token configuration
define('GUEST_TOKEN_LIFETIME', 60 * 60 * 24 * 7); // 7 days

// Timezone
date_default_timezone_set('Europe/Lisbon');

// Sprint security Phase 0 — session bootstrap. The cookie-flag logic lives in a
// side-effect-free, includable helper (includes/session.php) the CLI harness can
// assert. Apply the hardened params (HttpOnly + SameSite=Lax + env-gated Secure)
// BEFORE the session starts.
require_once __DIR__ . '/includes/session.php';
applySessionCookieParams();

// Start session
session_start();

// Initialize database if it doesn't exist, otherwise run migrations
if (!file_exists(DB_PATH)) {
    require_once __DIR__ . '/includes/db.php';
    initializeDatabase();
} else {
    require_once __DIR__ . '/includes/db.php';
    migrateDatabase(getDB());
}
