<?php
/**
 * API: Food Log
 */

require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'error' => 'unauthorized'], 401);
}

$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $foodId = $data['food_id'] ?? null;
    $mealId = $data['meal_id'] ?? null;
    $portion = $data['portion'] ?? null;
    $logDate = $data['log_date'] ?? date('Y-m-d');
    $logTime = $data['log_time'] ?? date('H:i:s');

    if (!$foodId || !$mealId || !$portion) {
        jsonResponse(['success' => false, 'error' => 'missing_fields'], 400);
    }

    if (!in_array($portion, ['little', 'some', 'lot', 'all'])) {
        jsonResponse(['success' => false, 'error' => 'invalid_portion'], 400);
    }

    if (logFood($user['id'], $foodId, $mealId, $portion, $logDate, $logTime)) {
        jsonResponse(['success' => true]);
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
