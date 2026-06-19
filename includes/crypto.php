<?php
/**
 * Scoped application-level field encryption (Sprint security Phase 5).
 * ====================================================================
 *
 * INCLUDABLE + SIDE-EFFECT-FREE. Defines functions only — reads NO key and
 * touches NO state at include time. The CLI regression harness (tests/run.php)
 * `require_once`es this file and drives encrypt/decrypt round-trips directly by
 * passing an EXPLICIT raw key (no real secret, no app DB), exactly as it does
 * for includes/secrets.php and includes/session.php's pure helpers.
 *
 * WHAT THIS IS
 * ------------
 * XChaCha20-Poly1305 AEAD field encryption via stock libsodium
 * (sodium_crypto_secretbox, bundled PHP 7.2+ — NO Composer, NO C build, NO deps).
 * Applied to ONLY high-sensitivity free-text / identity columns that are never
 * filtered / aggregated / ordered in SQL:
 *
 *     users.name        daily_checkin.notes
 *     medications.name  medications.dose
 *
 * Numeric / ordinal / date / coded columns stay CLEARTEXT (the dashboard
 * WHERE/ORDER/JOIN/aggregation paths depend on them), and so do gender +
 * date_of_birth (the WHO percentile engine derives age from them — encrypting
 * them is a documented follow-up that must first wire decrypt into the percentile
 * read paths). See SPRINT-SECURITY.md Phase 5.
 *
 * STRICTLY OPT-IN (zero-config safe)
 * ----------------------------------
 * Encryption only engages when a key is configured (includes/secrets.php ->
 * encryptionKey()). With NO key:
 *   - encryptField()  returns the plaintext UNCHANGED (column stays plaintext),
 *   - decryptField()  returns non-encrypted values UNCHANGED,
 * so a fresh download with no key file runs exactly as before. The four columns
 * stay plaintext and the app works.
 *
 * FAIL-CLOSED (never silently write plaintext under a configured key)
 * ------------------------------------------------------------------
 * If a key IS configured but the sodium extension is missing on the host:
 *   - encryptField() THROWS rather than writing plaintext under a key the
 *     operator believes is protecting the data (the catastrophic silent-plaintext
 *     failure the sprint forbids),
 *   - decryptField() THROWS when handed an already-encrypted value it cannot
 *     open, rather than returning ciphertext as if it were plaintext.
 * Deploy doc requires `extension=sodium` + a `php -m | grep sodium` check.
 *
 * CIPHERTEXT ENVELOPE + per-value SENTINEL
 * ----------------------------------------
 * Encrypted values are stored as a printable, self-describing string:
 *
 *     enc:v1:BASE64( nonce[24] || secretbox_ciphertext )
 *
 * The `enc:v1:` PREFIX is the per-value sentinel that makes everything else work:
 *   - decryptField() decrypts ONLY values carrying it and returns everything else
 *     verbatim — so a column mid-backfill (some rows encrypted, some still
 *     plaintext) decrypts transparently (transition-safe),
 *   - the one-time backfill is IDEMPOTENT: an already-`enc:v1:` value is skipped,
 *     so a half-finished run is safely resumable and a re-run is a no-op,
 *   - the `v1` tag leaves room for a future key-rotation / algorithm bump.
 * A fresh per-value random 24-byte nonce (XChaCha20's large nonce space makes
 * random nonces safe) means identical plaintexts yield different ciphertexts —
 * no equality oracle across rows.
 */

/**
 * Per-value ciphertext sentinel / envelope prefix. A short ASCII tag a human note
 * is overwhelmingly unlikely to start with; decryptField() additionally re-validates
 * length + AEAD tag, so a false-positive prefix simply fails closed rather than
 * corrupting data.
 */
if (!defined('COMECOME_ENC_PREFIX')) {
    define('COMECOME_ENC_PREFIX', 'enc:v1:');
}

/**
 * Is the sodium extension actually available for real encrypt/decrypt?
 * Hard requirement on hosts that configure a key. Kept as a one-liner so call
 * sites read clearly.
 */
function sodiumAvailable() {
    return function_exists('sodium_crypto_secretbox')
        && function_exists('sodium_crypto_secretbox_open')
        && defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES');
}

/**
 * Does this stored value carry the encrypted envelope sentinel?
 * Pure string check — never touches a key, so it is safe to call with encryption
 * off (it just returns false for plaintext).
 */
function isEncryptedValue($value) {
    return is_string($value) && strncmp($value, COMECOME_ENC_PREFIX, strlen(COMECOME_ENC_PREFIX)) === 0;
}

/**
 * Encrypt ONE field value for storage.
 *
 * Contract:
 *   - NULL in  -> NULL out (a NULL column stays NULL; nothing to protect).
 *   - No key configured (opt-in OFF) -> returns $plaintext UNCHANGED (plaintext
 *     column, zero-config).
 *   - Already-encrypted input (carries the sentinel) -> returned UNCHANGED, so
 *     double-encryption is impossible and the backfill is idempotent.
 *   - Key configured but sodium missing -> THROWS RuntimeException (fail closed;
 *     never write plaintext under a configured key).
 *   - Otherwise -> 'enc:v1:' . base64(nonce || secretbox(plaintext, nonce, key)).
 *
 * @param string|null $plaintext
 * @param string|null $key  Raw 32-byte key. Defaults to encryptionKey() (the
 *                          configured key, or null when unconfigured). Tests pass
 *                          an explicit raw key so no app config is involved.
 * @return string|null
 */
