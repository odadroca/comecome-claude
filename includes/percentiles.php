<?php
/**
 * ComeCome — Percentile / Z-score Engine (WHO LMS method)
 * =============================================================================
 * Sprint 7 — Percentiles Engine + WHO Reference Data (WHO-FIRST, decision i revised).
 *
 * SIDE-EFFECT FREE LIBRARY. No DB writes, no echo, no session, no schema/UI change.
 * Consumes the read-only WHO LMS tables from includes/growth-standards.php and turns
 * a measurement (+ age + sex) into a z-score and a percentile.
 *
 * WHO LMS method (Cole & Green):
 *     Z = ((value / M)^L − 1) / (L · S)        when L ≠ 0
 *     Z = ln(value / M) / S                     when L = 0
 *     Percentile = Φ(Z)                          (standard normal CDF, ×100 for %)
 *
 * Reference-data provenance, sourcing and license: see includes/growth-standards.php.
 * This file is provider-independent maths PLUS thin WHO-table lookups; the genuine
 * WHO numbers all live in growth-standards.php.
 *
 * GRACEFUL DEGRADATION (decision iv): every public lookup returns null rather than
 * throwing when age is out of coverage, sex/metric is unknown, the LMS table is
 * missing, or the input value is non-positive. Displayed z-scores are clamped to
 * ±5 SD so a wildly off measurement can never render an absurd percentile.
 */

/* -------------------------------------------------------------------------
 * WHO LMS table accessor (lazy-loaded once per request).
 * ------------------------------------------------------------------------- */

/**
 * Load and memoise the WHO LMS tables. Returns the nested
 * [metric][sex][ageMonths] => [L, M, S] array from growth-standards.php.
 */
function getGrowthStandards() {
    static $tables = null;
    if ($tables === null) {
        $tables = require __DIR__ . '/growth-standards.php';
    }
    return $tables;
}

/* -------------------------------------------------------------------------
 * Provider-independent maths.
 * ------------------------------------------------------------------------- */

/**
 * WHO LMS z-score for a measurement against one (L, M, S) triple.
 *
 *     Z = ((value/M)^L − 1) / (L·S)   (L ≠ 0)
 *     Z = ln(value/M) / S             (L = 0)
 *
 * Returns null for non-positive value or non-positive M/S (cannot form a ratio /
 * divide) — callers treat null as "not computable" and degrade gracefully.
 * Note: when value == M the result is exactly 0 in both branches.
 */
function calculateZScore($value, $L, $M, $S) {
    if (!is_numeric($value) || !is_numeric($L) || !is_numeric($M) || !is_numeric($S)) {
        return null;
    }
    $value = (float) $value;
    $L = (float) $L; $M = (float) $M; $S = (float) $S;
    if ($value <= 0 || $M <= 0 || $S == 0.0) {
        return null;
    }

    if ($L == 0.0) {
        return log($value / $M) / $S;
    }
    return (pow($value / $M, $L) - 1) / ($L * $S);
}

/**
 * Standard normal CDF Φ(z) via the Abramowitz & Stegun 7.1.26 rational
 * approximation (max abs error ≈ 7.5e-8). Returns a probability in [0, 1].
 *
 * (Implemented through erf-style polynomial in t = 1/(1 + 0.2316419·|z|).)
 */
function zScoreToPercentile($z) {
    if (!is_numeric($z)) {
        return null;
    }
    $z = (float) $z;

    // A&S 7.1.26 coefficients.
    $b1 =  0.319381530;
    $b2 = -0.356563782;
    $b3 =  1.781477937;
    $b4 = -1.821255978;
    $b5 =  1.330274429;
    $p  =  0.2316419;
    $c  =  0.39894228040143267794; // 1/sqrt(2π)

    $az = abs($z);
    $t = 1.0 / (1.0 + $p * $az);
    $poly = $t * ($b1 + $t * ($b2 + $t * ($b3 + $t * ($b4 + $t * $b5))));
    $pdf = $c * exp(-($az * $az) / 2.0);
    $upperTail = $pdf * $poly; // P(Z > az)

    // Φ(z): for z >= 0 it's 1 - upperTail; for z < 0, by symmetry it's upperTail.
    return $z >= 0 ? (1.0 - $upperTail) : $upperTail;
}

