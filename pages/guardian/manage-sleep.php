<?php
/**
 * Guardian - Manage Sleep (detailed sleep logs per child)
 */

requireGuardian();
$user = getCurrentUser();
$db = getDB();
$message = '';

$children = getAllUsers('child');
$selectedChildId = $_GET['child'] ?? ($children[0]['id'] ?? null);
$selectedDate = $_GET['date'] ?? date('Y-m-d');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sprint security — every state-changing action requires a valid CSRF
    // token; a forged cross-site POST lacks it and is bounced before any DB write.
    if (function_exists('verifyCsrf') && !verifyCsrf()) {
        header('Location: ?page=manage-sleep&msg=csrf_error');
        exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'save_sleep') {
        $childId = (int) ($_POST['user_id'] ?? 0);
        $date = $_POST['log_date'] ?? date('Y-m-d');
        $type = $_POST['sleep_type'] ?? 'night';
        $start = $_POST['sleep_start'] ?: null;
        $end = $_POST['sleep_end'] ?: null;
        $quality = $_POST['quality'] ?: null;
        $notes = trim($_POST['notes'] ?? '') ?: null;

        if ($childId && in_array($type, ['night', 'nap'])) {
            saveSleepLog($childId, $date, $type, $start, $end, $quality, $notes);
        }
        header('Location: ?page=manage-sleep&child=' . $childId . '&date=' . $date . '&msg=saved');
        exit;

    } elseif ($action === 'delete_sleep') {
        $id = (int) ($_POST['id'] ?? 0);
        $childId = (int) ($_POST['child_id'] ?? 0);
        $date = $_POST['date'] ?? date('Y-m-d');
        if ($id) {
            deleteSleepLog($id);
        }
        header('Location: ?page=manage-sleep&child=' . $childId . '&date=' . $date . '&msg=saved');
        exit;

    } elseif ($action === 'add_interruption') {
        $sleepLogId = (int) ($_POST['sleep_log_id'] ?? 0);
        $wakeTime = $_POST['wake_time'] ?: null;
        $backTime = $_POST['back_to_sleep_time'] ?: null;
        $reason = trim($_POST['reason'] ?? '') ?: null;
        $childId = (int) ($_POST['child_id'] ?? 0);
        $date = $_POST['date'] ?? date('Y-m-d');

        if ($sleepLogId && $wakeTime) {
            saveSleepInterruption($sleepLogId, $wakeTime, $backTime, $reason);
        }
        header('Location: ?page=manage-sleep&child=' . $childId . '&date=' . $date . '&msg=saved');
        exit;

    } elseif ($action === 'delete_interruption') {
        $id = (int) ($_POST['id'] ?? 0);
        $childId = (int) ($_POST['child_id'] ?? 0);
        $date = $_POST['date'] ?? date('Y-m-d');
        if ($id) {
            deleteSleepInterruption($id);
        }
        header('Location: ?page=manage-sleep&child=' . $childId . '&date=' . $date . '&msg=saved');
        exit;
    }
}

if (isset($_GET['msg'])) {
    // A POST without a valid CSRF token redirects here with msg=csrf_error — show
    // an error, NOT the green "saved" notice (the write was blocked, not applied).
    $message = ($_GET['msg'] === 'csrf_error') ? t('error_invalid_request') : t('changes_saved');
}

// Get sleep data for selected child and date
$sleepData = [];
if ($selectedChildId) {
    $sleepData = getSleepByDate($selectedChildId, $selectedDate);
}

ob_start();
?>

