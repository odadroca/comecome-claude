<?php
/**
 * Child - History Page
 * Your food story - every day tells a tale
 */

$user = getCurrentUser();
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$foodLog = getFoodLogByDate($user['id'], $selectedDate);
$checkIn = getCheckIn($user['id'], $selectedDate);

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

<div class="child-interface">
    <nav class="child-nav">
        <a href="index.php" class="btn-back">← <?php echo t('back'); ?></a>
        <h1><?php echo t('my_history'); ?></h1>
        <a href="index.php?page=logout" class="btn-logout">🚪</a>
    </nav>

    <main class="container">
        <!-- Date Selector with prev/next navigation -->
        <section class="date-selector">
            <a href="?page=history&date=<?php echo $prevDate; ?>" class="date-nav-btn">◀</a>
            <div style="text-align:center;flex:1;">
                <div style="font-weight:700;font-size:1.1rem;"><?php echo $dateLabel; ?></div>
                <input type="date" id="dateInput" value="<?php echo $selectedDate; ?>" max="<?php echo date('Y-m-d'); ?>" style="font-size:0.85rem;border:none;background:transparent;text-align:center;color:#667eea;">
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
            <h3><?php echo t('log_food'); ?> 🍽️</h3>

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
        <a href="?page=weight" class="footer-btn">
            <span style="font-size:1.5rem;">⚖️</span>
            <span><?php echo t('my_weight'); ?></span>
        </a>
        <a href="?page=history" class="footer-btn active">
            <span style="font-size:1.5rem;">📖</span>
            <span><?php echo t('my_history'); ?></span>
        </a>
    </footer>
</div>

<script>
document.getElementById('dateInput').addEventListener('change', function() {
    window.location = '?page=history&date=' + this.value;
});
</script>

<?php
$content = ob_get_clean();
renderLayout(t('my_history'), $content);
?>
