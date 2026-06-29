<?php
/**
 * HTML Report Export (matching user's sample format)
 */

if (!isset($reportData)) {
    die('No report data');
}

$child = $reportData['user'];
$weights = $reportData['weights'];
$medications = $reportData['medications'];
$dailyMealCount = $reportData['daily_meal_count'];
$mealsByType = $reportData['meals_by_type'];
$intakeByCategory = $reportData['intake_by_category'];
$sleepHistory = $reportData['sleep_history'] ?? [];
$sleepQualityHistory = $reportData['sleep_quality_history'] ?? [];
$clinical = $reportData['clinical_summary'] ?? null;
// Guest-report includes this template with no logged-in user; fall back gracefully.
$currentUser = getCurrentUser();
$generatedBy = $currentUser['name'] ?? '—';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Come-Come Report</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 9pt;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .section {
            margin-bottom: 15px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8pt;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 4px;
            text-align: left;
        }
        th {
            background: #f0f0f0;
            font-weight: bold;
        }
        .footer {
            text-align: center;
            font-size: 7pt;
            margin-top: 20px;
        }
        @media print {
            button { display: none; }
        }
        .clinical-summary {
            border: 1px solid #bbb;
            background: #f7f9fb;
            padding: 8px 12px;
            margin-bottom: 18px;
        }
        .clinical-summary h2 {
            font-size: 11pt;
            margin: 0 0 4px 0;
        }
        .clinical-summary .intro {
            font-size: 7.5pt;
            color: #555;
            margin: 0 0 8px 0;
        }
        .clinical-summary ul {
            margin: 4px 0;
            padding-left: 18px;
        }
        .clinical-summary li {
            font-size: 8.5pt;
            margin-bottom: 3px;
        }
        .clinical-summary .disclaimer {
            font-size: 7pt;
            color: #777;
            font-style: italic;
            margin-top: 6px;
        }
    </style>