<div class="guardian-interface">
    <?php include 'nav.php'; ?>

    <main class="container">
        <h1>😴 <?php echo t('manage_sleep'); ?></h1>

        <?php if ($message): ?>
        <?php $isErrorMsg = ($message === t('error_invalid_request')); ?>
        <div class="alert <?php echo $isErrorMsg ? 'alert-error' : 'alert-success'; ?>">
            <?php echo $isErrorMsg ? '❌' : '✅'; ?> <?php echo $message; ?>
        </div>
        <?php endif; ?>

        <?php if (empty($children)): ?>
        <p style="opacity:0.6;"><?php echo t('no_data'); ?></p>
        <?php else: ?>

        <!-- Child & Date Selector -->
        <section class="management-section">
            <form method="GET" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:end;">
                <input type="hidden" name="page" value="manage-sleep">
                <label style="flex:1;min-width:150px;">
                    <?php echo t('select_child'); ?>
                    <select name="child" onchange="this.form.submit()">
                        <?php foreach ($children as $child): ?>
                        <option value="<?php echo $child['id']; ?>" <?php echo $selectedChildId == $child['id'] ? 'selected' : ''; ?>>
                            <?php echo ($child['avatar_emoji'] ?? '') . ' ' . sanitize($child['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label style="flex:1;min-width:150px;">
                    <?php echo t('select_date'); ?>
                    <input type="date" name="date" value="<?php echo $selectedDate; ?>" onchange="this.form.submit()">
                </label>
            </form>
        </section>

        <!-- Add Night Sleep -->
        <section class="management-section">
            <h2>🌙 <?php echo t('night_sleep'); ?></h2>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save_sleep">
                <input type="hidden" name="user_id" value="<?php echo $selectedChildId; ?>">
                <input type="hidden" name="log_date" value="<?php echo $selectedDate; ?>">
                <input type="hidden" name="sleep_type" value="night">

                <div class="form-grid">
                    <label>
                        <?php echo t('bedtime'); ?>
                        <input type="time" name="sleep_start">
                    </label>
                    <label>
                        <?php echo t('wake_time'); ?>
                        <input type="time" name="sleep_end">
                    </label>
                    <label>
                        <?php echo t('sleep_quality'); ?>
                        <select name="quality">
                            <option value="">—</option>
                            <?php for ($q = 1; $q <= 5; $q++): ?>
                            <option value="<?php echo $q; ?>"><?php echo $q; ?> - <?php echo t('sleep_' . $q); ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>
                </div>
                <label>
                    <?php echo t('notes'); ?>
                    <input type="text" name="notes" placeholder="...">
                </label>
                <button type="submit" class="btn-primary" style="margin-top:0.5rem;"><?php echo t('save'); ?></button>
            </form>
        </section>

        <!-- Add Nap -->
        <section class="management-section">
            <h2>💤 <?php echo t('add_nap'); ?></h2>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save_sleep">
                <input type="hidden" name="user_id" value="<?php echo $selectedChildId; ?>">
                <input type="hidden" name="log_date" value="<?php echo $selectedDate; ?>">
                <input type="hidden" name="sleep_type" value="nap">

                <div class="form-grid">
                    <label>
                        <?php echo t('bedtime'); ?>
                        <input type="time" name="sleep_start">
                    </label>
                    <label>
                        <?php echo t('wake_time'); ?>
                        <input type="time" name="sleep_end">
                    </label>
                    <label>
                        <?php echo t('sleep_quality'); ?>
                        <select name="quality">
                            <option value="">—</option>
                            <?php for ($q = 1; $q <= 5; $q++): ?>
                            <option value="<?php echo $q; ?>"><?php echo $q; ?> - <?php echo t('sleep_' . $q); ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>
                </div>
                <label>
                    <?php echo t('notes'); ?>
                    <input type="text" name="notes" placeholder="...">
                </label>
                <button type="submit" class="btn-primary" style="margin-top:0.5rem;"><?php echo t('save'); ?></button>
            </form>
        </section>

        <!-- Existing Sleep Entries for this Date -->
        <?php if (!empty($sleepData)): ?>
        <section class="management-section">
            <h2>📋 <?php echo $selectedDate; ?></h2>
            <?php foreach ($sleepData as $entry): ?>
            <div style="border:1px solid var(--pico-muted-border-color);border-radius:8px;padding:1rem;margin-bottom:1rem;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
                    <strong>
                        <?php echo $entry['sleep_type'] === 'night' ? '🌙 ' . t('night_sleep') : '💤 ' . t('nap'); ?>
                    </strong>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo t('delete_confirmation'); ?>')">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="delete_sleep">
                        <input type="hidden" name="id" value="<?php echo $entry['id']; ?>">
                        <input type="hidden" name="child_id" value="<?php echo $selectedChildId; ?>">
                        <input type="hidden" name="date" value="<?php echo $selectedDate; ?>">
                        <button type="submit" class="btn-small btn-danger">🗑️</button>
                    </form>
                </div>
                <div style="display:flex;gap:1.5rem;flex-wrap:wrap;font-size:0.9rem;">
                    <?php if ($entry['sleep_start']): ?>
                    <span><?php echo t('bedtime'); ?>: <strong><?php echo $entry['sleep_start']; ?></strong></span>
                    <?php endif; ?>
                    <?php if ($entry['sleep_end']): ?>
                    <span><?php echo t('wake_time'); ?>: <strong><?php echo $entry['sleep_end']; ?></strong></span>
                    <?php endif; ?>
                    <?php if ($entry['quality']): ?>
                    <span><?php echo t('sleep_quality'); ?>: <strong><?php echo $entry['quality']; ?>/5</strong></span>
                    <?php endif; ?>
                    <?php if ($entry['sleep_start'] && $entry['sleep_end']): ?>
                    <?php
                        $start = strtotime($entry['sleep_start']);
                        $end = strtotime($entry['sleep_end']);
                        if ($end < $start) $end += 86400; // next day
                        $diff = $end - $start;
                        $hours = floor($diff / 3600);
                        $mins = floor(($diff % 3600) / 60);
                    ?>
                    <span><?php echo t('sleep_duration'); ?>: <strong><?php echo $hours . t('hours_short') . ' ' . $mins . t('minutes_short'); ?></strong></span>
                    <?php endif; ?>
                </div>
                <?php if ($entry['notes']): ?>
                <p style="font-size:0.85rem;opacity:0.8;margin:0.5rem 0 0;"><?php echo sanitize($entry['notes']); ?></p>
                <?php endif; ?>

                <!-- Interruptions -->
                <?php if (!empty($entry['interruptions'])): ?>
                <div style="margin-top:0.75rem;padding-top:0.5rem;border-top:1px solid var(--pico-muted-border-color);">
                    <strong style="font-size:0.85rem;"><?php echo t('interruptions'); ?>:</strong>
                    <?php foreach ($entry['interruptions'] as $int): ?>
                    <div style="display:flex;align-items:center;gap:0.5rem;font-size:0.85rem;margin-top:0.25rem;">
                        <span>⏰ <?php echo $int['wake_time']; ?></span>
                        <?php if ($int['back_to_sleep_time']): ?>
                        <span>→ <?php echo $int['back_to_sleep_time']; ?></span>
                        <?php endif; ?>
                        <?php if ($int['reason']): ?>
                        <span style="opacity:0.7;">(<?php echo sanitize($int['reason']); ?>)</span>
                        <?php endif; ?>
                        <form method="POST" style="display:inline;">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="delete_interruption">
                            <input type="hidden" name="id" value="<?php echo $int['id']; ?>">
                            <input type="hidden" name="child_id" value="<?php echo $selectedChildId; ?>">
                            <input type="hidden" name="date" value="<?php echo $selectedDate; ?>">
                            <button type="submit" class="btn-small btn-danger" style="padding:0.1rem 0.3rem;font-size:0.75rem;">✕</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Add Interruption Form -->
                <details style="margin-top:0.5rem;">
                    <summary style="font-size:0.85rem;cursor:pointer;"><?php echo t('add_interruption'); ?></summary>
                    <form method="POST" style="margin-top:0.5rem;">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="add_interruption">
                        <input type="hidden" name="sleep_log_id" value="<?php echo $entry['id']; ?>">
                        <input type="hidden" name="child_id" value="<?php echo $selectedChildId; ?>">
                        <input type="hidden" name="date" value="<?php echo $selectedDate; ?>">
                        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:end;">
                            <label style="flex:1;min-width:100px;">
                                <?php echo t('wake_time'); ?>
                                <input type="time" name="wake_time" required>
                            </label>
                            <label style="flex:1;min-width:100px;">
                                <?php echo t('bedtime'); ?>
                                <input type="time" name="back_to_sleep_time">
                            </label>
                            <label style="flex:2;min-width:150px;">
                                <?php echo t('interruption_reason'); ?>
                                <input type="text" name="reason" placeholder="...">
                            </label>
                            <button type="submit" class="btn-small"><?php echo t('save'); ?></button>
                        </div>
                    </form>
                </details>
            </div>
            <?php endforeach; ?>
        </section>
        <?php else: ?>
        <section class="management-section">
            <p style="text-align:center;opacity:0.6;">😴 <?php echo t('no_sleep_data'); ?></p>
        </section>
        <?php endif; ?>

        <?php endif; ?>
    </main>
</div>

<?php
$content = ob_get_clean();
renderLayout(t('manage_sleep'), $content);
?>
