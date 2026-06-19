<?php
/**
 * Guardian - Database Management (backup, restore/upload, reset)
 */

$user = getCurrentUser();
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sprint security Phase 3 — DB backup/download/restore/reset are all destructive
    // or data-exfiltrating state-changing actions, so every POST here requires a
    // valid CSRF token. A forged cross-site POST lacks it and is refused outright.
    if (function_exists('verifyCsrf') && !verifyCsrf()) {
        $message = t('error_invalid_request');
        $messageType = 'error';
        $action = '';
    } else {
    $action = $_POST['action'] ?? '';

    if ($action === 'backup') {
        $backupPath = backupDatabase();
        if ($backupPath) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($backupPath) . '"');
            readfile($backupPath);
            exit;
        }
    } elseif ($action === 'download') {
        // Direct download of current .db file (no backup copy)
        if (file_exists(DB_PATH)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="comecome_' . date('Y-m-d') . '.db"');
            header('Content-Length: ' . filesize(DB_PATH));
            readfile(DB_PATH);
            exit;
        }
    } elseif ($action === 'upload') {
        if (isset($_FILES['dbfile']) && $_FILES['dbfile']['error'] === UPLOAD_ERR_OK) {
            $tmpFile = $_FILES['dbfile']['tmp_name'];
            $origName = $_FILES['dbfile']['name'];

            // Validate it's a SQLite database
            $header = file_get_contents($tmpFile, false, null, 0, 16);
            if (strpos($header, 'SQLite format 3') === 0) {
                // Create backup of current DB first
                backupDatabase();

                // Replace the database file
                if (copy($tmpFile, DB_PATH)) {
                    // Sprint security Phase 0 — re-derive the default-PIN guard
                    // from the freshly-uploaded DB's guardian hash, so an admin
                    // who restores a DB with a custom PIN isn't wrongly locked,
                    // and one with the default '0000' is correctly force-changed.
                    refreshGuardianPinDefaultFlag(getDB());
                    $message = t('restore_success');
                    $messageType = 'success';
                } else {
                    $message = t('error_database');
                    $messageType = 'error';
                }
            } else {
                $message = t('error_invalid_input');
                $messageType = 'error';
            }
        } else {
            $message = t('error_generic');
            $messageType = 'error';
        }
    } elseif ($action === 'delete' && ($_POST['confirm'] ?? '') === 'ELIMINAR') {
        resetDatabase();
        $message = t('all_data_deleted');
        $messageType = 'warning';
    }
    } // end CSRF-valid branch
}

// Get DB file size
$dbSize = file_exists(DB_PATH) ? filesize(DB_PATH) : 0;
$dbSizeStr = $dbSize < 1024 ? $dbSize . ' B' : ($dbSize < 1048576 ? round($dbSize / 1024, 1) . ' KB' : round($dbSize / 1048576, 1) . ' MB');

ob_start();
?>

<div class="guardian-interface">
    <?php include 'nav.php'; ?>

    <main class="container">
        <h1><?php echo t('database_management'); ?></h1>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType === 'error' ? 'error' : ($messageType === 'warning' ? 'warning' : 'success'); ?>">
            <?php echo $messageType === 'error' ? '❌' : ($messageType === 'warning' ? '⚠️' : '✅'); ?> <?php echo $message; ?>
        </div>
        <?php endif; ?>

        <!-- DB Info -->
        <section class="management-section">
            <h2>📊 <?php echo t('database'); ?></h2>
            <p><?php echo t('database_management'); ?>: <strong><?php echo $dbSizeStr; ?></strong></p>
        </section>

        <!-- Backup & Download -->
        <section class="management-section">
            <h2>💾 <?php echo t('backup_database'); ?></h2>
            <p><?php echo t('backup_description'); ?></p>
            <div style="display:flex;gap:1rem;flex-wrap:wrap;">
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="backup">
                    <button type="submit" class="btn-primary">💾 <?php echo t('backup_database'); ?></button>
                </form>
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="download">
                    <button type="submit" class="btn-secondary">📥 <?php echo t('download_db'); ?></button>
                </form>
            </div>
        </section>

        <!-- Upload / Restore -->
        <section class="management-section">
            <h2>📤 <?php echo t('restore_database'); ?></h2>
            <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('<?php echo t('delete_confirmation'); ?>')">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="upload">
                <label>
                    <?php echo t('restore_database'); ?> (.db)
                    <input type="file" name="dbfile" accept=".db,.sqlite,.sqlite3" required>
                </label>
                <button type="submit" class="btn-primary">📤 <?php echo t('restore_database'); ?></button>
            </form>
        </section>

        <!-- Delete All -->
        <section class="management-section">
            <h2>🗑️ <?php echo t('delete_all_data'); ?></h2>
            <p class="text-danger" style="font-weight:600;"><?php echo t('delete_warning'); ?></p>
            <form method="POST" onsubmit="return confirm('<?php echo t('delete_warning'); ?>')">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="delete">
                <label>
                    <?php echo t('type_delete_confirm'); ?>
                    <input type="text" name="confirm" placeholder="ELIMINAR" required>
                </label>
                <button type="submit" class="btn-danger">
                    🗑️ <?php echo t('delete_all_data'); ?>
                </button>
            </form>
        </section>
    </main>
</div>

<?php
$content = ob_get_clean();
renderLayout(t('database_management'), $content);
?>
