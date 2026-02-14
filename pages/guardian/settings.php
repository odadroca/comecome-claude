<?php
/**
 * Guardian - Settings
 */

$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    setSetting('show_medication_to_children', $_POST['show_medication'] ?? '0');
    setSetting('default_language', $_POST['default_language'] ?? 'pt');
    $success = true;
}

$showMedication = getSetting('show_medication_to_children', '1');
$defaultLanguage = getSetting('default_language', 'pt');

ob_start();
?>

<div class="guardian-interface">
    <?php include 'nav.php'; ?>

    <main class="container">
        <h1><?php echo t('system_settings'); ?></h1>

        <?php if (isset($success)): ?>
        <div class="alert alert-success">
            ✅ <?php echo t('changes_saved'); ?>
        </div>
        <?php endif; ?>

        <form method="POST">
            <section class="management-section">
                <label>
                    <input type="checkbox" name="show_medication" value="1" <?php echo $showMedication == '1' ? 'checked' : ''; ?>>
                    <?php echo t('show_medication_children'); ?>
                </label>
                <small style="opacity:0.7;display:block;margin-top:0.5rem;">
                    Para crianças pequenas, pode ser útil esconder a referência a "medicamento"
                </small>
            </section>

            <section class="management-section">
                <label>
                    <?php echo t('default_language'); ?>
                    <select name="default_language">
                        <option value="pt" <?php echo $defaultLanguage === 'pt' ? 'selected' : ''; ?>><?php echo t('language_pt'); ?></option>
                        <option value="en" <?php echo $defaultLanguage === 'en' ? 'selected' : ''; ?>><?php echo t('language_en'); ?></option>
                    </select>
                </label>
            </section>

            <button type="submit" class="btn-primary"><?php echo t('save_changes'); ?></button>
        </form>
    </main>
</div>

<?php
$content = ob_get_clean();
renderLayout(t('settings'), $content);
?>
