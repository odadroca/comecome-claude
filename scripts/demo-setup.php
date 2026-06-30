<?php
/**
 * ComeCome — DEMO SETUP / RESET (A11 + A25). Additive tooling, NOT app code.
 * ===========================================================================
 * Turns the configured database into a ready-to-EXPLORE demo:
 *   1. (optional --reset) wipe the DB so all visitor changes are erased,
 *   2. seed the demo data            (db/seed-demo.php),
 *   3. set the published demo guardian PIN (scripts/reset-pin.php) — disarms the
 *      force-change-PIN gate,
 *   4. record guardian consent       (recordGuardianConsent) — clears the consent gate.
 *
 * Steps 3+4 are essential: a freshly seeded DB is NOT explorable, because index.php
 * (a) force-changes the default 0000 guardian PIN, then (b) once the PIN is non-default,
 * blocks EVERY page — children included — until guardian consent is recorded. Without
 * this script the advertised demo logins just hit those gates.
 *
 * Used by the docker-compose `demo` profile (additive, run once) and the `sandbox`
 * profile (--reset, on a timer). CLI-ONLY.  NEVER run against a real deployment.
 *
 * The target DB is resolved THROUGH config.php (honouring config.local.php /
 * COMECOME_CONFIG / COMECOME_DB_PATH exactly as the app does), and that single path is
 * used for the wipe + seed + reset-pin + consent, so the four steps cannot diverge.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("demo-setup.php is a command-line demo tool and must not be run over the web.\n");
}

$root = dirname(__DIR__);
$php  = PHP_BINARY ?: 'php';
$reset = in_array('--reset', array_slice($argv, 1), true);

$pin = getenv('SANDBOX_GUARDIAN_PIN');
if ($pin === false || $pin === '') {
    $pin = '1425';
}
if (!preg_match('/^[0-9]{4}$/', $pin)) {
    fwrite(STDERR, "ERROR: SANDBOX_GUARDIAN_PIN must be exactly 4 digits (got: $pin).\n");
    exit(1);
}

// Resolve the canonical DB path the SAME way the app does (config.php → config.local.php /
// COMECOME_CONFIG / COMECOME_DB_PATH), so the wipe + seed + reset-pin + consent all agree.
$cfg = $root . '/config.php';
$probe = escapeshellarg($php) . ' -r ' . escapeshellarg('require ' . var_export($cfg, true) . '; echo "CC_DBPATH=" . DB_PATH . "\n";');
$out = shell_exec($probe . ' 2>/dev/null');
if (!preg_match('/CC_DBPATH=(.+)/', (string) $out, $m)) {
    fwrite(STDERR, "ERROR: could not resolve DB_PATH from config.php.\n");
    exit(1);
}
$dbPath = trim($m[1]);
putenv('COMECOME_DB_PATH=' . $dbPath); // the seed subprocess targets the same file

$run = function (string $label, string $cmd) {
    fwrite(STDOUT, "[demo-setup] $label\n");
    $rc = 0;
    passthru($cmd, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "[demo-setup] FAILED (exit $rc): $label\n");
        exit($rc);
    }
};

if ($reset) {
    fwrite(STDOUT, "[demo-setup] --reset: wiping $dbPath\n");
    foreach (['', '-wal', '-shm'] as $suffix) {
        $f = $dbPath . $suffix;
        if (file_exists($f)) {
            @unlink($f);
        }
    }
}

// 1) Seed the demo data (seed-demo.php self-initializes the schema + guardian when the file is absent).
$run('seeding demo data', escapeshellarg($php) . ' ' . escapeshellarg($root . '/db/seed-demo.php'));
// 2) Set the published demo guardian PIN (reset-pin.php also refreshes the default-PIN guard → gate disarmed).
$run('setting demo guardian PIN', escapeshellarg($php) . ' ' . escapeshellarg($root . '/scripts/reset-pin.php') . ' ' . escapeshellarg($pin));
// 3) Record guardian consent so the consent gate is cleared (dashboard + child pages open).
$consentCode = 'require ' . var_export($cfg, true) . '; require ' . var_export($root . '/includes/auth.php', true) . '; recordGuardianConsent(); echo "consent recorded\n";';
$run('recording guardian consent', escapeshellarg($php) . ' -r ' . escapeshellarg($consentCode));

fwrite(STDOUT, "[demo-setup] done — demo ready (data seeded, guardian PIN set, consent recorded).\n");
exit(0);
