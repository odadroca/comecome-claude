<?php
/**
 * Guardian - Manage Children
 */

$user = getCurrentUser();
$children = getAllUsers('child');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sprint security — every state-changing action requires a valid CSRF
    // token; a forged cross-site POST lacks it and is bounced before any DB write.
    if (function_exists('verifyCsrf') && !verifyCsrf()) {
        header('Location: ?page=manage-children&msg=csrf_error');
        exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = $_POST['name'] ?? '';
        $pin = $_POST['pin'] ?? '';
        $avatar = $_POST['avatar'] ?? '😊';
        // Sprint 5: optional guardian-entered demographics (decision iii).
        $gender = $_POST['gender'] ?? null;
        $dateOfBirth = $_POST['date_of_birth'] ?? null;

        if ($name && $pin) {
            createUser($name, 'child', $pin, $avatar, $gender, $dateOfBirth);
            header('Location: ?page=manage-children');
            exit;
        }
    } elseif ($action === 'update') {
        $id = $_POST['id'] ?? '';
        $name = $_POST['name'] ?? '';
        $pin = $_POST['pin'] ?? '';
        $avatar = $_POST['avatar'] ?? '😊';
        $active = $_POST['active'] ?? 1;
        // Sprint 5: optional guardian-entered demographics (decision iii).
        // Always passed (blank clears the field) since the form always submits them.
        $gender = $_POST['gender'] ?? '';
        $dateOfBirth = $_POST['date_of_birth'] ?? '';

        if ($id && $name) {
            updateUser($id, $name, 'child', $pin ?: null, $avatar, $active, $gender, $dateOfBirth);
            header('Location: ?page=manage-children');
            exit;
        }
    } elseif ($action === 'delete') {
        $id = $_POST['id'] ?? '';
        if ($id && !userHasData($id)) {
            deleteUser($id);
        }
        header('Location: ?page=manage-children');
        exit;
    }
}

$editChild = null;
if (isset($_GET['edit'])) {
    $editChild = getUserById($_GET['edit']);
}

ob_start();
?>

<div class="guardian-interface">
    <?php include 'nav.php'; ?>

    <main class="container">
        <h1><?php echo t('manage_children'); ?></h1>

        <!-- Add/Edit Form -->
        <section class="management-section">
            <h2><?php echo $editChild ? t('edit') : t('add_new'); ?></h2>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="<?php echo $editChild ? 'update' : 'create'; ?>">
                <?php if ($editChild): ?>
                <input type="hidden" name="id" value="<?php echo $editChild['id']; ?>">
                <?php endif; ?>

                <div class="form-grid">
                    <label>
                        <?php echo t('name'); ?>
                        <input type="text" name="name" value="<?php echo $editChild ? sanitize($editChild['name']) : ''; ?>" required>
                    </label>

                    <label>
                        <?php echo t('pin'); ?> (4 dígitos)
                        <input type="password" name="pin" pattern="[0-9]{4}" maxlength="4" placeholder="<?php echo $editChild ? '••••' : '0000'; ?>" <?php echo $editChild ? '' : 'required'; ?>>
                        <?php if ($editChild): ?>
                        <small><?php echo t('leave_empty_keep_current'); ?></small>
                        <?php endif; ?>
                    </label>

                    <label>
                        <?php echo t('avatar'); ?>
                        <input type="text" name="avatar" value="<?php echo $editChild ? $editChild['avatar_emoji'] : '😊'; ?>" maxlength="2" required>
                    </label>

                    <?php
                    // Sprint 5: optional guardian-entered demographics. OPTIONAL at
                    // this stage (required-enforcement arrives with the Sprint 6
                    // percentiles toggle). Guardian/clinician-side only — these
                    // inputs never appear on any child page (decision iii).
                    $childGender = $editChild['gender'] ?? '';
                    $childDob = $editChild['date_of_birth'] ?? '';
                    ?>
                    <fieldset>
                        <legend><?php echo t('gender'); ?></legend>
                        <label style="display:inline-flex;align-items:center;gap:0.35rem;margin-right:1.25rem;">
                            <input type="radio" name="gender" value="male" <?php echo $childGender === 'male' ? 'checked' : ''; ?>>
                            <?php echo t('gender_male'); ?>
                        </label>
                        <label style="display:inline-flex;align-items:center;gap:0.35rem;">
                            <input type="radio" name="gender" value="female" <?php echo $childGender === 'female' ? 'checked' : ''; ?>>
                            <?php echo t('gender_female'); ?>
                        </label>
                    </fieldset>

                    <label>
                        <?php echo t('date_of_birth'); ?>
                        <input type="date" name="date_of_birth" value="<?php echo sanitize($childDob); ?>" max="<?php echo date('Y-m-d'); ?>">
                        <small><?php echo t('dob_optional_hint'); ?></small>
                    </label>

                    <?php if ($editChild): ?>
                    <label>
                        <?php echo t('active'); ?>
                        <select name="active">
                            <option value="1" <?php echo $editChild['active'] == 1 ? 'selected' : ''; ?>><?php echo t('active'); ?></option>
                            <option value="0" <?php echo $editChild['active'] == 0 ? 'selected' : ''; ?>><?php echo t('inactive'); ?></option>
                        </select>
                    </label>
                    <?php endif; ?>
                </div>

                <div style="display:flex;gap:1rem;">
                    <button type="submit" class="btn-primary">
                        <?php echo t('save'); ?>
                    </button>
                    <?php if ($editChild): ?>
                    <a href="?page=manage-children" class="btn-secondary"><?php echo t('cancel'); ?></a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <!-- Children List -->
        <section class="management-section">
            <h2><?php echo t('manage_children'); ?></h2>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo t('avatar'); ?></th>
                            <th><?php echo t('name'); ?></th>
                            <th><?php echo t('active'); ?></th>
                            <th><?php echo t('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($children as $child): ?>
                        <tr>
                            <td style="font-size:1.5rem;"><?php echo $child['avatar_emoji']; ?></td>
                            <td><?php echo sanitize($child['name']); ?></td>
                            <td><?php echo $child['active'] ? t('active') : t('inactive'); ?></td>
                            <td>
                                <a href="?page=manage-children&edit=<?php echo $child['id']; ?>" class="btn-small">
                                    ✏️ <?php echo t('edit'); ?>
                                </a>
                                <?php if (!userHasData($child['id'])): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo t('delete_confirmation'); ?>')">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $child['id']; ?>">
                                    <button type="submit" class="btn-small btn-danger">
                                        🗑️ <?php echo t('delete'); ?>
                                    </button>
                                </form>
                                <?php else: ?>
                                <span style="opacity:0.5;font-size:0.875rem;"><?php echo t('cannot_delete'); ?></span>
                                <?php endif; ?>
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
renderLayout(t('manage_children'), $content);
?>