/**
 * Clamp a z-score to ±5 SD for display safety (decision: "clamp displayed z beyond
 * ±5 SD"). Keeps a genuinely extreme/implausible measurement from rendering an
 * absurd percentile while still signalling the direction.
 */
function clampZScore($z) {
    if ($z === null || !is_numeric($z)) {
        return null;
    }
    $z = (float) $z;
    if ($z > 5.0)  return 5.0;
    if ($z < -5.0) return -5.0;
    return $z;
}

/* -------------------------------------------------------------------------
 * LMS lookup + linear interpolation between published month points.
 * ------------------------------------------------------------------------- */

/**
 * Resolve the (L, M, S) triple for a metric/sex/fractional-age by LINEAR
 * INTERPOLATION between the two bracketing published integer-month rows.
 *
 *   - $ageMonths may be fractional (e.g. 18.5). It is interpolated between
 *     floor and ceil month rows.
 *   - Out-of-coverage (below the first or above the last published month, or an
 *     unknown metric/sex) returns null — the caller then returns null too.
 *
 * @return array|null [L, M, S]
 */
function lookupLMS($metric, $sex, $ageMonths) {
    if (!is_numeric($ageMonths)) {
        return null;
    }
    $ageMonths = (float) $ageMonths;
    if ($ageMonths < 0) {
        return null;
    }

    $tables = getGrowthStandards();
    if (!isset($tables[$metric][$sex])) {
        return null;
    }
    $series = $tables[$metric][$sex]; // [month => [L,M,S]]

    $months = array_keys($series);
    $minMonth = $months[0];
    $maxMonth = $months[count($months) - 1];

    // Out of coverage → null (never extrapolate beyond the published range).
    if ($ageMonths < $minMonth || $ageMonths > $maxMonth) {
        return null;
    }

    $lower = (int) floor($ageMonths);
    $upper = (int) ceil($ageMonths);

    // Exact integer-month hit (the common case) — no interpolation needed.
    if ($lower === $upper && isset($series[$lower])) {
        return $series[$lower];
    }

    // Defensive: if a bracket row is somehow absent, fall back to the present one.
    if (!isset($series[$lower]) && isset($series[$upper])) {
        return $series[$upper];
    }
    if (!isset($series[$upper]) && isset($series[$lower])) {
        return $series[$lower];
    }
    if (!isset($series[$lower]) || !isset($series[$upper])) {
        return null;
    }

    [$L0, $M0, $S0] = $series[$lower];
    [$L1, $M1, $S1] = $series[$upper];
    $frac = ($ageMonths - $lower) / ($upper - $lower);

    return [
        $L0 + ($L1 - $L0) * $frac,
        $M0 + ($M1 - $M0) * $frac,
        $S0 + ($S1 - $S0) * $frac,
    ];
}

/**
 * Generic metric percentile: value + age(months) + sex → percentile in [0,100],
 * or null when not computable (out-of-coverage age, unknown sex/metric, bad value).
 *
 * sex normalisation: accepts 'boys'/'girls' or the DB's 'male'/'female' (Sprint 5
 * users.gender uses male/female). Anything else → null.
 *
 * The z-score is clamped to ±5 SD BEFORE conversion so the returned percentile is
 * display-safe (decision: clamp beyond ±5 SD).
 */
function calculateMetricPercentile($metric, $value, $ageMonths, $sex) {
    $normSex = normalizeSexKey($sex);
    if ($normSex === null) {
        return null;
    }
    $lms = lookupLMS($metric, $normSex, $ageMonths);
    if ($lms === null) {
        return null;
    }
    [$L, $M, $S] = $lms;
    $z = calculateZScore($value, $L, $M, $S);
    if ($z === null) {
        return null;
    }
    $z = clampZScore($z);
    $p = zScoreToPercentile($z);
    if ($p === null) {
        return null;
    }
    return $p * 100.0;
}

/**
 * Same as calculateMetricPercentile but returns the (clamped) z-score itself, for
 * callers that want the SD score (e.g. ±2 SD flagging in Sprint 8). null when not
 * computable.
 */
function calculateMetricZScore($metric, $value, $ageMonths, $sex) {
    $normSex = normalizeSexKey($sex);
    if ($normSex === null) {
        return null;
    }
    $lms = lookupLMS($metric, $normSex, $ageMonths);
    if ($lms === null) {
        return null;
    }
    [$L, $M, $S] = $lms;
    $z = calculateZScore($value, $L, $M, $S);
    if ($z === null) {
        return null;
    }
    return clampZScore($z);
}

