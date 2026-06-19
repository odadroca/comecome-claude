<?php
/**
 * ComeCome — Filesystem PIN-reset recovery tool (Sprint security Phase 0).
 * ==========================================================================
 *
 * WHY THIS EXISTS — the sole-guardian recovery path (critique fix):
 *   Phase 0 force-changes the default '0000' PIN, and Phase 1 adds brute-force
 *   throttling/lockout. On a single-admin install those two controls could, in a
 *   bad first-boot sequence, lock the ONLY guardian out with no in-app way back
 *   in. This script is the out-of-band escape hatch: anyone with filesystem/SSH
 *   access (i.e. the legitimate operator) can reset a guardian PIN directly,
 *   bypassing the web auth surface entirely.
 *
 * SECURITY:
 *   - CLI-ONLY. It hard-refuses to run under any web SAPI, so it can never be
 *     triggered over HTTP even if it lands inside the web root.
 *   - It only ever sets a NEW hashed PIN (password_hash) — it never prints or
 *     stores a plaintext PIN beyond echoing the one you must now type in.
 *   - It refreshes the default-PIN guard afterwards, so resetting TO '0000'
 *     correctly re-arms the in-app force-change gate.
 *
 * USAGE (run from the project root with the bundled/host PHP binary):
 *   php scripts/reset-pin.php                 # reset lowest-id active guardian to a RANDOM 4-digit PIN (printed once)
 *   php scripts/reset-pin.php 1234            # reset that guardian to PIN 1234
 *   php scripts/reset-pin.php 1234 3          # reset the guardian with user id=3 to PIN 1234
 *
 * The chosen PIN must be exactly 4 digits (matches the app's PIN format).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("reset-pin.php is a command-line recovery tool and must not be run over the web.\n");
}

// Load the app's config + DB layer. config.php is safe under CLI: session_start()
// is a harmless no-op-ish call there, and DB_PATH resolves the same as the app.
$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/db.php';

// --- Parse arguments --------------------------------------------------------
$argPin = $argv[1] ?? null;          // optional explicit 4-digit PIN
$argUserId = isset($argv[2]) ? (int) $argv[2] : null; // optional explicit guardian id

// Generate a cryptographically-random 4-digit PIN if none was supplied.
if ($argPin === null) {
    $newPin = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    $generated = true;
} else {
    $newPin = (string) $argPin;
    $generated = false;
}

if (!preg_match('/^[0-9]{4}$/', $newPin)) {
    fwrite(STDERR, "ERROR: PIN must be exactly 4 digits (got: " . $newPin . ").\n");
    exit(1);
}

$db = getDB();

// --- Locate the target guardian --------------------------------------------
if ($argUserId !== null) {
    $stmt = $db->prepare("SELECT id, name FROM users WHERE id = ? AND type = 'guardian'");
    $stmt->execute([$argUserId]);
} else {
    // Default: the lowest-id active guardian (the seeded admin on a fresh DB) —
    // the same row the in-app default-PIN guard evaluates.
    $stmt = $db->query("SELECT id, name FROM users WHERE type = 'guardian' AND active = 1 ORDER BY id LIMIT 1");
}
$guardian = $stmt ? $stmt->fetch() : false;

if (!$guardian) {
    fwrite(STDERR, "ERROR: no matching guardian user found to reset.\n");
    exit(1);
}

// --- Reset the PIN ----------------------------------------------------------
$hashed = password_hash($newPin, PASSWORD_DEFAULT);
$upd = $db->prepare("UPDATE users SET pin = ?, active = 1 WHERE id = ?");
$upd->execute([$hashed, $guardian['id']]);

// Re-derive the in-app default-PIN guard from the new hash (re-arms or clears it).
refreshGuardianPinDefaultFlag($db);

echo "OK: guardian '" . $guardian['name'] . "' (id=" . $guardian['id'] . ") PIN reset.\n";
if ($generated) {
    echo "    New PIN (shown once): " . $newPin . "\n";
}
echo "    Log in with this PIN, then change it from the My Account screen.\n";
exit(0);
