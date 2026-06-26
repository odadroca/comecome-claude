<?php
/**
 * API: Sleep Log Management (guardian use)
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

// Sprint security Phase 3 — state-changing POST/DELETE require a valid X-CSRF-Token.
requireCsrfForApi();
requireConsentForApi(); // block writes until the guardian has consented

$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $userId = $data['user_id'] ?? null;
    $date = $data['log_date'] ?? date('Y-m-d');
    $type = $data['sleep_type'] ?? 'night';
    $start = $data['sleep_start'] ?? null;
    $end = $data['sleep_end'] ?? null;
    $quality = $data['quality'] ?? null;
    $notes = $data['notes'] ?? null;

    if (!$userId) {
        jsonResponse(['success' => false, 'error' => 'missing_user_id'], 400);
    }

    if (!in_array($type, ['night', 'nap'])) {
        jsonResponse(['success' => false, 'error' => 'invalid_sleep_type'], 400);
    }

    if ($quality !== null && (!is_numeric($quality) || $quality < 1 || $quality > 5)) {
        jsonResponse(['success' => false, 'error' => 'invalid_quality'], 400);
    }

    $sleepLogId = saveSleepLog($userId, $date, $type, $start, $end, $quality, $notes);

    // Save interruptions if provided
    if (isset($data['interruptions']) && is_array($data['interruptions'])) {
        foreach ($data['interruptions'] as $interruption) {
            $wakeTime = $interruption['wake_time'] ?? null;
            if ($wakeTime) {
                saveSleepInterruption(
                    $sleepLogId,
                    $wakeTime,
                    $interruption['back_to_sleep_time'] ?? null,
                    $interruption['reason'] ?? null
                );
            }
        }
    }

    jsonResponse(['success' => true, 'id' => $sleepLogId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $userId = $_GET['user_id'] ?? null;
    $date = $_GET['date'] ?? date('Y-m-d');

    if (!$userId) {
        jsonResponse(['success' => false, 'error' => 'missing_user_id'], 400);
    }

    $sleepData = getSleepByDate($userId, $date);
    jsonResponse(['success' => true, 'data' => $sleepData]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? null;

    if (!$id) {
        jsonResponse(['success' => false, 'error' => 'missing_id'], 400);
    }

    deleteSleepLog($id);
    jsonResponse(['success' => true]);
}

jsonResponse(['success' => false, 'error' => 'invalid_method'], 405);
