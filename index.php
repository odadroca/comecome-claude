<?php
/**
 * ComeCome - ADHD-Friendly Food Tracking
 * Main Entry Point / Router
 */

require_once 'config.php';
require_once 'includes/db.php';
require_once 'includes/i18n.php';
require_once 'includes/auth.php';
require_once 'includes/helpers.php';

// Clean expired guest tokens
cleanExpiredTokens();

// Handle language switching
if (isset($_GET['lang']) && in_array($_GET['lang'], getAvailableLocales())) {
    setAppLocale($_GET['lang']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Router
$page = $_GET['page'] ?? 'home';

// Public pages (no authentication required)
$publicPages = ['login', 'guest-report'];

// Check authentication for protected pages
if (!in_array($page, $publicPages) && !isLoggedIn()) {
    $page = 'login';
}

// Route to appropriate page
switch ($page) {
    case 'login':
        include 'pages/login.php';
        break;

    // Child pages
    case 'log-food':
        requireAuth();
        include 'pages/child/log-food.php';
        break;

    case 'check-in':
        requireAuth();
        include 'pages/child/check-in.php';
        break;

    case 'weight':
        requireAuth();
        include 'pages/child/weight.php';
        break;

    case 'history':
        requireAuth();
        include 'pages/child/history.php';
        break;

    // Guardian pages
    case 'dashboard':
        requireGuardian();
        include 'pages/guardian/dashboard.php';
        break;

    case 'manage-users':
    case 'manage-children': // backward compat
        requireGuardian();
        include 'pages/guardian/manage-users.php';
        break;

    case 'manage-meals':
        requireGuardian();
        include 'pages/guardian/manage-meals.php';
        break;

    case 'manage-foods':
        requireGuardian();
        include 'pages/guardian/manage-foods.php';
        break;

    case 'manage-medications':
        requireGuardian();
        include 'pages/guardian/manage-medications.php';
        break;

    case 'manage-logs':
        requireGuardian();
        include 'pages/guardian/manage-logs.php';
        break;

    case 'export':
        requireGuardian();
        include 'pages/guardian/export.php';
        break;

    case 'settings':
        requireGuardian();
        include 'pages/guardian/settings.php';
        break;

    case 'database':
        requireGuardian();
        include 'pages/guardian/database.php';
        break;

    case 'translations':
        requireGuardian();
        include 'pages/translations.php';
        break;

    case 'guest-report':
        include 'pages/guest-report.php';
        break;

    case 'logout':
        logout();
        header('Location: index.php');
        exit;

    case 'home':
    default:
        if (isLoggedIn()) {
            $user = getCurrentUser();
            if ($user['type'] === 'guardian') {
                header('Location: index.php?page=dashboard');
            } else {
                header('Location: index.php?page=log-food');
            }
        } else {
            header('Location: index.php?page=login');
        }
        exit;
}
