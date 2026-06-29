<?php
/**
 * Authentication Functions
 */

/**
 * Get current logged-in user
 */
function getCurrentUser() {
    if (isset($_SESSION['user_id'])) {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND active = 1");
        $stmt->execute([$_SESSION['user_id']]);
        // Sprint security Phase 5 — decrypt-on-read: users.name is a scoped encrypted
        // column. decryptUserName() is a no-op passthrough with no key / plaintext rows.
        return decryptUserName($stmt->fetch());
    }
    return null;
}

/**
 * Sprint security Phase 5 — decrypt the scoped users.name column on a fetched user
 * row (or list of rows). Centralizes the decrypt-on-read so every user accessor
 * shares one transition-safe path: a not-yet-backfilled (plaintext) name and an
 * encrypted name both come back as readable text; with no key configured it is a
 * pure passthrough. Guarded with function_exists so this file still loads in any
 * partial-include order before includes/crypto.php is available.
 */
function decryptUserName($row) {
    if (function_exists('decryptRowFields')) {
        return decryptRowFields($row, ['name']);
    }
    return $row;
}

/**
 * Sprint security Phase 0 — pure idle-timeout predicate (side-effect free so the
 * CLI harness can assert the math without a live session). Returns true when more
 * than $lifetime seconds have elapsed since $lastActivity. A null/blank
 * $lastActivity (legacy session predating this field) is treated as NOT expired,
 * so existing logged-in sessions keep working until their next activity stamp.
 */
function sessionIsExpired($lastActivity, $now, $lifetime) {
    if ($lastActivity === null || $lastActivity === '') {
        return false;
    }
    return ($now - (int) $lastActivity) > (int) $lifetime;
}

/**
 * Launch Sprint 2 — guardian consent state helpers (settings-backed, no schema change).
 *
 * guardianConsentCurrent() — returns true iff the guardian has acknowledged the current
 *   version of the privacy/consent notice (CONSENT_NOTICE_VERSION from config.php).
 *   Side-effect-free and assertable in the CLI harness.
 *
 * recordGuardianConsent() — stamps guardian_consent_version to the current version
 *   and guardian_consent_at to a UTC ISO-8601 timestamp, marking acknowledgement.
 */
function guardianConsentCurrent(): bool {
    return getSetting('guardian_consent_version', '') === (string) CONSENT_NOTICE_VERSION;
}

function recordGuardianConsent(): void {
    setSetting('guardian_consent_version', (string) CONSENT_NOTICE_VERSION);
    setSetting('guardian_consent_at', gmdate('c'));
}

/**
 * Launch Sprint 2 / A21 — nutrition-insights medical disclaimer attestation
 * (settings-backed, no schema change).
 *
 * guardianNutritionAttestationCurrent() — returns true iff the guardian has
 *   acknowledged the current version of the in-app medical disclaimer
 *   (NUTRITION_ATTESTATION_VERSION from config.php). Side-effect-free.
 *
 * recordGuardianNutritionAttestation() — stamps nutrition_attestation_version
 *   to the current version and nutrition_attestation_at to a UTC ISO-8601
 *   timestamp, marking acknowledgement.
 */
function guardianNutritionAttestationCurrent(): bool {
    return getSetting('nutrition_attestation_version', '') === (string) NUTRITION_ATTESTATION_VERSION;
}

function recordGuardianNutritionAttestation(): void {
    setSetting('nutrition_attestation_version', (string) NUTRITION_ATTESTATION_VERSION);
    setSetting('nutrition_attestation_at', gmdate('c'));
}

/**
 * A27 — child privacy-note seen-state helper (settings-backed, no schema change).
 *
 * childPrivacyNoteSeen() — returns true iff the child has dismissed the one-time
 *   in-app privacy note. Uses a per-child settings flag so it is independent for
 *   each child and never interferes with guardian consent. Side-effect-free.
 */
