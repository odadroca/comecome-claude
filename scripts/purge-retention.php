<?php
/**
 * A15 — retention purge CLI for operators who prefer cron.
 *   php scripts/purge-retention.php          # DRY RUN: print what WOULD be deleted
 *   php scripts/purge-retention.php --apply   # actually delete + write the audit row
 * Uses the configured data_retention_months; a no-op when retention is off.
 */

// CLI-only: this tool DELETES data with --apply; it must never be reachable over the web.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("purge-retention.php is a command-line tool and must not be run over the web.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/retention.php';

$apply  = in_array('--apply', $argv, true);
$db     = getDB();
$months = (int) getSetting('data_retention_months', '0');

if ($months <= 0) { fwrite(STDOUT, "Retention is OFF (data_retention_months=0). Nothing to do.\n"); exit(0); }

$counts = computeRetentionPurge($db, $months);
$total  = array_sum($counts);
fwrite(STDOUT, "Retention: delete rows older than $months months.\n");
foreach ($counts as $t => $n) { fwrite(STDOUT, sprintf("  %-16s %d\n", $t, $n)); }

if (!$apply) { fwrite(STDOUT, "DRY RUN ($total rows would be deleted). Re-run with --apply to execute.\n"); exit(0); }

applyRetentionPurge($db, $months, null);
fwrite(STDOUT, "APPLIED: $total rows deleted; audit row written.\n");
exit(0);
