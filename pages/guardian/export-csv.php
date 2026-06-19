<?php
/**
 * CSV Export
 */

if (!isset($reportData)) {
    die('No report data');
}

$child = $reportData['user'];
$filename = 'comecome-report-' . preg_replace('/[^a-zA-Z0-9]/', '', $child['name']) . '-' . date('d-m-Y') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Report Header
fputcsv($output, ['ComeCome Report']);
fputcsv($output, ['Child', $child['name']]);
fputcsv($output, ['Period', formatDate($startDate) . ' to ' . formatDate($endDate)]);
fputcsv($output, ['Generated', date('d-m-Y H:i:s')]);
fputcsv($output, []);

// Clinical Summary (Sprint 3) — flat columns, descriptive figures only.
$clinical = $reportData['clinical_summary'] ?? null;
if ($clinical) {
    $appetiteAvg = $clinical['appetite_trend']['avg'] ?? '';
    $moodAvg = $clinical['mood_trend']['avg'] ?? '';
    $sleepQ = $clinical['sleep']['avg_quality'] ?? '';
    $sleepDur = $clinical['sleep']['avg_duration_min'] ?? '';
    $interruptFreq = $clinical['sleep']['interruption_freq'] ?? '';
    $medAdherence = $clinical['med_adherence_pct'] ?? '';

    $corr = $clinical['correlations'] ?? null;
    if ($corr && !empty($corr['enough'])) {
        $corrNote = t($corr['sleep_vs_next_appetite']['note_key']);
    } else {
        $corrNote = t('not_enough_data');
    }

    fputcsv($output, [t('clinical_summary')]);
    fputcsv($output, [
        'avg_appetite',
        'avg_mood',
        'avg_sleep_quality',
        'avg_sleep_duration_min',
        'interruption_freq',
        'med_adherence_pct',
        'sleep_appetite_corr_note'
    ]);
    fputcsv($output, [
        $appetiteAvg,
        $moodAvg,
        $sleepQ,
        $sleepDur,
        $interruptFreq,
        $medAdherence !== '' ? $medAdherence . '%' : '',
        $corrNote
    ]);
    fputcsv($output, []);
}

// Growth Percentiles (Sprint 8) — current WHO ranks + zones + trajectory.
// Columns weight_pct / height_pct / bmi_pct per spec. Renders only when the toggle
// is ON; emits a graceful note row when demographics are missing. Decision iii:
// derived AGE is included, raw date_of_birth is NOT.
$percentiles = $reportData['percentiles'] ?? null;
if (is_array($percentiles) && ($percentiles['reason'] ?? null) !== 'disabled') {
    fputcsv($output, [t('growth_percentiles')]);
    if (!($percentiles['available'] ?? false)) {
        $note = ($percentiles['reason'] ?? null) === 'missing_demographics'
            ? t('percentile_complete_dob_prompt')
            : t('percentile_no_measurements');
        fputcsv($output, [$note]);
    } else {
        $cur = $percentiles['current'];
        $trends = $percentiles['trends'];
        $rank = function ($m) use ($cur) {
            return ($cur[$m] ?? null) !== null ? $cur[$m]['rank'] : '';
        };
        $zone = function ($m) use ($cur) {
            return ($cur[$m] ?? null) !== null ? t($cur[$m]['zone_label_key']) : '';
        };
        // Header row: the spec's percentile columns plus age + zones + trajectory.
        fputcsv($output, [
            'age_months',
            'weight_pct',
            'height_pct',
            'bmi_pct',
            'weight_zone',
            'height_zone',
            'bmi_zone',
            'weight_trajectory',
            'height_trajectory',
        ]);
        fputcsv($output, [
            $percentiles['age_months'] ?? '',
            $rank('weight'),
            $rank('height'),
            $rank('bmi'),
            $zone('weight'),
            $zone('height'),
            $zone('bmi'),
            ($trends['weight'] ?? null) ? formatPercentileTrajectory($trends['weight']) : '',
            ($trends['height'] ?? null) ? formatPercentileTrajectory($trends['height']) : '',
        ]);
        fputcsv($output, [t('percentile_reference_who')]);
    }
    fputcsv($output, []);
}

// Weight Timeline
if (count($reportData['weights']) > 0) {
    fputcsv($output, [t('weight_timeline_title')]);
    fputcsv($output, [t('date'), t('weight') . ' (kg)', t('weight_change')]);

    $prevWeight = null;
    foreach ($reportData['weights'] as $entry) {
        $change = '';
        if ($prevWeight !== null) {
            $diff = $entry['weight_kg'] - $prevWeight;
            $change = $diff != 0 ? number_format($diff, 1) : '—';
        } else {
            $change = '—';
        }
        fputcsv($output, [
            formatDate($entry['log_date']),
            $entry['weight_kg'],
            $change
        ]);
        $prevWeight = $entry['weight_kg'];
    }
    fputcsv($output, []);
}

// Medication Adherence
if (count($reportData['medications']) > 0) {
    fputcsv($output, [t('medication_adherence')]);
    fputcsv($output, [
        t('medication'),
        t('dose'),
        t('taken'),
        t('missed'),
        t('adherence_percent')
    ]);

    foreach ($reportData['medications'] as $med) {
        $adherence = 0;
        if ($med['total_days'] > 0) {
            $adherence = ($med['taken_count'] / $med['total_days']) * 100;
        }
        fputcsv($output, [
            $med['name'],
            $med['dose'],
            $med['taken_count'],
            $med['missed_count'],
            number_format($adherence, 0) . '%'
        ]);
    }
    fputcsv($output, []);
}

// Daily Meal Count
if (count($reportData['daily_meal_count']) > 0) {
    fputcsv($output, [t('daily_meal_count')]);
    fputcsv($output, [t('date'), t('meals_logged')]);

    foreach ($reportData['daily_meal_count'] as $day) {
        fputcsv($output, [
            formatDate($day['log_date']),
            $day['meals_logged']
        ]);
    }
    fputcsv($output, []);
}

// Meals by Type
if (count($reportData['meals_by_type']) > 0) {
    fputcsv($output, [t('meals_by_type')]);
    fputcsv($output, [t('meal_type'), t('times_logged')]);

    foreach ($reportData['meals_by_type'] as $meal) {
        fputcsv($output, [
            t($meal['name_key']),
            $meal['times_logged']
        ]);
    }
    fputcsv($output, []);
}

// Intake by Category
if (count($reportData['intake_by_category']) > 0) {
    fputcsv($output, [t('intake_by_category')]);
    fputcsv($output, [t('category'), t('total_quantity')]);

    foreach ($reportData['intake_by_category'] as $cat) {
        fputcsv($output, [
            t($cat['name_key']),
            number_format($cat['total_quantity'], 2)
        ]);
    }
    fputcsv($output, []);
}

// Sleep Patterns
$sleepHistory = $reportData['sleep_history'] ?? [];
if (count($sleepHistory) > 0) {
    fputcsv($output, [t('sleep_patterns')]);
    fputcsv($output, [t('date'), t('type'), t('bedtime'), t('wake_time'), t('sleep_quality'), t('interruptions')]);

    foreach ($sleepHistory as $sleep) {
        fputcsv($output, [
            formatDate($sleep['log_date']),
            $sleep['sleep_type'] === 'night' ? t('night_sleep') : t('nap'),
            $sleep['sleep_start'] ?: '—',
            $sleep['sleep_end'] ?: '—',
            $sleep['quality'] ? $sleep['quality'] . '/5' : '—',
            $sleep['interruption_count']
        ]);
    }
    fputcsv($output, []);
}

fclose($output);
exit;