/**
 * Normalise a sex value to the growth-standards table key.
 * Accepts 'boys'/'girls' (table keys) and 'male'/'female' (users.gender, Sprint 5),
 * case-insensitively. Returns 'boys' | 'girls' | null.
 */
function normalizeSexKey($sex) {
    if (!is_string($sex)) {
        return null;
    }
    switch (strtolower(trim($sex))) {
        case 'boys':
        case 'male':
        case 'm':
            return 'boys';
        case 'girls':
        case 'female':
        case 'f':
            return 'girls';
        default:
            return null;
    }
}

/* -------------------------------------------------------------------------
 * Public per-metric helpers (the names the Sprint spec requires).
 * Each takes a raw value, an age in MONTHS, and a sex; returns percentile or null.
 * ------------------------------------------------------------------------- */

/** Weight-for-age percentile (WHO 0–120 months). */
function calculateWeightForAgePercentile($value, $ageMonths, $sex) {
    return calculateMetricPercentile('weight_for_age', $value, $ageMonths, $sex);
}

/** Height/length-for-age percentile (WHO 0–228 months). */
function calculateHeightForAgePercentile($value, $ageMonths, $sex) {
    return calculateMetricPercentile('height_for_age', $value, $ageMonths, $sex);
}

/** BMI-for-age percentile (WHO 0–228 months). $value is BMI (kg/m²). */
function calculateBMIForAgePercentile($value, $ageMonths, $sex) {
    return calculateMetricPercentile('bmi_for_age', $value, $ageMonths, $sex);
}

/* -------------------------------------------------------------------------
 * Convenience wrappers that derive age/BMI from raw inputs.
 * These lean on calculateAgeInMonths() and calculateBMI() from includes/helpers.php
 * (already shipped in Sprints 5/6). They are null-safe end to end.
 * ------------------------------------------------------------------------- */

/**
 * Weight-for-age percentile from a date_of_birth + sex, computing age internally.
 * Returns null if age can't be derived or is out of coverage.
 */
function weightForAgePercentileByDOB($weightKg, $dateOfBirth, $sex) {
    if (!function_exists('calculateAgeInMonths')) {
        return null;
    }
    $age = calculateAgeInMonths($dateOfBirth);
    if ($age === null) {
        return null;
    }
    return calculateWeightForAgePercentile($weightKg, $age, $sex);
}

/**
 * Height-for-age percentile from a date_of_birth + sex.
 */
function heightForAgePercentileByDOB($heightCm, $dateOfBirth, $sex) {
    if (!function_exists('calculateAgeInMonths')) {
        return null;
    }
    $age = calculateAgeInMonths($dateOfBirth);
    if ($age === null) {
        return null;
    }
    return calculateHeightForAgePercentile($heightCm, $age, $sex);
}

/**
 * BMI-for-age percentile from weight + height + date_of_birth + sex. Uses
 * calculateBMI() (Sprint 6) to form BMI and calculateAgeInMonths() for the age.
 * Returns null if BMI or age can't be computed or age is out of coverage.
 */
function bmiForAgePercentileByDOB($weightKg, $heightCm, $dateOfBirth, $sex) {
    if (!function_exists('calculateBMI') || !function_exists('calculateAgeInMonths')) {
        return null;
    }
    $bmi = calculateBMI($weightKg, $heightCm);
    $age = calculateAgeInMonths($dateOfBirth);
    if ($bmi === null || $age === null) {
        return null;
    }
    return calculateBMIForAgePercentile($bmi, $age, $sex);
}

/* =========================================================================
 * SPRINT 8 — PERCENTILES DISPLAY (guardian + clinician surfaces only).
 * =========================================================================
 * Side-effect-free presentation helpers layered on top of the Sprint-7 engine.
 * They turn a percentile into a P-band label, a green/yellow/red color zone, and
 * a human trajectory narrative ("P25 -> P35 over 3 months"). They also compute a
 * percentile-OVER-TIME series at QUERY time from the raw logs (nothing stored).
 *
 * CHILD BOUNDARY (decision i revised + Sprint-8 scope): NONE of this reaches the
 * child surface. The child Growth chart stays a plain encouraging line chart with
 * NO WHO curves and NO clinical flags. All ranks/bands/zones/narratives below are
 * for the guardian dashboard, exports, and clinician/guest reports ONLY.
 *
 * WHO-only, single +-2 SD convention (no CDC, no age-2 transition annotation —
 * that is the future follow-on 8b). All functions degrade to null/empty when a
 * percentile cannot be computed (age out of coverage, missing sex/DOB, no logs).
 */

