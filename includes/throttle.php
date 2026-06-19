<?php
/**
 * PIN brute-force throttling + lockout (Sprint security Phase 1).
 * ===============================================================
 *
 * THE #1 NAMED THREAT: a 4-digit PIN (10,000-space) over enumerable user_ids with
 * ZERO rate-limiting and a known default. This module adds progressive backoff and
 * a temporary lockout so an online guessing flood is no longer feasible.
 *
 * STORAGE MODEL (critique fix — UPDATE-in-place, NOT insert-per-attempt):
 *   A SINGLE AGGREGATED row per (user_id, ip_bucket) in `login_attempts`, holding
 *   fail_count + window_start + locked_until, UPSERTed in place. One failed PIN =
 *   one UPDATE of one row — never one INSERT per attempt. This matters precisely
 *   because SQLite has a single writer: an insert-per-attempt design would write-
 *   storm the very lock it is meant to defend, under the exact flood it defends
 *   against. The aggregated row keeps the write volume O(1) per attempt.
 *
 * COUNTER POLICY (thresholds resolved in-sprint):
 *   - per-`user_id` is the PRIMARY counter (ip_bucket = '' / the user row).
 *   - per-IP is only a LOOSE ceiling: households behind one NAT IP share an
 *     address, so a tight per-IP limit would let one mistyped sibling self-DoS the
 *     whole family. The per-IP cap is therefore high.
 *   Defaults (see constants): 5 user fails / 15 min => 15 min lock; per-IP 50 / 15
 *   min => 15 min lock. A successful auth CLEARS the user's counter.
 *
 * PRIVACY: a locked state is reported as a distinct `locked` result, but the
 * throttle NEVER reveals whether a given user_id exists — an unknown id is counted
 * and locked exactly like a known one, so failures are indistinguishable.
 *
 * SELF-PRUNE: expired/stale rows are swept opportunistically by
 * pruneLoginAttempts(), piggybacked on the existing cleanExpiredTokens() call in
 * index.php — no cron, matching the guest-token cleanup pattern.
 */

// --- Tunable thresholds -----------------------------------------------------
// Per-user (primary) — tight, the real brute-force control.
if (!defined('THROTTLE_USER_MAX_FAILS'))   define('THROTTLE_USER_MAX_FAILS', 5);
if (!defined('THROTTLE_USER_WINDOW'))      define('THROTTLE_USER_WINDOW', 15 * 60);   // 15 min
if (!defined('THROTTLE_USER_LOCK'))        define('THROTTLE_USER_LOCK', 15 * 60);      // 15 min lock
// Per-IP (loose ceiling) — high, only catches a wide spray across many user_ids
// from one address without self-DoSing a shared-NAT household.
if (!defined('THROTTLE_IP_MAX_FAILS'))     define('THROTTLE_IP_MAX_FAILS', 50);
if (!defined('THROTTLE_IP_WINDOW'))        define('THROTTLE_IP_WINDOW', 15 * 60);
if (!defined('THROTTLE_IP_LOCK'))          define('THROTTLE_IP_LOCK', 15 * 60);

/**
 * PURE backoff/lock decision — side-effect-free so the CLI harness can assert the
 * state machine without a DB. Given the current aggregated counters for ONE bucket
 * and the clock, decide the post-failure state.
 *
 * Window semantics: fails accumulate inside a rolling $window. A failure that
 * arrives AFTER the window has elapsed since $windowStart resets the counter to 1
 * and starts a fresh window (so honest mistypes spread over hours never accrue to a
 * lock). Reaching $maxFails within the window arms a lock for $lockSeconds.
 *
 * @param int      $failCount    fails already recorded in the current window
 * @param int|null $windowStart  unix ts the current window began (null = none yet)
 * @param int|null $lockedUntil  unix ts an existing lock expires (null = none)
 * @param int      $now          current unix ts
 * @param int      $maxFails     threshold within the window
 * @param int      $window       rolling window length (s)
 * @param int      $lockSeconds  lock duration once the threshold is hit (s)
 * @return array{fail_count:int, window_start:int, locked_until:int|null, locked:bool}
 *         the NEW persisted state after recording this failure.
 */
