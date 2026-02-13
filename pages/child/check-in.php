<?php
/**
 * Child - Daily Check-in Page
 * Your feelings matter. Every single one.
 */

$user = getCurrentUser();
$today = date('Y-m-d');
$checkIn = getCheckIn($user['id'], $today);
$showMedication = getSetting('show_medication_to_children', '1') == '1';

// Get user medications
$db = getDB();
$stmt = $db->prepare("
    SELECT m.* FROM medications m
    JOIN user_medications um ON m.id = um.medication_id
    WHERE um.user_id = ? AND m.active = 1
");
$stmt->execute([$user['id']]);
$medications = $stmt->fetchAll();

// Random encouragement for success
$encouragementKey = getRandomEncouragementKey('checkin');

ob_start();
?>

<div class="child-interface">
    <nav class="child-nav">
        <a href="index.php" class="btn-back">← <?php echo t('back'); ?></a>
        <h1><?php echo t('daily_checkin'); ?></h1>
        <a href="index.php?page=logout" class="btn-logout">🚪</a>
    </nav>

    <main class="container">
        <?php if ($checkIn): ?>
        <p style="text-align:center;font-size:0.85rem;color:#4CAF50;margin-bottom:0.5rem;font-weight:600;">
            ✅ <?php echo t('checkin_already_done'); ?>
        </p>
        <?php endif; ?>

        <form id="checkInForm" class="checkin-form">
            <!-- Appetite Level -->
            <section class="checkin-section">
                <h3><?php echo t('how_hungry_today'); ?></h3>
                <div class="face-scale">
                    <?php
                    $appetiteEmojis = ['😫', '😕', '😐', '🙂', '😋'];
                    for ($i = 1; $i <= 5; $i++):
                    ?>
                    <label class="face-option">
                        <input type="radio" name="appetite" value="<?php echo $i; ?>"
                               <?php echo ($checkIn && $checkIn['appetite_level'] == $i) ? 'checked' : ''; ?>
                               required>
                        <div class="face-emoji"><?php echo $appetiteEmojis[$i-1]; ?></div>
                        <div class="face-label"><?php echo t('appetite_' . $i); ?></div>
                    </label>
                    <?php endfor; ?>
                </div>
            </section>

            <!-- Mood Level -->
            <section class="checkin-section">
                <h3><?php echo t('how_feeling_today'); ?></h3>
                <div class="face-scale">
                    <?php
                    $moodEmojis = ['😢', '🙁', '😐', '😊', '🤩'];
                    for ($i = 1; $i <= 5; $i++):
                    ?>
                    <label class="face-option">
                        <input type="radio" name="mood" value="<?php echo $i; ?>"
                               <?php echo ($checkIn && $checkIn['mood_level'] == $i) ? 'checked' : ''; ?>
                               required>
                        <div class="face-emoji"><?php echo $moodEmojis[$i-1]; ?></div>
                        <div class="face-label"><?php echo t('mood_' . $i); ?></div>
                    </label>
                    <?php endfor; ?>
                </div>
            </section>

            <?php if ($showMedication && count($medications) > 0): ?>
            <!-- Medication -->
            <section class="checkin-section">
                <h3><?php echo t('took_medication'); ?></h3>
                <div class="medication-check">
                    <label class="option-card">
                        <input type="radio" name="medication" value="1"
                               <?php echo ($checkIn && $checkIn['medication_taken'] == 1) ? 'checked' : ''; ?>
                               required>
                        <div style="font-size:2rem;">✅</div>
                        <div><?php echo t('yes'); ?></div>
                    </label>
                    <label class="option-card">
                        <input type="radio" name="medication" value="0"
                               <?php echo ($checkIn && $checkIn['medication_taken'] == 0) ? 'checked' : ''; ?>
                               required>
                        <div style="font-size:2rem;">❌</div>
                        <div><?php echo t('no'); ?></div>
                    </label>
                </div>
            </section>
            <?php else: ?>
            <input type="hidden" name="medication" value="0">
            <?php endif; ?>

            <!-- Optional Notes -->
            <section class="checkin-section">
                <label for="notes">
                    <h3><?php echo t('any_notes'); ?> 💭</h3>
                    <textarea id="notes" name="notes" rows="4" placeholder="<?php echo t('notes_placeholder'); ?>"><?php echo $checkIn ? sanitize($checkIn['notes']) : ''; ?></textarea>
                </label>
            </section>

            <button type="submit" class="btn-primary btn-large">
                <?php echo t('save'); ?> ✅
            </button>
        </form>
    </main>

    <footer class="child-footer">
        <a href="?page=log-food" class="footer-btn">
            <span style="font-size:1.5rem;">🍽️</span>
            <span><?php echo t('log_food'); ?></span>
        </a>
        <a href="?page=check-in" class="footer-btn active">
            <span style="font-size:1.5rem;">✅</span>
            <span><?php echo t('check_in'); ?></span>
        </a>
        <a href="?page=weight" class="footer-btn">
            <span style="font-size:1.5rem;">⚖️</span>
            <span><?php echo t('my_weight'); ?></span>
        </a>
        <a href="?page=history" class="footer-btn">
            <span style="font-size:1.5rem;">📖</span>
            <span><?php echo t('my_history'); ?></span>
        </a>
    </footer>
</div>

<!-- Success Modal - Warm and encouraging -->
<dialog id="successModal">
    <article style="text-align:center;">
        <div class="success-emoji">🌟</div>
        <div class="success-message"><?php echo t('checkin_saved'); ?></div>
        <div class="success-encouragement"><?php echo t($encouragementKey); ?></div>
        <footer style="margin-top:1.5rem;">
            <button class="btn-primary" onclick="window.location='index.php'">
                <?php echo t('done'); ?> ✨
            </button>
        </footer>
    </article>
</dialog>

<script>
document.getElementById('checkInForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const data = {
        appetite: formData.get('appetite'),
        mood: formData.get('mood'),
        medication: formData.get('medication'),
        notes: formData.get('notes')
    };

    fetch('api/check-in.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            // Update streak for check-in too
            updateStreak();
            launchConfetti();
            vibrate([50, 100, 50]);
            document.getElementById('successModal').showModal();
        }
    });
});
</script>

<?php
$content = ob_get_clean();
renderLayout(t('daily_checkin'), $content);
?>
