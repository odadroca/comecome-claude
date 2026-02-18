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
$generatedBy = getCurrentUser()['name'];

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

    <div class="header">
        <h1>Come-Come Report</h1>
        <p><strong><?php echo sanitize($child['name']); ?></strong></p>
        <p><?php echo formatDate($startDate, 'd-m-Y'); ?> to <?php echo formatDate($endDate, 'd-m-Y'); ?></p>
        <p style="font-size: 7pt;">Generated: <?php echo date('d-m-Y H:i:s'); ?> by <?php echo sanitize($generatedBy); ?></p>
    </div>

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

    <div class="footer">
        <p><?php echo t('report_footer'); ?> — <strong><?php echo t('page_of', ['current' => 1, 'total' => 1]); ?></strong></p>
    </div>
</body>
</html>
