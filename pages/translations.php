<?php
/**
 * Translation Management Interface
 */

requireGuardian();
$user = getCurrentUser();

$selectedLocale = $_GET['locale'] ?? 'pt';
$translations = getAllTranslations($selectedLocale);

// Handle updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = $_POST['key'] ?? '';
    $value = $_POST['value'] ?? '';
    $locale = $_POST['locale'] ?? $selectedLocale;

    if ($key && $value && $locale) {
        saveTranslation($locale, $key, $value);
        header('Location: ?page=translations&locale=' . $locale);
        exit;
    }
}

$editKey = $_GET['edit'] ?? null;
$editValue = $editKey ? ($translations[$editKey] ?? '') : '';

ob_start();
?>

<div class="guardian-interface">
    <?php include 'guardian/nav.php'; ?>

    <main class="container">
        <h1><?php echo t('translation_management'); ?></h1>

        <div class="form-grid">
            <label>
                <?php echo t('select_language'); ?>
                <select onchange="window.location='?page=translations&locale='+this.value">
                    <?php foreach (getAvailableLocales() as $locale): ?>
                    <option value="<?php echo $locale; ?>" <?php echo $selectedLocale === $locale ? 'selected' : ''; ?>>
                        <?php echo strtoupper($locale); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <input type="text" id="searchBox" placeholder="<?php echo t('search_translations'); ?>" onkeyup="filterTranslations()">
        </div>

        <?php if ($editKey): ?>
        <section class="management-section">
            <h2><?php echo t('edit'); ?>: <?php echo $editKey; ?></h2>
            <form method="POST">
                <input type="hidden" name="locale" value="<?php echo $selectedLocale; ?>">
                <input type="hidden" name="key" value="<?php echo $editKey; ?>">
                <label>
                    <?php echo t('translation_value'); ?>
                    <textarea name="value" rows="3" required><?php echo sanitize($editValue); ?></textarea>
                </label>
                <div style="display:flex;gap:1rem;">
                    <button type="submit" class="btn-primary"><?php echo t('save'); ?></button>
                    <a href="?page=translations&locale=<?php echo $selectedLocale; ?>" class="btn-secondary"><?php echo t('cancel'); ?></a>
                </div>
            </form>
        </section>
        <?php endif; ?>

        <section class="management-section">
            <h2><?php echo t('translations'); ?> (<?php echo count($translations); ?>)</h2>
            <div class="table-responsive">
                <table id="translationsTable">
                    <thead>
                        <tr>
                            <th><?php echo t('translation_key'); ?></th>
                            <th><?php echo t('translation_value'); ?></th>
                            <th><?php echo t('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($translations as $key => $value): ?>
                        <?php if (strpos($key, '_') === 0) continue; // Skip section headers ?>
                        <?php if (!is_string($value)) continue; // Skip non-string values ?>
                        <tr>
                            <td><code><?php echo $key; ?></code></td>
                            <td><?php echo sanitize($value); ?></td>
                            <td>
                                <a href="?page=translations&locale=<?php echo $selectedLocale; ?>&edit=<?php echo urlencode($key); ?>" class="btn-small">
                                    ✏️ <?php echo t('edit'); ?>
                                </a>
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
function filterTranslations() {
    const search = document.getElementById('searchBox').value.toLowerCase();
    const rows = document.querySelectorAll('#translationsTable tbody tr');

    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(search) ? '' : 'none';
    });
}
</script>

<?php
$content = ob_get_clean();
renderLayout(t('translation_management'), $content);
?>
