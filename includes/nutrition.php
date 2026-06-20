<?php
/**
 * Sprint 11 — Growth-Support Nutrition Intelligence (rule-based, NOT AI).
 *
 * Three deterministic analyzers over data ComeCome already collects, surfaced ONLY on
 * the guardian dashboard + clinician report. ZERO child-facing surface. Everything here
 * is a descriptive read-layer: it stores nothing, makes no causal/diagnostic claim, and
 * is fully auditable (no model, no network, no external dependency — by design, per the
 * source plan's privacy/self-hosted ethos).
 *
 *   A. Medication-timing  — WHEN in the medication day intake actually happens
 *                           (distribution over food_log.med_window, stamped server-side
 *                           at insert in Sprint 9).
 *   B. Tag coverage       — WHAT growth-supporting foods the child gets (weekly servings
 *                           per growth tag + recent-vs-earlier trend), from food_growth_tags.
 *   C. Recommendations    — a rule engine cross-referencing A + B + percentile trajectory
 *                           (Sprint 8) + sleep quality (Sprint 2). Templated phrasing only.
 *
 * Gated by the guardian setting `show_nutrition_insights` (default OFF). All thresholds
 * are named constants below so they are tunable and unit-testable (§5.1 decision).
 *
 * Concurrency (§5.3 decision): every query here is a read-only SELECT bounded by user_id
 * + date range (covered by idx_food_log_user_date / idx_daily_checkin_user_date) and the
 * food_growth_tags PK. It takes no write lock; at single-family self-hosted scale, and
 * with the panel opt-in/default-OFF, no read-only connection or result cache is needed.
 * If a deployment ever reports lock contention, caching the aggregate is the next step.
 */

// ---- Tunable thresholds (per 7-day-normalized rate unless noted) -----------------
const NI_PROTEIN_MIN       = 5;   // protein_rich servings / week
const NI_BONE_MIN          = 5;   // bone_building servings / week
const NI_CALORIE_DENSE_MIN = 7;   // calorie_dense servings / week
const NI_BRAIN_FUEL_MIN    = 3;   // brain_fuel servings / week
const NI_HYDRATING_MIN     = 7;   // hydrating servings / week
const NI_POST_MED_HEAVY_PCT = 60; // >= this share of windowed intake is post_med
const NI_PRE_MED_LOW_PCT    = 15; // < this share is pre_med (best appetite window)
const NI_TAG_DROP_PCT       = 40; // a tag dropping >= this % recent-vs-earlier
const NI_SLEEP_LOW_AVG      = 2.5;// avg sleep quality (1-5) at/below this
const NI_MIN_LOG_DAYS       = 5;  // distinct days with any food log to analyze coverage
const NI_MIN_WINDOWED       = 3.0;// windowed servings needed for timing analysis

/** Is the guardian-facing nutrition intelligence enabled? (default OFF) */
function nutritionInsightsEnabled() {
    return getSetting('show_nutrition_insights', '0') === '1';
}

/** The six growth tags (single source of truth; mirrors the CHECK constraint). */
function growthTagNames() {
    return ['calorie_dense', 'protein_rich', 'bone_building', 'brain_fuel', 'easy_to_eat', 'hydrating'];
}

/** Tags that carry an underserved-minimum rule in analyzer C, with their thresholds. */
function growthTagMinimums() {
    return [
        'calorie_dense' => NI_CALORIE_DENSE_MIN,
        'protein_rich'  => NI_PROTEIN_MIN,
        'bone_building' => NI_BONE_MIN,
        'brain_fuel'    => NI_BRAIN_FUEL_MIN,
        'hydrating'     => NI_HYDRATING_MIN,
        // easy_to_eat has no minimum — it is a coping option, not a daily target.
    ];
}

/** Whole days in an inclusive [start,end] date range (>= 1). */
function ni_daysInRange($startDate, $endDate) {
    try {
        $d1 = new DateTime($startDate);
        $d2 = new DateTime($endDate);
        $days = (int) $d1->diff($d2)->days + 1;
        return max(1, $days);
    } catch (Exception $e) {
        return 1;
    }
}

/** The serving-weight SQL CASE used everywhere else (little .25 … all 1.0). */
function ni_portionCase($col = 'fl.portion') {
    return "SUM(CASE
            WHEN $col = 'little' THEN 0.25
            WHEN $col = 'some' THEN 0.5
            WHEN $col = 'lot' THEN 0.75
            WHEN $col = 'all' THEN 1.0
            ELSE 0 END)";
}

