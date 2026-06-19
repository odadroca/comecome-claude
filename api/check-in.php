<?php
/**
 * API: Daily Check-in
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

// Sprint security Phase 3 — state-changing POSTs require a valid X-CSRF-Token header.
requireCsrfForApi();

$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $appetite = $data['appetite'] ?? null;
    $mood = $data['mood'] ?? null;
    $medication = $data['medication'] ?? 0;
    $notes = $data['notes'] ?? null;
    $sleepQuality = $data['sleep_quality'] ?? null;
    $date = $data['date'] ?? date('Y-m-d');

    if (!$appetite || !$mood) {
        jsonResponse(['success' => false, 'error' => 'missing_fields'], 400);
    }

    if (!is_numeric($appetite) || $appetite < 1 || $appetite > 5) {
        jsonResponse(['success' => false, 'error' => 'invalid_appetite'], 400);
    }

    if (!is_numeric($mood) || $mood < 1 || $mood > 5) {
        jsonResponse(['success' => false, 'error' => 'invalid_mood'], 400);
    }

    if ($sleepQuality !== null && (!is_numeric($sleepQuality) || $sleepQuality < 1 || $sleepQuality > 5)) {
        jsonResponse(['success' => false, 'error' => 'invalid_sleep_quality'], 400);
    }

    if (saveCheckIn($user['id'], $date, $appetite, $mood, $medication, $notes, $sleepQuality)) {
        jsonResponse(['success' => true]);
    } else {
        jsonResponse(['success' => false, 'error' => 'database_error'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $date = $_GET['date'] ?? date('Y-m-d');
    $checkIn = getCheckIn($user['id'], $date);
    jsonResponse(['success' => true, 'data' => $checkIn]);
}

jsonResponse(['success' => false, 'error' => 'invalid_method'], 405);
