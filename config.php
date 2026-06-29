<?php
/**
 * ComeCome Configuration
 * ADHD-friendly food tracking application
 */

// Per-deployment overrides (git-ignored; never committed). Lets a host point DB_PATH
// outside the web root — or set any other constant (e.g. ENCRYPTION_KEY_FILE) —
// WITHOUT editing this tracked file. Optional: absent by default, so a fresh
// download still runs zero-config (DB at db/data.db).
//
// Sprint security Phase 4 — .env / secrets pattern. The loader checks TWO optional
// override locations, in this order (each, if present, may define() any constant;
// later defines lose to earlier ones because PHP define() is first-write-wins):
//
//   1. An ABOVE-DOCROOT config file, pointed at by the COMECOME_CONFIG env var.
//      THIS is the recommended production home for secrets — a file the web server
//      can never serve because it sits OUTSIDE public_html. Set it once in the
//      host env (e.g. Hostinger SetEnv / .htaccess `SetEnv COMECOME_CONFIG ...`).
//   2. The in-tree config.local.php (git-ignored) — convenient for pointing DB_PATH
//      out of the tree on simple installs, but it still lives inside the web root,
//      so it must NOT hold the raw key (point ENCRYPTION_KEY_FILE above docroot).
//
// A fresh download has NEITHER, so the app still runs zero-config in plaintext.
$cc_above_docroot_config = getenv('COMECOME_CONFIG');
if ($cc_above_docroot_config !== false && $cc_above_docroot_config !== ''
    && is_file($cc_above_docroot_config)) {
    require $cc_above_docroot_config;
}
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
define('APP_VERSION', '0.11.0');
// Consent-notice version. Bump this integer whenever the privacy/consent notice
// text changes materially; guardianConsentCurrent() returns false until the guardian
// re-acknowledges the new version.
define('CONSENT_NOTICE_VERSION', 1);

// Nutrition-attestation version. Bump this integer whenever the in-app medical
// disclaimer text (medical_disclaimer_* locale keys) changes materially (e.g. after
// the external EU/PT legal sign-off); a previously-attested guardian becomes "stale"
// → insights keep rendering but a re-acknowledge notice appears (soft re-ack).
define('NUTRITION_ATTESTATION_VERSION', 1);

// Sprint 2 (A15) — allowed data-retention periods in MONTHS. 0 = off (default).
// Server-side allowlist: any value not in this set is rejected.
define('RETENTION_PRESETS', [0, 6, 12, 24, 36]);

// Sprint 2 (A4) — child-safeguarding thresholds. Deterministic, mood-only.
// A child is flagged when, within the last SAFEGUARD_WINDOW_DAYS *calendar days*
// and among UNREVIEWED check-ins: any mood_level <= CRITICAL, OR
// >= LOW_COUNT check-ins with mood_level <= LOW. Tune here without code changes.
define('SAFEGUARD_MOOD_CRITICAL', 1);   // a single 😢 flags immediately
define('SAFEGUARD_MOOD_LOW', 2);        // 🙁-or-worse counts as a "low" day
define('SAFEGUARD_LOW_COUNT', 2);       // how many low days trip the flag
define('SAFEGUARD_WINDOW_DAYS', 7);     // rolling look-back, calendar days
define('DEFAULT_LOCALE', 'pt');
define('LOCALES_PATH', __DIR__ . '/locales');

// Session configuration
define('SESSION_LIFETIME', 60 * 60 * 24); // 24 hours

// Guest token configuration
define('GUEST_TOKEN_LIFETIME', 60 * 60 * 24 * 7); // 7 days

// Transport security (Sprint security Phase 2). HSTS max-age in SECONDS.
// Conservative default = 1 day (no preload) so an operator can confirm TLS is
// healthy before ratcheting it up; override in config.local.php once stable
// (e.g. 31536000 = 1 year). Only emitted over HTTPS (see hstsHeaderValue()).
if (!defined('HSTS_MAX_AGE')) {
    define('HSTS_MAX_AGE', 60 * 60 * 24); // 86400 = 1 day
}

// Timezone
date_default_timezone_set('Europe/Lisbon');

// Sprint security Phase 4 — secrets / encryption-key container. Side-effect-free
// to include (defines functions only); it reads NO key until encryptionKey() is
// called. Phase 5's field encryption calls it to obtain the raw 32-byte key from
// the above-docroot key file (ENCRYPTION_KEY_FILE). With no key configured it
// returns null and the app stays in zero-config plaintext mode. Required AFTER the
// override loaders above so ENCRYPTION_KEY_FILE (if set there) is already defined.
require_once __DIR__ . '/includes/secrets.php';

// Sprint security Phase 0 — session bootstrap. The cookie-flag logic lives in a
// side-effect-free, includable helper (includes/session.php) the CLI harness can
// assert. Apply the hardened params (HttpOnly + SameSite=Lax + env-gated Secure)
// BEFORE the session starts.
require_once __DIR__ . '/includes/session.php';
applySessionCookieParams();

// Start session
session_start();

// Sprint security Phase 2 — enforce TLS/HTTPS + HSTS at the app level (backstop
// for the .htaccess rule, which only runs under Apache). MUST come AFTER the
// Phase 0 cookie bootstrap above so the env-gated Secure flag is set first and
// local `php -S` HTTP dev is never broken (ordering invariant). On plain HTTP
// with HTTPS enforcement off (the zero-config default) this is a silent no-op;
// over HTTPS it adds the HSTS header; under COMECOME_FORCE_HTTPS it 301s
// HTTP->HTTPS. See includes/session.php for the pure decision logic.
enforceTransportSecurity();

// Initialize database if it doesn't exist, otherwise run migrations
if (!file_exists(DB_PATH)) {
    require_once __DIR__ . '/includes/db.php';
    initializeDatabase();
} else {
    require_once __DIR__ . '/includes/db.php';
    migrateDatabase(getDB());
}
