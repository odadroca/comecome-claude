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

fclose($output);
exit;
