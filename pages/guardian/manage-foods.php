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
    // Sprint security — every state-changing action requires a valid CSRF
    // token; a forged cross-site POST lacks it and is bounced before any DB write.
    if (function_exists('verifyCsrf') && !verifyCsrf()) {
        header('Location: ?page=manage-foods&msg=csrf_error');
        exit;
    }
    $action = $_POST['action'] ?? '';

    // A duplicate i18n key (UNIQUE name_key) or any other DB error must not
    // surface as a raw 500 — catch it and redirect with a friendly message.
    try {
    if ($action === 'create') {
        $nameKey = trim($_POST['name_key'] ?? '');          // optional "Advanced" override
        $displayName = trim($_POST['display_name'] ?? '');
        $emoji = trim($_POST['emoji'] ?? '🍽️');
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $sortOrder = (int) ($_POST['sort_order'] ?? 999);

        // The guardian no longer hand-authors the i18n key: derive it from the
        // display name. An explicit Advanced key still wins (normalised to food_).
        if ($nameKey === '') {
            $nameKey = slugifyTranslationKey('food_', $displayName);
        } elseif (strpos($nameKey, 'food_') !== 0) {
            $nameKey = slugifyTranslationKey('food_', $nameKey);
        }

        if ($displayName && $categoryId) {
            $stmt = $db->prepare("INSERT INTO foods (name_key, emoji, category_id, sort_order) VALUES (?, ?, ?, ?)");
            $stmt->execute([$nameKey, $emoji, $categoryId, $sortOrder]);
            // Store the guardian-entered display name as this key's translation.
            $locale = getAppLocale();
            $stmtT = $db->prepare("INSERT OR REPLACE INTO translations (locale, \"key\", value) VALUES (?, ?, ?)");
            $stmtT->execute([$locale, $nameKey, $displayName]);
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
        $nameKey = trim($_POST['name_key'] ?? '');          // optional "Advanced" override
        $displayName = trim($_POST['display_name'] ?? '');
        $sortOrder = (int) ($_POST['sort_order'] ?? 99);
        if ($nameKey === '') {
            $nameKey = slugifyTranslationKey('category_', $displayName);
        } elseif (strpos($nameKey, 'category_') !== 0) {
            $nameKey = slugifyTranslationKey('category_', $nameKey);
        }
        if ($displayName) {
            $stmt = $db->prepare("INSERT INTO food_categories (name_key, sort_order) VALUES (?, ?)");
            $stmt->execute([$nameKey, $sortOrder]);

            $locale = getAppLocale();
            $stmtT = $db->prepare("INSERT OR REPLACE INTO translations (locale, \"key\", value) VALUES (?, ?, ?)");
            $stmtT->execute([$locale, $nameKey, $displayName]);
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
    } catch (PDOException $e) {
        error_log('manage-foods POST failed: ' . $e->getMessage());
        $code = (stripos($e->getMessage(), 'UNIQUE constraint') !== false) ? 'duplicate' : 'error';
        header('Location: ?page=manage-foods&msg=' . $code);
        exit;
    }
}

$isErrorMsg = false;
if (isset($_GET['msg'])) {
    // Map the redirect code to a message. csrf_error / duplicate / error are all
    // failures (red); anything else is the green "saved" confirmation.
    switch ($_GET['msg']) {
        case 'csrf_error': $message = t('error_invalid_request'); $isErrorMsg = true; break;
        case 'duplicate':  $message = t('error_already_exists');  $isErrorMsg = true; break;
        case 'error':      $message = t('error_database');        $isErrorMsg = true; break;
        default:           $message = t('changes_saved');
    }
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
        <div class="alert <?php echo $isErrorMsg ? 'alert-error' : 'alert-success'; ?>">
            <?php echo $isErrorMsg ? '❌' : '✅'; ?> <?php echo $message; ?>
        </div>
        <?php endif; ?>

        <!-- Add/Edit Food Form -->
        <section class="management-section">
            <h2><?php echo $editFood ? '✏️ ' . t('edit') : '➕ ' . t('add_new'); ?> <?php echo t('food'); ?></h2>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="<?php echo $editFood ? 'update' : 'create'; ?>">
                <?php if ($editFood): ?>
                <input type="hidden" name="id" value="<?php echo $editFood['id']; ?>">
                <input type="hidden" name="name_key" value="<?php echo $editFood['name_key']; ?>">
                <?php endif; ?>

                <div class="form-grid">
                    <label>
                        <?php echo t('name'); ?> (<?php echo getAppLocale(); ?>)
                        <input type="text" name="display_name"
                               value="<?php echo $editFood ? sanitize(t($editFood['name_key'])) : ''; ?>"
                               required placeholder="<?php echo $editFood ? '' : 'Manga'; ?>"
                               <?php if (!$editFood): ?>data-slug-prefix="food_" data-slug-target="#foodSlugPreview" data-slug-override="#foodKeyOverride"<?php endif; ?>>
                        <?php if (!$editFood): ?>
                        <small class="slug-preview"><?php echo t('saved_as'); ?> <code id="foodSlugPreview">food_…</code></small>
                        <?php endif; ?>
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

                <?php if (!$editFood): ?>
                <details class="advanced-key">
                    <summary><?php echo t('advanced'); ?></summary>
                    <label>
                        <?php echo t('i18n_key'); ?>
                        <input type="text" name="name_key" id="foodKeyOverride" placeholder="food_manga" pattern="[a-z0-9_]+">
                        <small><?php echo t('i18n_key_hint'); ?></small>
                    </label>
                </details>
                <?php endif; ?>

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
                                    <?php echo csrfField(); ?>
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
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="create_category">
                    <div class="form-grid">
                        <label>
                            <?php echo t('name'); ?> (<?php echo getAppLocale(); ?>)
                            <input type="text" name="display_name" required placeholder="Sopas"
                                   data-slug-prefix="category_" data-slug-target="#catSlugPreview" data-slug-override="#catKeyOverride">
                            <small class="slug-preview"><?php echo t('saved_as'); ?> <code id="catSlugPreview">category_…</code></small>
                        </label>
                        <label>
                            Ordem
                            <input type="number" name="sort_order" value="99" min="1" max="99">
                        </label>
                    </div>
                    <details class="advanced-key">
                        <summary><?php echo t('advanced'); ?></summary>
                        <label>
                            <?php echo t('i18n_key'); ?>
                            <input type="text" name="name_key" id="catKeyOverride" placeholder="category_sopas" pattern="[a-z0-9_]+">
                            <small><?php echo t('i18n_key_hint'); ?></small>
                        </label>
                    </details>
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
                    <?php echo csrfField(); ?>
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

<script>
// Live "será guardado como food_x" preview — mirrors slugifyTranslationKey() in
// includes/helpers.php (NFD strips accents; non-alphanumerics collapse to _). An
// Advanced override, if filled, wins. Wires every [data-slug-prefix] input on the page.
(function () {
    function slugify(s) {
        s = (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
        s = s.replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
        return s || 'item';
    }
    document.querySelectorAll('[data-slug-prefix]').forEach(function (input) {
        var prefix = input.getAttribute('data-slug-prefix');
        var out = document.querySelector(input.getAttribute('data-slug-target'));
        var ovSel = input.getAttribute('data-slug-override');
        var ov = ovSel ? document.querySelector(ovSel) : null;
        if (!out) return;
        function render() {
            var o = ov && ov.value.trim();
            out.textContent = o ? (o.indexOf(prefix) === 0 ? o : prefix + slugify(o)) : prefix + slugify(input.value);
        }
        input.addEventListener('input', render);
        if (ov) ov.addEventListener('input', render);
        render();
    });
})();
</script>

<?php
$content = ob_get_clean();
renderLayout(t('manage_foods'), $content);
?>
