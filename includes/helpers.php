<?php
/**
 * Helper Functions
 */

/**
 * Convert portion text to numeric value for calculations
 */
function portionToValue($portion) {
    $values = [
        'little' => 0.25,
        'some' => 0.5,
        'lot' => 0.75,
        'all' => 1.0
    ];
    return $values[$portion] ?? 0;
}

/**
 * Sanitize input
 */
function sanitize($input) {
    if (!is_string($input)) $input = (string) $input;
    return htmlspecialchars(strip_tags($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Format date for display
 */
function formatDate($date, $format = 'd-m-Y') {
    if (empty($date)) return '';
    $dt = new DateTime($date);
    return $dt->format($format);
}

/**
 * Get date range for period
 */
function getDateRangeForPeriod($period) {
    $endDate = date('Y-m-d');

    switch ($period) {
        case '7':
            $startDate = date('Y-m-d', strtotime('-7 days'));
            break;
        case '14':
            $startDate = date('Y-m-d', strtotime('-14 days'));
            break;
        case '30':
            $startDate = date('Y-m-d', strtotime('-30 days'));
            break;
        case 'all':
        default:
            $startDate = date('Y-m-d', strtotime('-1 year'));
            break;
    }

    return [$startDate, $endDate];
}

/**
 * JSON response helper
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Get dashboard data for a user
 */
function getDashboardData($userId, $startDate, $endDate) {
    $db = getDB();

    // Daily intake by meal
    $stmt = $db->prepare("
        SELECT
            fl.log_date,
            m.name_key as meal_name_key,
            COUNT(fl.id) as count,
            SUM(CASE
                WHEN fl.portion = 'little' THEN 0.25
                WHEN fl.portion = 'some' THEN 0.5
                WHEN fl.portion = 'lot' THEN 0.75
                WHEN fl.portion = 'all' THEN 1.0
            END) as total_quantity
        FROM food_log fl
        JOIN meals m ON fl.meal_id = m.id
        WHERE fl.user_id = ?
        AND fl.log_date BETWEEN ? AND ?
        GROUP BY fl.log_date, m.id, m.name_key
        ORDER BY fl.log_date, m.sort_order
    ");
    $stmt->execute([$userId, $startDate, $endDate]);
    $dailyIntake = $stmt->fetchAll();

    // Appetite and mood history
    $stmt = $db->prepare("
        SELECT * FROM daily_checkin
        WHERE user_id = ?
        AND check_date BETWEEN ? AND ?
        ORDER BY check_date DESC
    ");
    $stmt->execute([$userId, $startDate, $endDate]);
    $checkIns = $stmt->fetchAll();

    // Most eaten foods
    $stmt = $db->prepare("
        SELECT
            f.name_key,
            f.emoji,
            COUNT(fl.id) as times_eaten,
            SUM(CASE
                WHEN fl.portion = 'little' THEN 0.25
                WHEN fl.portion = 'some' THEN 0.5
                WHEN fl.portion = 'lot' THEN 0.75
                WHEN fl.portion = 'all' THEN 1.0
            END) as total_quantity
        FROM food_log fl
        JOIN foods f ON fl.food_id = f.id
        WHERE fl.user_id = ?
        AND fl.log_date BETWEEN ? AND ?
        GROUP BY f.id, f.name_key, f.emoji
        ORDER BY times_eaten DESC
        LIMIT 10
    ");
    $stmt->execute([$userId, $startDate, $endDate]);
    $topFoods = $stmt->fetchAll();

    // Weight timeline
    $stmt = $db->prepare("
        SELECT * FROM weight_log
        WHERE user_id = ?
        AND log_date BETWEEN ? AND ?
        ORDER BY log_date
    ");
    $stmt->execute([$userId, $startDate, $endDate]);
    $weightHistory = $stmt->fetchAll();

    return [
        'daily_intake' => $dailyIntake,
        'check_ins' => $checkIns,
        'top_foods' => $topFoods,
        'weight_history' => $weightHistory
    ];
}

/**
 * Get report data for export
 */
function getReportData($userId, $startDate, $endDate) {
    $db = getDB();
    $user = getUserById($userId);

    // Weight timeline
    $stmt = $db->prepare("
        SELECT * FROM weight_log
        WHERE user_id = ?
        AND log_date BETWEEN ? AND ?
        ORDER BY log_date
    ");
    $stmt->execute([$userId, $startDate, $endDate]);
    $weights = $stmt->fetchAll();

    // Medication adherence
    $stmt = $db->prepare("
        SELECT
            m.name,
            m.dose,
            SUM(CASE WHEN dc.medication_taken = 1 THEN 1 ELSE 0 END) as taken_count,
            SUM(CASE WHEN dc.medication_taken = 0 THEN 1 ELSE 0 END) as missed_count,
            COUNT(*) as total_days
        FROM user_medications um
        JOIN medications m ON um.medication_id = m.id
        LEFT JOIN daily_checkin dc ON dc.user_id = um.user_id
            AND dc.check_date BETWEEN ? AND ?
        WHERE um.user_id = ?
        GROUP BY m.id, m.name, m.dose
    ");
    $stmt->execute([$startDate, $endDate, $userId]);
    $medications = $stmt->fetchAll();

    // Daily meal count
    $stmt = $db->prepare("
        SELECT
            log_date,
            COUNT(DISTINCT meal_id) as meals_logged
        FROM food_log
        WHERE user_id = ?
        AND log_date BETWEEN ? AND ?
        GROUP BY log_date
        ORDER BY log_date
    ");
    $stmt->execute([$userId, $startDate, $endDate]);
    $dailyMealCount = $stmt->fetchAll();

    // Meals by type
    $stmt = $db->prepare("
        SELECT
            m.name_key,
            COUNT(DISTINCT fl.log_date || '-' || fl.meal_id) as times_logged
        FROM food_log fl
        JOIN meals m ON fl.meal_id = m.id
        WHERE fl.user_id = ?
        AND fl.log_date BETWEEN ? AND ?
        GROUP BY m.id, m.name_key
        ORDER BY m.sort_order
    ");
    $stmt->execute([$userId, $startDate, $endDate]);
    $mealsByType = $stmt->fetchAll();

    // Intake by category
    $stmt = $db->prepare("
        SELECT
            fc.name_key,
            SUM(CASE
                WHEN fl.portion = 'little' THEN 0.25
                WHEN fl.portion = 'some' THEN 0.5
                WHEN fl.portion = 'lot' THEN 0.75
                WHEN fl.portion = 'all' THEN 1.0
            END) as total_quantity
        FROM food_log fl
        JOIN foods f ON fl.food_id = f.id
        JOIN food_categories fc ON f.category_id = fc.id
        WHERE fl.user_id = ?
        AND fl.log_date BETWEEN ? AND ?
        GROUP BY fc.id, fc.name_key
        ORDER BY total_quantity DESC
    ");
    $stmt->execute([$userId, $startDate, $endDate]);
    $intakeByCategory = $stmt->fetchAll();

    return [
        'user' => $user,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'weights' => $weights,
        'medications' => $medications,
        'daily_meal_count' => $dailyMealCount,
        'meals_by_type' => $mealsByType,
        'intake_by_category' => $intakeByCategory
    ];
}

/**
 * Get time-based greeting key for i18n
 */
function getTimeGreeting() {
    $hour = (int) date('H');
    if ($hour < 6) return 'greeting_night';
    if ($hour < 12) return 'greeting_morning';
    if ($hour < 18) return 'greeting_afternoon';
    return 'greeting_evening';
}

/**
 * Get time-based greeting emoji
 */
function getTimeEmoji() {
    $hour = (int) date('H');
    if ($hour < 6) return '🌙';
    if ($hour < 12) return '🌅';
    if ($hour < 18) return '☀️';
    return '🌆';
}

/**
 * Get a random fun greeting phrase from greetings.json
 */
function getRandomGreetingPhrase() {
    $locale = getAppLocale();
    $file = LOCALES_PATH . '/greetings.json';

    if (!file_exists($file)) return '';

    $data = json_decode(file_get_contents($file), true);
    if (!$data || !isset($data[$locale])) {
        $locale = 'en'; // fallback
    }
    if (!isset($data[$locale])) return '';

    $hour = (int) date('H');
    if ($hour < 6) $period = 'night';
    elseif ($hour < 12) $period = 'morning';
    elseif ($hour < 18) $period = 'afternoon';
    else $period = 'evening';

    $phrases = $data[$locale][$period] ?? [];
    if (empty($phrases)) return '';

    return $phrases[array_rand($phrases)];
}

/**
 * Get a random encouraging message key for i18n
 */
function getRandomEncouragementKey($type = 'food') {
    $messages = [
        'food' => ['encourage_food_1', 'encourage_food_2', 'encourage_food_3', 'encourage_food_4', 'encourage_food_5'],
        'checkin' => ['encourage_checkin_1', 'encourage_checkin_2', 'encourage_checkin_3', 'encourage_checkin_4', 'encourage_checkin_5'],
        'weight' => ['encourage_weight_1', 'encourage_weight_2', 'encourage_weight_3', 'encourage_weight_4', 'encourage_weight_5'],
    ];
    $keys = $messages[$type] ?? $messages['food'];
    return $keys[array_rand($keys)];
}

/**
 * Render HTML layout
 */
function renderLayout($title, $content, $additionalHead = '') {
    ?>
    <!DOCTYPE html>
    <html lang="<?php echo getAppLocale(); ?>" data-theme="light">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="description" content="<?php echo t('app_tagline'); ?>">
        <meta name="theme-color" content="#4CAF50">
        <title><?php echo sanitize($title); ?> - <?php echo t('app_name'); ?></title>
        <link rel="stylesheet" href="assets/css/pico.min.css">
        <link rel="stylesheet" href="assets/css/custom.css">
        <link rel="manifest" href="manifest.json">
        <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🍽️</text></svg>">
        <script>
        (function(){var t=localStorage.getItem('comecome_theme');if(t)document.documentElement.setAttribute('data-theme',t);else if(window.matchMedia&&window.matchMedia('(prefers-color-scheme:dark)').matches)document.documentElement.setAttribute('data-theme','dark');})();
        </script>
        <?php echo $additionalHead; ?>
    </head>
    <body>
        <?php echo $content; ?>
        <script src="assets/js/app.js"></script>
    </body>
    </html>
    <?php
}