function childPrivacyNoteSeen(int $childId): bool {
    return getSetting("child_privacy_note_seen_$childId", '') !== '';
}

/**
 * Block API WRITES until the guardian has acknowledged the consent notice.
 *
 * The page-router consent gate (index.php) only covers requests routed through
 * index.php; the api/*.php endpoints are a separate write surface. A logged-in
 * child holds a valid session + CSRF token (login/blocked pages expose one), so
 * without this guard they could POST food/check-in/weight data directly before
 * the guardian consents. Mirrors requireCsrfForApi(): only state-changing
 * methods are gated (GET reads pass — there is nothing to read pre-consent),
 * and a blocked request gets a 403 JSON 'consent_required'.
 */
function requireConsentForApi($method = null) {
    if ($method === null) {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }
    $method = strtoupper((string) $method);
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }
    if (!guardianConsentCurrent()) {
        jsonResponse(['success' => false, 'error' => 'consent_required'], 403);
    }
}

/**
 * Check if user is logged in
 *
 * Sprint security Phase 0 — also enforces the idle timeout: a session idle past
 * SESSION_LIFETIME is logged out here (the previously-unused constant is now
 * wired in). Active sessions get their last_activity stamp refreshed on each
 * authenticated request (sliding window).
 */
function isLoggedIn() {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    // Idle-timeout gate. SESSION_LIFETIME is defined in config.php; default
    // generously if somehow absent so we never divide/compare against undefined.
    $lifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 86400;
    $lastActivity = $_SESSION['last_activity'] ?? null;
    if (sessionIsExpired($lastActivity, time(), $lifetime)) {
        logout();
        return false;
    }

    if (getCurrentUser() === null) {
        return false;
    }

    // Sliding window: refresh the activity stamp on each authenticated check.
    $_SESSION['last_activity'] = time();
    return true;
}

/**
 * Check if current user is guardian
 */
function isGuardian() {
    $user = getCurrentUser();
    return $user && $user['type'] === 'guardian';
}

/**
 * Check if current user is child
 */
function isChild() {
    $user = getCurrentUser();
    return $user && $user['type'] === 'child';
}

/**
 * Authenticate user with PIN.
 *
 * Sprint security Phase 1 — PIN brute-force throttling/lockout is woven in here, the
 * primary verify site:
 *   1. If the (user, ip) is ALREADY locked out, refuse WITHOUT running
 *      password_verify and WITHOUT incrementing — hammering a locked account neither
 *      extends the lock nor leaks timing. Returns false (the distinct `locked` state
 *      is surfaced to the login page via loginIsLockedOut(), so the user sees a
 *      lockout message, not "wrong PIN").
 *   2. A correct PIN CLEARS the user's failure counter.
 *   3. A wrong PIN (or unknown/inactive user_id) records ONE aggregated failure —
 *      the same accounting for a non-existent id as a real one, so failures never
 *      reveal whether a user exists.
 *
 * Return contract is UNCHANGED (strict bool) so existing call sites — including the
 * smoke harness's `=== true/false` assertions — keep working. $ip is injectable for
 * the CLI harness; it defaults to the request's REMOTE_ADDR bucket.
 */
