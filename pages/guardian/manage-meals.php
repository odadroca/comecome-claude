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
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $nameKey = trim($_POST['name_key'] ?? '');
        $timeStart = $_POST['time_start'] ?? '';
        $timeEnd = $_POST['time_end'] ?? '';
        $sortOrder = (int) ($_POST['sort_order'] ?? 99);

        if ($nameKey && $timeStart && $timeEnd) {
            if (strpos($nameKey, 'meal_') !== 0) {
                $nameKey = 'meal_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($nameKey));
            }
            $stmt = $db->prepare("INSERT INTO meals (name_key, sort_order, time_start, time_end) VALUES (?, ?, ?, ?)");
            $stmt->execute([$nameKey, $sortOrder, $timeStart, $timeEnd]);

            $displayName = $_POST['display_name'] ?? '';
            if ($displayName) {
                $locale = getAppLocale();
                $stmtT = $db->prepare("INSERT OR REPLACE INTO translations (locale, \"key\", value) VALUES (?, ?, ?)");
                $stmtT->execute([$locale, $nameKey, $displayName]);
            }
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
}

if (isset($_GET['msg'])) {
    $message = t('changes_saved');
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
        <div class="alert alert-success">
            ✅ <?php echo $message; ?>
        </div>
        <?php endif; ?>

        <!-- Add/Edit Meal Form -->
        <section class="management-section">
            <h2><?php echo $editMeal ? '✏️ ' . t('edit') : '➕ ' . t('add_new'); ?></h2>
            <form method="POST">
                <input type="hidden" name="action" value="<?php echo $editMeal ? 'update' : 'create'; ?>">
                <?php if ($editMeal): ?>
                <input type="hidden" name="id" value="<?php echo $editMeal['id']; ?>">
                <input type="hidden" name="name_key" value="<?php echo $editMeal['name_key']; ?>">
                <?php endif; ?>

                <div class="form-grid">
                    <?php if (!$editMeal): ?>
                    <label>
                        <?php echo t('name'); ?> (chave i18n)
                        <input type="text" name="name_key" required placeholder="meal_brunch" pattern="[a-z0-9_]+">
                        <small>Apenas letras minúsculas, números e _</small>
                    </label>
                    <?php endif; ?>

                    <label>
                        <?php echo t('name'); ?> (<?php echo getAppLocale(); ?>)
                        <input type="text" name="display_name" value="<?php echo $editMeal ? sanitize(t($editMeal['name_key'])) : ''; ?>" required placeholder="Brunch">
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

<?php
$content = ob_get_clean();
renderLayout(t('manage_meals'), $content);
?>
