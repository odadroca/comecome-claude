<?php
/**
 * API: Weight Tracking
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

    $weight = $data['weight'] ?? null;
    $date = $data['date'] ?? date('Y-m-d');

    if (!$weight) {
        jsonResponse(['success' => false, 'error' => 'missing_weight'], 400);
    }

    if (!is_numeric($weight) || $weight < 1 || $weight > 200) {
        jsonResponse(['success' => false, 'error' => 'invalid_weight'], 400);
    }

    if (logWeight($user['id'], $weight, $date)) {
        jsonResponse(['success' => true]);
    } else {
        jsonResponse(['success' => false, 'error' => 'database_error'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $days = $_GET['days'] ?? null;
    $history = getWeightHistory($user['id'], $days);
    jsonResponse(['success' => true, 'data' => $history]);
}

jsonResponse(['success' => false, 'error' => 'invalid_method'], 405);