function authenticateUser($userId, $pin, $ip = null) {
    $db = getDB();

    // Pre-verify lockout gate: if this user/ip is locked, do not even verify.
    if (function_exists('loginIsLockedOut') && loginIsLockedOut($db, $userId, $ip)) {
        return false;
    }

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND active = 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if ($user && password_verify($pin, $user['pin'])) {
        // Sprint security Phase 5 — decrypt the scoped name BEFORE it is cached in the
        // session (so $_SESSION['user_name'] holds readable text, not ciphertext).
        // PIN verify above used the cleartext hash column, which is NOT encrypted.
        $user = decryptUserName($user);
        // Sprint security Phase 1 — successful auth clears the user's throttle counter.
        if (function_exists('clearFailedLogins')) {
            clearFailedLogins($db, $userId);
        }
        // Sprint security Phase 0 — close session fixation: rotate the session id
        // on every successful authentication so a pre-login (attacker-fixed) id is
        // never elevated to an authenticated one. `true` deletes the old session
        // file. Guarded so the CLI/test context (no active session) never warns.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_type'] = $user['type'];
        $_SESSION['user_name'] = $user['name'];
        // Sprint security Phase 0 — stamp last-activity so the idle-timeout in
        // isLoggedIn()/requireAuth() has a baseline from the moment of login.
        $_SESSION['last_activity'] = time();
        return true;
    }

    // Failed verify (wrong PIN, or unknown/inactive id) — record one aggregated
    // failure against the per-user (primary) + per-IP (loose) buckets. The same
    // accounting for an unknown id as a real one (no user-existence oracle).
    if (function_exists('recordFailedLogin')) {
        recordFailedLogin($db, $userId, $ip);
    }

    return false;
}

/**
 * Logout current user
 */
function logout() {
    // Clear all session data
    $_SESSION = [];

    // Delete session cookie to prevent stale sessions
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }

    session_destroy();
}

/**
 * Require authentication
 */
function requireAuth() {
    if (!isLoggedIn()) {
        header('Location: index.php?page=login');
        exit;
    }
}

/**
 * Require guardian access
 */
function requireGuardian() {
    requireAuth();
    if (!isGuardian()) {
        header('Location: index.php');
        exit;
    }
}

/**
 * Get all users
 */
function getAllUsers($type = null) {
    $db = getDB();

    if ($type) {
        $stmt = $db->prepare("SELECT * FROM users WHERE type = ? ORDER BY name");
        $stmt->execute([$type]);
    } else {
        $stmt = $db->query("SELECT * FROM users ORDER BY name");
    }

    // Sprint security Phase 5 — decrypt-on-read for each row's scoped name. NOTE the
    // ORDER BY name sorts on the STORED value: with encryption ON that is ciphertext,
    // so the list order is no longer alphabetical. That is the accepted trade-off of
    // encrypting an identity column (it is never aggregated/filtered, only listed) and
    // is invisible to children (guardian-only screens). With encryption OFF the sort
    // is unchanged.
    if (function_exists('decryptRowsFields')) {
        return decryptRowsFields($stmt->fetchAll(), ['name']);
    }
    return $stmt->fetchAll();
}

/**
 * Get user by ID
 */
function getUserById($id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    // Sprint security Phase 5 — decrypt-on-read of the scoped name.
    return decryptUserName($stmt->fetch());
}

/**
 * Create user
 *
 * Sprint 5: gender + dateOfBirth are OPTIONAL, NULLABLE demographic fields
 * appended at the end so existing call sites (which pass only name/type/pin/
 * avatar) keep working unchanged. Blank strings are normalized to NULL so a
 * fresh DB and a migrated DB behave identically. These are guardian-entered,
 * guardian/clinician-side only (decision iii) — never shown on a child page.
 */
