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
 * Sprint 5 — Demographics Foundation.
 *
 * Compute a child's age in WHOLE months from a date_of_birth (YYYY-MM-DD).
 * Returns an integer number of completed months, or null when the DOB is
 * blank/unparseable/in the future (graceful degradation — never throws, never
 * returns a negative age). Uses DateTime::diff so month/year boundaries are
 * handled correctly (no naive 30-day arithmetic).
 *
 * This is the building block for the dashboard age display and, later, the
 * percentile engine (Sprints 7–8). Privacy (decision iii): the derived age is
 * guardian/clinician-side context; the raw DOB never reaches a child page.
 */
function calculateAgeInMonths($dateOfBirth) {
    if (empty($dateOfBirth)) return null;

    try {
        $dob = new DateTime($dateOfBirth);
        $now = new DateTime('now');
    } catch (Exception $e) {
        return null; // unparseable DOB — degrade gracefully
    }

    if ($dob > $now) return null; // future DOB — guard against bad input

    $diff = $dob->diff($now);
    return ($diff->y * 12) + $diff->m;
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

    // Sleep data
    $sleepHistory = getSleepHistory($userId, $startDate, $endDate);
    $sleepQualityHistory = getSleepQualityHistory($userId, $startDate, $endDate);

    // Sprint 3: clinical summary feeds the dashboard "Insights" panel.
    $clinicalSummary = computeClinicalSummary($userId, $startDate, $endDate);

    return [
        'daily_intake' => $dailyIntake,
        'check_ins' => $checkIns,
        'top_foods' => $topFoods,
        'weight_history' => $weightHistory,
        'sleep_history' => $sleepHistory,
        'sleep_quality_history' => $sleepQualityHistory,
        'clinical_summary' => $clinicalSummary
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

    // Sleep data
    $sleepHistory = getSleepHistory($userId, $startDate, $endDate);
    $sleepQualityHistory = getSleepQualityHistory($userId, $startDate, $endDate);

    // Sprint 3: takeaways-first clinical summary (descriptive, never causal).
    $clinicalSummary = computeClinicalSummary($userId, $startDate, $endDate);

    return [
        'user' => $user,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'clinical_summary' => $clinicalSummary,
        'weights' => $weights,
        'medications' => $medications,
        'daily_meal_count' => $dailyMealCount,
        'meals_by_type' => $mealsByType,
        'intake_by_category' => $intakeByCategory,
        'sleep_history' => $sleepHistory,
        'sleep_quality_history' => $sleepQualityHistory
    ];
}

/**
 * Sprint 3 — Clinical Report Hardening + Cross-Feature Correlations.
 *
 * All functions below are descriptive, rule-based read-layers over already-collected
 * data. They store nothing and add zero child-facing surface. Correlations are
 * explicitly framed as associations, never causal claims or diagnoses.
 */

/**
 * Lag-1 (next-day) association between a night's sleep quality and the FOLLOWING
 * day's appetite, mood and total food intake.
 *
 * Rule-based, NOT AI. For each day D that has a self-reported sleep_quality, we pair
 * it with day D+1's appetite_level / mood_level / total intake. We then split the
 * paired days into "good sleep" (quality >= 4) vs "poor sleep" (quality <= 2) buckets
 * and report the signed difference of the means — a simple, explainable measure.
 *
 * Gated on >= 5 paired days; below that returns { enough: false } so the UI can show
 * a friendly "not enough data yet" state.
 *
 * SQL note: daily_checkin keys on check_date; food_log keys on log_date.
 */
function computeCorrelations($userId, $startDate, $endDate) {
    $db = getDB();

    // Check-ins with a self-reported sleep_quality, plus that day's appetite/mood.
    $stmt = $db->prepare("
        SELECT check_date, sleep_quality, appetite_level, mood_level
        FROM daily_checkin
        WHERE user_id = ?
          AND check_date BETWEEN ? AND ?
        ORDER BY check_date
    ");
    $stmt->execute([$userId, $startDate, $endDate]);
    $rows = $stmt->fetchAll();

    // Index check-ins by date for O(1) next-day lookup.
    $byDate = [];
    foreach ($rows as $r) {
        $byDate[$r['check_date']] = $r;
    }

    // Daily total food intake (portion map) for next-day intake pairing.
    $stmt = $db->prepare("
        SELECT
            log_date,
            SUM(CASE
                WHEN portion = 'little' THEN 0.25
                WHEN portion = 'some' THEN 0.5
                WHEN portion = 'lot' THEN 0.75
                WHEN portion = 'all' THEN 1.0
                ELSE 0
            END) as total_intake
        FROM food_log
        WHERE user_id = ?
          AND log_date BETWEEN ? AND ?
        GROUP BY log_date
    ");
    $stmt->execute([$userId, $startDate, $endDate]);
    $intakeByDate = [];
    foreach ($stmt->fetchAll() as $r) {
        $intakeByDate[$r['log_date']] = (float) $r['total_intake'];
    }

    // Build lag-1 pairs: sleep on day D vs appetite/mood/intake on day D+1.
    $pairs = [];
    foreach ($byDate as $date => $row) {
        if ($row['sleep_quality'] === null || $row['sleep_quality'] === '') {
            continue;
        }
        $nextDate = date('Y-m-d', strtotime($date . ' +1 day'));
        $next = $byDate[$nextDate] ?? null;
        if (!$next) {
            continue;
        }
        $pairs[] = [
            'sleep'    => (int) $row['sleep_quality'],
            'appetite' => $next['appetite_level'] !== null ? (int) $next['appetite_level'] : null,
            'mood'     => $next['mood_level'] !== null ? (int) $next['mood_level'] : null,
            'intake'   => $intakeByDate[$nextDate] ?? null,
        ];
    }

    $pairedDays = count($pairs);
    if ($pairedDays < 5) {
        return ['enough' => false, 'paired_days' => $pairedDays];
    }

    return [
        'enough' => true,
        'paired_days' => $pairedDays,
        'sleep_vs_next_appetite' => correlationDirection($pairs, 'appetite'),
        'sleep_vs_next_mood'     => correlationDirection($pairs, 'mood'),
    ];
}

/**
 * Internal: turn paired days into a {direction, note, key} association descriptor
 * for one next-day metric. Compares the mean of the metric after good sleep
 * (quality >= 4) vs after poor sleep (quality <= 2). A small threshold avoids
 * over-reading noise. Returns translation KEYS so callers stay locale-agnostic.
 */
function correlationDirection($pairs, $metric) {
    $good = [];
    $poor = [];
    foreach ($pairs as $p) {
        if ($p[$metric] === null) {
            continue;
        }
        if ($p['sleep'] >= 4) {
            $good[] = $p[$metric];
        } elseif ($p['sleep'] <= 2) {
            $poor[] = $p[$metric];
        }
    }

    // Need both buckets populated to make any comparison.
    if (count($good) === 0 || count($poor) === 0) {
        return ['direction' => 'none', 'note_key' => 'correlation_none'];
    }

    $goodMean = array_sum($good) / count($good);
    $poorMean = array_sum($poor) / count($poor);
    $delta = $goodMean - $poorMean;

    // Threshold of 0.5 (on the 1-5 scale) before we call it a direction.
    if ($delta >= 0.5) {
        $direction = 'positive';
    } elseif ($delta <= -0.5) {
        $direction = 'negative';
    } else {
        return ['direction' => 'none', 'note_key' => 'correlation_none'];
    }

    $suffix = $metric === 'mood' ? 'mood' : 'appetite';
    return [
        'direction' => $direction,
        'note_key'  => 'correlation_' . $direction . '_' . $suffix,
        'delta'     => round($delta, 2),
    ];
}

/**
 * Derive descriptive night-sleep statistics from sleep_log + sleep_interruptions.
 * Nothing is stored.
 *
 * Defensive time parsing: sleep_start/sleep_end were created TEXT by
 * migrateDatabase() but declared DATETIME in schema.sql, so we parse with
 * strtotime() and silently skip rows that don't parse. Overnight spans (end < start)
 * are wrapped to the next day.
 */
function deriveSleepStats($userId, $startDate, $endDate) {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT id, sleep_start, sleep_end
        FROM sleep_log
        WHERE user_id = ?
          AND sleep_type = 'night'
          AND log_date BETWEEN ? AND ?
    ");
    $stmt->execute([$userId, $startDate, $endDate]);
    $nights = $stmt->fetchAll();

    $durations = [];
    foreach ($nights as $night) {
        if (empty($night['sleep_start']) || empty($night['sleep_end'])) {
            continue;
        }
        $start = strtotime($night['sleep_start']);
        $end = strtotime($night['sleep_end']);
        if ($start === false || $end === false) {
            continue; // garbled time — skip without error
        }
        if ($end <= $start) {
            $end += 86400; // crossed midnight
        }
        $minutes = ($end - $start) / 60;
        // Guard against absurd spans from malformed data.
        if ($minutes > 0 && $minutes <= 1440) {
            $durations[] = $minutes;
        }
    }

    $avgDuration = count($durations) > 0
        ? round(array_sum($durations) / count($durations))
        : null;

    // Interruption frequency: avg interruptions per logged night in the window.
    $stmt = $db->prepare("
        SELECT COUNT(*) AS total_nights
        FROM sleep_log
        WHERE user_id = ?
          AND sleep_type = 'night'
          AND log_date BETWEEN ? AND ?
    ");
    $stmt->execute([$userId, $startDate, $endDate]);
    $totalNights = (int) ($stmt->fetch()['total_nights'] ?? 0);

    $stmt = $db->prepare("
        SELECT COUNT(*) AS total_interruptions
        FROM sleep_interruptions si
        JOIN sleep_log sl ON si.sleep_log_id = sl.id
        WHERE sl.user_id = ?
          AND sl.sleep_type = 'night'
          AND sl.log_date BETWEEN ? AND ?
    ");
    $stmt->execute([$userId, $startDate, $endDate]);
    $totalInterruptions = (int) ($stmt->fetch()['total_interruptions'] ?? 0);

    $interruptionFreq = $totalNights > 0
        ? round($totalInterruptions / $totalNights, 2)
        : null;

    return [
        'avg_duration_min'   => $avgDuration,
        'nights_with_times'  => count($durations),
        'total_nights'       => $totalNights,
        'interruption_freq'  => $interruptionFreq,
    ];
}

/**
 * Internal: average + simple linear-slope trend for a daily_checkin metric over the
 * window. Returns { avg, slope, trend_key } where trend_key is a translation key.
 * Slope is per-day via least squares over the ordered series; we only classify the
 * direction (rising/falling/stable), so the absolute slope magnitude isn't surfaced.
 */
function checkinTrend($userId, $startDate, $endDate, $column) {
    // Whitelist the column name (it is interpolated, never user-supplied here, but
    // we guard anyway to keep the prepared-statement discipline intact).
    $allowed = ['appetite_level', 'mood_level', 'sleep_quality'];
    if (!in_array($column, $allowed, true)) {
        return ['avg' => null, 'slope' => null, 'trend_key' => 'trend_flat'];
    }

    $db = getDB();
    $stmt = $db->prepare("
        SELECT check_date, $column AS val
        FROM daily_checkin
        WHERE user_id = ?
          AND check_date BETWEEN ? AND ?
          AND $column IS NOT NULL
        ORDER BY check_date
    ");
    $stmt->execute([$userId, $startDate, $endDate]);
    $rows = $stmt->fetchAll();

    $n = count($rows);
    if ($n === 0) {
        return ['avg' => null, 'slope' => null, 'trend_key' => 'trend_flat'];
    }

    $values = [];
    foreach ($rows as $r) {
        $values[] = (float) $r['val'];
    }
    $avg = round(array_sum($values) / $n, 1);

    if ($n < 2) {
        return ['avg' => $avg, 'slope' => 0.0, 'trend_key' => 'trend_flat'];
    }

    // Least-squares slope over x = 0..n-1.
    $sumX = 0; $sumY = 0; $sumXY = 0; $sumXX = 0;
    foreach ($values as $i => $y) {
        $sumX += $i;
        $sumY += $y;
        $sumXY += $i * $y;
        $sumXX += $i * $i;
    }
    $denom = ($n * $sumXX) - ($sumX * $sumX);
    $slope = $denom != 0 ? (($n * $sumXY) - ($sumX * $sumY)) / $denom : 0.0;
    $slope = round($slope, 3);

    if ($slope >= 0.05) {
        $trendKey = 'trend_up';
    } elseif ($slope <= -0.05) {
        $trendKey = 'trend_down';
    } else {
        $trendKey = 'trend_flat';
    }

    return ['avg' => $avg, 'slope' => $slope, 'trend_key' => $trendKey];
}

/**
 * Overall medication adherence percentage over the window, aggregated across all
 * the child's assigned medications. Mirrors the existing per-med aggregation used
 * by getReportData() but collapsed to a single headline figure. Returns null when
 * there is nothing to measure (no meds assigned / no check-ins in range).
 */
function computeMedAdherence($userId, $startDate, $endDate) {
    $db = getDB();

    // Does the child have any assigned medication at all?
    $stmt = $db->prepare("SELECT COUNT(*) AS c FROM user_medications WHERE user_id = ?");
    $stmt->execute([$userId]);
    if ((int) ($stmt->fetch()['c'] ?? 0) === 0) {
        return null;
    }

    $stmt = $db->prepare("
        SELECT
            SUM(CASE WHEN medication_taken = 1 THEN 1 ELSE 0 END) AS taken,
            COUNT(*) AS total
        FROM daily_checkin
        WHERE user_id = ?
          AND check_date BETWEEN ? AND ?
    ");
    $stmt->execute([$userId, $startDate, $endDate]);
    $row = $stmt->fetch();
    $total = (int) ($row['total'] ?? 0);
    if ($total === 0) {
        return null;
    }
    $taken = (int) ($row['taken'] ?? 0);
    return round(($taken / $total) * 100);
}

/**
 * Aggregate the takeaways-first "Clinical Summary" used by every report surface
 * (HTML / CSV / JSON / guest-report) and the dashboard Insights panel. Pure
 * read-layer; stores nothing. Sparse data degrades gracefully (null fields).
 */
function computeClinicalSummary($userId, $startDate, $endDate) {
    $appetite = checkinTrend($userId, $startDate, $endDate, 'appetite_level');
    $mood     = checkinTrend($userId, $startDate, $endDate, 'mood_level');
    $sleepQ   = checkinTrend($userId, $startDate, $endDate, 'sleep_quality');
    $sleepStats = deriveSleepStats($userId, $startDate, $endDate);

    return [
        'med_adherence_pct' => computeMedAdherence($userId, $startDate, $endDate),
        'appetite_trend'    => $appetite,
        'mood_trend'        => $mood,
        'sleep' => [
            'avg_quality'        => $sleepQ['avg'],
            'avg_duration_min'   => $sleepStats['avg_duration_min'],
            'interruption_freq'  => $sleepStats['interruption_freq'],
            'nights_with_times'  => $sleepStats['nights_with_times'],
            'total_nights'       => $sleepStats['total_nights'],
        ],
        'correlations' => computeCorrelations($userId, $startDate, $endDate),
    ];
}

/**
 * Whitelisted projection of report data for the JSON export surface (decision iii).
 *
 * NEVER serialize user.pin or other credential/internal columns. This is the single
 * choke-point that keeps the JSON export from auto-leaking sensitive fields added by
 * later sprints (gender / date_of_birth / percentiles). New sensitive fields must be
 * added here explicitly and on purpose.
 */
function projectReportForJson($reportData) {
    $user = $reportData['user'] ?? [];

    // Explicit user field whitelist — pin/credential columns deliberately excluded.
    $safeUser = [
        'id'           => $user['id'] ?? null,
        'name'         => $user['name'] ?? null,
        'type'         => $user['type'] ?? null,
        'avatar_emoji' => $user['avatar_emoji'] ?? null,
    ];

    return [
        'user'              => $safeUser,
        'start_date'        => $reportData['start_date'] ?? null,
        'end_date'          => $reportData['end_date'] ?? null,
        'clinical_summary'  => $reportData['clinical_summary'] ?? null,
        'weights'           => $reportData['weights'] ?? [],
        'medications'       => $reportData['medications'] ?? [],
        'daily_meal_count'  => $reportData['daily_meal_count'] ?? [],
        'meals_by_type'     => $reportData['meals_by_type'] ?? [],
        'intake_by_category'=> $reportData['intake_by_category'] ?? [],
        'sleep_history'     => $reportData['sleep_history'] ?? [],
        'sleep_quality_history' => $reportData['sleep_quality_history'] ?? [],
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
