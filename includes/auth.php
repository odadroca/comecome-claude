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
 */
function createUser($name, $type, $pin, $avatarEmoji = '😊') {
    $db = getDB();
    $hashedPin = password_hash($pin, PASSWORD_DEFAULT);

    $stmt = $db->prepare("
        INSERT INTO users (name, type, pin, avatar_emoji)
        VALUES (?, ?, ?, ?)
    ");

    $stmt->execute([$name, $type, $hashedPin, $avatarEmoji]);
    return $db->lastInsertId();
}

/**
 * Update user
 */
function updateUser($id, $name, $type, $pin = null, $avatarEmoji = null, $active = 1) {
    $db = getDB();

    if ($pin) {
        $hashedPin = password_hash($pin, PASSWORD_DEFAULT);
        $stmt = $db->prepare("
            UPDATE users
            SET name = ?, type = ?, pin = ?, avatar_emoji = ?, active = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $type, $hashedPin, $avatarEmoji, $active, $id]);
    } else {
        $stmt = $db->prepare("
            UPDATE users
            SET name = ?, type = ?, avatar_emoji = ?, active = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $type, $avatarEmoji, $active, $id]);
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
