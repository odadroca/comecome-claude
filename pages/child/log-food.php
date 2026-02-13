<?php
/**
 * Child - Log Food Page
 * The heart of ComeCome - where eating becomes a celebration
 */

$user = getCurrentUser();
$currentMeal = getCurrentMeal();
$selectedMeal = $_GET['meal'] ?? ($currentMeal ? $currentMeal['id'] : null);

// Get meals for selection
$db = getDB();
$stmt = $db->query("SELECT * FROM meals WHERE active = 1 ORDER BY sort_order");
$meals = $stmt->fetchAll();

// Get foods for selected meal
$foods = [];
$favorites = [];
if ($selectedMeal) {
    $foods = getFoodsForMeal($selectedMeal);
    $favorites = getUserFavorites($user['id']);

    // Mark favorites in foods array
    $favoriteIds = array_column($favorites, 'id');
    foreach ($foods as &$food) {
        $food['is_favorite'] = in_array($food['id'], $favoriteIds);
    }
}

// Get today's food count for encouragement
$todayCount = 0;
$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM food_log WHERE user_id = ? AND log_date = ?");
$stmt->execute([$user['id'], date('Y-m-d')]);
$todayCount = $stmt->fetch()['cnt'] ?? 0;

// Random encouragement for success modal
$encouragementKey = getRandomEncouragementKey('food');

// Meal emojis for visual flair
$mealEmojis = [
    'meal_breakfast' => '🌅',
    'meal_morning_snack' => '🍎',
    'meal_lunch' => '🍽️',
    'meal_afternoon_snack' => '🧁',
    'meal_dinner' => '🌙',
    'meal_night_snack' => '🥛',
];

ob_start();
?>

<div class="child-interface">
    <!-- Navigation -->
    <nav class="child-nav">
        <a href="index.php" class="btn-back">← <?php echo t('back'); ?></a>
        <h1><?php echo t('welcome', ['name' => $user['name']]); ?></h1>
        <span class="streak-badge" id="streakDisplay"></span>
        <button class="theme-toggle" type="button" aria-label="Toggle theme"></button>
        <a href="index.php?page=logout" class="btn-logout">🚪</a>
    </nav>

    <main class="container">
        <section class="log-food-section">
            <h2 style="text-align: center;"><?php echo t('whats_the_meal'); ?></h2>

            <?php if ($todayCount > 0): ?>
            <p style="text-align:center;font-size:0.85rem;color:#667eea;margin-bottom:1rem;">
                <?php echo t('today_logged_count', ['count' => $todayCount]); ?>
            </p>
            <?php endif; ?>

            <!-- Meal Selection -->
            <div class="meal-selection">
                <?php foreach ($meals as $meal): ?>
                <a href="?page=log-food&meal=<?php echo $meal['id']; ?>"
                   class="meal-btn <?php echo $selectedMeal == $meal['id'] ? 'active' : ''; ?>">
                    <span style="font-size:1.5rem;"><?php echo $mealEmojis[$meal['name_key']] ?? '🍴'; ?></span>
                    <?php echo t($meal['name_key']); ?>
                    <?php if ($currentMeal && $currentMeal['id'] == $meal['id']): ?>
                    <?php /* auto-detected indicator removed per user request */ ?>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if ($selectedMeal): ?>
            <!-- Food Grid -->
            <div class="food-section">
                <?php
                // Group foods by category
                $foodsByCategory = [];
                $categoryNames = [];
                foreach ($foods as $food) {
                    $catKey = $food['category_name_key'];
                    if (!isset($foodsByCategory[$catKey])) {
                        $foodsByCategory[$catKey] = [];
                        $categoryNames[$catKey] = t($catKey);
                    }
                    $foodsByCategory[$catKey][] = $food;
                }

                // Favorites section
                $availableIds = array_column($foods, 'id');
                $mealFavorites = array_filter($favorites, function($f) use ($availableIds) {
                    return in_array($f['id'], $availableIds);
                });
                ?>

                <?php if (count($mealFavorites) > 0): ?>
                <h3><?php echo t('favorites'); ?> ⭐</h3>
                <div class="food-grid">
                    <?php foreach ($mealFavorites as $food): ?>
                    <button class="food-card favorite" data-food-id="<?php echo $food['id']; ?>" data-food-name="<?php echo t($food['name_key']); ?>" data-is-favorite="1" data-category="<?php echo $food['category_name_key']; ?>">
                        <div class="food-emoji"><?php echo $food['emoji']; ?></div>
                        <div class="food-name"><?php echo t($food['name_key']); ?></div>
                        <div class="favorite-badge">⭐</div>
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <h3><?php echo count($mealFavorites) > 0 ? t('all_foods') : t('choose_food'); ?></h3>
                <p style="text-align:center;font-size:0.875rem;opacity:0.7;"><?php echo t('long_press_favorite'); ?></p>

                <!-- Category filter tabs -->
                <?php if (count($foodsByCategory) > 1): ?>
                <div class="category-tabs">
                    <button class="category-tab active" data-category="all"><?php echo t('all'); ?></button>
                    <?php foreach ($categoryNames as $catKey => $catName): ?>
                    <button class="category-tab" data-category="<?php echo $catKey; ?>"><?php echo $catName; ?></button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="food-grid" id="foodGrid">
                    <?php foreach ($foods as $food): ?>
                    <button class="food-card <?php echo $food['is_favorite'] ? 'is-favorite' : ''; ?>"
                            data-food-id="<?php echo $food['id']; ?>"
                            data-food-name="<?php echo t($food['name_key']); ?>"
                            data-is-favorite="<?php echo $food['is_favorite'] ? '1' : '0'; ?>"
                            data-category="<?php echo $food['category_name_key']; ?>">
                        <div class="food-emoji"><?php echo $food['emoji']; ?></div>
                        <div class="food-name"><?php echo t($food['name_key']); ?></div>
                        <?php if ($food['is_favorite']): ?>
                        <div class="favorite-badge">⭐</div>
                        <?php endif; ?>
                    </button>
                    <?php endforeach; ?>
                </div>

                <p style="text-align:center;margin-top:2rem;font-size:0.8rem;opacity:0.5;">
                    <?php echo t('missing_food_hint'); ?>
                </p>
            </div>
            <?php endif; ?>
        </section>
    </main>

    <!-- Quick Navigation -->
    <footer class="child-footer">
        <a href="?page=log-food" class="footer-btn active">
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
        <a href="?page=history" class="footer-btn">
            <span style="font-size:1.5rem;">📖</span>
            <span><?php echo t('my_history'); ?></span>
        </a>
    </footer>