function throttleComputeAfterFailure($failCount, $windowStart, $lockedUntil, $now,
                                     $maxFails, $window, $lockSeconds) {
    $failCount   = (int) $failCount;
    $windowStart = ($windowStart === null || $windowStart === '') ? null : (int) $windowStart;
    $lockedUntil = ($lockedUntil === null || $lockedUntil === '') ? null : (int) $lockedUntil;

    // A still-active lock holds: the failure does not extend it (no infinite
    // re-lock from hammering a locked account), counters are untouched.
    if ($lockedUntil !== null && $lockedUntil > $now) {
        return [
            'fail_count'   => $failCount,
            'window_start' => $windowStart !== null ? $windowStart : $now,
            'locked_until' => $lockedUntil,
            'locked'       => true,
        ];
    }

    // Fresh window if none exists or the prior one has elapsed.
    if ($windowStart === null || ($now - $windowStart) > $window) {
        $windowStart = $now;
        $failCount = 0;
    }

    $failCount += 1;

    $newLockedUntil = null;
    $locked = false;
    if ($failCount >= $maxFails) {
        $newLockedUntil = $now + $lockSeconds;
        $locked = true;
    }

    return [
        'fail_count'   => $failCount,
        'window_start' => $windowStart,
        'locked_until' => $newLockedUntil,
        'locked'       => $locked,
    ];
}

/**
 * PURE lock-active predicate — is a bucket currently locked, given its stored
 * locked_until and the clock? Side-effect free (harness-assertable).
 */
function throttleIsLocked($lockedUntil, $now) {
    if ($lockedUntil === null || $lockedUntil === '') { return false; }
    return ((int) $lockedUntil) > $now;
}

/**
 * Normalize the client IP into a coarse bucket key for the loose per-IP ceiling.
 * Falls back to a constant when no address is available (CLI/tests) so per-IP
 * accounting degrades to a single shared bucket rather than throwing.
 */
function throttleIpBucket($server = null) {
    if ($server === null) { $server = isset($_SERVER) ? $_SERVER : []; }
    $ip = $server['REMOTE_ADDR'] ?? '';
    return $ip !== '' ? (string) $ip : 'cli';
}

/**
 * Fetch the aggregated counters for one bucket, or null if no row exists yet.
 * Bucket is identified by (user_id, ip_bucket); the per-user row uses ip_bucket=''.
 */
