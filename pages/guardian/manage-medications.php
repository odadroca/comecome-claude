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
            // Sprint security Phase 5 — encrypt the scoped medication name + dose on
            // write (no-op passthrough with no key). active stays cleartext (filtered).
            $stmt = $db->prepare("INSERT INTO medications (name, dose) VALUES (?, ?)");
            $stmt->execute([encryptField($name), encryptField($dose)]);
        }
        header('Location: ?page=manage-medications&msg=saved');
        exit;
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $dose = trim($_POST['dose'] ?? '');
        $active = (int) ($_POST['active'] ?? 1);
        if ($id && $name) {
            // Sprint security Phase 5 — encrypt the scoped name + dose on write.
            $stmt = $db->prepare("UPDATE medications SET name = ?, dose = ?, active = ? WHERE id = ?");
            $stmt->execute([encryptField($name), encryptField($dose), $active, $id]);
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
    } elseif ($action === 'schedule_create') {
        // Sprint 9: create a medication-timing schedule for a child. dose_time +
        // med-type (auto-fills offsets) + optional manual offset overrides. The
        // classifier (computeMedWindow) reads the stored offsets at food-log INSERT.
        $childId = (int) ($_POST['schedule_user_id'] ?? 0);
        $medId   = (int) ($_POST['schedule_medication_id'] ?? 0);
        $doseTime = trim($_POST['dose_time'] ?? '');
        $medType  = $_POST['med_type'] ?? 'short_acting';
        if (!in_array($medType, medTypeOptions(), true)) $medType = 'short_acting';
        // Empty overrides fall back to the med-type defaults inside the CRUD helper.
        $peakStart = ($_POST['peak_start_offset'] ?? '') !== '' ? (int) $_POST['peak_start_offset'] : null;
        $peakEnd   = ($_POST['peak_end_offset'] ?? '') !== '' ? (int) $_POST['peak_end_offset'] : null;
        if ($childId && $medId && preg_match('/^\d{1,2}:\d{2}$/', $doseTime)) {
            createMedicationSchedule($childId, $medId, $doseTime, $medType, $peakStart, $peakEnd, 1);
        }
        header('Location: ?page=manage-medications&msg=saved#schedules');
        exit;
    } elseif ($action === 'schedule_update') {
        $id = (int) ($_POST['id'] ?? 0);
        $doseTime = trim($_POST['dose_time'] ?? '');
        $medType  = $_POST['med_type'] ?? 'short_acting';
        if (!in_array($medType, medTypeOptions(), true)) $medType = 'short_acting';
        $peakStart = ($_POST['peak_start_offset'] ?? '') !== '' ? (int) $_POST['peak_start_offset'] : null;
        $peakEnd   = ($_POST['peak_end_offset'] ?? '') !== '' ? (int) $_POST['peak_end_offset'] : null;
        $active = (int) ($_POST['active'] ?? 1);
        if ($id && preg_match('/^\d{1,2}:\d{2}$/', $doseTime)) {
            updateMedicationSchedule($id, $doseTime, $medType, $peakStart, $peakEnd, $active);
        }
        header('Location: ?page=manage-medications&msg=saved#schedules');
        exit;
    } elseif ($action === 'schedule_delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) deleteMedicationSchedule($id);
        header('Location: ?page=manage-medications&msg=saved#schedules');
        exit;
    }
}

if (isset($_GET['msg'])) {
    $message = t('changes_saved');
}

// Get all medications (including inactive).
// Sprint security Phase 5 — decrypt-on-read of the scoped name/dose for the whole
// page (this $medications list backs the table, the assignment picker, and the
// schedule dropdown). The ORDER BY active DESC, name sorts on the STORED value, so
// with encryption ON the secondary name sort is by ciphertext, not alphabetical —
// the accepted trade-off for encrypting an identity column; invisible to children.
$stmt = $db->query("SELECT * FROM medications ORDER BY active DESC, name");
$medications = function_exists('decryptRowsFields')
    ? decryptRowsFields($stmt->fetchAll(), ['name', 'dose'])
    : $stmt->fetchAll();

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
    // Sprint security Phase 5 — decrypt-on-read so the edit form pre-fills plaintext.
    $editMed = function_exists('decryptRowFields')
        ? decryptRowFields($stmt->fetch(), ['name', 'dose'])
        : $stmt->fetch();
}

