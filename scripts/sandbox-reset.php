<?php
/**
 * ComeCome — PUBLIC DEMO SANDBOX full reset (A25). Additive tooling, NOT app code.
 * ============================================================================
 * Restores the public demo to an identical pristine state, erasing ALL visitor
 * changes (extra children, edits, PIN changes):
 *   1. wipe the SQLite DB (file + -wal/-shm),
 *   2. re-seed via db/seed-demo.php (self-initializes a fresh schema + guardian +
 *      the two "(demo)" children + ~90 days of data),
 *   3. set the guardian to the PUBLISHED demo PIN (SANDBOX_GUARDIAN_PIN, default
 *      1425) via scripts/reset-pin.php — which also refreshes the default-PIN guard,
 *      so the in-app force-change-PIN gate stays DISARMED and the published demo
 *      credentials keep working after every reset.
 *
 * Run on a timer by the `reset` sidecar in the docker-compose `sandbox` profile,
 * or from host cron. CLI-ONLY.
 *
 *   !!  NEVER point this at a real deployment — it DESTROYS the database every run.
 *
 * DB path + PIN are read from the environment (COMECOME_DB_PATH, SANDBOX_GUARDIAN_PIN),
 * resolved identically to the app + seeder; the seeder and reset-pin subprocesses
 * inherit the same environment, so all three agree on the target DB.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("sandbox-reset.php is a command-line demo tool and must not be run over the web.\n");
}

$root = dirname(__DIR__);

// Resolve the DB path the same way config.php / seed-demo.php do (env override → default).
$envDbPath = getenv('COMECOME_DB_PATH');
$dbPath = ($envDbPath !== false && $envDbPath !== '') ? $envDbPath : $root . '/db/data.db';

$pin = getenv('SANDBOX_GUARDIAN_PIN');
if ($pin === false || $pin === '') {
    $pin = '1425';
}
if (!preg_match('/^[0-9]{4}$/', $pin)) {
    fwrite(STDERR, "ERROR: SANDBOX_GUARDIAN_PIN must be exactly 4 digits (got: $pin).\n");
    exit(1);
}

fwrite(STDOUT, "[sandbox-reset] wiping " . $dbPath . " ...\n");
foreach (['', '-wal', '-shm'] as $suffix) {
    $f = $dbPath . $suffix;
    if (file_exists($f)) {
        @unlink($f);
    }
}

$php = PHP_BINARY ?: 'php';
$run = function (string $label, string $cmd) {
    fwrite(STDOUT, "[sandbox-reset] $label\n");
    $rc = 0;
    passthru($cmd, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "[sandbox-reset] FAILED (exit $rc): $label\n");
        exit($rc);
    }
};

// 1) Re-seed a fresh DB (seed-demo.php self-initializes the schema + guardian when the file is absent).
$run('seeding demo data', escapeshellarg($php) . ' ' . escapeshellarg($root . '/db/seed-demo.php'));
// 2) Set the published demo guardian PIN (reset-pin.php also refreshes the default-PIN guard → gate disarmed).
$run('setting demo guardian PIN', escapeshellarg($php) . ' ' . escapeshellarg($root . '/scripts/reset-pin.php') . ' ' . escapeshellarg($pin));

fwrite(STDOUT, "[sandbox-reset] done — demo restored; guardian PIN set to the published demo value.\n");
exit(0);
