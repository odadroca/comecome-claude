<?php
/**
 * ComeCome — field-encryption KEY GENERATOR (Sprint security Phase 4).
 * ===================================================================
 *
 * Writes a fresh, cryptographically-random 32-byte field-encryption key as a
 * base64 PHP `return` file — the key container Phase 5's at-rest field encryption
 * consumes via includes/secrets.php::encryptionKey().
 *
 * SECURITY
 *   - CLI-ONLY. Hard-refuses any web SAPI so it can never run over HTTP.
 *   - It NEVER prints the key. The secret only ever lands in the target file.
 *   - It refuses to OVERWRITE an existing key file (passing --force is required),
 *     because clobbering a key in use makes every already-encrypted field
 *     undecryptable. Generate to a NEW path, migrate, then retire the old one.
 *   - It attempts chmod 0400 on the new file (owner read-only). On Windows / hosts
 *     that ignore POSIX perms this is a best-effort no-op; the operator must still
 *     confirm the file is not web-served (keep it ABOVE public_html).
 *
 * USAGE
 *   php scripts/gen-key.php /home/uXXXXXXXX/private/encryption-key.php
 *   php scripts/gen-key.php /abs/path/encryption-key.php --force   # overwrite
 *
 * AFTER GENERATING
 *   1) chmod 0400 the file (the script tries, but verify on your host).
 *   2) Point the app at it:  define('ENCRYPTION_KEY_FILE', '/abs/path/...php');
 *      in config.local.php (git-ignored) or the above-docroot COMECOME_CONFIG file.
 *   3) NEVER store this file in the same backup archive as the database.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("gen-key.php is a command-line tool and must not be run over the web.\n");
}

$root = dirname(__DIR__);
require_once $root . '/includes/secrets.php';

// --- Parse arguments --------------------------------------------------------
$args  = array_slice($argv, 1);
$force = false;
$target = null;
foreach ($args as $a) {
    if ($a === '--force' || $a === '-f') { $force = true; continue; }
    if ($target === null) { $target = $a; }
}

if ($target === null || $target === '') {
    fwrite(STDERR, "USAGE: php scripts/gen-key.php /absolute/path/encryption-key.php [--force]\n");
    fwrite(STDERR, "       (place the path ABOVE public_html; never commit the result)\n");
    exit(1);
}

if (is_file($target) && !$force) {
    fwrite(STDERR, "ERROR: $target already exists. Refusing to overwrite a key in use.\n");
    fwrite(STDERR, "       Generate to a NEW path, or pass --force if you are certain.\n");
    exit(1);
}

$dir = dirname($target);
if (!is_dir($dir)) {
    fwrite(STDERR, "ERROR: target directory does not exist: $dir\n");
    exit(1);
}

// --- Generate + write the container ----------------------------------------
$b64 = generateEncryptionKeyBase64();

// The container is a PHP file that simply returns the base64 string. Single-quote
// the value (base64 alphabet is quote-safe) so nothing is interpolated.
$contents = "<?php\n"
    . "// ComeCome field-encryption key container (Sprint security Phase 4).\n"
    . "// 32-byte key, base64-encoded. Generated " . date('Y-m-d H:i:s') . ".\n"
    . "// KEEP ABOVE public_html, chmod 0400, NEVER in the DB backup archive, NEVER commit.\n"
    . "return '" . $b64 . "';\n";

// A prior run may have left the file at 0400 (owner read-only), which blocks a
// --force rewrite. Restore owner-write first so the overwrite can proceed.
if (is_file($target)) {
    @chmod($target, 0600);
}

if (file_put_contents($target, $contents, LOCK_EX) === false) {
    fwrite(STDERR, "ERROR: failed to write key file: $target\n");
    exit(1);
}

// Best-effort lock-down to owner-read-only. No-op / partial on Windows + some hosts.
$chmodOk = @chmod($target, 0400);

// --- Verify the freshly-written container actually loads + validates --------
$check = loadEncryptionKeyContainer($target);
if (!$check['ok']) {
    fwrite(STDERR, "ERROR: wrote key file but it failed validation: " . $check['error'] . "\n");
    exit(1);
}

echo "OK: wrote a valid 32-byte field-encryption key container.\n";
echo "    Path : $target\n";
echo "    Perms: " . ($chmodOk ? "chmod 0400 applied" : "chmod 0400 NOT applied (set it manually on this host)") . "\n";
echo "\nNext steps:\n";
echo "  1) Confirm the file is ABOVE public_html and not web-served.\n";
echo "  2) define('ENCRYPTION_KEY_FILE', '$target'); in config.local.php (or the COMECOME_CONFIG file).\n";
echo "  3) Back up this key SEPARATELY from the database (never in the same archive).\n";
exit(0);
