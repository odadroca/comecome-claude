<?php
/**
 * Guardian - Manage Users (Guardians + Children)
 */

requireGuardian();
$user = getCurrentUser();
$db = getDB();
$message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = $_POST['name'] ?? '';
        $pin = $_POST['pin'] ?? '';
        $avatar = $_POST['avatar'] ?? '😊';
        $type = $_POST['type'] ?? 'child';

        if ($name && $pin && in_array($type, ['child', 'guardian'])) {
            createUser($name, $type, $pin, $avatar);
        }
        header('Location: ?page=manage-users&msg=saved');
        exit;
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = $_POST['name'] ?? '';
        $pin = $_POST['pin'] ?? '';
        $avatar = $_POST['avatar'] ?? '😊';
        $active = (int) ($_POST['active'] ?? 1);
        $targetUser = getUserById($id);

        if ($id && $name && $targetUser) {
            updateUser($id, $name, $targetUser['type'], $pin ?: null, $avatar, $active);
        }
        header('Location: ?page=manage-users&msg=saved');
        exit;
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id && $id !== $user['id'] && !userHasData($id)) {
            deleteUser($id);
        }
        header('Location: ?page=manage-users&msg=saved');
        exit;
    } elseif ($action === 'update_self') {
        $name = $_POST['name'] ?? '';
        $pin = $_POST['pin'] ?? '';
        $currentPin = $_POST['current_pin'] ?? '';
        $avatar = $_POST['avatar'] ?? $user['avatar_emoji'];

        if ($name && $currentPin && password_verify($currentPin, $user['pin'])) {
            updateUser($user['id'], $name, 'guardian', $pin ?: null, $avatar, 1);
            $_SESSION['user_name'] = $name;
            $message = t('changes_saved');
        } else {
            $message = t('login_error');
        }
        header('Location: ?page=manage-users&msg=' . ($message === t('login_error') ? 'pin_error' : 'saved'));
        exit;
    }
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'pin_error') {
        $message = t('login_error');
    } else {
        $message = t('changes_saved');
    }
}

// Reload current user (may have been updated)
$user = getCurrentUser();

// Get all users
$guardians = getAllUsers('guardian');
$children = getAllUsers('child');

// Edit mode
$editUser = null;
if (isset($_GET['edit'])) {
    $editUser = getUserById($_GET['edit']);
}

ob_start();
?>

