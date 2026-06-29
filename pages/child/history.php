<?php
/**
 * Child - History Page
 * Your food story - every day tells a tale
 */

$user = getCurrentUser();
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$showCheckinSection = getSetting('show_checkin', '1') == '1';
$foodLog = getFoodLogByDate($user['id'], $selectedDate);
$checkIn = $showCheckinSection ? getCheckIn($user['id'], $selectedDate) : null;

// Calculate previous and next day for navigation
$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
$isToday = $selectedDate === date('Y-m-d');
$isYesterday = $selectedDate === date('Y-m-d', strtotime('-1 day'));

// Friendly date label
if ($isToday) {
    $dateLabel = t('today');
} elseif ($isYesterday) {
    $dateLabel = t('yesterday');
} else {
    $dateLabel = formatDate($selectedDate, 'd/m/Y');
}

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
        <h1><?php echo t('my_history'); ?></h1>
        <button class="theme-toggle" type="button" aria-label="Toggle theme"></button>
        <a href="index.php?page=logout" class="btn-logout">🚪</a>
    </nav>

    <main class="container">
        <!-- Date Selector with prev/next navigation -->
        <section class="date-selector">
            <a href="?page=history&date=<?php echo $prevDate; ?>" class="date-nav-btn">◀</a>
            <div style="text-align:center;flex:1;">
                <div style="font-weight:700;font-size:1.1rem;"><?php echo $dateLabel; ?></div>
                <input type="date" id="dateInput" value="<?php echo $selectedDate; ?>" max="<?php echo date('Y-m-d'); ?>" style="font-size:0.85rem;border:none;background:transparent;text-align:center;color:#0F5563;">
            </div>
            <?php if (!$isToday): ?>
            <a href="?page=history&date=<?php echo $nextDate; ?>" class="date-nav-btn">▶</a>
            <?php else: ?>
            <div style="width:40px;"></div>
            <?php endif; ?>
        </section>

        <!-- Check-in Summary -->
        <?php if ($checkIn): ?>
        <section class="checkin-summary">
            <h3><?php echo t('daily_checkin'); ?> ✅</h3>
            <div class="summary-grid">
                <div class="summary-item">
                    <span class="summary-label"><?php echo t('appetite'); ?></span>
                    <span class="summary-value">
                        <?php
                        $appetiteEmojis = ['😫', '😕', '😐', '🙂', '😋'];
                        echo $appetiteEmojis[$checkIn['appetite_level'] - 1];
                        ?>
                    </span>
                </div>
                <div class="summary-item">
                    <span class="summary-label"><?php echo t('mood'); ?></span>
                    <span class="summary-value">
                        <?php
                        $moodEmojis = ['😢', '🙁', '😐', '😊', '🤩'];
                        echo $moodEmojis[$checkIn['mood_level'] - 1];
                        ?>
                    </span>
                </div>
                <?php if (getSetting('show_sleep_tracking', '1') == '1' && isset($checkIn['sleep_quality']) && $checkIn['sleep_quality']): ?>
                <div class="summary-item">
                    <span class="summary-label"><?php echo t('sleep_quality'); ?></span>
                    <span class="summary-value">
                        <?php
                        $sleepEmojis = ['😫', '😴', '😐', '😊', '🌟'];
                        echo $sleepEmojis[$checkIn['sleep_quality'] - 1];
                        ?>
                    </span>
                </div>
                <?php endif; ?>
                <?php if (getSetting('show_medication_to_children', '1') == '1'): ?>
                <div class="summary-item">
                    <span class="summary-label"><?php echo t('medication'); ?></span>
                    <span class="summary-value">
                        <?php echo $checkIn['medication_taken'] ? '✅' : '❌'; ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($checkIn['notes']): ?>
            <div class="checkin-notes">
                <strong><?php echo t('notes'); ?>:</strong>
                <p><?php echo nl2br(sanitize($checkIn['notes'])); ?></p>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <!-- Food Log -->
        <section class="food-log-section">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;">
                <h3 style="margin:0;"><?php echo t('log_food'); ?> 🍽️</h3>
                <!-- Small, deliberately understated link to log a meal for the day being
                     viewed (the "I forgot to log it" path). Carries the selected date to
                     the normal food picker; the child surface stays flat. -->
                <a href="index.php?page=log-food&date=<?php echo $selectedDate; ?>"
                   class="add-past-meal-link"
                   title="<?php echo t('log_past_meal'); ?>"
                   aria-label="<?php echo t('log_past_meal'); ?>"
                   style="font-size:1.25rem;line-height:1;text-decoration:none;opacity:0.45;">➕</a>
            </div>

            <?php if (count($foodLog) > 0): ?>
            <div class="food-log-list">
                <?php
                $groupedByMeal = [];
                foreach ($foodLog as $entry) {
                    $mealKey = $entry['meal_name_key'];
                    if (!isset($groupedByMeal[$mealKey])) {
                        $groupedByMeal[$mealKey] = [];
                    }
                    $groupedByMeal[$mealKey][] = $entry;
                }

                foreach ($groupedByMeal as $mealKey => $entries):
                ?>
                <div class="meal-group">
                    <h4><?php echo t($mealKey); ?></h4>
                    <div class="food-entries">
                        <?php foreach ($entries as $entry): ?>
                        <div class="food-entry">
                            <span class="food-emoji"><?php echo $entry['emoji']; ?></span>
                            <span class="food-details">
                                <strong><?php echo t($entry['food_name_key']); ?></strong>
                                <small><?php echo t('portion_' . $entry['portion']); ?> · <?php echo date('H:i', strtotime($entry['log_time'])); ?></small>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-emoji"><?php echo $isToday ? '🌱' : '📭'; ?></div>
                <div class="empty-state-text">
                    <?php echo $isToday ? t('empty_today_title') : t('no_logs_today'); ?>
                </div>
                <?php if ($isToday): ?>
                <div class="empty-state-hint"><?php echo t('empty_today_hint'); ?></div>
                <a href="?page=log-food" class="btn-primary" style="display:inline-block;width:auto;margin-top:1rem;">
                    🍽️ <?php echo t('log_food'); ?>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </section>

        <!-- Daily Intake Chart -->
        <?php if (count($foodLog) > 0): ?>
        <section class="chart-container" style="margin-top:1.5rem;">
            <h3 style="text-align:center;margin-bottom:1rem;"><?php echo t('daily_intake'); ?> 📊</h3>
            <canvas id="intakeChart"></canvas>
        </section>
        <?php endif; ?>
    </main>

    <?php $currentPage = 'history'; include __DIR__ . '/footer.php'; ?>
