<?php
/**
 * Guardian - Manage Daily Logs (view/edit/delete food logs for any child)
 */

requireGuardian();
$user = getCurrentUser();
$db = getDB();

// Get all children
$children = getAllUsers('child');
$selectedChild = $_GET['child'] ?? ($children[0]['id'] ?? null);
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$message = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_food_log') {
        $logId = (int) ($_POST['log_id'] ?? 0);
        if ($logId) {
            $stmt = $db->prepare("DELETE FROM food_log WHERE id = ?");
            $stmt->execute([$logId]);
            $message = t('changes_saved');
        }
    } elseif ($action === 'update_food_log') {
        $logId = (int) ($_POST['log_id'] ?? 0);
        $portion = $_POST['portion'] ?? '';
        $mealId = (int) ($_POST['meal_id'] ?? 0);
        if ($logId && in_array($portion, ['little', 'some', 'lot', 'all']) && $mealId) {
            $stmt = $db->prepare("UPDATE food_log SET portion = ?, meal_id = ? WHERE id = ?");
            $stmt->execute([$portion, $mealId, $logId]);
            $message = t('changes_saved');
        }
    } elseif ($action === 'delete_checkin') {
        $checkinId = (int) ($_POST['checkin_id'] ?? 0);
        if ($checkinId) {
            $stmt = $db->prepare("DELETE FROM daily_checkin WHERE id = ?");
            $stmt->execute([$checkinId]);
            $message = t('changes_saved');
        }
    } elseif ($action === 'update_checkin') {
        $checkinId = (int) ($_POST['checkin_id'] ?? 0);
        $appetite = (int) ($_POST['appetite'] ?? 0);
        $mood = (int) ($_POST['mood'] ?? 0);
        $medication = (int) ($_POST['medication'] ?? 0);
        $notes = $_POST['notes'] ?? '';
        if ($checkinId && $appetite >= 1 && $appetite <= 5 && $mood >= 1 && $mood <= 5) {
            $stmt = $db->prepare("UPDATE daily_checkin SET appetite_level = ?, mood_level = ?, medication_taken = ?, notes = ? WHERE id = ?");
            $stmt->execute([$appetite, $mood, $medication, $notes, $checkinId]);
            $message = t('changes_saved');
        }
    } elseif ($action === 'delete_weight') {
        $weightId = (int) ($_POST['weight_id'] ?? 0);
        if ($weightId) {
            $stmt = $db->prepare("DELETE FROM weight_log WHERE id = ?");
            $stmt->execute([$weightId]);
            $message = t('changes_saved');
        }
    }

    header('Location: ?page=manage-logs&child=' . $selectedChild . '&date=' . $selectedDate);
    exit;
}

// Get food logs for selected child/date
$foodLogs = [];
$checkIn = null;
$weightEntry = null;

if ($selectedChild) {
    $foodLogs = getFoodLogByDate($selectedChild, $selectedDate);

    $stmt = $db->prepare("SELECT * FROM daily_checkin WHERE user_id = ? AND check_date = ?");
    $stmt->execute([$selectedChild, $selectedDate]);
    $checkIn = $stmt->fetch();

    $stmt = $db->prepare("SELECT * FROM weight_log WHERE user_id = ? AND log_date = ?");
    $stmt->execute([$selectedChild, $selectedDate]);
    $weightEntry = $stmt->fetch();
}

// Get all meals for the edit dropdown
$stmt = $db->query("SELECT * FROM meals WHERE active = 1 ORDER BY sort_order");
$meals = $stmt->fetchAll();

ob_start();
?>