</div>

<!-- Portion Selection Modal -->
<dialog id="portionModal">
    <article>
        <header>
            <h3 id="portionModalTitle"><?php echo t('how_much'); ?></h3>
        </header>
        <div class="portion-grid">
            <button class="portion-btn" data-portion="little">
                <div style="font-size:3rem;">🤏</div>
                <div><?php echo t('portion_little'); ?></div>
            </button>
            <button class="portion-btn" data-portion="some">
                <div style="font-size:3rem;">👌</div>
                <div><?php echo t('portion_some'); ?></div>
            </button>
            <button class="portion-btn" data-portion="lot">
                <div style="font-size:3rem;">👍</div>
                <div><?php echo t('portion_lot'); ?></div>
            </button>
            <button class="portion-btn" data-portion="all">
                <div style="font-size:3rem;">💪</div>
                <div><?php echo t('portion_all'); ?></div>
            </button>
        </div>
        <footer>
            <button class="btn-secondary" onclick="document.getElementById('portionModal').close()">
                <?php echo t('cancel'); ?>
            </button>
        </footer>
    </article>
</dialog>

<!-- Success Modal - THE CELEBRATION -->
<dialog id="successModal">
    <article style="text-align:center;">
        <div class="success-emoji">🎉</div>
        <div class="success-message"><?php echo t('food_logged'); ?></div>
        <div class="success-encouragement" id="successEncouragement"><?php echo t($encouragementKey); ?></div>
        <div class="success-streak" id="successStreak" style="display:none;"></div>
        <footer style="display:flex;gap:1rem;margin-top:1.5rem;">
            <button class="btn-secondary" onclick="location.reload()">
                <?php echo t('add_another'); ?> ➕
            </button>
            <button class="btn-primary" onclick="window.location='index.php?page=log-food'" style="flex:1;">
                <?php echo t('done'); ?> ✨
            </button>
        </footer>
    </article>
</dialog>

<script>
let selectedFood = null;
let selectedMeal = <?php echo json_encode($selectedMeal); ?>;
let longPressTimer = null;
let isLongPress = false;
let wasTouchScroll = false;
let touchStartY = 0;
let touchStartX = 0;

// Encouragement messages (translated via PHP)
const encouragements = [
    <?php echo json_encode(t('encourage_food_1')); ?>,
    <?php echo json_encode(t('encourage_food_2')); ?>,
    <?php echo json_encode(t('encourage_food_3')); ?>,
    <?php echo json_encode(t('encourage_food_4')); ?>,
    <?php echo json_encode(t('encourage_food_5')); ?>
];

