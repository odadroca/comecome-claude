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
// Sprint security Phase 3 — CSRF helpers (csrfToken/csrfField/verifyCsrf). Loaded
// after session_start() (config.php) so the per-session token can be minted; pages
// embed csrfField() in state-changing forms and renderLayout() emits csrfMetaTag().
require_once 'includes/csrf.php';

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

// Sprint security Phase 0 — force-change the default '0000' guardian PIN.
// A logged-in guardian still on the seeded default PIN cannot reach ANY page
// other than the change-PIN form (manage-users self-edit) or logout, until the
// flag clears (it clears the instant updateUser() persists a non-'0000' PIN).
// The flag is only ON while the stored hash actually verifies '0000', so a
// custom-PIN guardian is NEVER caught by this gate (backward-compat hard gate).
if (!in_array($page, ['login', 'guest-report', 'logout'], true)
    && isLoggedIn() && isGuardian() && guardianPinIsDefault()) {
    // Allow the change-PIN page itself (and its POST handler) through so the
    // guardian can actually clear the gate; everything else redirects there.
    if ($page !== 'manage-users' && $page !== 'manage-children') {
        header('Location: index.php?page=manage-users&pin_change_required=1');
        exit;
    }
}

// Launch Sprint 2 — guardian consent gate.
// A logged-in user who has not acknowledged the current privacy/consent notice
// is intercepted here (AFTER the default-PIN gate so PIN-change keeps precedence).
// Guardians are redirected to the consent screen; children see a neutral
// "not set up yet" message (never the consent form).
// Gate logic:
//   * While the PIN is still the factory default ('0000'), the default-PIN gate
//     (above) routes the guardian to manage-users to change it; the consent gate
//     stays dormant for that flow so the two gates cannot form a redirect loop.
//   * Once the PIN is non-default, the consent gate blocks EVERY page except
//     login/logout/guest-report/consent -- INCLUDING manage-users/manage-children --
//     so a guardian with a changed PIN but no consent cannot bypass the gate by
//     navigating directly to those pages.
if (!in_array($page, ['login', 'logout', 'guest-report', 'consent'], true)
    && isLoggedIn() && !guardianConsentCurrent()
    && !(isGuardian() && guardianPinIsDefault())) {
    if (isGuardian()) {
        header('Location: index.php?page=consent');
        exit;
    } elseif (isChild()) {
        $content = '<main class="container" style="max-width:640px;margin:3rem auto;">'
            . '<article><h2>' . t('consent_child_blocked_title') . '</h2>'
            . '<p>' . t('consent_child_blocked_body') . '</p></article></main>';
        renderLayout(t('consent_child_blocked_title'), $content);
        exit;
    }
}

// Route to appropriate page
switch ($page) {
    case 'login':
        include 'pages/login.php';
        break;

    // Child pages (with feature toggle guards)
    case 'log-food':
        requireAuth();
        if (isChild() && getSetting('show_food_journal', '1') != '1') {
            header('Location: index.php');
            exit;
        }
        include 'pages/child/log-food.php';
        break;

    case 'check-in':
        requireAuth();
        if (isChild() && getSetting('show_checkin', '1') != '1') {
            header('Location: index.php');
            exit;
        }
        include 'pages/child/check-in.php';
        break;

    case 'weight':
        requireAuth();
        if (isChild() && getSetting('show_weight_tracking', '1') != '1') {
            header('Location: index.php');
            exit;
        }
        include 'pages/child/weight.php';
        break;

    case 'history':
        requireAuth();
        if (isChild() && getSetting('show_food_journal', '1') != '1') {
            header('Location: index.php');
            exit;
        }
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

    case 'manage-sleep':
        requireGuardian();
        include 'pages/guardian/manage-sleep.php';
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

    case 'safeguarding':
        requireGuardian();
        include 'pages/guardian/safeguarding.php';
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

    case 'consent':
        requireGuardian();
        include 'pages/consent.php';
        break;

    case 'home':
    default:
        if (isLoggedIn()) {
            $user = getCurrentUser();
            if ($user['type'] === 'guardian') {
                header('Location: index.php?page=dashboard');
            } else {
                // Redirect to first available child feature
                $childPages = [
                    'log-food' => 'show_food_journal',
                    'check-in' => 'show_checkin',
                    'weight' => 'show_weight_tracking',
                ];
                $defaultPage = 'log-food';
                foreach ($childPages as $pageName => $settingKey) {
                    if (getSetting($settingKey, '1') == '1') {
                        $defaultPage = $pageName;
                        break;
                    }
                }
                header('Location: index.php?page=' . $defaultPage);
            }
        } else {
            header('Location: index.php?page=login');
        }
        exit;
}
