<?php
/**
 * Secrets / encryption-key container loader (Sprint security Phase 4).
 * ===================================================================
 *
 * INCLUDABLE + SIDE-EFFECT-FREE. This file defines functions ONLY — it does NOT
 * read a key, touch the filesystem, or emit anything at include time. That is
 * deliberate: the CLI regression harness (tests/run.php) can `require_once` this
 * file and assert the key-container validation logic directly (it passes an
 * explicit path so no real secret is involved), exactly as it does for
 * includes/session.php's pure helpers.
 *
 * WHAT THIS IS (and is NOT)
 * -------------------------
 * Phase 4 builds the KEY CONTAINER — how the field-encryption key is stored,
 * located, loaded, and VALIDATED. It does NOT encrypt/decrypt anything; that is
 * Phase 5 (includes/crypto.php), which will call encryptionKey() to obtain the
 * raw 32-byte key. Keeping the container here, separate from the cipher, means:
 *   - the loader is testable on a PHP build WITHOUT the sodium extension
 *     (validation is pure base64 + length math), and
 *   - field encryption stays strictly OPT-IN: with NO key configured,
 *     encryptionKey() returns null and the app runs zero-config in plaintext.
 *
 * KEY CONTAINER FORMAT (precisely specified by the sprint, critique fix)
 * ---------------------------------------------------------------------
 * The field-encryption key is:
 *   - 32 RAW BYTES (the XChaCha20-Poly1305 / sodium_crypto_secretbox key size),
 *   - BASE64-ENCODED,
 *   - returned from a PHP FILE: `<?php return 'BASE64==';`
 *   - which lives ABOVE the web root (public_html) and is chmod 0400.
 *
 * Why a PHP `return` file and NOT an .ini:
 *   parse_ini_file() mangles binary / reserved tokens (`yes`/`no`/`null`,
 *   leading `=`, quotes). A base64 string can contain `=` padding and is exactly
 *   the kind of value .ini parsing corrupts. `require`-ing a PHP file returns the
 *   string verbatim, and a stray HTTP request to a `.php` secret EXECUTES (prints
 *   nothing) rather than serving the secret as text the way a `.ini`/`.txt` would.
 *
 * FAIL-CLOSED CONTRACT
 * --------------------
 * loadEncryptionKeyContainer() returns a structured result; encryptionKey()
 * distills it to: a valid 32-byte raw key, OR null. It NEVER returns a
 * wrong-length / malformed key. If a key file IS configured but is broken
 * (missing, unreadable, non-string, bad base64, wrong length), that is a
 * MISCONFIGURATION the operator must fix — encryptionKey($strict=true) throws so
 * Phase 5 fails LOUD rather than silently writing data under a bad/half key.
 * With no key configured at all (the zero-config default), it simply returns null
 * and the caller stays in plaintext mode.
 */

/**
 * The required raw key length in bytes. This is sodium_crypto_secretbox's key
 * size; we hard-code the literal so the loader still validates on a PHP build
 * that lacks the sodium extension (this very dev binary does), then cross-check
 * against the real constant when it IS present so the two can never drift.
 */
if (!defined('COMECOME_ENC_KEY_BYTES')) {
    define('COMECOME_ENC_KEY_BYTES', 32);
}

/**
 * Resolve the configured key-file path, or null if encryption is not configured.
 *
 * Precedence (first hit wins), mirroring DB_PATH's config.local > env > default:
 *   1. define('ENCRYPTION_KEY_FILE', '/abs/above/docroot/encryption-key.php')
 *      — set in config.local.php or the above-docroot config (Phase 4 loader).
 *   2. getenv('COMECOME_KEY_FILE') — absolute path via the environment.
 *   3. null — NOT configured. Encryption stays off (zero-config plaintext).
 *
 * There is deliberately NO in-tree default: a key must never live inside the web
 * root, so we refuse to invent a path under it. The operator opts in explicitly
 * by pointing this ABOVE public_html.
 *
 * @return string|null absolute-ish path string, or null when unconfigured.
 */
function encryptionKeyFilePath() {
    if (defined('ENCRYPTION_KEY_FILE')) {
        $p = (string) ENCRYPTION_KEY_FILE;
        return $p !== '' ? $p : null;
    }
    $env = getenv('COMECOME_KEY_FILE');
    if ($env !== false && $env !== '') {
        return (string) $env;
    }
    return null;
}

/**
 * Load + validate the key container. PURE w.r.t. app state: it only reads the
 * given file (or the configured path) and returns a structured verdict. Never
 * throws — the caller decides how strict to be.
 *
 * @param string|null $path  Explicit key-file path (tests pass one); when null,
 *                           falls back to encryptionKeyFilePath().
 * @return array {
 *   configured : bool   — was a key file path configured at all?
 *   ok         : bool   — did a valid 32-byte key load?
 *   key        : ?string— the RAW 32-byte key (only when ok), else null.
 *   error      : ?string— operator-facing reason when configured but !ok.
 *   path       : ?string— the path consulted (for the error message/logs).
 * }
 */
