<?php
/**
 * Guardian - Manage Meals (Full CRUD)
 */

requireGuardian();
$user = getCurrentUser();
$db = getDB();
$message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sprint security — every state-changing action requires a valid CSRF
    // token; a forged cross-site POST lacks it and is bounced before any DB write.
    if (function_exists('verifyCsrf') && !verifyCsrf()) {
        header('Location: ?page=manage-meals&msg=csrf_error');
        exit;
    }
    $action = $_POST['action'] ?? '';

    // A duplicate i18n key (UNIQUE name_key) or any other DB error must not
    // surface as a raw 500 — catch it and redirect with a friendly message.
    try {
    if ($action === 'create') {
        $nameKey = trim($_POST['name_key'] ?? '');          // optional "Advanced" override
        $displayName = trim($_POST['display_name'] ?? '');
        $timeStart = $_POST['time_start'] ?? '';
        $timeEnd = $_POST['time_end'] ?? '';
        $sortOrder = (int) ($_POST['sort_order'] ?? 99);

        // Derive the i18n key from the display name; an Advanced key still wins.
        if ($nameKey === '') {
            $nameKey = slugifyTranslationKey('meal_', $displayName);
        } elseif (strpos($nameKey, 'meal_') !== 0) {
            $nameKey = slugifyTranslationKey('meal_', $nameKey);
        }

        if ($displayName && $timeStart && $timeEnd) {
            $stmt = $db->prepare("INSERT INTO meals (name_key, sort_order, time_start, time_end) VALUES (?, ?, ?, ?)");
            $stmt->execute([$nameKey, $sortOrder, $timeStart, $timeEnd]);

            $locale = getAppLocale();
            $stmtT = $db->prepare("INSERT OR REPLACE INTO translations (locale, \"key\", value) VALUES (?, ?, ?)");
            $stmtT->execute([$locale, $nameKey, $displayName]);
        }
        header('Location: ?page=manage-meals&msg=saved');
        exit;
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $timeStart = $_POST['time_start'] ?? '';
        $timeEnd = $_POST['time_end'] ?? '';
        $sortOrder = (int) ($_POST['sort_order'] ?? 99);
        $active = (int) ($_POST['active'] ?? 1);

        if ($id && $timeStart && $timeEnd) {
            $stmt = $db->prepare("UPDATE meals SET time_start = ?, time_end = ?, sort_order = ?, active = ? WHERE id = ?");
            $stmt->execute([$timeStart, $timeEnd, $sortOrder, $active, $id]);

            $displayName = $_POST['display_name'] ?? '';
            $nameKey = $_POST['name_key'] ?? '';
            if ($displayName && $nameKey) {
                $locale = getAppLocale();
                $stmtT = $db->prepare("INSERT OR REPLACE INTO translations (locale, \"key\", value) VALUES (?, ?, ?)");
                $stmtT->execute([$locale, $nameKey, $displayName]);
            }
        }
        header('Location: ?page=manage-meals&msg=saved');
        exit;
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            // Check if meal has log entries
            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM food_log WHERE meal_id = ?");
            $stmt->execute([$id]);
            $count = $stmt->fetch()['cnt'];
            if ($count == 0) {
                $db->prepare("DELETE FROM meal_categories WHERE meal_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM meals WHERE id = ?")->execute([$id]);
            } else {
                $db->prepare("UPDATE meals SET active = 0 WHERE id = ?")->execute([$id]);
            }
        }
        header('Location: ?page=manage-meals&msg=saved');
        exit;
    }
    } catch (PDOException $e) {
        error_log('manage-meals POST failed: ' . $e->getMessage());
        $code = (stripos($e->getMessage(), 'UNIQUE constraint') !== false) ? 'duplicate' : 'error';
        header('Location: ?page=manage-meals&msg=' . $code);
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

// Get meals
$stmt = $db->query("SELECT * FROM meals ORDER BY sort_order");
$meals = $stmt->fetchAll();

// Edit mode
$editMeal = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM meals WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editMeal = $stmt->fetch();
}

ob_start();
?>

