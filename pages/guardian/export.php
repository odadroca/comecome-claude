<?php
/**
 * Guardian - Export Data
 */

$user = getCurrentUser();
$children = getAllUsers('child');
$selectedChild = $_GET['child_id'] ?? null;
$format = $_GET['format'] ?? 'html';
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Generate report
if ($selectedChild && isset($_GET['generate'])) {
    $reportData = getReportData($selectedChild, $startDate, $endDate);

    if ($format === 'html') {
        include 'export-html.php';
        exit;
    } elseif ($format === 'csv') {
        include 'export-csv.php';
        exit;
    } elseif ($format === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="comecome-report-' . $reportData['user']['name'] . '-' . date('Y-m-d') . '.json"');
        // Decision (iii): whitelisted projection — NEVER serialize user.pin or
        // internal columns. projectReportForJson() is the single choke-point that
        // keeps later sprints (gender/DOB/percentiles) from auto-leaking.
        echo json_encode(projectReportForJson($reportData), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Sprint security Phase 3 — guest-token actions are now CSRF-protected POSTs
// (creating, revoking and regenerating a shareable clinician link all CHANGE state,
// so they must not be drive-by GETs). A forged cross-site request lacks the token.
$tokenMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token_action'])) {
    if (function_exists('verifyCsrf') && !verifyCsrf()) {
        $tokenMessage = t('error_invalid_request');
    } else {
        $tokenAction = $_POST['token_action'];
        $tokenChild  = (int) ($_POST['child_id'] ?? 0);

        if ($tokenAction === 'create' && $tokenChild) {
            $hours = intval($_POST['hours'] ?? 168); // 7 days default
            $token = generateGuestToken($tokenChild, $hours);
            $scheme = (function_exists('requestIsHttps') && requestIsHttps()) ? 'https'
                    : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
            $guestUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/index.php?page=guest-report&token=' . $token;
            $_SESSION['guest_url'] = $guestUrl;
            $_SESSION['guest_expires'] = $hours;
            header('Location: ?page=export');
            exit;
        } elseif ($tokenAction === 'revoke' && isset($_POST['token'])) {
            revokeGuestToken($_POST['token']);
            header('Location: ?page=export&token_msg=revoked');
            exit;
        } elseif ($tokenAction === 'regenerate' && $tokenChild) {
            // Revoke every outstanding active link for this child, then mint a fresh one.
            revokeAllGuestTokensForUser($tokenChild);
            $hours = intval($_POST['hours'] ?? 168);
            $token = generateGuestToken($tokenChild, $hours);
            $scheme = (function_exists('requestIsHttps') && requestIsHttps()) ? 'https'
                    : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
            $guestUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/index.php?page=guest-report&token=' . $token;
            $_SESSION['guest_url'] = $guestUrl;
            $_SESSION['guest_expires'] = $hours;
            header('Location: ?page=export&token_msg=regenerated');
            exit;
        }
    }
}
if (isset($_GET['token_msg'])) {
    if ($_GET['token_msg'] === 'revoked')      { $tokenMessage = t('guest_link_revoked'); }
    elseif ($_GET['token_msg'] === 'regenerated') { $tokenMessage = t('guest_link_regenerated'); }
}

ob_start();
?>

<div class="guardian-interface">
    <?php include 'nav.php'; ?>

    <main class="container">
        <h1><?php echo t('export_data'); ?></h1>

        <!-- Export Form -->
        <section class="export-section">
            <form method="GET" action="">
                <input type="hidden" name="page" value="export">
                <input type="hidden" name="generate" value="1">

                <div class="form-grid">
                    <label for="childSelect">
                        <?php echo t('select_child'); ?>
                        <select id="childSelect" name="child_id" required>
                            <option value=""><?php echo t('select_child'); ?>...</option>
                            <?php foreach ($children as $child): ?>
                            <option value="<?php echo $child['id']; ?>"
                                    <?php echo $selectedChild == $child['id'] ? 'selected' : ''; ?>>
                                <?php echo $child['avatar_emoji']; ?> <?php echo sanitize($child['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label for="formatSelect">
                        <?php echo t('select_format'); ?>
                        <select id="formatSelect" name="format" required>
                            <option value="html"><?php echo t('format_print'); ?></option>
                            <option value="csv"><?php echo t('format_csv'); ?></option>
                            <option value="json"><?php echo t('format_json'); ?></option>
                        </select>
                    </label>
                </div>

                <div class="form-grid">
                    <label for="startDate">
                        <?php echo t('from_date'); ?>
                        <input type="date" id="startDate" name="start_date" value="<?php echo $startDate; ?>" required>
                    </label>

                    <label for="endDate">
                        <?php echo t('to_date'); ?>
                        <input type="date" id="endDate" name="end_date" value="<?php echo $endDate; ?>" max="<?php echo date('Y-m-d'); ?>" required>
                    </label>
                </div>

                <button type="submit" class="btn-primary">
                    <?php echo t('generate_report'); ?> 📄
                </button>
            </form>
        </section>

        <!-- Guest Link Generation -->
        <section class="export-section" style="margin-top:2rem;">
            <h2><?php echo t('generate_guest_link'); ?></h2>
            <p style="opacity:0.8;font-size:0.875rem;">
                <?php echo t('guest_link_expires'); ?>
            </p>

            <?php if ($tokenMessage): ?>
            <div class="alert alert-<?php echo $tokenMessage === t('error_invalid_request') ? 'error' : 'success'; ?>">
                <?php echo sanitize($tokenMessage); ?>
            </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['guest_url'])): ?>
            <div class="guest-link-result">
                <p><strong><?php echo t('guest_link_created'); ?></strong></p>
                <p style="font-size:0.875rem;">
                    <?php echo t('guest_link_expires'); ?>: <?php echo (int) $_SESSION['guest_expires']; ?> <?php echo t('guest_link_hours'); ?>
                </p>
                <div class="guest-url-box">
                    <input type="text" readonly value="<?php echo sanitize($_SESSION['guest_url']); ?>" id="guestUrl">
                    <button class="btn-secondary" onclick="copyGuestLink()">
                        <?php echo t('copy_link'); ?> 📋
                    </button>
                </div>
            </div>
            <?php unset($_SESSION['guest_url'], $_SESSION['guest_expires']); ?>
            <?php endif; ?>

            <form method="POST" action="" style="margin-top:1rem;">
                <?php echo csrfField(); ?>
                <input type="hidden" name="token_action" value="create">

                <div class="form-grid">
                    <label for="tokenChild">
                        <?php echo t('select_child'); ?>
                        <select id="tokenChild" name="child_id" required>
                            <option value=""><?php echo t('select_child'); ?>...</option>
                            <?php foreach ($children as $child): ?>
                            <option value="<?php echo $child['id']; ?>">
                                <?php echo $child['avatar_emoji']; ?> <?php echo sanitize($child['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label for="tokenHours">
                        <?php echo t('guest_link_expires'); ?>
                        <select id="tokenHours" name="hours" required>
                            <option value="24">24 <?php echo t('guest_link_hours'); ?></option>
                            <option value="72">72 <?php echo t('guest_link_hours'); ?></option>
                            <option value="168" selected>7 dias (168 <?php echo t('guest_link_hours'); ?>)</option>
                            <option value="336">14 dias (336 <?php echo t('guest_link_hours'); ?>)</option>
                        </select>
                    </label>
                </div>

                <button type="submit" class="btn-primary">
                    <?php echo t('create_link'); ?> 🔗
                </button>
            </form>
        </section>

        <!-- Active Guest Links (revoke / regenerate) — Sprint security Phase 3 -->
        <section class="export-section" style="margin-top:2rem;">
            <h2><?php echo t('manage_guest_links'); ?></h2>
            <p style="opacity:0.8;font-size:0.875rem;">
                <?php echo t('manage_guest_links_help'); ?>
            </p>
            <?php
            // Show the active (non-revoked, non-expired) tokens per child so the
            // guardian can kill a shared link early or regenerate all of them.
            $anyActive = false;
            foreach ($children as $child):
                $tokens = getGuestTokensForUser($child['id']);
                $activeTokens = array_filter($tokens, function ($tk) {
                    return ((int) $tk['is_revoked'] === 0) && ((int) $tk['not_expired'] === 1);
                });
                if (count($activeTokens) === 0) { continue; }
                $anyActive = true;
            ?>
            <div class="guest-token-group" style="margin-top:1rem;">
                <h3 style="font-size:1rem;">
                    <?php echo $child['avatar_emoji']; ?> <?php echo sanitize($child['name']); ?>
                    (<?php echo count($activeTokens); ?>)
                </h3>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th><?php echo t('guest_link_created'); ?></th>
                                <th><?php echo t('guest_link_expires'); ?></th>
                                <th><?php echo t('actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeTokens as $tk): ?>
                            <tr>
                                <td><?php echo sanitize(formatDate($tk['created_at'], 'd-m-Y H:i')); ?></td>
                                <td><?php echo sanitize(formatDate($tk['expires_at'], 'd-m-Y H:i')); ?></td>
                                <td style="white-space:nowrap;">
                                    <form method="POST" action="" style="display:inline;"
                                          onsubmit="return confirm('<?php echo t('guest_link_revoke_confirm'); ?>')">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="token_action" value="revoke">
                                        <input type="hidden" name="token" value="<?php echo sanitize($tk['token']); ?>">
                                        <button type="submit" class="btn-small btn-danger">
                                            🚫 <?php echo t('guest_link_revoke'); ?>
                                        </button>
                                    </form>
                                    <form method="POST" action="" style="display:inline;"
                                          onsubmit="return confirm('<?php echo t('guest_link_regenerate_confirm'); ?>')">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="token_action" value="regenerate">
                                        <input type="hidden" name="child_id" value="<?php echo $child['id']; ?>">
                                        <input type="hidden" name="hours" value="168">
                                        <button type="submit" class="btn-small btn-secondary">
                                            🔄 <?php echo t('guest_link_regenerate'); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (!$anyActive): ?>
            <p style="opacity:0.6;"><?php echo t('guest_link_none_active'); ?></p>
            <?php endif; ?>
        </section>
    </main>
</div>

<script>
function copyGuestLink() {
    const input = document.getElementById('guestUrl');
    input.select();
    document.execCommand('copy');
    alert('<?php echo t('copy_link'); ?>!');
}
</script>

<?php
$content = ob_get_clean();
renderLayout(t('export_data'), $content);
?>