function createUser($name, $type, $pin, $avatarEmoji = '😊', $gender = null, $dateOfBirth = null) {
    $db = getDB();
    $hashedPin = password_hash($pin, PASSWORD_DEFAULT);
    $gender = normalizeGender($gender);
    $dateOfBirth = normalizeDateOfBirth($dateOfBirth);

    // Sprint security Phase 5 — encrypt the scoped identity name on write (no-op
    // passthrough with no key). gender/date_of_birth stay CLEARTEXT: the WHO
    // percentile engine derives age from them (excluded from encryption this sprint).
    $name = function_exists('encryptField') ? encryptField($name) : $name;

    $stmt = $db->prepare("
        INSERT INTO users (name, type, pin, avatar_emoji, gender, date_of_birth)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([$name, $type, $hashedPin, $avatarEmoji, $gender, $dateOfBirth]);
    return $db->lastInsertId();
}

/**
 * Normalize a gender input to a valid stored value or NULL.
 * Only 'male'/'female' are accepted (matches the CHECK constraint); anything
 * else (blank, unset, unexpected) becomes NULL rather than risking a constraint
 * violation.
 */
function normalizeGender($gender) {
    return in_array($gender, ['male', 'female'], true) ? $gender : null;
}

/**
 * Normalize a date_of_birth input to a YYYY-MM-DD string or NULL.
 * Blank/whitespace becomes NULL. Anything non-empty is stored as-is (the
 * date input already emits YYYY-MM-DD); helpers null-guard malformed values.
 */
function normalizeDateOfBirth($dateOfBirth) {
    if ($dateOfBirth === null) return null;
    $dateOfBirth = trim((string) $dateOfBirth);
    return $dateOfBirth === '' ? null : $dateOfBirth;
}

/**
 * Update user
 *
 * Sprint 5: gender + dateOfBirth are OPTIONAL, NULLABLE demographic fields
 * appended at the end. They use a sentinel default (the string '__keep__') so
 * existing call sites that don't pass them leave the stored values UNTOUCHED —
 * persisting them only when a caller explicitly supplies a value (including an
 * explicit null/blank to clear). Guardian/clinician-side only (decision iii).
 */
function updateUser($id, $name, $type, $pin = null, $avatarEmoji = null, $active = 1,
                    $gender = '__keep__', $dateOfBirth = '__keep__') {
    $db = getDB();

    // Sprint security Phase 5 — encrypt the scoped name on write (no-op passthrough
    // with no key). Applied once here so both the with-PIN and without-PIN UPDATE
    // branches below bind the encrypted value.
    $name = function_exists('encryptField') ? encryptField($name) : $name;

    // Build the optional demographic SET clause only when the caller opted in.
    $demoSet = '';
    $demoParams = [];
    if ($gender !== '__keep__') {
        $demoSet .= ', gender = ?';
        $demoParams[] = normalizeGender($gender);
    }
    if ($dateOfBirth !== '__keep__') {
        $demoSet .= ', date_of_birth = ?';
        $demoParams[] = normalizeDateOfBirth($dateOfBirth);
    }

    if ($pin) {
        $hashedPin = password_hash($pin, PASSWORD_DEFAULT);
        $stmt = $db->prepare("
            UPDATE users
            SET name = ?, type = ?, pin = ?, avatar_emoji = ?, active = ?" . $demoSet . "
            WHERE id = ?
        ");
        $stmt->execute(array_merge([$name, $type, $hashedPin, $avatarEmoji, $active], $demoParams, [$id]));

        // Sprint security Phase 0 — a PIN just changed. Re-derive the default-PIN
        // guard from the new stored hashes: if the guardian moved off '0000' the
        // flag clears and the force-change redirect lifts; if a guardian PIN was
        // (re)set TO '0000' it re-locks. Re-derivation (not a blind clear) keeps a
        // multi-guardian install correct. Only meaningful for guardian rows but
        // safe to run unconditionally.
        if (function_exists('refreshGuardianPinDefaultFlag')) {
            refreshGuardianPinDefaultFlag($db);
        }
    } else {
        $stmt = $db->prepare("
            UPDATE users
            SET name = ?, type = ?, avatar_emoji = ?, active = ?" . $demoSet . "
            WHERE id = ?
        ");
        $stmt->execute(array_merge([$name, $type, $avatarEmoji, $active], $demoParams, [$id]));
    }

    return $stmt->rowCount() > 0;
}

/**
 * Delete user
 */
function deleteUser($id) {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
    return $stmt->execute([$id]);
}

/**
 * Check if user has any data
 */
function userHasData($id) {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT 1 FROM food_log WHERE user_id = ?
        UNION
        SELECT 1 FROM daily_checkin WHERE user_id = ?
        UNION
        SELECT 1 FROM weight_log WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$id, $id, $id]);

    return $stmt->fetch() !== false;
}