<div class="guardian-interface">
    <?php include 'nav.php'; ?>

    <main class="container">
        <h1><?php echo t('manage_users'); ?></h1>

        <?php if ($message): ?>
        <div class="alert <?php echo $message === t('login_error') ? 'alert-error' : 'alert-success'; ?>">
            <?php echo $message === t('login_error') ? '❌' : '✅'; ?> <?php echo $message; ?>
        </div>
        <?php endif; ?>

        <!-- Guardian Self-Edit Section -->
        <section class="management-section">
            <h2>🔑 <?php echo t('my_account'); ?></h2>
            <form method="POST">
                <input type="hidden" name="action" value="update_self">
                <div class="form-grid">
                    <label>
                        <?php echo t('name'); ?>
                        <input type="text" name="name" value="<?php echo sanitize($user['name']); ?>" required>
                    </label>
                    <label>
                        <?php echo t('avatar'); ?>
                        <input type="text" name="avatar" value="<?php echo $user['avatar_emoji']; ?>" maxlength="2" required>
                    </label>
                    <label>
                        <?php echo t('current_pin'); ?>
                        <input type="password" name="current_pin" pattern="[0-9]{4}" maxlength="4" placeholder="••••" required>
                    </label>
                    <label>
                        <?php echo t('new_pin'); ?>
                        <input type="password" name="pin" pattern="[0-9]{4}" maxlength="4" placeholder="••••">
                        <small><?php echo t('leave_empty_keep_current'); ?></small>
                    </label>
                </div>
                <button type="submit" class="btn-primary"><?php echo t('save'); ?></button>
            </form>
        </section>

        <!-- Add/Edit User Form -->
        <section class="management-section">
            <h2><?php echo $editUser ? '✏️ ' . t('edit') : '➕ ' . t('add_new'); ?></h2>
            <form method="POST">
                <input type="hidden" name="action" value="<?php echo $editUser ? 'update' : 'create'; ?>">
                <?php if ($editUser): ?>
                <input type="hidden" name="id" value="<?php echo $editUser['id']; ?>">
                <?php endif; ?>

                <div class="form-grid">
                    <label>
                        <?php echo t('name'); ?>
                        <input type="text" name="name" value="<?php echo $editUser ? sanitize($editUser['name']) : ''; ?>" required>
                    </label>

                    <label>
                        <?php echo t('pin'); ?> (4 <?php echo t('digits'); ?>)
                        <input type="password" name="pin" pattern="[0-9]{4}" maxlength="4" placeholder="<?php echo $editUser ? '••••' : '0000'; ?>" <?php echo $editUser ? '' : 'required'; ?>>
                        <?php if ($editUser): ?>
                        <small><?php echo t('leave_empty_keep_current'); ?></small>
                        <?php endif; ?>
                    </label>

                    <label>
                        <?php echo t('avatar'); ?>
                        <input type="text" name="avatar" value="<?php echo $editUser ? $editUser['avatar_emoji'] : '😊'; ?>" maxlength="2" required>
                    </label>

                    <?php if (!$editUser): ?>
                    <label>
                        <?php echo t('type'); ?>
                        <select name="type">
                            <option value="child"><?php echo t('child'); ?></option>
                            <option value="guardian"><?php echo t('guardian'); ?></option>
                        </select>
                    </label>
                    <?php endif; ?>

                    <?php if ($editUser): ?>
                    <label>
                        <?php echo t('active'); ?>
                        <select name="active">
                            <option value="1" <?php echo $editUser['active'] == 1 ? 'selected' : ''; ?>><?php echo t('active'); ?></option>
                            <option value="0" <?php echo $editUser['active'] == 0 ? 'selected' : ''; ?>><?php echo t('inactive'); ?></option>
                        </select>
                    </label>
                    <?php endif; ?>
                </div>

                <div style="display:flex;gap:1rem;">
                    <button type="submit" class="btn-primary"><?php echo t('save'); ?></button>
                    <?php if ($editUser): ?>
                    <a href="?page=manage-users" class="btn-secondary"><?php echo t('cancel'); ?></a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <!-- Guardians List -->
        <section class="management-section">
            <h2>🛡️ <?php echo t('guardians'); ?> (<?php echo count($guardians); ?>)</h2>
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
                        <?php foreach ($guardians as $g): ?>
                        <tr>
                            <td style="font-size:1.5rem;"><?php echo $g['avatar_emoji']; ?></td>
                            <td>
                                <?php echo sanitize($g['name']); ?>
                                <?php if ($g['id'] == $user['id']): ?>
                                <small style="opacity:0.6;">(<?php echo t('you'); ?>)</small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $g['active'] ? t('active') : t('inactive'); ?></td>
                            <td style="white-space:nowrap;">
                                <a href="?page=manage-users&edit=<?php echo $g['id']; ?>" class="btn-small">✏️</a>
                                <?php if ($g['id'] != $user['id'] && !userHasData($g['id'])): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo t('delete_confirmation'); ?>')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $g['id']; ?>">
                                    <button type="submit" class="btn-small btn-danger">🗑️</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Children List -->
        <section class="management-section">
            <h2>👶 <?php echo t('children'); ?> (<?php echo count($children); ?>)</h2>
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
                            <td style="white-space:nowrap;">
                                <a href="?page=manage-users&edit=<?php echo $child['id']; ?>" class="btn-small">✏️</a>
                                <?php if (!userHasData($child['id'])): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo t('delete_confirmation'); ?>')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $child['id']; ?>">
                                    <button type="submit" class="btn-small btn-danger">🗑️</button>
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
renderLayout(t('manage_users'), $content);
?>
