<?php
/**
 * Guardian - Manage Foods (Full CRUD)
 */

requireGuardian();
$user = getCurrentUser();
$db = getDB();
$message = '';

// Get all categories for dropdowns
$stmt = $db->query("SELECT * FROM food_categories ORDER BY sort_order");
$categories = $stmt->fetchAll();

// Get all meals for category-meal association
$stmt = $db->query("SELECT * FROM meals ORDER BY sort_order");
$allMeals = $stmt->fetchAll();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $nameKey = trim($_POST['name_key'] ?? '');
        $emoji = trim($_POST['emoji'] ?? '🍽️');
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $sortOrder = (int) ($_POST['sort_order'] ?? 999);

        if ($nameKey && $categoryId) {
            // Ensure name_key has food_ prefix
            if (strpos($nameKey, 'food_') !== 0) {
                $nameKey = 'food_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($nameKey));
            }
            $stmt = $db->prepare("INSERT INTO foods (name_key, emoji, category_id, sort_order) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$nameKey, $emoji, $categoryId, $sortOrder])) {
                // Also add a default translation for this food key
                $displayName = $_POST['display_name'] ?? '';
                if ($displayName) {
                    $locale = getAppLocale();
                    $stmtT = $db->prepare("INSERT OR REPLACE INTO translations (locale, \"key\", value) VALUES (?, ?, ?)");
                    $stmtT->execute([$locale, $nameKey, $displayName]);
                }
                $message = t('changes_saved');
            }
        }
        header('Location: ?page=manage-foods&msg=saved');
        exit;
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $emoji = trim($_POST['emoji'] ?? '🍽️');
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $sortOrder = (int) ($_POST['sort_order'] ?? 999);
        $active = (int) ($_POST['active'] ?? 1);

        if ($id && $categoryId) {
            $stmt = $db->prepare("UPDATE foods SET emoji = ?, category_id = ?, sort_order = ?, active = ? WHERE id = ?");
            $stmt->execute([$emoji, $categoryId, $sortOrder, $active, $id]);

            // Update display name translation if provided
            $displayName = $_POST['display_name'] ?? '';
            $nameKey = $_POST['name_key'] ?? '';
            if ($displayName && $nameKey) {
                $locale = getAppLocale();
                $stmtT = $db->prepare("INSERT OR REPLACE INTO translations (locale, \"key\", value) VALUES (?, ?, ?)");
                $stmtT->execute([$locale, $nameKey, $displayName]);
            }
        }
        header('Location: ?page=manage-foods&msg=saved');
        exit;
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            // Check if food has log entries
            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM food_log WHERE food_id = ?");
            $stmt->execute([$id]);
            $count = $stmt->fetch()['cnt'];
            if ($count == 0) {
                $db->prepare("DELETE FROM user_favorites WHERE food_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM foods WHERE id = ?")->execute([$id]);
            } else {
                // Just deactivate if it has data
                $db->prepare("UPDATE foods SET active = 0 WHERE id = ?")->execute([$id]);
            }
        }
        header('Location: ?page=manage-foods&msg=saved');
        exit;
    } elseif ($action === 'create_category') {
        $nameKey = trim($_POST['name_key'] ?? '');
        $sortOrder = (int) ($_POST['sort_order'] ?? 99);
        if ($nameKey) {
            if (strpos($nameKey, 'category_') !== 0) {
                $nameKey = 'category_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($nameKey));
            }
            $stmt = $db->prepare("INSERT INTO food_categories (name_key, sort_order) VALUES (?, ?)");
            $stmt->execute([$nameKey, $sortOrder]);

            $displayName = $_POST['display_name'] ?? '';
            if ($displayName) {
                $locale = getAppLocale();
                $stmtT = $db->prepare("INSERT OR REPLACE INTO translations (locale, \"key\", value) VALUES (?, ?, ?)");
                $stmtT->execute([$locale, $nameKey, $displayName]);
            }
        }
        header('Location: ?page=manage-foods&msg=saved');
        exit;
    } elseif ($action === 'update_meal_categories') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $mealIds = $_POST['meal_ids'] ?? [];
        if ($categoryId) {
            // Remove all existing associations for this category
            $db->prepare("DELETE FROM meal_categories WHERE category_id = ?")->execute([$categoryId]);
            // Add selected ones
            $stmt = $db->prepare("INSERT INTO meal_categories (meal_id, category_id) VALUES (?, ?)");
            foreach ($mealIds as $mealId) {
                $stmt->execute([(int) $mealId, $categoryId]);
            }
        }
        header('Location: ?page=manage-foods&msg=saved');
        exit;
    }
}