// Sprint 9: Medication Timing schedules, grouped per child for the config UI below.
$schedulesByChild = [];
foreach ($children as $child) {
    $schedulesByChild[$child['id']] = getMedicationSchedules($child['id']);
}

// Schedule edit mode (separate query param so it doesn't collide with med edit).
$editSchedule = null;
if (isset($_GET['edit_schedule'])) {
    $stmt = $db->prepare("SELECT * FROM medication_schedules WHERE id = ?");
    $stmt->execute([(int) $_GET['edit_schedule']]);
    $editSchedule = $stmt->fetch();
}

// med-type → default offsets, mirrored from medication.php for the client-side
// auto-fill (JS reads this JSON so the dropdown pre-fills the offset inputs).
$medTypeDefaults = [
    'short_acting'  => medTypeDefaultOffsets('short_acting'),
    'long_acting'   => medTypeDefaultOffsets('long_acting'),
    'non_stimulant' => medTypeDefaultOffsets('non_stimulant'),
];

// Localized window labels for the timeline legend.
$windowLabels = [
    'pre_med'  => t('window_pre_med'),
    'onset'    => t('window_onset'),
    'mid_med'  => t('window_mid_med'),
    'post_med' => t('window_post_med'),
];

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

        <!-- Sprint 9: Medication Timing schedules (guardian-only config) -->
        <?php if (!empty($medications) && !empty($children)): ?>
        <section class="management-section" id="schedules">
            <h2>⏱️ <?php echo t('medication_timing'); ?></h2>
            <p style="opacity:0.7;font-size:0.875rem;margin-bottom:0.5rem;">
                <?php echo t('medication_timing_intro'); ?>
            </p>
            <div class="alert alert-warning" style="font-size:0.85rem;">
                ⚠️ <?php echo t('med_timing_disclaimer'); ?>
            </div>

            <?php
            // Window-colour map shared by the timeline + legend.
            $winColors = [
                'pre_med'  => '#90a4ae',
                'onset'    => '#66bb6a',
                'mid_med'  => '#ef5350',
                'post_med' => '#ffa726',
            ];
            ?>

            <!-- 24-hour window legend -->
            <div style="display:flex;flex-wrap:wrap;gap:0.75rem;margin-bottom:1rem;font-size:0.8rem;">
                <?php foreach ($windowLabels as $wk => $wlabel): ?>
                <span style="display:inline-flex;align-items:center;gap:0.35rem;">
                    <span style="width:14px;height:14px;border-radius:3px;background:<?php echo $winColors[$wk]; ?>;display:inline-block;"></span>
                    <?php echo sanitize($wlabel); ?>
                </span>
                <?php endforeach; ?>
            </div>

            <?php foreach ($children as $child): ?>
            <?php $childSchedules = $schedulesByChild[$child['id']] ?? []; ?>
            <details<?php echo ($editSchedule && $editSchedule['user_id'] == $child['id']) ? ' open' : ''; ?> style="margin-bottom:0.75rem;border:1px solid #e0e0e0;border-radius:6px;padding:0.5rem 0.75rem;">
                <summary style="font-weight:bold;">
                    <?php echo $child['avatar_emoji'] . ' ' . sanitize($child['name']); ?>
                    <span style="opacity:0.6;font-weight:normal;font-size:0.8rem;">(<?php echo count($childSchedules); ?>)</span>
                </summary>

                <?php if (!empty($childSchedules)): ?>
                <div class="table-responsive" style="margin-top:0.75rem;">
                    <table>
                        <thead>
                            <tr>
                                <th><?php echo t('medication_name'); ?></th>
                                <th><?php echo t('dose_time'); ?></th>
                                <th><?php echo t('med_type'); ?></th>
                                <th><?php echo t('window_24h'); ?></th>
                                <th><?php echo t('active'); ?></th>
                                <th><?php echo t('actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($childSchedules as $sch): ?>
                            <?php
                            // Build a 24h timeline (0..1440 min) for this schedule.
                            $segments = [];
                            $hasWindow = ($sch['peak_start_offset'] !== null && $sch['peak_end_offset'] !== null);
                            if ($hasWindow && preg_match('/^(\d{1,2}):(\d{2})$/', $sch['dose_time'], $dm)) {
                                $doseMin = ((int) $dm[1]) * 60 + (int) $dm[2];
                                $ps = $doseMin + (int) $sch['peak_start_offset'];
                                $pe = $doseMin + (int) $sch['peak_end_offset'];
                                // Clamp to the day for display.
                                $clamp = function ($v) { return max(0, min(1440, $v)); };
                                $boundaries = [
                                    ['pre_med',  0,                $clamp($doseMin)],
                                    ['onset',    $clamp($doseMin), $clamp($ps)],
                                    ['mid_med',  $clamp($ps),      $clamp($pe)],
                                    ['post_med', $clamp($pe),      1440],
                                ];
                                foreach ($boundaries as $b) {
                                    $w = $b[2] - $b[1];
                                    if ($w > 0) $segments[] = [$b[0], ($b[1] / 1440) * 100, ($w / 1440) * 100];
                                }
                            }
                            ?>
                            <tr<?php echo !$sch['active'] ? ' style="opacity:0.5;"' : ''; ?>>
                                <td><?php echo sanitize($sch['medication_name']); ?></td>
                                <td><?php echo sanitize($sch['dose_time']); ?></td>
                                <td><?php echo t('med_type_' . $sch['med_type']); ?></td>
                                <td style="min-width:160px;">
                                    <?php if ($hasWindow && !empty($segments)): ?>
                                    <div title="<?php echo sanitize($sch['dose_time']); ?>" style="display:flex;height:14px;width:100%;border-radius:3px;overflow:hidden;border:1px solid #ccc;">
                                        <?php foreach ($segments as $seg): ?>
                                        <span style="width:<?php echo round($seg[2], 2); ?>%;background:<?php echo $winColors[$seg[0]]; ?>;" title="<?php echo sanitize($windowLabels[$seg[0]]); ?>"></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <small style="opacity:0.6;">+<?php echo (int) $sch['peak_start_offset']; ?>/<?php echo (int) $sch['peak_end_offset']; ?> min</small>
                                    <?php else: ?>
                                    <small style="opacity:0.6;">— <?php echo t('window_none'); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $sch['active'] ? '✅' : '❌'; ?></td>
                                <td style="white-space:nowrap;">
                                    <a href="?page=manage-medications&edit_schedule=<?php echo $sch['id']; ?>#schedules" class="btn-small">✏️</a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo t('delete_confirmation'); ?>')">
                                        <input type="hidden" name="action" value="schedule_delete">
                                        <input type="hidden" name="id" value="<?php echo $sch['id']; ?>">
                                        <button type="submit" class="btn-small btn-danger">🗑️</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p style="opacity:0.6;margin-top:0.5rem;font-size:0.85rem;"><?php echo t('no_schedules'); ?></p>
                <?php endif; ?>

                <!-- Add a new schedule for this child -->
                <?php if (!$editSchedule || $editSchedule['user_id'] != $child['id']): ?>
                <form method="POST" class="med-schedule-form" style="margin-top:0.75rem;border-top:1px dashed #ddd;padding-top:0.75rem;">
                    <input type="hidden" name="action" value="schedule_create">
                    <input type="hidden" name="schedule_user_id" value="<?php echo $child['id']; ?>">
                    <div class="form-grid">
                        <label>
                            <?php echo t('medication'); ?>
                            <select name="schedule_medication_id" required>
                                <?php foreach ($medications as $med): if (!$med['active']) continue; ?>
                                <option value="<?php echo $med['id']; ?>"><?php echo sanitize($med['name']); ?><?php echo $med['dose'] ? ' (' . sanitize($med['dose']) . ')' : ''; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <?php echo t('dose_time'); ?>
                            <input type="time" name="dose_time" value="08:00" required>
                        </label>
                        <label>
                            <?php echo t('med_type'); ?>
                            <select name="med_type" class="med-type-select">
                                <?php foreach (medTypeOptions() as $mt): ?>
                                <option value="<?php echo $mt; ?>"><?php echo t('med_type_' . $mt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <?php echo t('peak_start_offset'); ?>
                            <input type="number" name="peak_start_offset" class="offset-start" min="0" max="1440" placeholder="30">
                            <small style="opacity:0.6;"><?php echo t('offset_help'); ?></small>
                        </label>
                        <label>
                            <?php echo t('peak_end_offset'); ?>
                            <input type="number" name="peak_end_offset" class="offset-end" min="0" max="1440" placeholder="240">
                        </label>
                    </div>
                    <button type="submit" class="btn-small" style="margin-top:0.5rem;"><?php echo t('add_schedule'); ?></button>
                </form>
                <?php endif; ?>

                <!-- Edit an existing schedule for this child -->
                <?php if ($editSchedule && $editSchedule['user_id'] == $child['id']): ?>
                <form method="POST" class="med-schedule-form" style="margin-top:0.75rem;border-top:2px solid #1FA4B5;padding-top:0.75rem;">
                    <input type="hidden" name="action" value="schedule_update">
                    <input type="hidden" name="id" value="<?php echo $editSchedule['id']; ?>">
                    <h4 style="margin:0 0 0.5rem;">✏️ <?php echo t('edit'); ?></h4>
                    <div class="form-grid">
                        <label>
                            <?php echo t('dose_time'); ?>
                            <input type="time" name="dose_time" value="<?php echo sanitize(substr($editSchedule['dose_time'], 0, 5)); ?>" required>
                        </label>
                        <label>
                            <?php echo t('med_type'); ?>
                            <select name="med_type" class="med-type-select">
                                <?php foreach (medTypeOptions() as $mt): ?>
                                <option value="<?php echo $mt; ?>" <?php echo $editSchedule['med_type'] === $mt ? 'selected' : ''; ?>><?php echo t('med_type_' . $mt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <?php echo t('peak_start_offset'); ?>
                            <input type="number" name="peak_start_offset" class="offset-start" min="0" max="1440" value="<?php echo $editSchedule['peak_start_offset'] !== null ? (int) $editSchedule['peak_start_offset'] : ''; ?>">
                            <small style="opacity:0.6;"><?php echo t('offset_help'); ?></small>
                        </label>
                        <label>
                            <?php echo t('peak_end_offset'); ?>
                            <input type="number" name="peak_end_offset" class="offset-end" min="0" max="1440" value="<?php echo $editSchedule['peak_end_offset'] !== null ? (int) $editSchedule['peak_end_offset'] : ''; ?>">
                        </label>
                        <label>
                            <?php echo t('active'); ?>
                            <select name="active">
                                <option value="1" <?php echo $editSchedule['active'] == 1 ? 'selected' : ''; ?>><?php echo t('active'); ?></option>
                                <option value="0" <?php echo $editSchedule['active'] == 0 ? 'selected' : ''; ?>><?php echo t('inactive'); ?></option>
                            </select>
                        </label>
                    </div>
                    <div style="display:flex;gap:1rem;margin-top:0.5rem;">
                        <button type="submit" class="btn-small btn-primary"><?php echo t('save'); ?></button>
                        <a href="?page=manage-medications#schedules" class="btn-small btn-secondary"><?php echo t('cancel'); ?></a>
                    </div>
                </form>
                <?php endif; ?>
            </details>
            <?php endforeach; ?>
        </section>

        <!-- Med-type → offset auto-fill: when the guardian picks a type, pre-fill the
             offset inputs with that type's default (overridable). Non-stimulant clears
             them (no acute appetite window). Defaults are approximations needing
             per-child tuning. -->
        <script>
        (function () {
            var MED_TYPE_DEFAULTS = <?php echo json_encode($medTypeDefaults); ?>;
            document.querySelectorAll('.med-schedule-form').forEach(function (form) {
                var sel = form.querySelector('.med-type-select');
                var startInput = form.querySelector('.offset-start');
                var endInput = form.querySelector('.offset-end');
                if (!sel || !startInput || !endInput) return;
                sel.addEventListener('change', function () {
                    var def = MED_TYPE_DEFAULTS[sel.value];
                    if (def && def.length === 2) {
                        startInput.value = def[0];
                        endInput.value = def[1];
                    } else {
                        // non-stimulant / unknown: no acute appetite window.
                        startInput.value = '';
                        endInput.value = '';
                    }
                });
            });
        })();
        </script>
        <?php endif; ?>
    </main>
</div>

<?php
$content = ob_get_clean();
renderLayout(t('manage_medications'), $content);
?>
