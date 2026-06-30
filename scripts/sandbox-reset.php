<?php
/**
 * ComeCome — DEPRECATED compatibility shim.
 * =============================================================================
 * The sandbox/demo reset logic now lives in scripts/demo-setup.php. This file is
 * kept only so a host-cron set up from the earlier A25 docs —
 *     docker compose exec -T -u www-data comecome php scripts/sandbox-reset.php
 * — keeps wiping/reseeding the public demo instead of silently failing on a
 * missing file. It just forwards to `demo-setup.php --reset` (env such as
 * SANDBOX_GUARDIAN_PIN / COMECOME_DB_PATH is inherited).
 *
 * Prefer calling `php scripts/demo-setup.php --reset` directly; this shim may be
 * removed in a future release.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("sandbox-reset.php is a command-line demo tool and must not be run over the web.\n");
}

fwrite(STDERR, "[sandbox-reset] DEPRECATED — forwarding to demo-setup.php --reset. Update your cron to call demo-setup.php directly.\n");

$rc = 0;
passthru(escapeshellarg(PHP_BINARY ?: 'php') . ' ' . escapeshellarg(__DIR__ . '/demo-setup.php') . ' --reset', $rc);
exit($rc);