// Food card click/long-press handlers
document.querySelectorAll('.food-card').forEach(card => {
    // Click handler - works for desktop and mobile tap
    card.addEventListener('click', function(e) {
        // Skip if it was a long press or a scroll gesture
        if (isLongPress || wasTouchScroll) {
            isLongPress = false;
            wasTouchScroll = false;
            return;
        }
        isLongPress = false;
        wasTouchScroll = false;

        selectedFood = {
            id: this.dataset.foodId,
            name: this.dataset.foodName
        };
        document.getElementById('portionModalTitle').textContent = this.dataset.foodName + ' - <?php echo t('how_much'); ?>';
        document.getElementById('portionModal').showModal();
    });

    // Mobile: track touch position to distinguish scroll from tap
    card.addEventListener('touchstart', function(e) {
        isLongPress = false;
        wasTouchScroll = false;
        touchStartY = e.touches[0].clientY;
        touchStartX = e.touches[0].clientX;
        longPressTimer = setTimeout(() => {
            isLongPress = true;
            navigator.vibrate && navigator.vibrate(50);
            toggleFavorite(this.dataset.foodId, this);
        }, 600);
    }, {passive: true});

    card.addEventListener('touchend', function(e) {
        clearTimeout(longPressTimer);
    }, {passive: true});

    card.addEventListener('touchmove', function(e) {
        // If finger moved more than 8px in any direction, it's a scroll
        const moveY = Math.abs(e.touches[0].clientY - touchStartY);
        const moveX = Math.abs(e.touches[0].clientX - touchStartX);
        if (moveY > 8 || moveX > 8) {
            clearTimeout(longPressTimer);
            isLongPress = false;
            wasTouchScroll = true;
        }
    }, {passive: true});

    // Desktop: right-click to favorite
    card.addEventListener('contextmenu', function(e) {
        e.preventDefault();
        toggleFavorite(this.dataset.foodId, this);
    });
});

// Category filter tabs
document.querySelectorAll('.category-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        // Update active tab
        document.querySelectorAll('.category-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');

        const category = this.dataset.category;
        const cards = document.querySelectorAll('#foodGrid .food-card');

        cards.forEach(card => {
            if (category === 'all' || card.dataset.category === category) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    });
});

// Portion selection
document.querySelectorAll('.portion-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        // Disable buttons while saving to prevent double-tap
        document.querySelectorAll('.portion-btn').forEach(b => b.disabled = true);
        this.textContent = '⏳';
        logFood(selectedFood.id, this.dataset.portion);
    });
});

// Toggle favorite
function toggleFavorite(foodId, element) {
    fetch('api/favorites.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({food_id: foodId})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            element.classList.toggle('is-favorite');
            const badge = element.querySelector('.favorite-badge');
            if (data.is_favorite) {
                if (!badge) {
                    element.insertAdjacentHTML('beforeend', '<div class="favorite-badge">⭐</div>');
                }
            } else {
                if (badge) badge.remove();
            }
        }
    })
    .catch(() => {}); // Silent fail for favorites
}

// Re-enable portion buttons when modal opens
function resetPortionButtons() {
    document.querySelectorAll('.portion-btn').forEach((btn, i) => {
        btn.disabled = false;
        const emojis = ['🤏', '👌', '👍', '💪'];
        const labels = [<?php echo json_encode(t('portion_little')); ?>, <?php echo json_encode(t('portion_some')); ?>, <?php echo json_encode(t('portion_lot')); ?>, <?php echo json_encode(t('portion_all')); ?>];
        btn.innerHTML = '<div style="font-size:3rem;">' + emojis[i] + '</div><div>' + labels[i] + '</div>';
    });
}

// Reset buttons every time portion modal opens
const portionModal = document.getElementById('portionModal');
if (portionModal.addEventListener) {
    // Use MutationObserver to detect when dialog opens
    new MutationObserver(function() {
        if (portionModal.open) resetPortionButtons();
    }).observe(portionModal, {attributes: true, attributeFilter: ['open']});
}

// Log food - WITH CELEBRATION
function logFood(foodId, portion) {
    fetch('api/food-log.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            food_id: foodId,
            meal_id: selectedMeal,
            portion: portion
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('portionModal').close();

            // Update streak
            const streakCount = updateStreak();

            // Show random encouragement
            const msg = encouragements[Math.floor(Math.random() * encouragements.length)];
            document.getElementById('successEncouragement').textContent = msg;

            // Show streak if > 1
            const streakEl = document.getElementById('successStreak');
            if (streakCount > 1) {
                streakEl.textContent = getStreakEmoji(streakCount) + ' ' + streakCount + ' <?php echo t('streak_days'); ?>';
                streakEl.style.display = 'inline-flex';
            }

            // CONFETTI first, then modal after short delay
            // (dialog top-layer covers confetti, so show confetti first)
            launchConfetti();
            vibrate([50, 100, 50, 100, 50]);

            setTimeout(function() {
                document.getElementById('successModal').showModal();
            }, 600);
        } else {
            // Show error feedback
            document.getElementById('portionModal').close();
            alert('<?php echo t('error_generic'); ?>');
            resetPortionButtons();
        }
    })
    .catch(function() {
        document.getElementById('portionModal').close();
        alert('<?php echo t('error_generic'); ?>');
        resetPortionButtons();
    });
}
</script>

<?php
$content = ob_get_clean();
renderLayout(t('log_food'), $content);
?>
