<?php
/**
 * Helper Functions
 */

// Sprint 8 — Percentiles Display. The dashboard + report data builders below weave
// in WHO percentile ranks/zones/trajectories, so the side-effect-free Sprint-7
// engine must be available here. require_once is safe: tests/smoke.php and the
// router may also pull it in. The engine itself loads its WHO LMS tables lazily.
require_once __DIR__ . '/percentiles.php';

// Sprint 11 — Growth-Support Nutrition Intelligence. The dashboard + report data
// builders below attach a rule-based nutrition-intelligence block (medication-timing,
// growth-tag coverage, recommendations). Guardian/clinician-side only; gated internally
// on show_nutrition_insights (default OFF). medication.php (its schedule helpers) is
// already loaded transitively via includes/db.php.
require_once __DIR__ . '/nutrition.php';

/**
 * Sensible log_time for a (possibly backdated) food entry — used by the child
 * history "add a past meal" link and the guardian Manage-Logs add form, so neither
 * needs a time picker (decision: meal + portion only).
 *
 * For TODAY, use the real wall-clock time (the child is logging "now"). For a PAST
 * date, use the selected meal's start time (e.g. supper ~19:00) — a sensible default
 * that keeps the server-side med_window stamping roughly accurate for backdated rows.
 * Falls back to noon if the meal has no start time. Returns "HH:MM:SS".
 */
function defaultLogTimeForDate($mealId, $logDate) {
    if ($logDate === date('Y-m-d')) {
        return date('H:i:s');
    }
    $db = getDB();
    $stmt = $db->prepare("SELECT time_start FROM meals WHERE id = ?");
    $stmt->execute([$mealId]);
    $t = $stmt->fetchColumn();
    if ($t) {
        // meals.time_start is stored as 'HH:MM'; normalize to 'HH:MM:SS'.
        return (strlen($t) === 5) ? $t . ':00' : $t;
    }
    return '12:00:00';
}

/**
 * Validate a 'Y-m-d' string and clamp anything invalid or in the future to today.
 * Used to sanitize a client-supplied log_date (e.g. the child backdate link).
 */
function clampLogDate($logDate) {
    $today = date('Y-m-d');
    if (!is_string($logDate) || $logDate === '') return $today;
    $d = DateTime::createFromFormat('Y-m-d', $logDate);
    if (!$d || $d->format('Y-m-d') !== $logDate) return $today;   // malformed
    return ($logDate > $today) ? $today : $logDate;               // no future dates
}

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
 * Sprint 6 — Growth Page Foundation.
 *
 * Body Mass Index from weight (kg) and height (cm): BMI = kg / m^2, rounded to one
 * decimal (the clinical display convention). This is the same arithmetic the WHO/CDC
 * BMI-for-age percentile engine (Sprints 7–8) will consume.
 *
 * Null-guards missing/invalid inputs (graceful degradation, decision iv): returns
 * null when either value is blank, non-numeric, or non-positive — height_cm is
 * OPTIONAL, so a child may have weight without a paired height and BMI is simply
 * unavailable rather than throwing or producing a divide-by-zero.
 */