/**
 * Top-level entry point. Build the full nutrition-intelligence block for a child over a
 * date range. $percentiles is the already-computed computePercentileSummary() result
 * (passed in so we don't recompute it); pass null to skip the growth-trajectory rule.
 *
 * Returns a stable shape (mirrors computePercentileSummary's contract):
 *   ['available'=>bool, 'reason'=>'disabled'|'not_enough_data'|null, 'window_days'=>int,
 *    'timing'=>..., 'coverage'=>..., 'recommendations'=>[...], 'tag_index'=>...]
 */
function buildNutritionIntelligence($userId, $startDate, $endDate, $percentiles = null) {
    $base = [
        'available'       => false,
        'reason'          => null,
        'window_days'     => ni_daysInRange($startDate, $endDate),
        'timing'          => null,
        'coverage'        => null,
        'recommendations' => [],
        'tag_index'       => null,
    ];

    // Toggle gate — OFF means render nothing anywhere (no prompt).
    if (!nutritionInsightsEnabled()) {
        $base['reason'] = 'disabled';
        return $base;
    }

    $db = getDB();

    // Data-sufficiency gate: need a handful of logging days to say anything useful.
    $stmt = $db->prepare(
        "SELECT COUNT(DISTINCT log_date) AS d FROM food_log
         WHERE user_id = ? AND log_date BETWEEN ? AND ?"
    );
    $stmt->execute([$userId, $startDate, $endDate]);
    $logDays = (int) ($stmt->fetch()['d'] ?? 0);
    if ($logDays < NI_MIN_LOG_DAYS) {
        $base['reason'] = 'not_enough_data';
        return $base;
    }

    $base['timing']     = analyzeMedTiming($db, $userId, $startDate, $endDate);
    $base['coverage']   = analyzeTagCoverage($db, $userId, $startDate, $endDate);
    $base['tag_index']  = analyzeTagIndex($db, $userId);
    $sleepAvg           = ni_avgSleepQuality($db, $userId, $startDate, $endDate);
    $base['recommendations'] = buildNutritionRecommendations(
        $base['timing'], $base['coverage'], $percentiles, $sleepAvg
    );
    $base['available'] = true;
    return $base;
}

/**
 * Analyzer A — distribution of intake across the medication windows. Only food_log rows
 * whose med_window is non-NULL count (NULL = no active appetite-affecting schedule), so
 * the shares describe the medicated day specifically. Serving-weighted, like the rest of
 * the app's intake figures.
 */
function analyzeMedTiming($db, $userId, $startDate, $endDate) {
    $sum = ni_portionCase('portion'); // food_log is unaliased in this query
    $stmt = $db->prepare(
        "SELECT med_window, $sum AS servings, COUNT(*) AS entries
         FROM food_log
         WHERE user_id = ? AND log_date BETWEEN ? AND ? AND med_window IS NOT NULL
         GROUP BY med_window"
    );
    $stmt->execute([$userId, $startDate, $endDate]);

    $byWindow = [];
    foreach (medWindowNames() as $w) {
        $byWindow[$w] = ['servings' => 0.0, 'pct' => 0.0];
    }
    $total = 0.0;
    foreach ($stmt->fetchAll() as $row) {
        $w = $row['med_window'];
        if (!isset($byWindow[$w])) continue;
        $s = (float) $row['servings'];
        $byWindow[$w]['servings'] = $s;
        $total += $s;
    }
    if ($total > 0) {
        foreach ($byWindow as $w => &$d) {
            $d['pct'] = round($d['servings'] / $total * 100);
        }
        unset($d);
    }

    $hasSchedule = !empty(getActiveMedicationSchedules($userId));

    return [
        'windowed_total' => round($total, 2),
        'by_window'      => $byWindow,
        'has_schedule'   => $hasSchedule,
        // Enough windowed intake to make timing statements about.
        'enough'         => $hasSchedule && $total >= NI_MIN_WINDOWED,
    ];
}

/**
 * Analyzer B — weekly servings per growth tag plus a recent-vs-earlier trend. A food
 * with several tags counts toward each (a glass of milk supports protein AND bone — that
 * is intentional). Counts are serving-weighted and normalized to a 7-day rate so the
 * thresholds in analyzer C are comparable regardless of the selected period.
 */
