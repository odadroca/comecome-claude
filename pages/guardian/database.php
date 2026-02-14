<?php
/**
 * Guardian - Database Management (backup, restore/upload, reset)
 */

$user = getCurrentUser();
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                    $message = t('restore_success');
                    $messageType = 'success';
                } else {
                    $message = 'Erro ao restaurar a base de dados.';
                    $messageType = 'error';
                }
            } else {
                $message = 'Ficheiro inválido. Apenas ficheiros SQLite (.db) são aceites.';
                $messageType = 'error';
            }
        } else {
            $message = 'Nenhum ficheiro selecionado ou erro no upload.';
            $messageType = 'error';
        }
    } elseif ($action === 'delete' && ($_POST['confirm'] ?? '') === 'ELIMINAR') {
        resetDatabase();
        $message = t('all_data_deleted');
        $messageType = 'warning';
    }
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
        <div class="alert <?php echo $messageType === 'error' ? 'alert-error' : ($messageType === 'warning' ? 'alert-warning' : 'alert-success'); ?>">
            <?php echo $messageType === 'error' ? '❌' : ($messageType === 'warning' ? '⚠️' : '✅'); ?> <?php echo $message; ?>
        </div>
        <?php endif; ?>

        <!-- DB Info -->
        <section class="management-section">
            <h2>📊 Informação</h2>
            <p>Tamanho da base de dados: <strong><?php echo $dbSizeStr; ?></strong></p>
        </section>

        <!-- Backup & Download -->
        <section class="management-section">
            <h2>💾 <?php echo t('backup_database'); ?></h2>
            <p><?php echo t('backup_description'); ?></p>
            <div style="display:flex;gap:1rem;flex-wrap:wrap;">
                <form method="POST">
                    <input type="hidden" name="action" value="backup">
                    <button type="submit" class="btn-primary">💾 <?php echo t('backup_database'); ?></button>
                </form>
                <form method="POST">
                    <input type="hidden" name="action" value="download">
                    <button type="submit" class="btn-secondary">📥 <?php echo t('download_db'); ?></button>
                </form>
            </div>
        </section>

        <!-- Upload / Restore -->
        <section class="management-section">
            <h2>📤 <?php echo t('restore_database'); ?></h2>
            <p>Carregar um ficheiro de base de dados (.db) para restaurar. Um backup automático será criado antes da restauração.</p>
            <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('Tem a certeza que quer substituir a base de dados atual? Um backup será criado automaticamente.')">
                <input type="hidden" name="action" value="upload">
                <label>
                    Ficheiro .db
                    <input type="file" name="dbfile" accept=".db,.sqlite,.sqlite3" required>
                </label>
                <button type="submit" class="btn-primary">📤 Restaurar</button>
            </form>
        </section>

        <!-- Delete All -->
        <section class="management-section">
            <h2>🗑️ <?php echo t('delete_all_data'); ?></h2>
            <p class="text-danger" style="font-weight:600;"><?php echo t('delete_warning'); ?></p>
            <form method="POST" onsubmit="return confirm('Tem ABSOLUTA certeza? Esta ação NÃO PODE ser desfeita!')">
                <input type="hidden" name="action" value="delete">
                <label>
                    <?php echo t('type_delete_confirm'); ?>
                    <input type="text" name="confirm" placeholder="ELIMINAR" required>
                </label>
                <button type="submit" class="btn-secondary btn-danger">
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