function loadEncryptionKeyContainer($path = null) {
    $result = [
        'configured' => false,
        'ok'         => false,
        'key'        => null,
        'error'      => null,
        'path'       => null,
    ];

    if ($path === null) {
        $path = encryptionKeyFilePath();
    }

    // Not configured at all → zero-config plaintext mode. Not an error.
    if ($path === null || $path === '') {
        return $result;
    }

    $result['configured'] = true;
    $result['path'] = $path;

    // The file must exist and be readable. Anything else is a misconfiguration.
    if (!is_file($path)) {
        $result['error'] = 'key file not found';
        return $result;
    }
    if (!is_readable($path)) {
        $result['error'] = 'key file not readable (check ownership / 0400 perms)';
        return $result;
    }

    // `require` returns whatever the file's `return` statement yields. Wrap so a
    // parse error / fatal in the secret file cannot take the whole app down here.
    $b64 = null;
    try {
        $b64 = require $path;
    } catch (Throwable $e) {
        // Do NOT leak the throwable detail (it could echo a path); log generically.
        error_log('ComeCome encryption key container failed to load: ' . $e->getMessage());
        $result['error'] = 'key file did not return a value';
        return $result;
    }

    if (!is_string($b64)) {
        $result['error'] = 'key file must `return` a base64 string';
        return $result;
    }

    $b64 = trim($b64);
    if ($b64 === '') {
        $result['error'] = 'key file returned an empty string';
        return $result;
    }

    // STRICT base64 decode: reject any stray/whitespace/garbage char rather than
    // silently skipping it (the 4th arg = strict). A mangled key must fail, not
    // half-decode into a wrong key.
    $raw = base64_decode($b64, true);
    if ($raw === false) {
        $result['error'] = 'key is not valid base64';
        return $result;
    }

    $len = strlen($raw);
    if ($len !== COMECOME_ENC_KEY_BYTES) {
        $result['error'] = 'decoded key is ' . $len . ' bytes, expected '
            . COMECOME_ENC_KEY_BYTES;
        return $result;
    }

    // Belt-and-braces: if the sodium extension IS loaded, assert our hard-coded
    // length still equals the library's expectation so they can never drift.
    if (defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES')
        && SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== COMECOME_ENC_KEY_BYTES) {
        $result['error'] = 'internal key-size mismatch vs libsodium';
        return $result;
    }

    $result['ok'] = true;
    $result['key'] = $raw;
    return $result;
}

/**
 * The raw 32-byte field-encryption key, or null when encryption is not
 * configured. This is the function Phase 5's includes/crypto.php calls.
 *
 * @param bool        $strict When true, a CONFIGURED-but-BROKEN key container
 *                            throws a RuntimeException (fail loud — Phase 5 must
 *                            never write under a bad/half key). When false
 *                            (default), a broken container is treated like "no
 *                            key" and returns null, so a misconfiguration cannot
 *                            crash a request that does not actually need crypto.
 * @param string|null $path   Optional explicit path (tests); defaults to the
 *                            configured location.
 * @return string|null raw 32-byte key, or null when unconfigured (or, in
 *                      non-strict mode, when misconfigured).
 */
function encryptionKey($strict = false, $path = null) {
    $c = loadEncryptionKeyContainer($path);

    if ($c['ok']) {
        return $c['key'];
    }

    // Configured but broken: fail closed.
    if ($c['configured'] && $strict) {
        throw new RuntimeException(
            'ComeCome: encryption key is configured but invalid (' . $c['error'] . ').'
        );
    }
    if ($c['configured']) {
        // Non-strict: log it so the operator sees it, but do not hand back a key.
        error_log('ComeCome: encryption key configured but invalid: ' . $c['error']);
    }
    return null;
}

/**
 * Convenience predicate: is a USABLE (valid) field-encryption key available?
 * Phase 5's encrypt-on-write paths gate on this so encryption is strictly opt-in.
 */
function encryptionEnabled() {
    return encryptionKey(false) !== null;
}

/**
 * Generate a fresh, base64-encoded 32-byte key suitable for an encryption-key
 * container file. Pure (no I/O) — scripts/gen-key.php writes the file; tests use
 * the raw value. Uses random_bytes (CSPRNG, stock PHP 7+).
 */
function generateEncryptionKeyBase64() {
    return base64_encode(random_bytes(COMECOME_ENC_KEY_BYTES));
}
