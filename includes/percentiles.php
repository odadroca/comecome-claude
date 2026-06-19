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
