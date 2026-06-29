<?php
/**
 * A27 — Child privacy-note modal (one-time, per-child).
 *
 * Rendered ONLY when the current user is a child AND childPrivacyNoteSeen() is false.
 * It is informational only — NOT a gate (the task screen renders behind/after it).
 * The OK! form POSTs to ?page=ack-privacy-note (Task 2 implements the handler).
 *
 * Styling: uses --cc-* theme tokens exclusively. No hardcoded light backgrounds
 * (A21 dark-mode lesson) — legible in both light and dark mode.
 *
 * Self-guard (defence in depth): silently skip if a future page includes this
 * partial without checking the conditions first (caller guards in the four child
 * task pages remain the primary check; this is the fallback).
 */
if (!isChild() || childPrivacyNoteSeen((int) ($user['id'] ?? 0))) { return; }
?>
<?php /* A non-modal <dialog open> renders inline (UA position:absolute, z-index auto), so the
   later-painted food cards covered it. Style it as a fixed, full-viewport overlay above all
   child content. This is safe without showModal()/JS because the modal is a top-level sibling
   in $content (before .child-interface) and its only ancestors are <body>/<html> — neither has
   a transform/filter/backdrop-filter, so position:fixed is viewport-relative and a high z-index
   sits above the auto-z-index cards. The rgba backdrop dims in both light and dark mode; the
   card itself uses --cc-* tokens. */ ?>
<dialog id="privacyNoteModal" open style="
    position: fixed;
    inset: 0;
    z-index: 10000;
    margin: 0;
    max-width: none;
    max-height: none;
    width: 100%;
    height: 100%;
    border: none;
    padding: 1rem;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
">
    <article style="
        max-width: 360px;
        margin: auto;
        background: var(--cc-surface-card);
        color: var(--cc-text-strong);
        border-radius: var(--cc-radius-xl);
        border: 1px solid var(--cc-border);
        box-shadow: var(--cc-shadow-md);
        padding: 1.75rem 1.5rem 1.25rem;
        text-align: center;
    ">
        <h2 style="
            font-size: 1.6rem;
            margin-bottom: 0.75rem;
            color: var(--cc-text-strong);
        "><?php echo htmlspecialchars(t('child_privacy_note_title'), ENT_QUOTES, 'UTF-8'); ?></h2>
        <p style="
            font-size: 1.05rem;
            line-height: 1.55;
            color: var(--cc-text-body);
            margin-bottom: 1.5rem;
        "><?php echo htmlspecialchars(t('child_privacy_note_body'), ENT_QUOTES, 'UTF-8'); ?></p>
        <form method="post" action="index.php?page=ack-privacy-note">
            <?php echo csrfField(); ?>
            <button type="submit" class="btn-primary" style="
                width: 100%;
                padding: 0.85rem;
                font-size: 1.1rem;
                border-radius: var(--cc-radius-lg);
                background: var(--cc-primary);
                color: var(--cc-on-primary);
                border: none;
                cursor: pointer;
            "><?php echo htmlspecialchars(t('child_privacy_note_ok'), ENT_QUOTES, 'UTF-8'); ?></button>
        </form>
    </article>
</dialog>
