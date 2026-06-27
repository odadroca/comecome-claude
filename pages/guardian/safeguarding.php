<?php
/**
 * Guardian — Wellbeing / child-safeguarding (A4). Guardian-only; in-app only.
 */
require_once __DIR__ . '/../../includes/safeguarding.php';

requireGuardian();

// Mark-reviewed POST. Router-only write surface — there is deliberately NO api/
// endpoint for this, so there is no second surface to gate.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('verifyCsrf') && !verifyCsrf()) {
        header('Location: index.php?page=safeguarding');
        exit;
    }
    $childId = (int) ($_POST['child_id'] ?? 0);
    if ($childId > 0) {
        markSafeguardingReviewed($childId);
    }
    header('Location: index.php?page=safeguarding');
    exit;
}

$user  = getCurrentUser();                 // nav.php needs $user
$flags = computeSafeguardingFlags(getDB());

// Resolve decrypted display info via the same pattern the dashboard uses.
$childrenById = [];
foreach (getAllUsers('child') as $c) {
    $childrenById[(int) $c['id']] = $c;
}
$moodEmojis = ['😢', '🙁', '😐', '😊', '🤩'];

ob_start();
?>

<div class="guardian-interface">
    <?php include 'nav.php'; ?>

    <main class="container">
        <h1>🛟 <?php echo t('safeguarding_title'); ?></h1>
        <p style="opacity:0.8;"><?php echo t('safeguarding_intro'); ?></p>

        <?php if (empty($flags)): ?>
            <article><p><?php echo t('safeguarding_none'); ?></p></article>
        <?php else: ?>
            <article class="safeguarding-playbook">
                <p><?php echo t('safeguarding_playbook_1'); ?></p>
                <p><?php echo t('safeguarding_playbook_2'); ?></p>
                <p><?php echo t('safeguarding_playbook_3'); ?></p>
            </article>

            <?php foreach ($flags as $flag):
                $child = $childrenById[$flag['user_id']] ?? null;
                if (!$child) { continue; } ?>
                <article>
                    <h2><?php echo sanitize($child['avatar_emoji']); ?> <?php echo sanitize($child['name']); ?></h2>
                    <table>
                        <thead>
                            <tr>
                                <th><?php echo t('safeguarding_col_date'); ?></th>
                                <th><?php echo t('safeguarding_col_mood'); ?></th>
                                <th><?php echo t('safeguarding_col_appetite'); ?></th>
                                <th><?php echo t('safeguarding_col_notes'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($flag['triggers'] as $row): ?>
                                <tr>
                                    <td><?php echo sanitize($row['check_date']); ?></td>
                                    <td><?php echo $moodEmojis[((int) $row['mood_level']) - 1] ?? ''; ?></td>
                                    <td><?php echo $row['appetite_level'] !== null ? sanitize((string) $row['appetite_level']) : '—'; ?></td>
                                    <td><?php echo ($row['notes'] !== null && $row['notes'] !== '') ? sanitize($row['notes']) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <form method="POST" action="index.php?page=safeguarding">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="child_id" value="<?php echo (int) $flag['user_id']; ?>">
                        <button type="submit"><?php echo t('safeguarding_mark_reviewed'); ?></button>
                    </form>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
</div>

<?php
$content = ob_get_clean();
renderLayout(t('safeguarding_title'), $content);
