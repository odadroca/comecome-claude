<?php
/**
 * Child - Weight Tracking Page
 * Growing is natural. Every number is just a number.
 */

$user = getCurrentUser();
$weightHistory = getWeightHistory($user['id'], 30); // Last 30 days

// Random encouragement
$encouragementKey = getRandomEncouragementKey('weight');

ob_start();
?>

<div class="child-interface">
    <nav class="child-nav">
        <a href="index.php" class="btn-back">← <?php echo t('back'); ?></a>
        <h1><?php echo t('weight_tracking'); ?> 🌱</h1>
        <a href="index.php?page=logout" class="btn-logout">🚪</a>
    </nav>

    <main class="container">
        <!-- Weight Entry -->
        <section class="weight-entry-section">
            <h2 style="text-align:center;"><?php echo t('enter_weight'); ?></h2>
            <p class="weight-encouragement"><?php echo t('weight_encouragement'); ?></p>
            <form id="weightForm" class="weight-form">
                <div class="weight-input-group">
                    <input type="number" id="weight" name="weight" step="0.1" min="1" max="200" placeholder="25.5" required>
                    <span class="weight-unit">kg</span>
                </div>
                <button type="submit" class="btn-primary btn-large">
                    <?php echo t('save'); ?> 🌱
                </button>
            </form>
        </section>

        <!-- Weight History Chart -->
        <?php if (count($weightHistory) > 0): ?>
        <section class="weight-chart-section">
            <h3><?php echo t('weight_trend'); ?> 📈</h3>
            <div class="chart-container">
                <canvas id="weightChart"></canvas>
            </div>

            <!-- Weight History Table -->
            <div class="weight-history-table">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo t('date'); ?></th>
                            <th><?php echo t('weight'); ?></th>
                            <th><?php echo t('weight_change'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $prevWeight = null;
                        foreach ($weightHistory as $entry):
                            $change = null;
                            if ($prevWeight !== null) {
                                $change = $entry['weight_kg'] - $prevWeight;
                            }
                            $prevWeight = $entry['weight_kg'];
                        ?>
                        <tr>
                            <td><?php echo formatDate($entry['log_date'], 'd/m/Y'); ?></td>
                            <td><?php echo number_format($entry['weight_kg'], 1); ?> kg</td>
                            <td>
                                <?php if ($change === null): ?>
                                    🌱
                                <?php elseif ($change > 0): ?>
                                    <span class="weight-increase">+<?php echo number_format($change, 1); ?> kg 📈</span>
                                <?php elseif ($change < 0): ?>
                                    <span class="weight-decrease"><?php echo number_format($change, 1); ?> kg</span>
                                <?php else: ?>
                                    ➡️
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-emoji">🌱</div>
            <div class="empty-state-text"><?php echo t('no_weight_data'); ?></div>
            <div class="empty-state-hint"><?php echo t('weight_first_entry_hint'); ?></div>
        </div>
        <?php endif; ?>
    </main>

    <footer class="child-footer">
        <a href="?page=log-food" class="footer-btn">
            <span style="font-size:1.5rem;">🍽️</span>
            <span><?php echo t('log_food'); ?></span>
        </a>
        <a href="?page=check-in" class="footer-btn">
            <span style="font-size:1.5rem;">✅</span>
            <span><?php echo t('check_in'); ?></span>
        </a>
        <a href="?page=weight" class="footer-btn active">
            <span style="font-size:1.5rem;">⚖️</span>
            <span><?php echo t('my_weight'); ?></span>
        </a>
        <a href="?page=history" class="footer-btn">
            <span style="font-size:1.5rem;">📖</span>
            <span><?php echo t('my_history'); ?></span>
        </a>
    </footer>
</div>

<!-- Success Modal -->
<dialog id="successModal">
    <article style="text-align:center;">
        <div class="success-emoji">🌱</div>
        <div class="success-message"><?php echo t('weight_saved'); ?></div>
        <div class="success-encouragement"><?php echo t($encouragementKey); ?></div>
        <footer style="margin-top:1.5rem;">
            <button class="btn-primary" onclick="location.reload()">
                <?php echo t('done'); ?> ✨
            </button>
        </footer>
    </article>
</dialog>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.getElementById('weightForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const weight = document.getElementById('weight').value;

    fetch('api/weight.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({weight: weight})
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            launchConfetti();
            vibrate([50, 100, 50]);
            document.getElementById('successModal').showModal();
        }
    });
});

// Render weight chart
<?php if (count($weightHistory) > 0): ?>
const weightData = <?php echo json_encode(array_reverse($weightHistory)); ?>;
const ctx = document.getElementById('weightChart').getContext('2d');

new Chart(ctx, {
    type: 'line',
    data: {
        labels: weightData.map(d => {
            const date = new Date(d.log_date);
            return date.toLocaleDateString('<?php echo getAppLocale(); ?>', {month: 'short', day: 'numeric'});
        }),
        datasets: [{
            label: '<?php echo t('weight'); ?> (kg)',
            data: weightData.map(d => d.weight_kg),
            borderColor: '#667eea',
            backgroundColor: 'rgba(102, 126, 234, 0.1)',
            tension: 0.4,
            fill: true,
            pointBackgroundColor: '#764ba2',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointRadius: 5,
            pointHoverRadius: 8
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {display: false}
        },
        scales: {
            y: {
                beginAtZero: false,
                grid: {
                    color: 'rgba(102, 126, 234, 0.1)'
                }
            },
            x: {
                grid: {
                    display: false
                }
            }
        }
    }
});
<?php endif; ?>
</script>

<?php
$content = ob_get_clean();
renderLayout(t('weight_tracking'), $content);
?>