function encryptField($plaintext, $key = null) {
    if ($plaintext === null) {
        return null;
    }
    $plaintext = (string) $plaintext;

    if ($key === null) {
        // Resolve the configured key (or null). strict=false: an absent key means
        // "encryption off", not an error. A CONFIGURED-but-broken key still returns
        // null here — but secrets.php has already error_log'd it, and the fail-closed
        // guard below only fires once sodium is the missing piece. To be safe against
        // a broken-key + present-sodium combo writing plaintext, we re-check strict
        // when a path is configured.
        if (function_exists('encryptionEnabled') && !encryptionEnabled()
            && function_exists('encryptionKeyFilePath') && encryptionKeyFilePath() !== null) {
            // A key file IS configured but did not yield a usable key -> fail closed.
            throw new RuntimeException(
                'ComeCome: encryption key is configured but unusable; refusing to write plaintext under it.'
            );
        }
        $key = function_exists('encryptionKey') ? encryptionKey(false) : null;
    }

    // Opt-in OFF: no usable key -> store plaintext (zero-config mode).
    if ($key === null || $key === '') {
        return $plaintext;
    }

    // Idempotency: never re-encrypt an already-enveloped value.
    if (isEncryptedValue($plaintext)) {
        return $plaintext;
    }

    // A key IS present but sodium is not -> we MUST NOT silently store plaintext
    // under a configured key. Fail closed, loudly.
    if (!sodiumAvailable()) {
        throw new RuntimeException(
            'ComeCome: an encryption key is configured but the PHP sodium extension is '
            . 'not loaded. Enable extension=sodium (and verify with "php -m | grep sodium"); '
            . 'refusing to write plaintext under a configured key.'
        );
    }

    if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('ComeCome: encryption key has the wrong length.');
    }

    $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);

    return COMECOME_ENC_PREFIX . base64_encode($nonce . $cipher);
}

/**
 * Decrypt ONE stored field value back to plaintext (decrypt-on-read).
 *
 * Transparent + transition-safe:
 *   - NULL in -> NULL out.
 *   - A value WITHOUT the 'enc:v1:' sentinel is returned UNCHANGED — so a
 *     not-yet-backfilled (plaintext) row reads correctly even with the key set,
 *     and EVERY read path works with encryption off.
 *   - A sentinel value WITH a usable key + sodium -> decrypted plaintext.
 *   - A sentinel value but NO usable key, OR sodium missing, OR a tampered /
 *     truncated / wrong-key blob (AEAD tag fails) -> THROWS. We never hand back
 *     ciphertext dressed as plaintext, and a leaked-key/tamper attempt fails
 *     closed rather than corrupting the read.
 *
 * @param string|null $value
 * @param string|null $key  Raw 32-byte key; defaults to the configured key.
 * @return string|null
 */
function decryptField($value, $key = null) {
    if ($value === null) {
        return null;
    }
    if (!is_string($value) || !isEncryptedValue($value)) {
        // Plaintext (or non-string) — pass through untouched.
        return $value;
    }

    // From here on the value claims to be encrypted, so a failure to open it is a
    // hard error (fail closed), never a silent pass-through of ciphertext.
    if ($key === null) {
        $key = function_exists('encryptionKey') ? encryptionKey(false) : null;
    }
    if ($key === null || $key === '') {
        throw new RuntimeException(
            'ComeCome: found an encrypted field but no encryption key is available to '
            . 'decrypt it (is ENCRYPTION_KEY_FILE configured?).'
        );
    }
    if (!sodiumAvailable()) {
        throw new RuntimeException(
            'ComeCome: found an encrypted field but the PHP sodium extension is not '
            . 'loaded; cannot decrypt. Enable extension=sodium.'
        );
    }

    $packed = base64_decode(substr($value, strlen(COMECOME_ENC_PREFIX)), true);
    if ($packed === false
        || strlen($packed) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
        throw new RuntimeException('ComeCome: encrypted field is malformed or truncated.');
    }

    $nonce  = substr($packed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($packed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
    if ($plain === false) {
        // AEAD verification failed: wrong key OR the ciphertext was tampered with.
        throw new RuntimeException('ComeCome: encrypted field failed authentication (wrong key or tampered).');
    }
    return $plain;
}

/**
 * Convenience: decrypt the four scoped columns IN PLACE inside a fetched row
 * (associative array), if present. Used by the central read accessors so a single
 * call covers the common case. Only touches keys that exist on the row, so it is
 * safe to call on any fetched row shape. Non-sentinel (plaintext) values pass
 * through untouched.
 *
 * @param array|false $row  A fetched row (or false from a failed fetch).
 * @param string[]    $cols Column names on this row to decrypt.
 * @return array|false      The same row with the named columns decrypted.
 */
function decryptRowFields($row, array $cols) {
    if (!is_array($row)) {
        return $row;
    }
    foreach ($cols as $c) {
        if (array_key_exists($c, $row)) {
            $row[$c] = decryptField($row[$c]);
        }
    }
    return $row;
}

/**
 * Same as decryptRowFields() but for a list of rows (fetchAll result).
 *
 * @param array    $rows
 * @param string[] $cols
 * @return array
 */
function decryptRowsFields($rows, array $cols) {
    if (!is_array($rows)) {
        return $rows;
    }
    foreach ($rows as &$row) {
        $row = decryptRowFields($row, $cols);
    }
    unset($row);
    return $rows;
}
