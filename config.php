<?php
/**
 * ComeCome Configuration
 * ADHD-friendly food tracking application
 */

// Database configuration
define('DB_PATH', __DIR__ . '/db/data.db');
define('DB_SCHEMA', __DIR__ . '/db/schema.sql');
define('DB_SEED', __DIR__ . '/db/seed.sql');

// Application configuration
define('APP_NAME', 'ComeCome');
define('APP_VERSION', '0.8');
define('DEFAULT_LOCALE', 'pt');
define('LOCALES_PATH', __DIR__ . '/locales');

// Session configuration
define('SESSION_LIFETIME', 60 * 60 * 24); // 24 hours

// Guest token configuration
define('GUEST_TOKEN_LIFETIME', 60 * 60 * 24 * 7); // 7 days

// Timezone
date_default_timezone_set('Europe/Lisbon');

// Start session
session_start();

// Initialize database if it doesn't exist
if (!file_exists(DB_PATH)) {
    require_once __DIR__ . '/includes/db.php';
    initializeDatabase();
}