<div class="guardian-interface">
    <?php include 'nav.php'; ?>

    <main class="container">
        <h1><?php echo t('manage_meals'); ?></h1>

        <?php if ($message): ?>
        <div class="alert <?php echo $isErrorMsg ? 'alert-error' : 'alert-success'; ?>">
            <?php echo $isErrorMsg ? '❌' : '✅'; ?> <?php echo $message; ?>
        </div>
        <?php endif; ?>

        <!-- Add/Edit Meal Form -->
        <section class="management-section">
            <h2><?php echo $editMeal ? '✏️ ' . t('edit') : '➕ ' . t('add_new'); ?></h2>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="<?php echo $editMeal ? 'update' : 'create'; ?>">
                <?php if ($editMeal): ?>
                <input type="hidden" name="id" value="<?php echo $editMeal['id']; ?>">
                <input type="hidden" name="name_key" value="<?php echo $editMeal['name_key']; ?>">
                <?php endif; ?>

                <div class="form-grid">
                    <label>
                        <?php echo t('name'); ?> (<?php echo getAppLocale(); ?>)
                        <input type="text" name="display_name"
                               value="<?php echo $editMeal ? sanitize(t($editMeal['name_key'])) : ''; ?>"
                               required placeholder="<?php echo $editMeal ? '' : 'Brunch'; ?>"
                               <?php if (!$editMeal): ?>data-slug-prefix="meal_" data-slug-target="#mealSlugPreview" data-slug-override="#mealKeyOverride"<?php endif; ?>>
                        <?php if (!$editMeal): ?>
                        <small class="slug-preview"><?php echo t('saved_as'); ?> <code id="mealSlugPreview">meal_…</code></small>
                        <?php endif; ?>
                    </label>

                    <label>
                        Início
                        <input type="time" name="time_start" value="<?php echo $editMeal ? $editMeal['time_start'] : ''; ?>" required>
                    </label>

                    <label>
                        Fim
                        <input type="time" name="time_end" value="<?php echo $editMeal ? $editMeal['time_end'] : ''; ?>" required>
                    </label>

                    <label>
                        Ordem
                        <input type="number" name="sort_order" value="<?php echo $editMeal ? $editMeal['sort_order'] : 99; ?>" min="1" max="99">
                    </label>

                    <?php if ($editMeal): ?>
                    <label>
                        <?php echo t('active'); ?>
                        <select name="active">
                            <option value="1" <?php echo $editMeal['active'] == 1 ? 'selected' : ''; ?>><?php echo t('active'); ?></option>
                            <option value="0" <?php echo $editMeal['active'] == 0 ? 'selected' : ''; ?>><?php echo t('inactive'); ?></option>
                        </select>
                    </label>
                    <?php endif; ?>
                </div>

                <?php if (!$editMeal): ?>
                <details class="advanced-key">
                    <summary><?php echo t('advanced'); ?></summary>
                    <label>
                        <?php echo t('i18n_key'); ?>
                        <input type="text" name="name_key" id="mealKeyOverride" placeholder="meal_brunch" pattern="[a-z0-9_]+">
                        <small><?php echo t('i18n_key_hint'); ?></small>
                    </label>
                </details>
                <?php endif; ?>

                <div style="display:flex;gap:1rem;">
                    <button type="submit" class="btn-primary"><?php echo t('save'); ?></button>
                    <?php if ($editMeal): ?>
                    <a href="?page=manage-meals" class="btn-secondary"><?php echo t('cancel'); ?></a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <!-- Meals List -->
        <section class="management-section">
            <h2>🍽️ <?php echo t('manage_meals'); ?></h2>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo t('meal_name'); ?></th>
                            <th><?php echo t('time_range'); ?></th>
                            <th>Ordem</th>
                            <th><?php echo t('active'); ?></th>
                            <th><?php echo t('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($meals as $meal): ?>
                        <tr<?php echo !$meal['active'] ? ' style="opacity:0.5;"' : ''; ?>>
                            <td><?php echo t($meal['name_key']); ?></td>
                            <td><?php echo $meal['time_start'] . ' - ' . $meal['time_end']; ?></td>
                            <td><?php echo $meal['sort_order']; ?></td>
                            <td><?php echo $meal['active'] ? '✅' : '❌'; ?></td>
                            <td style="white-space:nowrap;">
                                <a href="?page=manage-meals&edit=<?php echo $meal['id']; ?>" class="btn-small">✏️</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo t('delete_confirmation'); ?>')">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $meal['id']; ?>">
                                    <button type="submit" class="btn-small btn-danger">🗑️</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<script>
// Live "será guardado como meal_x" preview — mirrors slugifyTranslationKey() in
// includes/helpers.php. An Advanced override, if filled, wins.
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
renderLayout(t('manage_meals'), $content);
?>