/**
 * The five WHO display bands shown as overlay reference lines, as required by the
 * Sprint-8 spec: P3 / P15 / P50 / P85 / P97.
 */
function percentileBands() {
    return [3, 15, 50, 85, 97];
}

/**
 * Classify a percentile (0..100) into a green/yellow/red color zone.
 *   green  : P15-P85   (typical range)
 *   yellow : P3-P15  OR P85-P97  (monitor)
 *   red    : <P3     OR >P97     (outside +-2 SD band)
 * Boundaries follow the spec's stated ranges; exact band edges (3/15/85/97) fall
 * in the more-reassuring adjacent zone (>=15 and <=85 => green; >=3 and <=97 =>
 * yellow). Returns a stable zone key ('green'|'yellow'|'red') or null when the
 * percentile is null/non-numeric (graceful — caller shows the "complete DOB" path).
 */
function percentileZone($percentile) {
    if ($percentile === null || !is_numeric($percentile)) {
        return null;
    }
    $p = (float) $percentile;
    if ($p >= 15.0 && $p <= 85.0) {
        return 'green';
    }
    if ($p >= 3.0 && $p <= 97.0) {
        return 'yellow';
    }
    return 'red';
}

/**
 * Translation key for a zone's reassuring/clinical label. Returned as a KEY so the
 * caller stays locale-agnostic (guardian/clinician surfaces resolve via t()).
 */
function percentileZoneLabelKey($zone) {
    switch ($zone) {
        case 'green':  return 'zone_green';
        case 'yellow': return 'zone_yellow';
        case 'red':    return 'zone_red';
        default:       return 'zone_unknown';
    }
}

/**
 * Nearest WHO display band (P3/P15/P50/P85/P97) for narrative phrasing such as
 * "around P50". Returns the closest band integer, or null when not computable.
 */
function nearestPercentileBand($percentile) {
    if ($percentile === null || !is_numeric($percentile)) {
        return null;
    }
    $p = (float) $percentile;
    $bands = percentileBands();
    $best = null; $bestDist = null;
    foreach ($bands as $b) {
        $d = abs($p - $b);
        if ($bestDist === null || $d < $bestDist) {
            $bestDist = $d;
            $best = $b;
        }
    }
    return $best;
}

/**
 * Format a percentile as a "P<rounded>" rank label (e.g. 34.6 -> "P35"). Clamps the
 * displayed rank to the 1..99 range so neither "P0" nor "P100" is ever shown (those
 * read as absolutes; the engine already clamps z to +-5 SD upstream). null -> null.
 */
function formatPercentileRank($percentile) {
    if ($percentile === null || !is_numeric($percentile)) {
        return null;
    }
    $r = (int) round((float) $percentile);
    if ($r < 1)  $r = 1;
    if ($r > 99) $r = 99;
    return 'P' . $r;
}

/**
 * One metric's CURRENT percentile descriptor for a display surface:
 *   [ 'value'=>raw, 'percentile'=>float, 'rank'=>'P35', 'zone'=>'green',
 *     'zone_label_key'=>'zone_green', 'nearest_band'=>50 ]
 * Returns null when the percentile is not computable (caller then shows nothing /
 * the graceful prompt). $value is the already-measured metric value (kg / cm / BMI).
 */
function describeMetricPercentile($metric, $value, $ageMonths, $sex) {
    $p = calculateMetricPercentile($metric, $value, $ageMonths, $sex);
    if ($p === null) {
        return null;
    }
    $zone = percentileZone($p);
    return [
        'value'          => $value !== null && is_numeric($value) ? (float) $value : null,
        'percentile'     => round($p, 1),
        'rank'           => formatPercentileRank($p),
        'zone'           => $zone,
        'zone_label_key' => percentileZoneLabelKey($zone),
        'nearest_band'   => nearestPercentileBand($p),
    ];
}

