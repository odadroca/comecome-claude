<?php
/**
 * Guardian Consent Screen — Launch Sprint 2, Task 2.
 *
 * Shown once (per CONSENT_NOTICE_VERSION bump) to any guardian who has not yet
 * acknowledged the current version of the privacy/consent notice. On POST the
 * acknowledgement is recorded and the guardian is forwarded to the dashboard.
 * Routing (index.php ?page=consent) is wired in Task 3.
 */

requireGuardian();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        header('Location: index.php?page=consent&msg=csrf_error');
        exit;
    }
    recordGuardianConsent();
    header('Location: index.php');
    exit;
}

ob_start();
?>

<main class="container" style="max-width: 640px; margin: 3rem auto;">
    <article>
        <header>
            <h2><?php echo t('consent_title'); ?></h2>
        </header>

        <p><?php echo t('consent_body'); ?></p>

        <p>
            <a href="PRIVACY.md" target="_blank" rel="noopener noreferrer">
                <?php echo t('consent_privacy_link'); ?>
            </a>
            &nbsp;&middot;&nbsp;
            <a href="DISCLAIMER.md" target="_blank" rel="noopener noreferrer">
                <?php echo t('consent_disclaimer_link'); ?>
            </a>
        </p>

        <form method="POST" action="index.php?page=consent">
            <?php echo csrfField(); ?>
            <button type="submit" class="btn-primary">
                <?php echo t('consent_agree'); ?>
            </button>
        </form>
    </article>
</main>

<?php
$content = ob_get_clean();
renderLayout(t('consent_title'), $content);
?>
