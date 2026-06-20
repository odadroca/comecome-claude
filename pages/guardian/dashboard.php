<?php
/**
 * Guardian - Dashboard
 */

$user = getCurrentUser();
$children = getAllUsers('child');
$selectedChild = $_GET['child_id'] ?? ($children[0]['id'] ?? null);
$period = $_GET['period'] ?? '7';

[$startDate, $endDate] = getDateRangeForPeriod($period);
$data = $selectedChild ? getDashboardData($selectedChild, $startDate, $endDate) : null;

// Sprint 5: derive the selected child's age (in months) from DOB when set.
// Guardian/clinician-side only (decision iii) — this never reaches a child page.
$selectedChildRecord = $selectedChild ? getUserById($selectedChild) : null;
$childAgeMonths = $selectedChildRecord
    ? calculateAgeInMonths($selectedChildRecord['date_of_birth'] ?? null)
    : null;

ob_start();
?>

<div class="guardian-interface">
    <?php include 'nav.php'; ?>

    <main class="container">
        <h1><?php echo t('guardian_dashboard'); ?></h1>

        <?php if ($childAgeMonths !== null): ?>
        <!-- Sprint 5: child age (from DOB). Guardian-side only. -->
        <p class="child-age" style="opacity:0.8;margin-top:-0.5rem;">
            🎂 <strong><?php echo t('age'); ?>:</strong>
            <?php
            $years = intdiv($childAgeMonths, 12);
            $months = $childAgeMonths % 12;
            $parts = [];
            if ($years > 0) {
                $parts[] = $years . ' ' . t($years === 1 ? 'year' : 'years');
            }
            $parts[] = $months . ' ' . t($months === 1 ? 'month' : 'months');
            echo implode(', ', $parts);
            ?>
        </p>
        <?php endif; ?>

        <!-- Filters -->
        <div class="dashboard-filters">
            <div class="filter-group">
                <label for="childSelect"><?php echo t('select_child'); ?></label>
                <select id="childSelect" onchange="filterDashboard()">
                    <?php foreach ($children as $child): ?>
                    <option value="<?php echo $child['id']; ?>"
                            <?php echo $selectedChild == $child['id'] ? 'selected' : ''; ?>>
                        <?php echo $child['avatar_emoji']; ?> <?php echo sanitize($child['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="periodSelect"><?php echo t('select_period'); ?></label>
                <select id="periodSelect" onchange="filterDashboard()">
                    <option value="7" <?php echo $period == '7' ? 'selected' : ''; ?>><?php echo t('period_7'); ?></option>
                    <option value="14" <?php echo $period == '14' ? 'selected' : ''; ?>><?php echo t('period_14'); ?></option>
                    <option value="30" <?php echo $period == '30' ? 'selected' : ''; ?>><?php echo t('period_30'); ?></option>
                    <option value="all" <?php echo $period == 'all' ? 'selected' : ''; ?>><?php echo t('period_all'); ?></option>
                </select>
            </div>
        </div>

        <?php if ($data): ?>

        <!-- 0. Insights Panel (Sprint 3) — takeaways-first, descriptive only -->
        <?php $clinical = $data['clinical_summary'] ?? null; ?>
        <?php if ($clinical): ?>
        <section class="dashboard-section">
            <h2>💡 <?php echo t('insights_panel_title'); ?></h2>
            <div class="insights-panel">
                <?php
                $corr = $clinical['correlations'] ?? null;
                $appetite = $clinical['appetite_trend'] ?? null;
                $mood = $clinical['mood_trend'] ?? null;
                $sleep = $clinical['sleep'] ?? null;
                ?>
                <ul style="list-style:none;padding:0;margin:0;">
                    <?php if ($clinical['med_adherence_pct'] !== null): ?>
                    <li style="margin-bottom:0.5rem;">
                        💊 <strong><?php echo t('med_adherence'); ?>:</strong> <?php echo $clinical['med_adherence_pct']; ?>%
                    </li>
                    <?php endif; ?>

                    <?php if ($appetite && $appetite['avg'] !== null): ?>
                    <li style="margin-bottom:0.5rem;">
                        🍽️ <strong><?php echo t('avg_appetite'); ?>:</strong>
                        <?php echo $appetite['avg']; ?> <?php echo t('out_of_5'); ?> (<?php echo t($appetite['trend_key']); ?>)
                    </li>
                    <?php endif; ?>

                    <?php if ($mood && $mood['avg'] !== null): ?>
                    <li style="margin-bottom:0.5rem;">
                        😊 <strong><?php echo t('avg_mood'); ?>:</strong>
                        <?php echo $mood['avg']; ?> <?php echo t('out_of_5'); ?> (<?php echo t($mood['trend_key']); ?>)
                    </li>
                    <?php endif; ?>

                    <?php if ($sleep && $sleep['avg_duration_min'] !== null): ?>
                    <li style="margin-bottom:0.5rem;">
                        😴 <strong><?php echo t('avg_sleep_duration'); ?>:</strong>
                        <?php echo floor($sleep['avg_duration_min'] / 60); ?><?php echo t('hours_short'); ?> <?php echo $sleep['avg_duration_min'] % 60; ?><?php echo t('minutes_short'); ?>
                    </li>
                    <?php endif; ?>

                    <li style="margin-bottom:0.5rem;">
                        🌙 <strong><?php echo t('correlation_sleep_appetite'); ?>:</strong>
                        <?php if ($corr && !empty($corr['enough'])): ?>
                            <?php echo t($corr['sleep_vs_next_appetite']['note_key']); ?>
                        <?php else: ?>
                            <em style="opacity:0.7;"><?php echo t('not_enough_data'); ?></em>
                        <?php endif; ?>
                    </li>

                    <?php if ($corr && !empty($corr['enough'])): ?>
                    <li style="margin-bottom:0.5rem;">
                        🌙 <strong><?php echo t('correlation_sleep_mood'); ?>:</strong>
                        <?php echo t($corr['sleep_vs_next_mood']['note_key']); ?>
                    </li>
                    <li style="opacity:0.7;font-size:0.85rem;">
                        <?php echo t('paired_days'); ?>: <?php echo $corr['paired_days']; ?>
                    </li>
                    <?php endif; ?>

                    <?php
                    // Sprint 8 (task 4): one-line percentile trajectory in the narrative.
                    $traj = $clinical['percentile_trajectory'] ?? null;
                    if ($traj):
                        $trajStr = formatPercentileTrajectory($traj);
                        if ($trajStr !== ''):
                    ?>
                    <li style="margin-bottom:0.5rem;">
                        📈 <strong><?php echo t($traj['metric_key']); ?>:</strong>
                        <?php echo sanitize($trajStr); ?>
                    </li>
                    <?php endif; endif; ?>
                </ul>
                <p style="font-size:0.75rem;opacity:0.6;font-style:italic;margin-top:0.75rem;">
                    <?php echo t('correlation_disclaimer'); ?>
                </p>
            </div>
        </section>
        <?php endif; ?>

        <!-- 0b. Growth Percentiles (Sprint 8) — WHO bands/zones/trajectory.
             Guardian-only. Shown when show_percentiles is ON AND the child has
             gender+DOB; a graceful "complete gender/DOB" prompt otherwise. The
             child Growth chart stays a plain encouraging line with NO overlay. -->
        <?php
        $percentiles = $data['percentiles'] ?? null;
        $percentileHtml = $percentiles ? renderPercentileSection($percentiles, 'dashboard') : '';
        if ($percentileHtml !== ''):
        ?>
        <section class="dashboard-section">
            <h2>📈 <?php echo t('growth_percentiles'); ?></h2>
            <?php echo $percentileHtml; ?>
        </section>
        <?php endif; ?>

        <!-- 1. Weight Timeline Chart -->
        <?php if (count($data['weight_history']) > 0): ?>
        <section class="dashboard-section">
            <h2><?php echo t('weight_timeline'); ?></h2>
            <div class="chart-container">
                <canvas id="weightChart"></canvas>
            </div>
        </section>
        <?php endif; ?>

        <!-- 2. Food Evolution Chart (period-based, right below weight) -->
        <?php if (count($data['daily_intake']) > 0): ?>
        <section class="dashboard-section">
            <h2><?php echo t('food_evolution'); ?></h2>
            <div class="chart-container">
                <canvas id="intakeChart"></canvas>
            </div>
        </section>
        <?php endif; ?>

        <!-- 3. Appetite & Mood History -->
        <?php if (count($data['check_ins']) > 0): ?>
        <section class="dashboard-section">
            <h2><?php echo t('appetite_mood_history'); ?></h2>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo t('date'); ?></th>
                            <th><?php echo t('appetite'); ?></th>
                            <th><?php echo t('mood'); ?></th>
                            <th><?php echo t('sleep_quality'); ?></th>
                            <th><?php echo t('medication'); ?></th>
                            <th><?php echo t('notes'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['check_ins'] as $checkIn): ?>
                        <tr>
                            <td><?php echo formatDate($checkIn['check_date'], 'd-m-Y'); ?></td>
                            <td><?php echo str_repeat('⭐', $checkIn['appetite_level']); ?></td>
                            <td><?php echo str_repeat('😊', $checkIn['mood_level']); ?></td>
                            <td><?php echo isset($checkIn['sleep_quality']) && $checkIn['sleep_quality'] ? str_repeat('💤', $checkIn['sleep_quality']) : '—'; ?></td>
                            <td><?php echo $checkIn['medication_taken'] ? '✅' : '❌'; ?></td>
                            <td><?php echo $checkIn['notes'] ? '📝' : '—'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <!-- 4. Sleep Patterns -->
        <?php if (!empty($data['sleep_history'])): ?>
        <section class="dashboard-section">
            <h2>😴 <?php echo t('sleep_patterns'); ?></h2>
            <div class="table-responsive">
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
                        <?php foreach ($data['sleep_history'] as $sleep): ?>
                        <tr>
                            <td><?php echo formatDate($sleep['log_date'], 'd-m-Y'); ?></td>
                            <td><?php echo $sleep['sleep_type'] === 'night' ? '🌙' : '💤'; ?></td>
                            <td><?php echo $sleep['sleep_start'] ?: '—'; ?></td>
                            <td><?php echo $sleep['sleep_end'] ?: '—'; ?></td>
                            <td><?php echo $sleep['quality'] ? $sleep['quality'] . '/5' : '—'; ?></td>
                            <td><?php echo $sleep['interruption_count']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <!-- 5. Most Eaten Foods -->
        <?php if (count($data['top_foods']) > 0): ?>
        <section class="dashboard-section">
            <h2><?php echo t('most_eaten_foods'); ?></h2>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo t('food'); ?></th>
                            <th><?php echo t('times_eaten'); ?></th>
                            <th><?php echo t('total_quantity'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['top_foods'] as $food): ?>
                        <tr>
                            <td>
                                <span style="font-size:1.5rem;margin-right:0.5rem;"><?php echo $food['emoji']; ?></span>
                                <?php echo t($food['name_key']); ?>
                            </td>
                            <td><?php echo $food['times_eaten']; ?></td>
                            <td><?php echo number_format($food['total_quantity'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <?php if (count($data['check_ins']) == 0 && count($data['top_foods']) == 0 && count($data['weight_history']) == 0 && count($data['daily_intake']) == 0 && empty($data['sleep_history'])): ?>
        <p style="text-align:center;padding:3rem;opacity:0.6;">
            <?php echo t('no_data_period'); ?>
        </p>
        <?php endif; ?>

        <?php else: ?>
        <p style="text-align:center;padding:3rem;opacity:0.6;">
            <?php echo t('select_child'); ?>
        </p>
        <?php endif; ?>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
function filterDashboard() {
    const child = document.getElementById('childSelect').value;
    const period = document.getElementById('periodSelect').value;
    window.location = `?page=dashboard&child_id=${child}&period=${period}`;
}

<?php if ($data && count($data['weight_history']) > 0): ?>
// Weight Chart
const weightData = <?php echo json_encode($data['weight_history']); ?>;
new Chart(document.getElementById('weightChart'), {
    type: 'line',
    data: {
        labels: weightData.map(d => { const dt = new Date(d.log_date); return String(dt.getDate()).padStart(2,'0') + '-' + String(dt.getMonth()+1).padStart(2,'0'); }),
        datasets: [{
            label: '<?php echo t('weight'); ?> (kg)',
            data: weightData.map(d => d.weight_kg),
            borderColor: '#E8722C',
            backgroundColor: 'rgba(232, 124, 44, 0.12)',
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true
    }
});
<?php endif; ?>

<?php if ($data && count($data['daily_intake']) > 0): ?>
// Food Evolution Chart
const intakeData = <?php echo json_encode($data['daily_intake']); ?>;
const groupedIntake = {};
intakeData.forEach(item => {
    if (!groupedIntake[item.log_date]) groupedIntake[item.log_date] = {};
    groupedIntake[item.log_date][item.meal_name_key] = item.total_quantity;
});

const dates = Object.keys(groupedIntake).sort();
const mealTypes = [...new Set(intakeData.map(d => d.meal_name_key))];

// Brand palette (design refresh) cycled by index — replaces the old hsl() ramp.
const CC_CHART = ['#E8722C','#7E3A5D','#5E9A45','#E0A02E','#4C8FA6','#A65C82'];

new Chart(document.getElementById('intakeChart'), {
    type: 'bar',
    data: {
        labels: dates.map(d => { const dt = new Date(d); return String(dt.getDate()).padStart(2,'0') + '-' + String(dt.getMonth()+1).padStart(2,'0'); }),
        datasets: mealTypes.map((meal, idx) => ({
            label: '<?php echo '{MEAL}'; ?>'.replace('{MEAL}', meal),
            data: dates.map(date => groupedIntake[date][meal] || 0),
            backgroundColor: CC_CHART[idx % CC_CHART.length]
        }))
    },
    options: {
        responsive: true,
        scales: {
            x: {stacked: true},
            y: {stacked: true}
        }
    }
});
<?php endif; ?>
</script>

<?php
$content = ob_get_clean();
renderLayout(t('guardian_dashboard'), $content);
?>