function analyzeTagCoverage($db, $userId, $startDate, $endDate) {
    $days = ni_daysInRange($startDate, $endDate);
    // Split into earlier / recent halves of equal length (recent = last floor(days/2)).
    $half = max(1, intdiv($days, 2));
    $recentStart = date('Y-m-d', strtotime($endDate . ' -' . ($half - 1) . ' days'));

    $servingsByTag = function ($from, $to) use ($db, $userId) {
        $sum = ni_portionCase();
        $stmt = $db->prepare(
            "SELECT fgt.tag, $sum AS servings
             FROM food_log fl
             JOIN food_growth_tags fgt ON fgt.food_id = fl.food_id
             WHERE fl.user_id = ? AND fl.log_date BETWEEN ? AND ?
             GROUP BY fgt.tag"
        );
        $stmt->execute([$userId, $from, $to]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['tag']] = (float) $r['servings'];
        }
        return $out;
    };

    $full    = $servingsByTag($startDate, $endDate);
    $recent  = $servingsByTag($recentStart, $endDate);
    // Earlier half is the part before the recent window; guard the off-by-one boundary.
    $earlierEnd = date('Y-m-d', strtotime($recentStart . ' -1 day'));
    $earlier = ($earlierEnd >= $startDate) ? $servingsByTag($startDate, $earlierEnd) : [];

    $out = [];
    foreach (growthTagNames() as $tag) {
        $servings = $full[$tag] ?? 0.0;
        $r = $recent[$tag] ?? 0.0;
        $e = $earlier[$tag] ?? 0.0;
        // Trend: needs a meaningful earlier baseline to claim a drop/rise.
        $trend = 'flat';
        $dropPct = 0;
        if ($e > 0) {
            $delta = $r - $e;
            $dropPct = (int) round(abs($delta) / $e * 100);
            if ($delta < 0 && $dropPct >= NI_TAG_DROP_PCT) $trend = 'down';
            elseif ($delta > 0 && $dropPct >= NI_TAG_DROP_PCT) $trend = 'up';
        }
        $out[$tag] = [
            'servings'    => round($servings, 1),
            'weekly_rate' => round($servings * 7 / $days, 1),
            'recent'      => round($r, 1),
            'earlier'     => round($e, 1),
            'trend'       => $trend,
            'drop_pct'    => $dropPct,
        ];
    }
    return $out;
}

/**
 * Tag-coverage indicator (§5.2 decision) — how many of the catalog's active foods carry
 * at least one growth tag. Surfaces the long-tail erosion from guardian-added foods
 * WITHOUT auto-tagging anything. Catalog-wide (not per-child); cheap COUNT query.
 */
function analyzeTagIndex($db, $userId) {
    $total = (int) $db->query("SELECT COUNT(*) FROM foods WHERE active = 1")->fetchColumn();
    $tagged = (int) $db->query(
        "SELECT COUNT(DISTINCT f.id) FROM foods f
         JOIN food_growth_tags fgt ON fgt.food_id = f.id
         WHERE f.active = 1"
    )->fetchColumn();
    return [
        'total'    => $total,
        'tagged'   => $tagged,
        'untagged' => max(0, $total - $tagged),
    ];
}

/** Average self-reported sleep quality (1-5) over the range, or null if none. */
function ni_avgSleepQuality($db, $userId, $startDate, $endDate) {
    // daily_checkin.sleep_quality is the primary signal (Sprint 2); fall back to none.
    $stmt = $db->prepare(
        "SELECT AVG(sleep_quality) AS q FROM daily_checkin
         WHERE user_id = ? AND check_date BETWEEN ? AND ? AND sleep_quality IS NOT NULL"
    );
    $stmt->execute([$userId, $startDate, $endDate]);
    $q = $stmt->fetch()['q'] ?? null;
    return $q !== null ? round((float) $q, 1) : null;
}

/**
 * Analyzer C — the rule engine. Pure function of the analyzer outputs (so it is trivially
 * unit-testable). Each recommendation is ['id','severity','key','params'] — the caller
 * renders the localized text via t($key,$params). 'attention' = actionable gap;
 * 'info' = context. Order: timing → coverage gaps → downtrends → growth → sleep.
 */
