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

// Generate guest token
if ($selectedChild && isset($_GET['create_token'])) {
    $hours = intval($_GET['hours'] ?? 168); // 7 days default
    $token = generateGuestToken($selectedChild, $hours);
    $guestUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/index.php?page=guest-report&token=' . $token;
    $_SESSION['guest_url'] = $guestUrl;
    $_SESSION['guest_expires'] = $hours;
    header('Location: ?page=export');
    exit;
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

            <?php if (isset($_SESSION['guest_url'])): ?>
            <div class="guest-link-result">
                <p><strong><?php echo t('guest_link_created'); ?></strong></p>
                <p style="font-size:0.875rem;">
                    <?php echo t('guest_link_expires'); ?>: <?php echo $_SESSION['guest_expires']; ?> <?php echo t('guest_link_hours'); ?>
                </p>
                <div class="guest-url-box">
                    <input type="text" readonly value="<?php echo $_SESSION['guest_url']; ?>" id="guestUrl">
                    <button class="btn-secondary" onclick="copyGuestLink()">
                        <?php echo t('copy_link'); ?> 📋
                    </button>
                </div>
            </div>
            <?php unset($_SESSION['guest_url'], $_SESSION['guest_expires']); ?>
            <?php endif; ?>

            <form method="GET" action="" style="margin-top:1rem;">
                <input type="hidden" name="page" value="export">
                <input type="hidden" name="create_token" value="1">

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
