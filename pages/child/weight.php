<?php
/**
 * Child - Weight Tracking Page
 * Growing is natural. Every number is just a number.
 */

$user = getCurrentUser();
$weightHistory = getWeightHistory($user['id'], 30); // Last 30 days

// Sprint 6 (decision ii): height folds into THIS page only when the guardian has
// enabled show_percentiles (default OFF). When OFF, the page renders byte-for-byte
// as the original weight page — no height field, heading stays "Weight". When ON,
// the page becomes "Growth", gains one optional height input, and reuses the exact
// same tap-log-celebrate flow. No new route, no new footer item (child stays 4).
$showPercentiles = getSetting('show_percentiles', '0') === '1';
$heightHistory = $showPercentiles ? getHeightHistory($user['id'], 30) : [];
$pageTitle = $showPercentiles ? t('growth') : t('weight_tracking');

// Random encouragement
$encouragementKey = getRandomEncouragementKey('weight');

ob_start();
?>

<?php
// A27 — child privacy-note modal (one-time, per-child; informational only, not a gate).
if (isChild() && !childPrivacyNoteSeen((int) $user['id'])) {
    include __DIR__ . '/privacy-note-modal.php';
}
?>
<div class="child-interface">
    <nav class="child-nav">
        <a href="index.php" class="btn-back">← <?php echo t('back'); ?></a>
        <h1><?php echo $pageTitle; ?> 🌱</h1>
        <button class="theme-toggle" type="button" aria-label="Toggle theme"></button>
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
                <?php if ($showPercentiles): ?>
                <?php /* Sprint 6: optional height field, shown only when the guardian
                         toggle is on. Not required — the child can still log just
                         weight and celebrate. (Kept as a PHP comment, never emitted
                         to the child-visible page source — child-boundary, Sprint 8.) */ ?>
                <div class="weight-input-group">
                    <input type="number" id="height" name="height" step="0.1" min="30" max="220" placeholder="<?php echo t('height_cm'); ?>">
                    <span class="weight-unit">cm</span>
                </div>
                <?php endif; ?>
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
                            <td><?php echo formatDate($entry['log_date'], 'd-m-Y'); ?></td>
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

    <?php $currentPage = 'weight'; include __DIR__ . '/footer.php'; ?>
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
<?php if (!$showPercentiles): ?>
document.getElementById('weightForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const weight = document.getElementById('weight').value;
    const submitBtn = this.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.textContent = '⏳';

    fetch('api/weight.php', {
        method: 'POST',
        // Sprint security Phase 3 — attach the per-session CSRF token (window.CSRF_TOKEN).
        headers: {'Content-Type': 'application/json', [window.CSRF_HEADER || 'X-CSRF-Token']: window.CSRF_TOKEN || ''},
        body: JSON.stringify({weight: parseFloat(weight)})
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            launchConfetti();
            vibrate([50, 100, 50]);
            setTimeout(function() {
                document.getElementById('successModal').showModal();
            }, 600);
        } else {
            submitBtn.disabled = false;
            submitBtn.textContent = '<?php echo t('save'); ?> 🌱';
            alert('<?php echo t('error_generic'); ?>');
        }
    })
    .catch(function() {
        submitBtn.disabled = false;
        submitBtn.textContent = '<?php echo t('save'); ?> 🌱';
        alert('<?php echo t('error_generic'); ?>');
    });
});
<?php else: ?>
// Sprint 6 (Growth page active): log weight then, if the optional height is
// filled in, log it to api/height.php before celebrating. An empty or failing
// height never blocks the weight save or the celebration (graceful degradation).
document.getElementById('weightForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const weight = document.getElementById('weight').value;
    const submitBtn = this.querySelector('button[type="submit"]');
    const resetBtn = function() {
        submitBtn.disabled = false;
        submitBtn.textContent = '<?php echo t('save'); ?> 🌱';
    };
    submitBtn.disabled = true;
    submitBtn.textContent = '⏳';

    const celebrate = function() {
        launchConfetti();
        vibrate([50, 100, 50]);
        setTimeout(function() {
            document.getElementById('successModal').showModal();
        }, 600);
    };

    const logHeightThen = function(done) {
        const heightEl = document.getElementById('height');
        const height = heightEl ? heightEl.value : '';
        if (!height) { done(); return; }
        fetch('api/height.php', {
            method: 'POST',
            // Sprint security Phase 3 — attach the per-session CSRF token (window.CSRF_TOKEN).
            headers: {'Content-Type': 'application/json', [window.CSRF_HEADER || 'X-CSRF-Token']: window.CSRF_TOKEN || ''},
            body: JSON.stringify({height: parseFloat(height)})
        })
        .then(r => r.json())
        .then(function() { done(); })   // height is optional — don't fail the flow
        .catch(function() { done(); });
    };

    fetch('api/weight.php', {
        method: 'POST',
        // Sprint security Phase 3 — attach the per-session CSRF token (window.CSRF_TOKEN).
        headers: {'Content-Type': 'application/json', [window.CSRF_HEADER || 'X-CSRF-Token']: window.CSRF_TOKEN || ''},
        body: JSON.stringify({weight: parseFloat(weight)})
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            logHeightThen(celebrate);
        } else {
            resetBtn();
            alert('<?php echo t('error_generic'); ?>');
        }
    })
    .catch(function() {
        resetBtn();
        alert('<?php echo t('error_generic'); ?>');
    });
});
<?php endif; ?>

// Render weight chart
<?php if (count($weightHistory) > 0): ?>
const weightData = <?php echo json_encode(array_reverse($weightHistory)); ?>;
const ctx = document.getElementById('weightChart').getContext('2d');

new Chart(ctx, {
    type: 'line',
    data: {
        labels: weightData.map(d => {
            const date = new Date(d.log_date);
            return String(date.getDate()).padStart(2,'0') + '-' + String(date.getMonth()+1).padStart(2,'0');
        }),
        datasets: [{
            label: '<?php echo t('weight'); ?> (kg)',
            data: weightData.map(d => d.weight_kg),
            borderColor: '#1FA4B5',
            backgroundColor: 'rgba(31, 164, 181, 0.12)',
            tension: 0.4,
            fill: true,
            pointBackgroundColor: '#1A8A99',
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
renderLayout($pageTitle, $content);
?>
