<?php
/**
 * Guardian - Settings
 */

// RETENTION_PRESETS is authoritative in config.php; defensive fallback so this page
// renders cleanly in test harnesses that skip config.php (mirrors safeguarding.php).
if (!defined('RETENTION_PRESETS')) { define('RETENTION_PRESETS', [0, 6, 12, 24, 36]); }

$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sprint security — every state-changing action requires a valid CSRF
    // token; a forged cross-site POST lacks it and is bounced before any DB write.
    if (function_exists('verifyCsrf') && !verifyCsrf()) {
        header('Location: ?page=settings&msg=csrf_error');
        exit;
    }
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
    // Sprint 11 / S2 A21: Nutrition Intelligence toggle (default OFF). Enabling
    // requires the guardian to acknowledge the in-app medical disclaimer in the same
    // POST (attestation gate). Turning OFF needs no checkbox.
    $niWasOn   = getSetting('show_nutrition_insights', '0') === '1';
    $niWantOn  = ($_POST['show_nutrition_insights'] ?? '0') === '1';
    $niAcknowledge = !empty($_POST['nutrition_attestation_acknowledge']);
    $niRejected = false;
    if ($niWantOn && !$niWasOn && !$niAcknowledge) {
        // Enabling from off requires acknowledgement — checkbox missing, so reject.
        // Leave at '0', write no attestation. Flag the rejection so the success
        // banner is suppressed and an inline error is shown instead.
        $niRejected = true;
    } else {
        // Turning off, already on (with or without ack), or enabling with ack.
        setSetting('show_nutrition_insights', $niWantOn ? '1' : '0');
        if ($niWantOn && $niAcknowledge) {
            // Enable-with-ack OR re-ack while staying on — record the attestation.
            recordGuardianNutritionAttestation();
        }
    }
    setSetting('show_safeguarding_alerts', $_POST['show_safeguarding_alerts'] ?? '0');
    setSetting('default_language', $_POST['default_language'] ?? 'pt');
    $rm = (int) ($_POST['data_retention_months'] ?? 0);
    if (in_array($rm, RETENTION_PRESETS, true)) {
        setSetting('data_retention_months', (string) $rm);
    }
    if (!$niRejected) {
        $success = true;
    }
}

$showFoodJournal = getSetting('show_food_journal', '1');
$showCheckin = getSetting('show_checkin', '1');
$showWeightTracking = getSetting('show_weight_tracking', '1');
$showSleepTracking = getSetting('show_sleep_tracking', '1');
$showMedication = getSetting('show_medication_to_children', '1');
$showPercentiles = getSetting('show_percentiles', '0');
$showNutritionInsights = getSetting('show_nutrition_insights', '0');
$showSafeguarding = getSetting('show_safeguarding_alerts', '1');
$defaultLanguage = getSetting('default_language', 'pt');
$retentionMonths = (int) getSetting('data_retention_months', '0');

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
        <?php echo renderEncryptionWarning(); ?>

        <?php if (isset($success)): ?>
        <div class="alert alert-success">
            ✅ <?php echo t('changes_saved'); ?>
        </div>
        <?php endif; ?>
        <?php if (isset($niRejected) && $niRejected): ?>
        <div class="alert alert-danger">
            ⚠️ <?php echo t('nutrition_attestation_required'); ?>
        </div>
        <?php endif; ?>

        <form method="POST">
            <?php echo csrfField(); ?>
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

                <!-- Sprint 11 / S2 A21: Nutrition Intelligence (guardian/clinician-side, default OFF).
                     Enabling requires acknowledging the medical disclaimer in the same POST. -->
                <label>
                    <input type="checkbox" name="show_nutrition_insights" value="1" <?php echo $showNutritionInsights == '1' ? 'checked' : ''; ?>>
                    🥗 <?php echo t('show_nutrition_insights'); ?>
                </label>
                <small style="opacity:0.7;display:block;margin-top:0.25rem;margin-bottom:0.75rem;">
                    <?php echo t('show_nutrition_insights_hint'); ?>
                </small>

                <?php if ($showNutritionInsights !== '1' || nutritionAttestationStale()): ?>
                <!-- S2 / A21: medical disclaimer + attestation checkbox.
                     Shown when insights are OFF (guardian enabling) OR when the existing
                     attestation is stale (re-ack path for already-on installs).
                     The `required` attribute is intentionally absent — the server-side gate
                     handles enable-without-ack; the re-ack-while-on path is soft. -->
                <div class="alert" style="background:var(--cc-surface-sunken);border:1px solid var(--cc-border);border-left:4px solid #f9a825;color:var(--cc-text-body);border-radius:6px;padding:1rem;margin-top:0.5rem;margin-bottom:0.75rem;font-size:0.9rem;">
                    <strong><?php echo t('medical_disclaimer_short'); ?></strong>
                    <p style="margin:0.5rem 0 0;"><?php echo t('medical_disclaimer_full'); ?></p>
                </div>
                <label style="font-weight:600;">
                    <input type="checkbox" name="nutrition_attestation_acknowledge" value="1">
                    <?php echo t('nutrition_attestation_checkbox'); ?>
                </label>
                <?php endif; ?>

                <!-- Sprint S2 / A4: Safeguarding wellbeing alerts (guardian-only, default ON). -->
                <label>
                    <input type="checkbox" name="show_safeguarding_alerts" value="1" <?php echo $showSafeguarding == '1' ? 'checked' : ''; ?>>
                    🛟 <?php echo t('show_safeguarding_alerts'); ?>
                </label>
                <small style="opacity:0.7;display:block;margin-top:0.25rem;margin-bottom:0.75rem;">
                    <?php echo t('show_safeguarding_alerts_hint'); ?>
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

            <section class="management-section danger-zone" style="border:1px solid var(--danger,#c0392b);border-radius:8px;padding:1rem;margin-top:1.5rem;">
                <h3>🗑️ <?php echo t('retention_title'); ?></h3>
                <p style="opacity:0.85;"><?php echo t('retention_warning'); ?></p>
                <label><?php echo t('retention_label'); ?>
                    <select name="data_retention_months">
                        <?php foreach (RETENTION_PRESETS as $m): ?>
                        <option value="<?php echo $m; ?>" <?php echo $retentionMonths === $m ? 'selected' : ''; ?>>
                            <?php echo $m === 0 ? t('retention_off') : sprintf(t('retention_months'), $m); ?>
                        </option>
                        <?php endforeach; ?>
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