<div class="guardian-interface">
    <?php include 'nav.php'; ?>

    <main class="container">
        <h1>📋 <?php echo t('manage_logs'); ?></h1>

        <?php if ($message): ?>
        <div class="alert alert-success">
            ✅ <?php echo $message; ?>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <section class="management-section">
            <form method="GET" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:end;">
                <input type="hidden" name="page" value="manage-logs">
                <label style="flex:1;min-width:150px;">
                    <?php echo t('select_child'); ?>
                    <select name="child" onchange="this.form.submit()">
                        <?php foreach ($children as $child): ?>
                        <option value="<?php echo $child['id']; ?>" <?php echo $selectedChild == $child['id'] ? 'selected' : ''; ?>>
                            <?php echo $child['avatar_emoji'] . ' ' . sanitize($child['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label style="flex:1;min-width:150px;">
                    <?php echo t('date'); ?>
                    <input type="date" name="date" value="<?php echo $selectedDate; ?>" onchange="this.form.submit()">
                </label>
                <div style="display:flex;gap:0.5rem;">
                    <a href="?page=manage-logs&child=<?php echo $selectedChild; ?>&date=<?php echo date('Y-m-d', strtotime($selectedDate . ' -1 day')); ?>" class="btn-small">◀</a>
                    <a href="?page=manage-logs&child=<?php echo $selectedChild; ?>&date=<?php echo date('Y-m-d'); ?>" class="btn-small"><?php echo t('today'); ?></a>
                    <a href="?page=manage-logs&child=<?php echo $selectedChild; ?>&date=<?php echo date('Y-m-d', strtotime($selectedDate . ' +1 day')); ?>" class="btn-small">▶</a>
                </div>
            </form>
        </section>

        <!-- Food Logs -->
        <section class="management-section">
            <h2>🍽️ <?php echo t('log_food'); ?> (<?php echo count($foodLogs); ?>)</h2>
            <?php if (empty($foodLogs)): ?>
                <p style="opacity:0.6;"><?php echo t('no_logs_today'); ?></p>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo t('food'); ?></th>
                            <th><?php echo t('meal_name'); ?></th>
                            <th><?php echo t('portion'); ?></th>
                            <th style="width:4rem;"><?php echo t('time'); ?></th>
                            <th><?php echo t('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($foodLogs as $log): ?>
                        <tr>
                            <td><?php echo $log['emoji'] . ' ' . t($log['food_name_key']); ?></td>
                            <td>
                                <form method="POST" style="display:inline;" class="inline-edit">
                                    <input type="hidden" name="action" value="update_food_log">
                                    <input type="hidden" name="log_id" value="<?php echo $log['id']; ?>">
                                    <input type="hidden" name="portion" value="<?php echo $log['portion']; ?>">
                                    <select name="meal_id" onchange="this.form.submit()" style="margin:0;padding:0.25rem;">
                                        <?php foreach ($meals as $m): ?>
                                        <option value="<?php echo $m['id']; ?>" <?php echo $m['id'] == $log['meal_id'] ? 'selected' : ''; ?>>
                                            <?php echo t($m['name_key']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td>
                                <form method="POST" style="display:inline;" class="inline-edit">
                                    <input type="hidden" name="action" value="update_food_log">
                                    <input type="hidden" name="log_id" value="<?php echo $log['id']; ?>">
                                    <input type="hidden" name="meal_id" value="<?php echo $log['meal_id']; ?>">
                                    <select name="portion" onchange="this.form.submit()" style="margin:0;padding:0.25rem;">
                                        <option value="little" <?php echo $log['portion'] === 'little' ? 'selected' : ''; ?>><?php echo t('portion_little'); ?></option>
                                        <option value="some" <?php echo $log['portion'] === 'some' ? 'selected' : ''; ?>><?php echo t('portion_some'); ?></option>
                                        <option value="lot" <?php echo $log['portion'] === 'lot' ? 'selected' : ''; ?>><?php echo t('portion_lot'); ?></option>
                                        <option value="all" <?php echo $log['portion'] === 'all' ? 'selected' : ''; ?>><?php echo t('portion_all'); ?></option>
                                    </select>
                                </form>
                            </td>
                            <td style="font-size:0.875rem;"><?php echo substr($log['log_time'], 0, 5); ?></td>
                            <td>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo t('delete_confirmation'); ?>')">
                                    <input type="hidden" name="action" value="delete_food_log">
                                    <input type="hidden" name="log_id" value="<?php echo $log['id']; ?>">
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

        <!-- Check-in -->
        <section class="management-section">
            <h2>😊 Check-in</h2>
            <?php if (!$checkIn): ?>
                <p style="opacity:0.6;"><?php echo t('no_data'); ?></p>
            <?php else: ?>
            <form method="POST">
                <input type="hidden" name="action" value="update_checkin">
                <input type="hidden" name="checkin_id" value="<?php echo $checkIn['id']; ?>">
                <div class="form-grid">
                    <label>
                        <?php echo t('appetite'); ?> (1-5)
                        <select name="appetite">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $checkIn['appetite_level'] == $i ? 'selected' : ''; ?>><?php echo $i; ?> <?php echo str_repeat('⭐', $i); ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>
                    <label>
                        <?php echo t('mood'); ?> (1-5)
                        <select name="mood">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $checkIn['mood_level'] == $i ? 'selected' : ''; ?>><?php echo $i; ?> <?php echo ['😢','😕','😐','🙂','😄'][$i-1]; ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>
                    <label>
                        <?php echo t('medication'); ?>
                        <select name="medication">
                            <option value="0" <?php echo $checkIn['medication_taken'] == 0 ? 'selected' : ''; ?>>❌ <?php echo t('no'); ?></option>
                            <option value="1" <?php echo $checkIn['medication_taken'] == 1 ? 'selected' : ''; ?>>✅ <?php echo t('yes'); ?></option>
                        </select>
                    </label>
                    <label>
                        <?php echo t('notes'); ?>
                        <input type="text" name="notes" value="<?php echo sanitize($checkIn['notes'] ?? ''); ?>">
                    </label>
                </div>
                <div style="display:flex;gap:0.5rem;">
                    <button type="submit" class="btn-primary"><?php echo t('save_changes'); ?></button>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo t('delete_confirmation'); ?>')">
                        <input type="hidden" name="action" value="delete_checkin">
                        <input type="hidden" name="checkin_id" value="<?php echo $checkIn['id']; ?>">
                        <button type="submit" class="btn-small btn-danger">🗑️ <?php echo t('delete'); ?></button>
                    </form>
                </div>
            </form>
            <?php endif; ?>
        </section>

        <!-- Weight -->
        <?php if ($weightEntry): ?>
        <section class="management-section">
            <h2>⚖️ <?php echo t('weight'); ?></h2>
            <p><?php echo $weightEntry['weight_kg']; ?> kg</p>
            <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo t('delete_confirmation'); ?>')">
                <input type="hidden" name="action" value="delete_weight">
                <input type="hidden" name="weight_id" value="<?php echo $weightEntry['id']; ?>">
                <button type="submit" class="btn-small btn-danger">🗑️ <?php echo t('delete'); ?></button>
            </form>
        </section>
        <?php endif; ?>
    </main>
</div>

<?php
$content = ob_get_clean();
renderLayout(t('manage_logs'), $content);
?>
