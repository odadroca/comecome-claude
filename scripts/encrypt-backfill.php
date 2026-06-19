<?php
/**
 * ComeCome — scoped field-encryption BACKFILL (Sprint security Phase 5).
 * =====================================================================
 *
 * One-time (idempotent, resumable) migration that encrypts the EXISTING plaintext
 * values in the four scoped columns so an already-populated database catches up to
 * the encrypt-on-write paths added this sprint:
 *
 *     users.name        daily_checkin.notes
 *     medications.name  medications.dose
 *
 * EXCLUDED by design (this sprint): users.gender + users.date_of_birth — the WHO
 * percentile engine derives age from them; encrypting them is a documented
 * follow-up. All numeric/ordinal/date/coded columns stay cleartext.
 *
 * SAFETY MODEL
 * ------------
 *   - CLI-ONLY. Hard-refuses any web SAPI.
 *   - REQUIRES a configured, usable encryption key (ENCRYPTION_KEY_FILE / sodium).
 *     With no key it exits 0 doing nothing (encryption is opt-in — there is nothing
 *     to back-fill). With a configured-but-broken key it fails closed (loud).
 *   - VERIFY-FIRST (default = DRY RUN): for EVERY plaintext value it would encrypt,
 *     it encrypts -> immediately decrypts the fresh ciphertext -> asserts a
 *     BYTE-IDENTICAL round-trip (=== on the raw bytes, so a multibyte pt-PT name
 *     with accented/diacritic characters or an accented note must round-trip
 *     exactly) BEFORE any write happens. Any mismatch aborts the whole run with no
 *     DB change.
 *   - IDEMPOTENT + RESUMABLE: values already carrying the 'enc:v1:' sentinel are
 *     SKIPPED. A half-finished run (process killed mid-way) is safely re-runnable;
 *     a fully-applied run re-runs as a no-op.
 *   - The plaintext "snapshot" is held ONLY in memory for the duration of the
 *     verify+write of each row and is overwritten immediately after; this tool
 *     deliberately NEVER writes a plaintext export to disk (that would defeat the
 *     encryption). Take a normal DB backup BEFORE running if you want a rollback
 *     point — and keep that backup encrypted + off-host, separate from the key.
 *   - Each table is written inside a transaction so a mid-table failure rolls back.
 *
 * USAGE
 *   php scripts/encrypt-backfill.php            # DRY RUN: verify round-trips, write nothing
 *   php scripts/encrypt-backfill.php --apply    # APPLY: after verify, encrypt in place
 *   php scripts/encrypt-backfill.php --apply --quiet
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("encrypt-backfill.php is a command-line tool and must not be run over the web.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/db.php'; // pulls in secrets.php + crypto.php

$apply = in_array('--apply', $argv, true);
$quiet = in_array('--quiet', $argv, true);

function out($msg) { global $quiet; if (!$quiet) { echo $msg; } }

// --- Preconditions ----------------------------------------------------------
// A configured-but-broken key must fail LOUD (never run the app's data through a
// half key). encryptionKey(true) throws in that case.
try {
    $key = encryptionKey(true);
} catch (RuntimeException $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

if ($key === null) {
    out("No encryption key is configured — field encryption is OPT-IN and OFF.\n");
    out("Nothing to back-fill. (Configure ENCRYPTION_KEY_FILE first if you want encryption.)\n");
    exit(0);
}

if (!sodiumAvailable()) {
    fwrite(STDERR, "ERROR: a key is configured but the PHP sodium extension is not loaded.\n");
    fwrite(STDERR, "       Enable extension=sodium (verify: php -m | grep sodium) and retry.\n");
    exit(1);
}

$db = getDB();

// The scoped targets: [table, primary-key column, [value columns...]].
$targets = [
    ['users',        'id', ['name']],
    ['daily_checkin','id', ['notes']],
    ['medications',  'id', ['name', 'dose']],
];

out(($apply ? "APPLY" : "DRY RUN") . " — scoped field-encryption backfill\n");
out(str_repeat('-', 58) . "\n");

$totalToEncrypt = 0;
$totalAlready   = 0;
$totalNull      = 0;
$verifyFailures = [];

// --- PASS 1: verify-first (no writes) ---------------------------------------
// For every value we WOULD encrypt, prove encrypt->decrypt is byte-identical.
foreach ($targets as [$table, $pk, $cols]) {
    $colList = implode(', ', array_merge([$pk], $cols));
    $rows = $db->query("SELECT $colList FROM $table")->fetchAll();
    foreach ($rows as $row) {
        foreach ($cols as $col) {
            $val = $row[$col];
            if ($val === null) { $totalNull++; continue; }
            if (isEncryptedValue($val)) { $totalAlready++; continue; }

            // Verify round-trip on the in-memory plaintext snapshot.
            $cipher = encryptField($val, $key);
            $back   = decryptField($cipher, $key);
            if ($back !== $val) {
                $verifyFailures[] = "$table.$col (id=" . $row[$pk] . ")";
            } else {
                $totalToEncrypt++;
            }
            // Overwrite the in-memory plaintext snapshot immediately.
            $val = $cipher = $back = null;
        }
    }
}

out("To encrypt : $totalToEncrypt value(s)\n");
out("Already enc: $totalAlready value(s) (skipped — idempotent)\n");
out("NULL/empty : $totalNull value(s) (nothing to protect)\n");

if (!empty($verifyFailures)) {
    fwrite(STDERR, "\nABORT: encrypt/decrypt round-trip FAILED for:\n");
    foreach ($verifyFailures as $f) { fwrite(STDERR, "  - $f\n"); }
    fwrite(STDERR, "No changes were written. Investigate before retrying.\n");
    exit(1);
}

if (!$apply) {
    out("\nDRY RUN OK: every value round-trips byte-identically. No DB changes made.\n");
    out("Re-run with --apply to encrypt in place.\n");
    exit(0);
}

if ($totalToEncrypt === 0) {
    out("\nNothing to do — all scoped values are already encrypted. (no-op)\n");
    exit(0);
}

// --- PASS 2: apply (transactional per table) --------------------------------
$written = 0;
foreach ($targets as [$table, $pk, $cols]) {
    $colList = implode(', ', array_merge([$pk], $cols));
    $rows = $db->query("SELECT $colList FROM $table")->fetchAll();

    $db->beginTransaction();
    try {
        foreach ($rows as $row) {
            $sets = [];
            $params = [];
            foreach ($cols as $col) {
                $val = $row[$col];
                if ($val === null || isEncryptedValue($val)) { continue; } // skip NULL + already-enc
                $sets[] = "$col = ?";
                $params[] = encryptField($val, $key);
            }
            if (empty($sets)) { continue; }
            $params[] = $row[$pk];
            $sql = "UPDATE $table SET " . implode(', ', $sets) . " WHERE $pk = ?";
            $db->prepare($sql)->execute($params);
            $written += count($sets);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        fwrite(STDERR, "ERROR while encrypting $table — rolled back: " . $e->getMessage() . "\n");
        exit(1);
    }
}

out("\nAPPLIED: encrypted $written value(s) in place.\n");
out("Re-running this script now is a safe no-op (sentinel-based idempotency).\n");
exit(0);