if (isset($_GET['msg'])) {
    $message = t('changes_saved');
}

// Reload foods after any changes
$stmt = $db->query("SELECT f.*, fc.name_key as category_name FROM foods f JOIN food_categories fc ON f.category_id = fc.id ORDER BY fc.sort_order, f.sort_order");
$foods = $stmt->fetchAll();

// Reload categories
$stmt = $db->query("SELECT * FROM food_categories ORDER BY sort_order");
$categories = $stmt->fetchAll();

// Get meal-category associations
$mealCats = [];
$stmt = $db->query("SELECT * FROM meal_categories");
foreach ($stmt->fetchAll() as $mc) {
    $mealCats[$mc['category_id']][] = $mc['meal_id'];
}

// Edit mode
$editFood = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM foods WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editFood = $stmt->fetch();
}

ob_start();
?>

<div class="guardian-interface">
    <?php include 'nav.php'; ?>

    <main class="container">
        <h1><?php echo t('manage_foods'); ?></h1>

        <?php if ($message): ?>
        <div class="alert alert-success">
            ✅ <?php echo $message; ?>
        </div>
        <?php endif; ?>

        <!-- Add/Edit Food Form -->
        <section class="management-section">
            <h2><?php echo $editFood ? '✏️ ' . t('edit') : '➕ ' . t('add_new'); ?> <?php echo t('food'); ?></h2>
            <form method="POST">
                <input type="hidden" name="action" value="<?php echo $editFood ? 'update' : 'create'; ?>">
                <?php if ($editFood): ?>
                <input type="hidden" name="id" value="<?php echo $editFood['id']; ?>">
                <input type="hidden" name="name_key" value="<?php echo $editFood['name_key']; ?>">
                <?php endif; ?>

                <div class="form-grid">
                    <?php if (!$editFood): ?>
                    <label>
                        <?php echo t('name'); ?> (chave i18n)
                        <input type="text" name="name_key" required placeholder="food_soup" pattern="[a-z0-9_]+">
                        <small>Apenas letras minúsculas, números e _</small>
                    </label>
                    <?php endif; ?>

                    <label>
                        <?php echo t('name'); ?> (<?php echo getAppLocale(); ?>)
                        <input type="text" name="display_name" value="<?php echo $editFood ? sanitize(t($editFood['name_key'])) : ''; ?>" required placeholder="Sopa">
                    </label>

                    <label>
                        <?php echo t('emoji'); ?>
                        <input type="text" name="emoji" value="<?php echo $editFood ? $editFood['emoji'] : '🍽️'; ?>" maxlength="4" required style="font-size:1.5rem;width:4rem;">
                    </label>

                    <label>
                        <?php echo t('category'); ?>
                        <select name="category_id" required>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo ($editFood && $editFood['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                <?php echo t($cat['name_key']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        Ordem
                        <input type="number" name="sort_order" value="<?php echo $editFood ? $editFood['sort_order'] : 999; ?>" min="1" max="999">
                    </label>

                    <?php if ($editFood): ?>
                    <label>
                        <?php echo t('active'); ?>
                        <select name="active">
                            <option value="1" <?php echo $editFood['active'] == 1 ? 'selected' : ''; ?>><?php echo t('active'); ?></option>
                            <option value="0" <?php echo $editFood['active'] == 0 ? 'selected' : ''; ?>><?php echo t('inactive'); ?></option>
                        </select>
                    </label>
                    <?php endif; ?>
                </div>

                <div style="display:flex;gap:1rem;">
                    <button type="submit" class="btn-primary"><?php echo t('save'); ?></button>
                    <?php if ($editFood): ?>
                    <a href="?page=manage-foods" class="btn-secondary"><?php echo t('cancel'); ?></a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <!-- Food List -->
        <section class="management-section">
            <h2>🍎 <?php echo t('manage_foods'); ?> (<?php echo count($foods); ?>)</h2>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo t('emoji'); ?></th>
                            <th><?php echo t('food_name'); ?></th>
                            <th><?php echo t('category'); ?></th>
                            <th><?php echo t('active'); ?></th>
                            <th><?php echo t('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($foods as $food): ?>
                        <tr<?php echo !$food['active'] ? ' style="opacity:0.5;"' : ''; ?>>
                            <td style="font-size:1.5rem;"><?php echo $food['emoji']; ?></td>
                            <td><?php echo t($food['name_key']); ?></td>
                            <td><?php echo t($food['category_name']); ?></td>
                            <td><?php echo $food['active'] ? '✅' : '❌'; ?></td>
                            <td style="white-space:nowrap;">
                                <a href="?page=manage-foods&edit=<?php echo $food['id']; ?>" class="btn-small">✏️</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo t('delete_confirmation'); ?>')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $food['id']; ?>">
                                    <button type="submit" class="btn-small btn-danger">🗑️</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Category Management -->
        <section class="management-section">
            <h2>📁 Categorias</h2>

            <!-- Add Category -->
            <details style="margin-bottom:1rem;">
                <summary>➕ <?php echo t('add_new'); ?> Categoria</summary>
                <form method="POST" style="margin-top:1rem;">
                    <input type="hidden" name="action" value="create_category">
                    <div class="form-grid">
                        <label>
                            Chave i18n
                            <input type="text" name="name_key" required placeholder="category_soups" pattern="[a-z0-9_]+">
                        </label>
                        <label>
                            <?php echo t('name'); ?> (<?php echo getAppLocale(); ?>)
                            <input type="text" name="display_name" required placeholder="Sopas">
                        </label>
                        <label>
                            Ordem
                            <input type="number" name="sort_order" value="99" min="1" max="99">
                        </label>
                    </div>
                    <button type="submit" class="btn-primary"><?php echo t('save'); ?></button>
                </form>
            </details>

            <!-- Category-Meal Associations -->
            <h3>🔗 Categorias ↔ Refeições</h3>
            <p style="opacity:0.7;font-size:0.875rem;margin-bottom:1rem;">Escolha em que refeições cada categoria de alimento aparece.</p>
            <?php foreach ($categories as $cat): ?>
            <details style="margin-bottom:0.5rem;">
                <summary><?php echo t($cat['name_key']); ?></summary>
                <form method="POST" style="margin-top:0.5rem;padding-left:1rem;">
                    <input type="hidden" name="action" value="update_meal_categories">
                    <input type="hidden" name="category_id" value="<?php echo $cat['id']; ?>">
                    <?php foreach ($allMeals as $meal): ?>
                    <label style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.25rem;">
                        <input type="checkbox" name="meal_ids[]" value="<?php echo $meal['id']; ?>"
                            <?php echo in_array($meal['id'], $mealCats[$cat['id']] ?? []) ? 'checked' : ''; ?>>
                        <?php echo t($meal['name_key']); ?>
                    </label>
                    <?php endforeach; ?>
                    <button type="submit" class="btn-small" style="margin-top:0.5rem;"><?php echo t('save'); ?></button>
                </form>
            </details>
            <?php endforeach; ?>
        </section>
    </main>
</div>

<?php
$content = ob_get_clean();
renderLayout(t('manage_foods'), $content);
?>