</div>

<?php if (count($foodLog) > 0): ?>
<script src="assets/js/chart.umd.min.js"></script>
<?php endif; ?>

<script>
document.getElementById('dateInput').addEventListener('change', function() {
    window.location = '?page=history&date=' + this.value;
});

<?php if (count($foodLog) > 0): ?>
// Build chart data from food log
const mealData = {};
const portionValues = {'little': 0.25, 'some': 0.5, 'lot': 0.75, 'all': 1.0};
const chartFoodLog = <?php echo json_encode($foodLog); ?>;

chartFoodLog.forEach(entry => {
    const mealName = <?php echo json_encode(array_map(function($key) { return t($key); }, array_unique(array_column($foodLog, 'meal_name_key')))); ?>;
    const key = entry.meal_name_key;
    if (!mealData[key]) mealData[key] = 0;
    mealData[key] += (portionValues[entry.portion] || 0);
});

const mealLabels = Object.keys(mealData).map(k => {
    const translations = <?php
        $mealTranslations = [];
        foreach (array_unique(array_column($foodLog, 'meal_name_key')) as $mk) {
            $mealTranslations[$mk] = t($mk);
        }
        echo json_encode($mealTranslations);
    ?>;
    return translations[k] || k;
});

const mealColors = ['#1FA4B5', '#0F5563', '#5E9A45', '#E0A02E', '#4C8FA6', '#90A0A4'];

const intakeCtx = document.getElementById('intakeChart');
if (intakeCtx) {
    new Chart(intakeCtx.getContext('2d'), {
        type: 'bar',
        data: {
            labels: mealLabels,
            datasets: [{
                label: '<?php echo t('daily_intake'); ?>',
                data: Object.values(mealData),
                backgroundColor: mealColors.slice(0, mealLabels.length),
                borderRadius: 8,
                borderSkipped: false
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
                    beginAtZero: true,
                    grid: { color: 'rgba(102, 126, 234, 0.1)' },
                    ticks: {
                        callback: function(value) {
                            return value + ' <?php echo t('portion_all'); ?>';
                        }
                    }
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });
}
<?php endif; ?>
</script>

<?php
$content = ob_get_clean();
renderLayout(t('my_history'), $content);
?>