function buildNutritionRecommendations($timing, $coverage, $percentiles, $sleepAvg) {
    $recs = [];

    // --- Timing (only when there is an active stimulant schedule + enough windowed data)
    if (is_array($timing) && !empty($timing['enough'])) {
        $post = $timing['by_window']['post_med']['pct'] ?? 0;
        $pre  = $timing['by_window']['pre_med']['pct'] ?? 0;
        if ($post >= NI_POST_MED_HEAVY_PCT) {
            $recs[] = ['id' => 'post_med_heavy', 'severity' => 'attention',
                       'key' => 'rec_post_med_heavy', 'params' => ['pct' => $post]];
        }
        if ($pre < NI_PRE_MED_LOW_PCT) {
            $recs[] = ['id' => 'pre_med_low', 'severity' => 'attention',
                       'key' => 'rec_pre_med_low', 'params' => ['pct' => $pre]];
        }
    }

    // --- Coverage: underserved tags (weekly rate below the per-tag minimum)
    if (is_array($coverage)) {
        foreach (growthTagMinimums() as $tag => $min) {
            $rate = $coverage[$tag]['weekly_rate'] ?? 0;
            if ($rate < $min) {
                $recs[] = ['id' => 'low_' . $tag, 'severity' => 'attention',
                           'key' => 'rec_tag_low_' . $tag,
                           'params' => ['rate' => $rate, 'min' => $min]];
            }
        }
        // --- Downtrends: a tag that fell sharply recent-vs-earlier
        foreach (growthTagNames() as $tag) {
            if (($coverage[$tag]['trend'] ?? 'flat') === 'down') {
                // Keep the rule engine free of t() (pure/testable, no i18n load-order
                // coupling). tag_code is resolved to a localized label at render time.
                $recs[] = ['id' => 'drop_' . $tag, 'severity' => 'info',
                           'key' => 'rec_tag_drop',
                           'params' => [
                               'tag_code' => $tag,
                               'earlier'  => $coverage[$tag]['earlier'],
                               'recent'   => $coverage[$tag]['recent'],
                           ]];
            }
        }
    }

    // --- Growth trajectory: weight-for-age falling (Sprint 8 percentile block)
    if (is_array($percentiles) && !empty($percentiles['available'])) {
        $wTrend = $percentiles['trends']['weight'] ?? null;
        if (is_array($wTrend) && ($wTrend['direction'] ?? null) === 'down') {
            $recs[] = ['id' => 'growth_falling', 'severity' => 'attention',
                       'key' => 'rec_growth_falling',
                       'params' => ['from' => $wTrend['from_rank'], 'to' => $wTrend['to_rank']]];
        }
    }

    // --- Sleep ↔ appetite context (informational, never causal)
    if ($sleepAvg !== null && $sleepAvg <= NI_SLEEP_LOW_AVG) {
        $recs[] = ['id' => 'sleep_low', 'severity' => 'info',
                   'key' => 'rec_sleep_low', 'params' => ['avg' => $sleepAvg]];
    }

    return $recs;
}

/* =========================================================================
 * Rendering — shared by the guardian dashboard ('dashboard') and the clinician
 * report / guest-report ('report'). Returns '' when the toggle is OFF, mirroring
 * renderPercentileSection() so callers can `if ($html !== '')`. Inline styles match
 * the report's print-friendly convention.
 * ========================================================================= */