function calculateBMI($weightKg, $heightCm) {
    if ($weightKg === null || $heightCm === null) return null;
    if (!is_numeric($weightKg) || !is_numeric($heightCm)) return null;
    if ($weightKg <= 0 || $heightCm <= 0) return null;

    $heightM = $heightCm / 100;
    $bmi = $weightKg / ($heightM * $heightM);

    return round($bmi, 1);
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
    // Sprint security Phase 5 — decrypt-on-read of the scoped notes column for the
    // dashboard check-in table (no-op passthrough with no key / plaintext rows).
    // appetite/mood/sleep_quality stay cleartext and feed the aggregations.
    $checkIns = function_exists('decryptRowsFields')
        ? decryptRowsFields($stmt->fetchAll(), ['notes'])
        : $stmt->fetchAll();

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

    // Sprint 8: Growth Percentiles block (guardian dashboard). Gated internally on
    // show_percentiles + gender/DOB; returns a graceful prompt descriptor otherwise.
    $percentiles = computePercentileSummary($userId, $startDate, $endDate);

    // Sprint 8 (task 4): weave a one-line percentile trajectory into the clinical
    // summary narrative the Insights panel renders.
    $clinicalSummary['percentile_trajectory'] = percentileTrajectoryLine($percentiles);

    // Sprint 11: rule-based nutrition intelligence (med-timing, tag coverage,
    // recommendations). Gated internally on show_nutrition_insights; reuses the
    // percentile block just computed for the growth-trajectory rule.
    $nutrition = buildNutritionIntelligence($userId, $startDate, $endDate, $percentiles);

    return [
        'daily_intake' => $dailyIntake,
        'check_ins' => $checkIns,
        'top_foods' => $topFoods,
        'weight_history' => $weightHistory,
        'sleep_history' => $sleepHistory,
        'sleep_quality_history' => $sleepQualityHistory,
        'clinical_summary' => $clinicalSummary,
        'percentiles' => $percentiles,
        'nutrition' => $nutrition
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
    // Sprint security Phase 5 — decrypt-on-read of the scoped medication name/dose for
    // the report/export surfaces. The GROUP BY m.id, m.name, m.dose groups correctly
    // because each medication's blob is consistent per row; we decrypt AFTER fetch.
    $medications = function_exists('decryptRowsFields')
        ? decryptRowsFields($stmt->fetchAll(), ['name', 'dose'])
        : $stmt->fetchAll();

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

    // Sprint 8: WHO percentile block for the clinician/guardian report surfaces
    // (HTML / CSV / JSON / guest-report). Gated internally; graceful when disabled
    // or demographics are missing.
    $percentiles = computePercentileSummary($userId, $startDate, $endDate);

    // Sprint 8 (task 4): one-line percentile trajectory in the clinical narrative.
    $clinicalSummary['percentile_trajectory'] = percentileTrajectoryLine($percentiles);

    // Sprint 11: rule-based nutrition intelligence for the clinician report surfaces
    // (HTML / CSV / JSON / guest-report). Gated internally; reuses $percentiles.
    $nutrition = buildNutritionIntelligence($userId, $startDate, $endDate, $percentiles);

    return [
        'user' => $user,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'clinical_summary' => $clinicalSummary,
        'percentiles' => $percentiles,
        'nutrition' => $nutrition,
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
 * Sprint 8 — Percentiles Display (guardian + clinician surfaces only).
 *
 * Build the "Growth Percentiles" data block for a child: current weight/height/BMI
 * percentile ranks (+ band/zone), and a per-metric trajectory narrative computed at
 * QUERY time from weight_log + height_log + date_of_birth (nothing stored). Reuses
 * the Sprint-7 WHO engine end to end.
 *
 * GATING (Sprint-8 scope + decision iv): percentiles are shown ONLY when the
 * show_percentiles toggle is ON AND the child has BOTH gender and date_of_birth.
 * Otherwise this returns a graceful descriptor the surfaces use to render the
 * "complete gender/DOB to enable" prompt — it NEVER blocks and NEVER throws.
 *
 * Return shape:
 *   [
 *     'available'  => bool,                 // true only when fully computable
 *     'reason'     => null|'disabled'|'missing_demographics',
 *     'age_months' => int|null,             // derived age (guardian/clinician context)
 *     'sex'        => 'male'|'female'|null,
 *     'current'    => [                      // null entries where not computable
 *        'weight' => null|{value,percentile,rank,zone,zone_label_key,nearest_band},
 *        'height' => ...,
 *        'bmi'    => ...,
 *     ],
 *     'trends'     => [ 'weight'=>null|{...}, 'height'=>null|{...} ],
 *   ]
 *
 * CHILD BOUNDARY: gender/DOB never travel to a child page; this is consumed only by
 * the guardian dashboard, exports and the clinician/guest report.
 */
function computePercentileSummary($userId, $startDate, $endDate) {
    $base = [
        'available'  => false,
        'reason'     => null,
        'age_months' => null,
        'sex'        => null,
        'current'    => ['weight' => null, 'height' => null, 'bmi' => null],
        'trends'     => ['weight' => null, 'height' => null],
    ];

    // Toggle gate. When OFF there is nothing to show (and no prompt either).
    if (getSetting('show_percentiles', '0') !== '1') {
        $base['reason'] = 'disabled';
        return $base;
    }

    $user = getUserById($userId);
    $sex = $user['gender'] ?? null;
    $dob = $user['date_of_birth'] ?? null;
    $base['sex'] = $sex;

    // Demographics gate (decision iv): missing gender/DOB => graceful prompt, never block.
    if (empty($sex) || empty($dob)) {
        $base['reason'] = 'missing_demographics';
        return $base;
    }

    $ageMonths = calculateAgeInMonths($dob);
    $base['age_months'] = $ageMonths;

    $db = getDB();

    // Latest weight + height in window (current rank uses the most recent value).
    $stmt = $db->prepare("
        SELECT weight_kg, log_date FROM weight_log
        WHERE user_id = ? AND log_date BETWEEN ? AND ?
        ORDER BY log_date
    ");
    $stmt->execute([$userId, $startDate, $endDate]);
    $weights = $stmt->fetchAll();

    $stmt = $db->prepare("
        SELECT height_cm, log_date FROM height_log
        WHERE user_id = ? AND log_date BETWEEN ? AND ?
        ORDER BY log_date
    ");
    $stmt->execute([$userId, $startDate, $endDate]);
    $heights = $stmt->fetchAll();

    $latestWeight = !empty($weights) ? $weights[count($weights) - 1] : null;
    $latestHeight = !empty($heights) ? $heights[count($heights) - 1] : null;

    // CURRENT ranks (age "now" for the latest measurement display).
    if ($latestWeight !== null && $ageMonths !== null) {
        $base['current']['weight'] = describeMetricPercentile(
            'weight_for_age', (float) $latestWeight['weight_kg'], $ageMonths, $sex
        );
    }
    if ($latestHeight !== null && $ageMonths !== null) {
        $base['current']['height'] = describeMetricPercentile(
            'height_for_age', (float) $latestHeight['height_cm'], $ageMonths, $sex
        );
    }
    // BMI needs a same-window weight+height pair; use the two latest values.
    if ($latestWeight !== null && $latestHeight !== null && $ageMonths !== null) {
        $bmi = calculateBMI((float) $latestWeight['weight_kg'], (float) $latestHeight['height_cm']);
        if ($bmi !== null) {
            $base['current']['bmi'] = describeMetricPercentile(
                'bmi_for_age', $bmi, $ageMonths, $sex
            );
        }
    }

    // TRAJECTORIES over time (percentile-over-time computed at query time).
    $wSeries = percentileSeriesFromRows($weights, 'log_date', 'weight_kg', 'weight_for_age', $dob, $sex);
    $hSeries = percentileSeriesFromRows($heights, 'log_date', 'height_cm', 'height_for_age', $dob, $sex);
    $base['trends']['weight'] = describePercentileTrend($wSeries);
    $base['trends']['height'] = describePercentileTrend($hSeries);

    // "available" = the toggle is on, demographics present, and at least ONE current
    // rank computed. (A child with gender+DOB but an out-of-coverage age still shows
    // the section header; surfaces guard each metric individually.)
    $base['available'] = (
        $base['current']['weight'] !== null
        || $base['current']['height'] !== null
        || $base['current']['bmi'] !== null
    );

    return $base;
}

/**
 * Sprint 8 — one-line percentile trajectory woven into the Sprint-3 clinical_summary
 * narrative (task 4). Picks the most clinically salient available trajectory
 * (weight first, else height) and returns a compact descriptor with the translation
 * key + from/to ranks + months, or null when no trajectory is tellable. Pure
 * read-layer; clinician/guardian surfaces only.
 */
function percentileTrajectoryLine($percentiles) {
    if (!is_array($percentiles) || empty($percentiles['available'])) {
        return null;
    }
    $trends = $percentiles['trends'] ?? [];
    foreach (['weight', 'height'] as $metric) {
        $tr = $trends[$metric] ?? null;
        if ($tr !== null && isset($tr['from_rank'], $tr['to_rank'])) {
            return [
                'metric'        => $metric,
                'metric_key'    => $metric === 'weight' ? 'weight_for_age' : 'height_for_age',
                'from_rank'     => $tr['from_rank'],
                'to_rank'       => $tr['to_rank'],
                'months'        => $tr['months'],
                'direction'     => $tr['direction'],
                'narrative_key' => $tr['narrative_key'],
            ];
        }
    }
    return null;
}

/**
 * Sprint 8 — render a localized trajectory one-liner from a trend descriptor
 * (the {from}/{to}/{months} narrative). Returns '' when there is nothing to say,
 * so callers can `if ($s = formatPercentileTrajectory($tr)) { ... }`.
 */
function formatPercentileTrajectory($trend) {
    if (!is_array($trend) || empty($trend['narrative_key'])) {
        return '';
    }
    $months = $trend['months'];
    $monthsLabel = ($months === 1) ? t('month') : t('months');
    return t($trend['narrative_key'], [
        'from'         => $trend['from_rank'] ?? '',
        'to'           => $trend['to_rank'] ?? '',
        'months'       => ($months === null ? '?' : $months),
        'months_label' => $monthsLabel,
    ]);
}

/**
 * Sprint 8 — SHARED percentile-section HTML used by BOTH the guardian dashboard and
 * the HTML/guest report, so the four export surfaces stay in PARITY by construction.
 * Pure presentation over a $percentiles block from computePercentileSummary().
 *
 * $variant: 'dashboard' (richer card markup) or 'report' (compact print markup).
 * Honors the gating: 'disabled' => renders nothing; 'missing_demographics' =>
 * a gentle "complete gender/DOB" prompt (guardian/clinician surface only, never the
 * child). CHILD BOUNDARY: this function is never called from a child page.
 *
 * Returns an HTML string (escaped where it interpolates data).
 */
function renderPercentileSection($percentiles, $variant = 'dashboard') {
    if (!is_array($percentiles)) {
        return '';
    }
    $reason = $percentiles['reason'] ?? null;

    // Toggle OFF => show nothing at all on any surface.
    if ($reason === 'disabled') {
        return '';
    }

    $isReport = ($variant === 'report');
    ob_start();

    // Missing gender/DOB => graceful prompt (decision iv), never blocks.
    if (!($percentiles['available'] ?? false)) {
        if ($reason === 'missing_demographics') {
            ?>
            <div class="percentile-prompt" style="border:1px dashed #bbb;padding:10px 14px;border-radius:6px;opacity:0.85;">
                📈 <strong><?php echo t('growth_percentiles'); ?>:</strong>
                <?php echo t('percentile_complete_dob_prompt'); ?>
            </div>
            <?php
        } else {
            // Enabled + demographics present, but no computable measurement yet.
            ?>
            <div class="percentile-prompt" style="opacity:0.8;">
                📈 <strong><?php echo t('growth_percentiles'); ?>:</strong>
                <?php echo t('percentile_no_measurements'); ?>
            </div>
            <?php
        }
        return ob_get_clean();
    }

    $zoneColor = function ($zone) {
        switch ($zone) {
            case 'green':  return '#2e7d32';
            case 'yellow': return '#b8860b';
            case 'red':    return '#c62828';
            default:       return '#777';
        }
    };

    $metrics = [
        'weight' => 'weight_for_age',
        'height' => 'height_for_age',
        'bmi'    => 'bmi_for_age',
    ];
    ?>
    <table class="percentile-table" style="width:100%;border-collapse:collapse;<?php echo $isReport ? '' : 'margin-top:0.5rem;'; ?>">
        <thead>
            <tr>
                <th style="text-align:left;border:1px solid #ddd;padding:4px;"><?php echo t('percentile'); ?></th>
                <th style="text-align:left;border:1px solid #ddd;padding:4px;"><?php echo t('percentile_rank'); ?></th>
                <th style="text-align:left;border:1px solid #ddd;padding:4px;"><?php echo t('percentile_band'); ?></th>
                <th style="text-align:left;border:1px solid #ddd;padding:4px;"><?php echo t('percentile_trajectory_label'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($metrics as $key => $labelKey):
                $cur = $percentiles['current'][$key] ?? null;
                if ($cur === null) continue;
                $trend = ($key === 'bmi') ? null : ($percentiles['trends'][$key] ?? null);
                $trajStr = $trend ? formatPercentileTrajectory($trend) : '';
            ?>
            <tr>
                <td style="border:1px solid #ddd;padding:4px;"><?php echo t($labelKey); ?></td>
                <td style="border:1px solid #ddd;padding:4px;">
                    <strong><?php echo sanitize($cur['rank']); ?></strong>
                </td>
                <td style="border:1px solid #ddd;padding:4px;">
                    <span style="color:<?php echo $zoneColor($cur['zone']); ?>;font-weight:bold;">●</span>
                    <?php echo t($cur['zone_label_key']); ?>
                </td>
                <td style="border:1px solid #ddd;padding:4px;">
                    <?php echo $trajStr !== '' ? sanitize($trajStr) : '—'; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p style="font-size:<?php echo $isReport ? '7pt' : '0.75rem'; ?>;opacity:0.7;margin-top:6px;">
        <?php echo t('percentile_reference_who'); ?>
    </p>
    <?php
    return ob_get_clean();
}

/**
 * Whitelisted projection of report data for the JSON export surface (decision iii).
 *
 * NEVER serialize user.pin or other credential/internal columns. This is the single
 * choke-point that keeps the JSON export from auto-leaking sensitive fields added by
 * later sprints (gender / date_of_birth / percentiles). New sensitive fields must be
 * added here explicitly and on purpose.
 *
 * Sprint 8 (decision iii): include gender + derived AGE + the percentile block, but
 * NEVER the raw date_of_birth in this guest-token-shareable path (age is sufficient
 * for a clinician artifact), and NEVER user.pin.
 */
function projectReportForJson($reportData) {
    $user = $reportData['user'] ?? [];

    // Derive AGE from DOB here; the raw date_of_birth is deliberately NOT emitted on
    // this guest-token-shareable path (decision iii — age is sufficient for a shared
    // clinician artifact; exact birthdate stays guardian-side only).
    $ageMonths = isset($user['date_of_birth']) && $user['date_of_birth'] !== null
        ? calculateAgeInMonths($user['date_of_birth'])
        : null;

    // Explicit user field whitelist — pin/credential AND raw date_of_birth excluded.
    // gender + derived age ARE clinically necessary and allowed (decision iii).
    $safeUser = [
        'id'           => $user['id'] ?? null,
        'name'         => $user['name'] ?? null,
        'type'         => $user['type'] ?? null,
        'avatar_emoji' => $user['avatar_emoji'] ?? null,
        'gender'       => $user['gender'] ?? null,
        'age_months'   => $ageMonths,
        // NOTE: 'pin' and 'date_of_birth' are intentionally absent (never serialized).
    ];

    // Percentile block: re-key so the raw DOB / 'sex' never leaks here either — keep
    // gender as a clinical label plus the derived age and the ranks/zones/trends.
    $pct = $reportData['percentiles'] ?? null;
    $safePct = null;
    if (is_array($pct)) {
        $safePct = [
            'available'  => $pct['available'] ?? false,
            'reason'     => $pct['reason'] ?? null,
            'age_months' => $pct['age_months'] ?? null,
            'sex'        => $pct['sex'] ?? null,
            'current'    => $pct['current'] ?? null,
            'trends'     => $pct['trends'] ?? null,
        ];
    }

    // Sprint 11 — nutrition intelligence is already a de-identified aggregate (window
    // shares, weekly tag rates, rule-based recommendation keys/params). It contains NO
    // names, DOB, or raw food-log rows, so it is safe to project; we still re-key
    // explicitly here (the single choke-point) so future additions stay deliberate.
    $ni = $reportData['nutrition'] ?? null;
    $safeNi = null;
    if (is_array($ni)) {
        $safeNi = [
            'available'       => $ni['available'] ?? false,
            'reason'          => $ni['reason'] ?? null,
            'window_days'     => $ni['window_days'] ?? null,
            'timing'          => $ni['timing'] ?? null,
            'coverage'        => $ni['coverage'] ?? null,
            'recommendations' => $ni['recommendations'] ?? [],
            'tag_index'       => $ni['tag_index'] ?? null,
        ];
    }

    return [
        'user'              => $safeUser,
        'start_date'        => $reportData['start_date'] ?? null,
        'end_date'          => $reportData['end_date'] ?? null,
        'clinical_summary'  => $reportData['clinical_summary'] ?? null,
        'percentiles'       => $safePct,
        'nutrition'         => $safeNi,
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
        <meta name="theme-color" content="#1FA4B5">
        <title><?php echo sanitize($title); ?> - <?php echo t('app_name'); ?></title>
        <link rel="stylesheet" href="assets/css/pico.min.css">
        <link rel="stylesheet" href="assets/css/custom.css">
        <link rel="stylesheet" href="assets/css/comecome-theme.css"><!-- design refresh -->
        <link rel="manifest" href="manifest.json">
        <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🍽️</text></svg>">
        <script>
        (function(){var t=localStorage.getItem('comecome_theme');if(t)document.documentElement.setAttribute('data-theme',t);else if(window.matchMedia&&window.matchMedia('(prefers-color-scheme:dark)').matches)document.documentElement.setAttribute('data-theme','dark');})();
        </script>
        <?php
        // Sprint security Phase 3 — surface the per-session CSRF token to inline
        // fetch() callers (child + guardian pages) via <meta> + window.CSRF_TOKEN,
        // so the api endpoints can require it in an X-CSRF-Token header. Guarded so
        // a context that didn't load csrf.php still renders.
        if (function_exists('csrfMetaTag')) { echo csrfMetaTag() . "\n"; }
        ?>
        <?php echo $additionalHead; ?>
    </head>
    <body>
        <?php echo $content; ?>
        <script src="assets/js/app.js"></script>
    </body>
    </html>
    <?php
}
