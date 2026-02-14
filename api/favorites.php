<?php
/**
 * API: Favorites Management
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

    if (!$foodId) {
        jsonResponse(['success' => false, 'error' => 'missing_food_id'], 400);
    }

    $isFavorite = toggleFavorite($user['id'], $foodId);
    jsonResponse(['success' => true, 'is_favorite' => $isFavorite]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $favorites = getUserFavorites($user['id']);
    jsonResponse(['success' => true, 'data' => $favorites]);
}

jsonResponse(['success' => false, 'error' => 'invalid_method'], 405);