function renderNutritionSection($ni, $variant = 'dashboard') {
    if (!is_array($ni)) return '';
    $reason = $ni['reason'] ?? null;
    if ($reason === 'disabled') return '';

    $isReport = ($variant === 'report');
    ob_start();

    if (!($ni['available'] ?? false)) {
        // Enabled but not enough logging yet — friendly, non-blocking.
        ?>
        <div class="nutrition-prompt" style="opacity:0.8;">
            🥗 <strong><?php echo t('nutrition_intelligence'); ?>:</strong>
            <?php echo t('nutrition_not_enough_data'); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    $border = $isReport ? '1px solid #ddd' : '1px solid #ddd';

    // --- A. Medication timing ---------------------------------------------------
    $timing = $ni['timing'] ?? null;
    if (is_array($timing) && !empty($timing['has_schedule']) && ($timing['windowed_total'] ?? 0) > 0):
    ?>
    <h4 style="margin:0.6rem 0 0.3rem;"><?php echo t('nutrition_med_timing'); ?></h4>
    <table style="width:100%;border-collapse:collapse;">
        <thead><tr>
            <th style="text-align:left;border:<?php echo $border; ?>;padding:4px;"><?php echo t('nutrition_window'); ?></th>
            <th style="text-align:left;border:<?php echo $border; ?>;padding:4px;"><?php echo t('nutrition_share'); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach (medWindowNames() as $w):
            $pct = $timing['by_window'][$w]['pct'] ?? 0; ?>
            <tr>
                <td style="border:<?php echo $border; ?>;padding:4px;"><?php echo t('window_' . $w); ?></td>
                <td style="border:<?php echo $border; ?>;padding:4px;"><?php echo (int) $pct; ?>%</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php
    // --- B. Tag coverage --------------------------------------------------------
    $coverage = $ni['coverage'] ?? null;
    if (is_array($coverage)):
        $arrow = ['up' => '▲', 'down' => '▼', 'flat' => '—'];
        $minimums = growthTagMinimums();
    ?>
    <h4 style="margin:0.7rem 0 0.3rem;"><?php echo t('nutrition_tag_coverage'); ?></h4>
    <table style="width:100%;border-collapse:collapse;">
        <thead><tr>
            <th style="text-align:left;border:<?php echo $border; ?>;padding:4px;"><?php echo t('nutrition_tag'); ?></th>
            <th style="text-align:left;border:<?php echo $border; ?>;padding:4px;"><?php echo t('nutrition_weekly_servings'); ?></th>
            <th style="text-align:left;border:<?php echo $border; ?>;padding:4px;"><?php echo t('nutrition_trend'); ?></th>
            <th style="text-align:left;border:<?php echo $border; ?>;padding:4px;"><?php echo t('nutrition_status'); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach (growthTagNames() as $tag):
            $c = $coverage[$tag];
            $hasMin = isset($minimums[$tag]);
            $low = $hasMin && $c['weekly_rate'] < $minimums[$tag];
        ?>
            <tr>
                <td style="border:<?php echo $border; ?>;padding:4px;"><?php echo t('tag_' . $tag); ?></td>
                <td style="border:<?php echo $border; ?>;padding:4px;"><?php echo sanitize((string) $c['weekly_rate']); ?></td>
                <td style="border:<?php echo $border; ?>;padding:4px;"><?php echo $arrow[$c['trend']] ?? '—'; ?></td>
                <td style="border:<?php echo $border; ?>;padding:4px;">
                    <?php if (!$hasMin): ?>—<?php
                    elseif ($low): ?><span style="color:#c62828;font-weight:bold;">●</span> <?php echo t('nutrition_status_low'); ?>
                    <?php else: ?><span style="color:#2e7d32;font-weight:bold;">●</span> <?php echo t('nutrition_status_ok'); ?><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
        // Catalog tag-coverage indicator + soft nudge for untagged custom foods.
        $idx = $ni['tag_index'] ?? null;
        if (is_array($idx) && $idx['total'] > 0): ?>
        <p style="font-size:<?php echo $isReport ? '7pt' : '0.75rem'; ?>;opacity:0.75;margin:4px 0 0;">
            <?php echo t('nutrition_tag_coverage_indicator', ['tagged' => $idx['tagged'], 'total' => $idx['total']]); ?>
            <?php if ($idx['untagged'] > 0): ?>
                <?php echo t('nutrition_untagged_nudge', ['count' => $idx['untagged']]); ?>
            <?php endif; ?>
        </p>
        <?php endif; ?>
    <?php endif; ?>

    <?php
    // --- C. Recommendations -----------------------------------------------------
    $recs = $ni['recommendations'] ?? [];
    if (!empty($recs)): ?>
    <h4 style="margin:0.7rem 0 0.3rem;"><?php echo t('nutrition_recommendations'); ?></h4>
    <ul style="margin:0;padding-left:1.1rem;">
        <?php foreach ($recs as $r):
            $isInfo = ($r['severity'] ?? 'attention') === 'info';
            $marker = $isInfo ? 'ℹ️' : '⚠️';
            // Resolve any deferred tag_code into a localized {tag} param (rec_tag_drop).
            $params = $r['params'] ?? [];
            if (isset($params['tag_code'])) {
                $params['tag'] = t('tag_' . $params['tag_code']);
                unset($params['tag_code']);
            } ?>
        <li style="margin-bottom:3px;<?php echo $isInfo ? 'opacity:0.85;' : ''; ?>">
            <?php echo $marker . ' ' . sanitize(t($r['key'], $params)); ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <p style="font-size:<?php echo $isReport ? '7pt' : '0.75rem'; ?>;opacity:0.7;margin-top:6px;">
        <?php echo t('nutrition_reference_note'); ?>
    </p>
    <?php
    return ob_get_clean();
}
