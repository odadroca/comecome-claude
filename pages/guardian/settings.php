<?php
/**
 * Guardian - Settings
 */

$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    setSetting('show_food_journal', $_POST['show_food_journal'] ?? '0');
    setSetting('show_checkin', $_POST['show_checkin'] ?? '0');
    setSetting('show_weight_tracking', $_POST['show_weight_tracking'] ?? '0');
    setSetting('show_sleep_tracking', $_POST['show_sleep_tracking'] ?? '0');
    setSetting('show_medication_to_children', $_POST['show_medication'] ?? '0');
    // Sprint 6: Growth/Percentiles toggle (default OFF). Decision iv — enabling it
    // NEVER blocks. We capture whether it was just turned ON so we can SOFT-WARN
    // (below) about active children missing gender/DOB, without preventing the save.
    $percentilesWasOn = getSetting('show_percentiles', '0') === '1';
    $showPercentiles = $_POST['show_percentiles'] ?? '0';
    setSetting('show_percentiles', $showPercentiles);
    $justEnabledPercentiles = ($showPercentiles === '1' && !$percentilesWasOn);
    setSetting('default_language', $_POST['default_language'] ?? 'pt');
    $success = true;
}

$showFoodJournal = getSetting('show_food_journal', '1');
$showCheckin = getSetting('show_checkin', '1');
$showWeightTracking = getSetting('show_weight_tracking', '1');
$showSleepTracking = getSetting('show_sleep_tracking', '1');
$showMedication = getSetting('show_medication_to_children', '1');
$showPercentiles = getSetting('show_percentiles', '0');
$defaultLanguage = getSetting('default_language', 'pt');

// Sprint 6 (decision iv): graceful degradation + soft-warn. When percentiles are
// ON (and especially right after enabling), list any ACTIVE child still missing
// gender or date_of_birth so the guardian can complete them — but never block.
$childrenMissingDemographics = [];
if ($showPercentiles === '1') {
    foreach (getAllUsers('child') as $child) {
        if ((int) ($child['active'] ?? 1) !== 1) continue;
        $missingGender = empty($child['gender']);
        $missingDob = empty($child['date_of_birth']);
        if ($missingGender || $missingDob) {
            $childrenMissingDemographics[] = $child;
        }
    }
}

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
                <h3><?php echo t('child_features'); ?></h3>
                <small style="opacity:0.7;display:block;margin-bottom:1rem;">
                    <?php echo t('child_features_hint'); ?>
                </small>

                <label>
                    <input type="checkbox" name="show_food_journal" value="1" <?php echo $showFoodJournal == '1' ? 'checked' : ''; ?>>
                    🍽️ <?php echo t('show_food_journal'); ?>
                </label>
                <small style="opacity:0.7;display:block;margin-top:0.25rem;margin-bottom:0.75rem;">
                    <?php echo t('show_food_journal_hint'); ?>
                </small>

                <label>
                    <input type="checkbox" name="show_checkin" value="1" <?php echo $showCheckin == '1' ? 'checked' : ''; ?>>
                    ✅ <?php echo t('show_checkin'); ?>
                </label>
                <small style="opacity:0.7;display:block;margin-top:0.25rem;margin-bottom:0.75rem;">
                    <?php echo t('show_checkin_hint'); ?>
                </small>

                <label>
                    <input type="checkbox" name="show_weight_tracking" value="1" <?php echo $showWeightTracking == '1' ? 'checked' : ''; ?>>
                    ⚖️ <?php echo t('show_weight_tracking'); ?>
                </label>
                <small style="opacity:0.7;display:block;margin-top:0.25rem;margin-bottom:0.75rem;">
                    <?php echo t('show_weight_tracking_hint'); ?>
                </small>

                <label>
                    <input type="checkbox" name="show_sleep_tracking" value="1" <?php echo $showSleepTracking == '1' ? 'checked' : ''; ?>>
                    😴 <?php echo t('show_sleep_tracking'); ?>
                </label>
                <small style="opacity:0.7;display:block;margin-top:0.25rem;margin-bottom:0.75rem;">
                    <?php echo t('show_sleep_tracking_hint'); ?>
                </small>

                <label>
                    <input type="checkbox" name="show_medication" value="1" <?php echo $showMedication == '1' ? 'checked' : ''; ?>>
                    💊 <?php echo t('show_medication_children'); ?>
                </label>
            </section>

            <section class="management-section">
                <h3><?php echo t('growth_percentiles'); ?></h3>
                <small style="opacity:0.7;display:block;margin-bottom:1rem;">
                    <?php echo t('growth_percentiles_hint'); ?>
                </small>

                <?php
                // Soft-warn (decision iv): never blocks. Surfaced whenever the toggle
                // is ON and at least one active child still lacks gender/DOB, with a
                // link to complete it on manage-children.
                if (!empty($childrenMissingDemographics)):
                    $missingNames = array_map(function ($c) { return sanitize($c['name']); }, $childrenMissingDemographics);
                ?>
                <div class="alert alert-warning" style="margin-bottom:1rem;">
                    ⚠️ <?php echo t('percentiles_need_dob_warning'); ?>
                    <strong><?php echo implode(', ', $missingNames); ?></strong>
                    — <a href="?page=manage-children"><?php echo t('manage_children'); ?></a>
                </div>
                <?php endif; ?>

                <label>
                    <input type="checkbox" name="show_percentiles" value="1" <?php echo $showPercentiles == '1' ? 'checked' : ''; ?>>
                    📈 <?php echo t('show_percentiles'); ?>
                </label>
                <small style="opacity:0.7;display:block;margin-top:0.25rem;margin-bottom:0.75rem;">
                    <?php echo t('show_percentiles_hint'); ?>
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
