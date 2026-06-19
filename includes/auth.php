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
        return $stmt->fetch();
    }
    return null;
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && getCurrentUser() !== null;
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
 * Authenticate user with PIN
 */
function authenticateUser($userId, $pin) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND active = 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if ($user && password_verify($pin, $user['pin'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_type'] = $user['type'];
        $_SESSION['user_name'] = $user['name'];
        return true;
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

    return $stmt->fetchAll();
}

/**
 * Get user by ID
 */
function getUserById($id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
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
