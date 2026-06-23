<?php
/**
 * API: Height Tracking (Sprint 6 — Growth Page Foundation)
 *
 * Mirrors api/weight.php EXACTLY: auth required, ownership enforced (a logged-in
 * user only ever reads/writes their OWN rows via $user['id']), GET/POST/DELETE
 * surface. Height is validated to the 30–220 cm range. The height row is stored
 * regardless of the show_percentiles toggle state; that toggle only gates whether
 * the child SEES the input (decision ii) — the API itself stays simple and owned.
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

    $height = $data['height'] ?? null;
    $date = $data['date'] ?? date('Y-m-d');

    if (!$height) {
        jsonResponse(['success' => false, 'error' => 'missing_height'], 400);
    }

    if (!is_numeric($height) || $height < 30 || $height > 220) {
        jsonResponse(['success' => false, 'error' => 'invalid_height'], 400);
    }

    if (logHeight($user['id'], $height, $date)) {
        jsonResponse(['success' => true]);
    } else {
        jsonResponse(['success' => false, 'error' => 'database_error'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $days = $_GET['days'] ?? null;
    $history = getHeightHistory($user['id'], $days);
    jsonResponse(['success' => true, 'data' => $history]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true);
    $date = $data['date'] ?? null;

    if (!$date) {
        jsonResponse(['success' => false, 'error' => 'missing_date'], 400);
    }

    // Ownership enforced: only the caller's own row for that date can be removed.
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM height_log WHERE user_id = ? AND log_date = ?");
    if ($stmt->execute([$user['id'], $date])) {
        jsonResponse(['success' => true]);
    } else {
        jsonResponse(['success' => false, 'error' => 'database_error'], 500);
    }
}

jsonResponse(['success' => false, 'error' => 'invalid_method'], 405);