/**
 * Build a percentile-OVER-TIME series for ONE metric from already-fetched log rows.
 * Computed at QUERY time, nothing stored (Sprint-8 task 3). Each input row must have
 * a date field and a value field; rows whose percentile is not computable (age out
 * of coverage etc.) are skipped, so a sparse/partial series degrades gracefully.
 *
 * @param array  $rows       e.g. weight_log rows
 * @param string $dateField  e.g. 'log_date'
 * @param string $valueField e.g. 'weight_kg'
 * @param string $metric     'weight_for_age' | 'height_for_age'
 * @param string $dob        date_of_birth (YYYY-MM-DD)
 * @param string $sex        users.gender
 * @return array list of ['date'=>..., 'value'=>float, 'percentile'=>float]
 */
function percentileSeriesFromRows($rows, $dateField, $valueField, $metric, $dob, $sex) {
    $series = [];
    if (!is_array($rows) || empty($dob)) {
        return $series;
    }
    foreach ($rows as $row) {
        $date  = $row[$dateField]  ?? null;
        $value = $row[$valueField] ?? null;
        if ($date === null || $value === null || !is_numeric($value)) {
            continue;
        }
        // Age AT THE TIME of the measurement — not "now" — so the trajectory is honest.
        $ageMonths = ageInMonthsAtDate($dob, $date);
        if ($ageMonths === null) {
            continue;
        }
        $p = calculateMetricPercentile($metric, (float) $value, $ageMonths, $sex);
        if ($p === null) {
            continue;
        }
        $series[] = [
            'date'       => $date,
            'value'      => (float) $value,
            'percentile' => round($p, 1),
        ];
    }
    return $series;
}

/**
 * Age in completed months at a SPECIFIC measurement date (not "now"). Mirrors
 * calculateAgeInMonths()'s graceful contract: null on blank/unparseable/ordering
 * problems. Kept here (not helpers.php) because it is percentile-trajectory logic.
 */
function ageInMonthsAtDate($dateOfBirth, $atDate) {
    if (empty($dateOfBirth) || empty($atDate)) {
        return null;
    }
    try {
        $dob = new DateTime($dateOfBirth);
        $at  = new DateTime($atDate);
    } catch (Exception $e) {
        return null;
    }
    if ($dob > $at) {
        return null;
    }
    $diff = $dob->diff($at);
    return ($diff->y * 12) + $diff->m;
}

/**
 * Turn a percentile series into a trajectory narrative descriptor:
 *   [ 'from_rank'=>'P25', 'to_rank'=>'P35', 'months'=>3, 'direction'=>'up'|'down'|'stable',
 *     'narrative_key'=>'percentile_trend_up', 'points'=>N ]
 * Direction uses a small percentile-point threshold to avoid over-reading noise.
 * Returns null when there are fewer than 2 computable points (no trajectory to tell).
 *
 * narrative_key + the from/to ranks let callers compose the localized one-liner
 * "moving P25 -> P35 over 3 months" via t('percentile_trend_*', {...}).
 */
function describePercentileTrend($series) {
    if (!is_array($series) || count($series) < 2) {
        return null;
    }
    // Series may arrive in any date order; sort ascending by date for first->last.
    usort($series, function ($a, $b) {
        return strcmp((string) $a['date'], (string) $b['date']);
    });
    $first = $series[0];
    $last  = $series[count($series) - 1];

    $fromP = (float) $first['percentile'];
    $toP   = (float) $last['percentile'];
    $delta = $toP - $fromP;

    // Months spanned (whole months between first and last measurement).
    $months = null;
    try {
        $d1 = new DateTime($first['date']);
        $d2 = new DateTime($last['date']);
        if ($d2 >= $d1) {
            $diff = $d1->diff($d2);
            $months = ($diff->y * 12) + $diff->m;
        }
    } catch (Exception $e) {
        $months = null;
    }

    // 3 percentile points of movement before we call a direction.
    if ($delta >= 3.0) {
        $direction = 'up';
        $narrativeKey = 'percentile_trend_up';
    } elseif ($delta <= -3.0) {
        $direction = 'down';
        $narrativeKey = 'percentile_trend_down';
    } else {
        $direction = 'stable';
        $narrativeKey = 'percentile_trend_stable';
    }

    return [
        'from_rank'     => formatPercentileRank($fromP),
        'to_rank'       => formatPercentileRank($toP),
        'months'        => $months,
        'direction'     => $direction,
        'narrative_key' => $narrativeKey,
        'points'        => count($series),
    ];
}