function getLoginAttemptRow($db, $userId, $ipBucket) {
    $stmt = $db->prepare(
        "SELECT fail_count, window_start, locked_until
         FROM login_attempts WHERE user_id = ? AND ip_bucket = ?"
    );
    $stmt->execute([(int) $userId, (string) $ipBucket]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * UPSERT the aggregated counters for one bucket IN PLACE (one row, one write).
 * Uses INSERT ... ON CONFLICT (UNIQUE(user_id, ip_bucket)) DO UPDATE so a flood
 * never grows the table — the defining storage invariant of this phase.
 */
function upsertLoginAttempt($db, $userId, $ipBucket, $failCount, $windowStart, $lockedUntil) {
    $stmt = $db->prepare(
        "INSERT INTO login_attempts (user_id, ip_bucket, fail_count, window_start, locked_until)
         VALUES (:uid, :ip, :fc, :ws, :lu)
         ON CONFLICT(user_id, ip_bucket) DO UPDATE SET
            fail_count   = excluded.fail_count,
            window_start = excluded.window_start,
            locked_until = excluded.locked_until"
    );
    $stmt->execute([
        ':uid' => (int) $userId,
        ':ip'  => (string) $ipBucket,
        ':fc'  => (int) $failCount,
        ':ws'  => (int) $windowStart,
        ':lu'  => $lockedUntil === null ? null : (int) $lockedUntil,
    ]);
}

/**
 * Is this (user_id, ip) currently locked out? Checks BOTH the per-user row and the
 * loose per-IP row; either being locked blocks the attempt. Read-only — used as the
 * pre-verify gate AND by the login page to surface the distinct `locked` state.
 *
 * @return bool true if a verify attempt must be refused right now.
 */
function loginIsLockedOut($db, $userId, $ip = null, $now = null) {
    if ($now === null) { $now = time(); }
    $ipBucket = $ip !== null ? (string) $ip : throttleIpBucket();

    $userRow = getLoginAttemptRow($db, $userId, '');
    if ($userRow && throttleIsLocked($userRow['locked_until'], $now)) { return true; }

    $ipRow = getLoginAttemptRow($db, 0, $ipBucket); // per-IP row keyed user_id=0
    if ($ipRow && throttleIsLocked($ipRow['locked_until'], $now)) { return true; }

    return false;
}

/**
 * Record ONE failed PIN attempt against both the per-user (primary) and per-IP
 * (loose) buckets, advancing each state machine and persisting it in place.
 * Returns whether the attempt left the user locked.
 *
 * NOTE: called only AFTER a verify actually failed. A pre-existing lock short-
 * circuits before any verify (see authenticateUser), so this is the post-failure
 * accounting path.
 */
function recordFailedLogin($db, $userId, $ip = null, $now = null) {
    if ($now === null) { $now = time(); }
    $ipBucket = $ip !== null ? (string) $ip : throttleIpBucket();

    // --- per-user (primary, tight) ---
    $userRow = getLoginAttemptRow($db, $userId, '');
    $userNext = throttleComputeAfterFailure(
        $userRow['fail_count']   ?? 0,
        $userRow['window_start'] ?? null,
        $userRow['locked_until'] ?? null,
        $now,
        THROTTLE_USER_MAX_FAILS, THROTTLE_USER_WINDOW, THROTTLE_USER_LOCK
    );
    upsertLoginAttempt($db, $userId, '', $userNext['fail_count'],
                       $userNext['window_start'], $userNext['locked_until']);

    // --- per-IP (loose ceiling) — keyed user_id=0 so it never collides with a
    // real user's primary row ---
    $ipRow = getLoginAttemptRow($db, 0, $ipBucket);
    $ipNext = throttleComputeAfterFailure(
        $ipRow['fail_count']   ?? 0,
        $ipRow['window_start'] ?? null,
        $ipRow['locked_until'] ?? null,
        $now,
        THROTTLE_IP_MAX_FAILS, THROTTLE_IP_WINDOW, THROTTLE_IP_LOCK
    );
    upsertLoginAttempt($db, 0, $ipBucket, $ipNext['fail_count'],
                       $ipNext['window_start'], $ipNext['locked_until']);

    return $userNext['locked'] || $ipNext['locked'];
}

/**
 * Clear the per-user counter on a SUCCESSFUL authentication (the lock/backoff for
 * that user resets to zero). The loose per-IP row is intentionally left intact so a
 * wide spray across many user_ids from one address still accrues toward the ceiling.
 */
function clearFailedLogins($db, $userId) {
    $stmt = $db->prepare("DELETE FROM login_attempts WHERE user_id = ? AND ip_bucket = ''");
    $stmt->execute([(int) $userId]);
}

/**
 * Opportunistic self-prune (piggybacked on cleanExpiredTokens(), no cron): drop
 * rows whose window has fully elapsed AND whose lock (if any) has expired, so the
 * table stays tiny. A row that is neither locked nor inside an active window holds
 * no useful state. Bounded by the larger of the two windows + lock durations.
 */
function pruneLoginAttempts($db, $now = null) {
    if ($now === null) { $now = time(); }
    $maxWindow = max(THROTTLE_USER_WINDOW, THROTTLE_IP_WINDOW);
    $cutoff = $now - $maxWindow;
    $stmt = $db->prepare(
        "DELETE FROM login_attempts
         WHERE (locked_until IS NULL OR locked_until <= ?)
           AND window_start <= ?"
    );
    $stmt->execute([$now, $cutoff]);
    return $stmt->rowCount();
}
