<?php
/**
 * Guardian - Manage Medications (Full CRUD + child assignment)
 */

requireGuardian();
$user = getCurrentUser();
$db = getDB();
$message = '';

// Get all children
$children = getAllUsers('child');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $dose = trim($_POST['dose'] ?? '');
        if ($name) {
            $stmt = $db->prepare("INSERT INTO medications (name, dose) VALUES (?, ?)");
            $stmt->execute([$name, $dose]);
        }
        header('Location: ?page=manage-medications&msg=saved');
        exit;
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $dose = trim($_POST['dose'] ?? '');
        $active = (int) ($_POST['active'] ?? 1);
        if ($id && $name) {
            $stmt = $db->prepare("UPDATE medications SET name = ?, dose = ?, active = ? WHERE id = ?");
            $stmt->execute([$name, $dose, $active, $id]);
        }
        header('Location: ?page=manage-medications&msg=saved');
        exit;
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            // Check if medication is referenced
            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM user_medications WHERE medication_id = ?");
            $stmt->execute([$id]);
            $count = $stmt->fetch()['cnt'];
            if ($count == 0) {
                $db->prepare("DELETE FROM medications WHERE id = ?")->execute([$id]);
            } else {
                $db->prepare("UPDATE medications SET active = 0 WHERE id = ?")->execute([$id]);
            }
        }
        header('Location: ?page=manage-medications&msg=saved');
        exit;
    } elseif ($action === 'assign') {
        $medId = (int) ($_POST['medication_id'] ?? 0);
        $childIds = $_POST['child_ids'] ?? [];
        if ($medId) {
            $db->prepare("DELETE FROM user_medications WHERE medication_id = ?")->execute([$medId]);
            $stmt = $db->prepare("INSERT INTO user_medications (user_id, medication_id) VALUES (?, ?)");
            foreach ($childIds as $childId) {
                $stmt->execute([(int) $childId, $medId]);
            }
        }
        header('Location: ?page=manage-medications&msg=saved');
        exit;
    }
}

if (isset($_GET['msg'])) {
    $message = t('changes_saved');
}

// Get all medications (including inactive)
$stmt = $db->query("SELECT * FROM medications ORDER BY active DESC, name");
$medications = $stmt->fetchAll();

// Get medication-child assignments
$medAssignments = [];
$stmt = $db->query("SELECT * FROM user_medications");
foreach ($stmt->fetchAll() as $ma) {
    $medAssignments[$ma['medication_id']][] = $ma['user_id'];
}

// Edit mode
$editMed = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM medications WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editMed = $stmt->fetch();
}

ob_start();
?>

<div class="guardian-interface">
    <?php include 'nav.php'; ?>

    <main class="container">
        <h1><?php echo t('manage_medications'); ?></h1>

        <?php if ($message): ?>
        <div class="alert alert-success">
            ✅ <?php echo $message; ?>
        </div>
        <?php endif; ?>

        <!-- Add/Edit Medication Form -->
        <section class="management-section">
            <h2><?php echo $editMed ? '✏️ ' . t('edit') : '➕ ' . t('add_new'); ?></h2>
            <form method="POST">
                <input type="hidden" name="action" value="<?php echo $editMed ? 'update' : 'create'; ?>">
                <?php if ($editMed): ?>
                <input type="hidden" name="id" value="<?php echo $editMed['id']; ?>">
                <?php endif; ?>

                <div class="form-grid">
                    <label>
                        <?php echo t('medication_name'); ?>
                        <input type="text" name="name" value="<?php echo $editMed ? sanitize($editMed['name']) : ''; ?>" required placeholder="Ritalina">
                    </label>
                    <label>
                        <?php echo t('dose'); ?>
                        <input type="text" name="dose" value="<?php echo $editMed ? sanitize($editMed['dose']) : ''; ?>" placeholder="20mg">
                    </label>
                    <?php if ($editMed): ?>
                    <label>
                        <?php echo t('active'); ?>
                        <select name="active">
                            <option value="1" <?php echo $editMed['active'] == 1 ? 'selected' : ''; ?>><?php echo t('active'); ?></option>
                            <option value="0" <?php echo $editMed['active'] == 0 ? 'selected' : ''; ?>><?php echo t('inactive'); ?></option>
                        </select>
                    </label>
                    <?php endif; ?>
                </div>

                <div style="display:flex;gap:1rem;">
                    <button type="submit" class="btn-primary"><?php echo t('save'); ?></button>
                    <?php if ($editMed): ?>
                    <a href="?page=manage-medications" class="btn-secondary"><?php echo t('cancel'); ?></a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <!-- Medications List -->
        <section class="management-section">
            <h2>💊 <?php echo t('manage_medications'); ?></h2>
            <?php if (empty($medications)): ?>
                <p style="opacity:0.6;">Nenhum medicamento registado.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo t('medication_name'); ?></th>
                            <th><?php echo t('dose'); ?></th>
                            <th><?php echo t('active'); ?></th>
                            <th><?php echo t('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($medications as $med): ?>
                        <tr<?php echo !$med['active'] ? ' style="opacity:0.5;"' : ''; ?>>
                            <td><?php echo sanitize($med['name']); ?></td>
                            <td><?php echo sanitize($med['dose']); ?></td>
                            <td><?php echo $med['active'] ? '✅' : '❌'; ?></td>
                            <td style="white-space:nowrap;">
                                <a href="?page=manage-medications&edit=<?php echo $med['id']; ?>" class="btn-small">✏️</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo t('delete_confirmation'); ?>')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $med['id']; ?>">
                                    <button type="submit" class="btn-small btn-danger">🗑️</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>

        <!-- Medication-Child Assignments -->
        <?php if (!empty($medications) && !empty($children)): ?>
        <section class="management-section">
            <h2>👶 ↔ 💊 Atribuir Medicamentos</h2>
            <p style="opacity:0.7;font-size:0.875rem;margin-bottom:1rem;">Escolha que crianças tomam cada medicamento.</p>
            <?php foreach ($medications as $med): ?>
            <?php if (!$med['active']) continue; ?>
            <details style="margin-bottom:0.5rem;">
                <summary><?php echo sanitize($med['name']); ?> (<?php echo sanitize($med['dose']); ?>)</summary>
                <form method="POST" style="margin-top:0.5rem;padding-left:1rem;">
                    <input type="hidden" name="action" value="assign">
                    <input type="hidden" name="medication_id" value="<?php echo $med['id']; ?>">
                    <?php foreach ($children as $child): ?>
                    <label style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.25rem;">
                        <input type="checkbox" name="child_ids[]" value="<?php echo $child['id']; ?>"
                            <?php echo in_array($child['id'], $medAssignments[$med['id']] ?? []) ? 'checked' : ''; ?>>
                        <?php echo $child['avatar_emoji'] . ' ' . sanitize($child['name']); ?>
                    </label>
                    <?php endforeach; ?>
                    <button type="submit" class="btn-small" style="margin-top:0.5rem;"><?php echo t('save'); ?></button>
                </form>
            </details>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>
    </main>
</div>

<?php
$content = ob_get_clean();
renderLayout(t('manage_medications'), $content);
?>
