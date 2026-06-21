<?php
/**
 * API: Food Log
 */

require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_once '../includes/csrf.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'error' => 'unauthorized'], 401);
}

// Sprint security Phase 3 — state-changing methods (POST/DELETE) must carry a valid
// X-CSRF-Token header (injected into the page; the inline fetch() callers attach it).
// GET reads are never gated. Rejected requests get a 403 'invalid_csrf' JSON error.
requireCsrfForApi();

$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $foodId = $data['food_id'] ?? null;
    $mealId = $data['meal_id'] ?? null;
    $portion = $data['portion'] ?? null;
    // Optional backdating (child history "add a past meal" link). Validate + clamp the
    // client-supplied date (never trust it: no future dates, no malformed strings);
    // derive a sensible time from the meal when none is given (no time picker).
    $logDate = clampLogDate($data['log_date'] ?? date('Y-m-d'));
    $logTime = $data['log_time'] ?? defaultLogTimeForDate($mealId, $logDate);

    if (!$foodId || !$mealId || !$portion) {
        jsonResponse(['success' => false, 'error' => 'missing_fields'], 400);
    }

    if (!in_array($portion, ['little', 'some', 'lot', 'all'])) {
        jsonResponse(['success' => false, 'error' => 'invalid_portion'], 400);
    }

    $newLogId = logFood($user['id'], $foodId, $mealId, $portion, $logDate, $logTime);
    if ($newLogId) {
        jsonResponse(['success' => true, 'id' => $newLogId]);
    } else {
        jsonResponse(['success' => false, 'error' => 'database_error'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $date = $_GET['date'] ?? date('Y-m-d');
    $log = getFoodLogByDate($user['id'], $date);
    jsonResponse(['success' => true, 'data' => $log]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true);
    $logId = $data['log_id'] ?? null;

    if (!$logId) {
        jsonResponse(['success' => false, 'error' => 'missing_id'], 400);
    }

    $db = getDB();
    $stmt = $db->prepare("DELETE FROM food_log WHERE id = ? AND user_id = ?");

    if ($stmt->execute([$logId, $user['id']])) {
        jsonResponse(['success' => true]);
    } else {
        jsonResponse(['success' => false, 'error' => 'database_error'], 500);
    }
}

jsonResponse(['success' => false, 'error' => 'invalid_method'], 405);
