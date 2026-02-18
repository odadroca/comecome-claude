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

ob_start();
?>

<div class="guardian-interface">
    <?php include 'nav.php'; ?>

    <main class="container">
        <h1><?php echo t('guardian_dashboard'); ?></h1>

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
        <!-- Weight Timeline Chart -->
        <?php if (count($data['weight_history']) > 0): ?>
        <section class="dashboard-section">
            <h2><?php echo t('weight_timeline'); ?></h2>
            <div class="chart-container">
                <canvas id="weightChart"></canvas>
            </div>
        </section>
        <?php endif; ?>

        <!-- Appetite & Mood History -->
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
                            <td><?php echo $checkIn['medication_taken'] ? '✅' : '❌'; ?></td>
                            <td><?php echo $checkIn['notes'] ? '📝' : '—'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <!-- Most Eaten Foods -->
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

        <!-- Daily Intake Chart -->
        <?php if (count($data['daily_intake']) > 0): ?>
        <section class="dashboard-section">
            <h2><?php echo t('daily_intake'); ?></h2>
            <div class="chart-container">
                <canvas id="intakeChart"></canvas>
            </div>
        </section>
        <?php endif; ?>

        <?php if (count($data['check_ins']) == 0 && count($data['top_foods']) == 0 && count($data['weight_history']) == 0): ?>
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
            borderColor: '#4CAF50',
            backgroundColor: 'rgba(76, 175, 80, 0.1)',
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
// Daily Intake Chart
const intakeData = <?php echo json_encode($data['daily_intake']); ?>;
const groupedIntake = {};
intakeData.forEach(item => {
    if (!groupedIntake[item.log_date]) groupedIntake[item.log_date] = {};
    groupedIntake[item.log_date][item.meal_name_key] = item.total_quantity;
});

const dates = Object.keys(groupedIntake).sort();
const mealTypes = [...new Set(intakeData.map(d => d.meal_name_key))];

new Chart(document.getElementById('intakeChart'), {
    type: 'bar',
    data: {
        labels: dates.map(d => { const dt = new Date(d); return String(dt.getDate()).padStart(2,'0') + '-' + String(dt.getMonth()+1).padStart(2,'0'); }),
        datasets: mealTypes.map((meal, idx) => ({
            label: '<?php echo '{MEAL}'; ?>'.replace('{MEAL}', meal),
            data: dates.map(date => groupedIntake[date][meal] || 0),
            backgroundColor: `hsl(${idx * 60}, 70%, 60%)`
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