</head>
<body>
    <div style="text-align:center;margin-bottom:1rem;">
        <button onclick="window.print()" style="padding:0.5rem 1rem;font-size:1rem;cursor:pointer;">
            🖨️ <?php echo t('print'); ?>
        </button>
        <button onclick="window.close()" style="padding:0.5rem 1rem;font-size:1rem;cursor:pointer;">
            ❌ <?php echo t('close'); ?>
        </button>
    </div>

    <!-- A21 Task 4 — medical disclaimer block, unconditional (always present regardless
         of the show_nutrition_insights toggle). Exports cross the guardian trust boundary
         and also carry growth percentiles, so the disclaimer must always accompany them. -->
    <div style="border:2px solid #c00;background:#fff8f8;padding:10px 14px;margin-bottom:14px;font-size:8pt;color:#600;">
        <strong style="display:block;margin-bottom:4px;font-size:9pt;">⚠ <?php echo htmlspecialchars(t('medical_disclaimer_short'), ENT_QUOTES, 'UTF-8'); ?></strong>
        <?php echo htmlspecialchars(t('medical_disclaimer_full'), ENT_QUOTES, 'UTF-8'); ?>
    </div>

    <div class="header">
        <h1>Come-Come Report</h1>
        <p><strong><?php echo sanitize($child['name']); ?></strong></p>
        <p><?php echo formatDate($startDate, 'd-m-Y'); ?> to <?php echo formatDate($endDate, 'd-m-Y'); ?></p>
        <p style="font-size: 7pt;">Generated: <?php echo date('d-m-Y H:i:s'); ?> by <?php echo sanitize($generatedBy); ?></p>
    </div>

    <?php if ($clinical): ?>
    <div class="clinical-summary">
        <h2><?php echo t('clinical_summary'); ?></h2>
        <p class="intro"><?php echo t('clinical_summary_intro'); ?></p>
        <?php
        $hasFigure = false;
        $appetite = $clinical['appetite_trend'] ?? null;
        $mood = $clinical['mood_trend'] ?? null;
        $sleep = $clinical['sleep'] ?? null;
        $corr = $clinical['correlations'] ?? null;
        ?>
        <ul>
            <?php if ($clinical['med_adherence_pct'] !== null): $hasFigure = true; ?>
            <li><strong><?php echo t('med_adherence'); ?>:</strong> <?php echo $clinical['med_adherence_pct']; ?>%</li>
            <?php endif; ?>

            <?php if ($appetite && $appetite['avg'] !== null): $hasFigure = true; ?>
            <li><strong><?php echo t('avg_appetite'); ?>:</strong> <?php echo $appetite['avg']; ?> <?php echo t('out_of_5'); ?> (<?php echo t($appetite['trend_key']); ?>)</li>
            <?php endif; ?>

            <?php if ($mood && $mood['avg'] !== null): $hasFigure = true; ?>
            <li><strong><?php echo t('avg_mood'); ?>:</strong> <?php echo $mood['avg']; ?> <?php echo t('out_of_5'); ?> (<?php echo t($mood['trend_key']); ?>)</li>
            <?php endif; ?>

            <?php if ($sleep && $sleep['avg_quality'] !== null): $hasFigure = true; ?>
            <li><strong><?php echo t('avg_sleep_quality'); ?>:</strong> <?php echo $sleep['avg_quality']; ?> <?php echo t('out_of_5'); ?></li>
            <?php endif; ?>

            <?php if ($sleep && $sleep['avg_duration_min'] !== null): $hasFigure = true; ?>
            <li><strong><?php echo t('avg_sleep_duration'); ?>:</strong> <?php echo floor($sleep['avg_duration_min'] / 60); ?><?php echo t('hours_short'); ?> <?php echo $sleep['avg_duration_min'] % 60; ?><?php echo t('minutes_short'); ?></li>
            <?php endif; ?>

            <?php if ($sleep && $sleep['interruption_freq'] !== null): $hasFigure = true; ?>
            <li><strong><?php echo t('interruption_frequency'); ?>:</strong> <?php echo $sleep['interruption_freq']; ?></li>
            <?php endif; ?>

            <?php if ($corr && !empty($corr['enough'])): $hasFigure = true; ?>
            <li><strong><?php echo t('correlation_sleep_appetite'); ?>:</strong> <?php echo t($corr['sleep_vs_next_appetite']['note_key']); ?></li>
            <li><strong><?php echo t('correlation_sleep_mood'); ?>:</strong> <?php echo t($corr['sleep_vs_next_mood']['note_key']); ?></li>
            <li style="opacity:0.7;"><?php echo t('paired_days'); ?>: <?php echo $corr['paired_days']; ?></li>
            <?php else: ?>
            <li style="opacity:0.7;"><?php echo t('correlation_sleep_appetite'); ?>: <?php echo t('not_enough_data'); ?></li>
            <?php endif; ?>

            <?php
            // Sprint 8 (task 4): one-line percentile trajectory in the clinical narrative.
            $traj = $clinical['percentile_trajectory'] ?? null;
            if ($traj):
                $trajStr = formatPercentileTrajectory($traj);
                if ($trajStr !== ''): $hasFigure = true;
            ?>
            <li><strong><?php echo t($traj['metric_key']); ?>:</strong> <?php echo sanitize($trajStr); ?></li>
            <?php endif; endif; ?>
        </ul>

        <?php if (!$hasFigure): ?>
        <p class="intro"><?php echo t('no_clinical_data'); ?></p>
        <?php endif; ?>
        <p class="disclaimer"><?php echo t('correlation_disclaimer'); ?></p>
    </div>
    <?php endif; ?>

    <?php
    // Sprint 8 — Growth Percentiles (WHO bands/zones/trajectory). Clinician surface.
    // Same shared renderer as the dashboard (four-surface parity). Renders nothing
    // when the toggle is OFF; a "complete gender/DOB" prompt when demographics are
    // missing. Decision iii: this report shows derived AGE (above), never raw DOB.
    $percentiles = $reportData['percentiles'] ?? null;
    $percentileHtml = $percentiles ? renderPercentileSection($percentiles, 'report') : '';
    if ($percentileHtml !== ''):
    ?>
    <div class="section">
        <h3><?php echo t('growth_percentiles'); ?></h3>
        <?php echo $percentileHtml; ?>
    </div>
    <?php endif; ?>

    <?php
    // Sprint 11 — Medication-Aware Nutrition Summary (rule-based). Same shared renderer
    // as the dashboard (four-surface parity). Renders nothing when the toggle is OFF and
    // a friendly note when there is not yet enough logging. De-identified aggregates only.
    $nutrition = $reportData['nutrition'] ?? null;
    $nutritionHtml = $nutrition ? renderNutritionSection($nutrition, 'report') : '';
    if ($nutritionHtml !== ''):
    ?>
    <div class="section">
        <h3><?php echo t('nutrition_intelligence'); ?></h3>
        <?php echo $nutritionHtml; ?>
    </div>
    <?php endif; ?>

    <?php if (count($weights) > 0): ?>
    <div class="section">
        <h3><?php echo t('weight_timeline_title'); ?></h3>
        <table>
            <thead>
                <tr>
                    <th><?php echo t('date'); ?></th>
                    <th><?php echo t('weight'); ?> (kg)</th>
                    <th><?php echo t('weight_change'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $prevWeight = null;
                foreach ($weights as $entry):
                    $change = null;
                    $changeStr = '—';
                    if ($prevWeight !== null) {
                        $change = $entry['weight_kg'] - $prevWeight;
                        if ($change > 0) {
                            $changeStr = '+' . number_format($change, 1);
                        } elseif ($change < 0) {
                            $changeStr = number_format($change, 1);
                        } else {
                            $changeStr = '—';
                        }
                    }
                    $prevWeight = $entry['weight_kg'];
                ?>
                <tr>
                    <td><?php echo formatDate($entry['log_date'], 'd-m-Y'); ?></td>
                    <td><?php echo number_format($entry['weight_kg'], 1); ?></td>
                    <td><?php echo $changeStr; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (count($medications) > 0): ?>
    <div class="section">
        <h3><?php echo t('medication_adherence'); ?></h3>
        <table>
            <thead>
                <tr>
                    <th><?php echo t('medication'); ?></th>
                    <th><?php echo t('dose'); ?></th>
                    <th><?php echo t('taken'); ?></th>
                    <th><?php echo t('missed'); ?></th>
                    <th><?php echo t('adherence_percent'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($medications as $med): ?>
                <?php
                $adherence = 0;
                if ($med['total_days'] > 0) {
                    $adherence = ($med['taken_count'] / $med['total_days']) * 100;
                }
                ?>
                <tr>
                    <td><?php echo sanitize($med['name']); ?></td>
                    <td><?php echo sanitize($med['dose']); ?></td>
                    <td><?php echo $med['taken_count']; ?></td>
                    <td><?php echo $med['missed_count']; ?></td>
                    <td><?php echo number_format($adherence, 0); ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (count($dailyMealCount) > 0): ?>
    <div class="section">
        <h3><?php echo t('daily_meal_count'); ?></h3>
        <p style="font-size: 8pt;">Number of meals logged per day (max 6).</p>
        <table>
            <thead>
                <tr>
                    <th><?php echo t('date'); ?></th>
                    <th><?php echo t('meals_logged'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dailyMealCount as $day): ?>
                <tr>
                    <td><?php echo formatDate($day['log_date'], 'd-m-Y'); ?></td>
                    <td><?php echo $day['meals_logged']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (count($mealsByType) > 0): ?>
    <div class="section">
        <h3><?php echo t('meals_by_type'); ?></h3>
        <table>
            <thead>
                <tr>
                    <th><?php echo t('meal_type'); ?></th>
                    <th><?php echo t('times_logged'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($mealsByType as $meal): ?>
                <tr>
                    <td><?php echo t($meal['name_key']); ?></td>
                    <td><?php echo $meal['times_logged']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (count($intakeByCategory) > 0): ?>
    <div class="section">
        <h3><?php echo t('intake_by_category'); ?></h3>
        <p style="font-size: 8pt;">Sum of food quantities consumed per category.</p>
        <table>
            <thead>
                <tr>
                    <th><?php echo t('category'); ?></th>
                    <th><?php echo t('total_quantity'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($intakeByCategory as $cat): ?>
                <tr>
                    <td><?php echo t($cat['name_key']); ?></td>
                    <td><?php echo number_format($cat['total_quantity'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (count($sleepHistory) > 0): ?>
    <div class="section">
        <h3><?php echo t('sleep_patterns'); ?></h3>
        <table>
            <thead>
                <tr>
                    <th><?php echo t('date'); ?></th>
                    <th><?php echo t('type'); ?></th>
                    <th><?php echo t('bedtime'); ?></th>
                    <th><?php echo t('wake_time'); ?></th>
                    <th><?php echo t('sleep_quality'); ?></th>
                    <th><?php echo t('interruptions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sleepHistory as $sleep): ?>
                <tr>
                    <td><?php echo formatDate($sleep['log_date'], 'd-m-Y'); ?></td>
                    <td><?php echo $sleep['sleep_type'] === 'night' ? t('night_sleep') : t('nap'); ?></td>
                    <td><?php echo $sleep['sleep_start'] ?: '—'; ?></td>
                    <td><?php echo $sleep['sleep_end'] ?: '—'; ?></td>
                    <td><?php echo $sleep['quality'] ? $sleep['quality'] . '/5' : '—'; ?></td>
                    <td><?php echo $sleep['interruption_count']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="footer">
        <p><?php echo t('report_footer'); ?> — <strong><?php echo t('page_of', ['current' => 1, 'total' => 1]); ?></strong></p>
    </div>
</body>
</html>
