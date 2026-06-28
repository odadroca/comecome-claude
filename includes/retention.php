<?php
/**
 * Data retention & erasure helpers (A15). Pure-ish: every function takes a PDO.
 * The audit trail is PII-FREE — only ids, counts, scope, timestamp are ever written.
 */
require_once __DIR__ . '/db.php';

/**
 * Append a PII-free row to data_deletion_log.
 * @param string   $scope   'child' | 'retention_purge'
 * @param int|null $actorId guardian who acted (null for CLI)
 * @param int|null $targetId child erased (null for retention_purge)
 * @param array    $counts  per-table counts, e.g. ['food_log'=>3,'daily_checkin'=>5]
 */
function writeDeletionAudit(PDO $db, string $scope, ?int $actorId, ?int $targetId, array $counts): void {
    $stmt = $db->prepare(
        "INSERT INTO data_deletion_log (actor_user_id, target_user_id, scope, record_counts)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$actorId, $targetId, $scope, json_encode($counts)]);
}

/**
 * Erase a child and ALL their data (whole-child). Counts are gathered BEFORE the
 * delete (so the audit reflects what was removed), then the users row is deleted and
 * ON DELETE CASCADE wipes ALL referencing tables. Writes a PII-free 'child' audit row.
 *
 * Tables counted (all 9 direct-FK tables via user_id, plus sleep_interruptions via
 * transitive cascade through sleep_log):
 *   food_log, daily_checkin, weight_log, height_log, sleep_log,
 *   user_favorites, user_medications, medication_schedules, guest_tokens,
 *   sleep_interruptions (counted via JOIN to sleep_log — no user_id column).
 *
 * @return array per-table counts that were erased (covers ALL cascaded tables).
 */
function eraseChildData(PDO $db, int $childId, ?int $actorId = null): array {
    // All 9 tables with a direct user_id FK to users(id) ON DELETE CASCADE.
    $tables = [
        'food_log', 'daily_checkin', 'weight_log', 'height_log', 'sleep_log',
        'user_favorites', 'user_medications', 'medication_schedules', 'guest_tokens',
    ];
    $counts = [];
    foreach ($tables as $t) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM $t WHERE user_id = ?");
        $stmt->execute([$childId]);
        $counts[$t] = (int) $stmt->fetchColumn();
    }
    // sleep_interruptions cascades transitively via sleep_log(id) — it has no user_id.
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM sleep_interruptions si
           JOIN sleep_log sl ON sl.id = si.sleep_log_id
          WHERE sl.user_id = ?"
    );
    $stmt->execute([$childId]);
    $counts['sleep_interruptions'] = (int) $stmt->fetchColumn();
    // FK enforcement is OFF globally in getDB(), so deleting the users row would NOT
    // cascade. Every users(id) FK is ON DELETE CASCADE, so enable enforcement just for
    // this delete — it wipes all referencing tables in one statement — then restore it.
    // Only a DELETE runs here, so the FK-loose medication INSERT path is never tripped.
    $db->exec('PRAGMA foreign_keys = ON');
    try {
        $db->prepare("DELETE FROM users WHERE id = ? AND type = 'child'")->execute([$childId]);
    } finally {
        $db->exec('PRAGMA foreign_keys = OFF');
    }

    writeDeletionAudit($db, 'child', $actorId, $childId, $counts);
    return $counts;
}

/**
 * N-months-before $today, CLAMPED to the target month's last valid day.
 * Avoids strtotime()'s month overflow (e.g. 2026-08-31 -6 months would roll Feb 31
 * forward to Mar 3 and purge rows younger than the retention window). Clamping to the
 * last day of the target month is the safe direction: never delete data younger than N months.
 */
function retentionCutoff(string $today, int $months): string {
    $d = DateTime::createFromFormat('Y-m-d', $today) ?: new DateTime($today);
    $day = (int) $d->format('d');
    $d->modify('first day of this month');     // go to day 1 so the month subtraction can't overflow
    $d->modify('-' . $months . ' months');
    $d->setDate((int) $d->format('Y'), (int) $d->format('m'), min($day, (int) $d->format('t')));
    return $d->format('Y-m-d');
}

/** The child time-series tables and their date column for retention. */
function retentionTables(): array {
    return [
        'food_log'      => 'log_date',
        'daily_checkin' => 'check_date',
        'weight_log'    => 'log_date',
        'height_log'    => 'log_date',
        'sleep_log'     => 'log_date',   // sleep_interruptions cascades on sleep_log delete
    ];
}

/** Per-table count of rows OLDER than ($today - $months). Read-only. */
function computeRetentionPurge(PDO $db, int $months, ?string $today = null): array {
    $counts = [];
    foreach (retentionTables() as $t => $col) { $counts[$t] = 0; }
    $counts['sleep_interruptions'] = 0;
    if ($months <= 0) { return $counts; }
    $today  = $today ?? date('Y-m-d');
    $cutoff = retentionCutoff($today, $months);
    foreach (retentionTables() as $t => $col) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM $t WHERE $col < ?");
        $stmt->execute([$cutoff]);
        $counts[$t] = (int) $stmt->fetchColumn();
    }
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM sleep_interruptions si
           JOIN sleep_log sl ON sl.id = si.sleep_log_id
          WHERE sl.log_date < ?"
    );
    $stmt->execute([$cutoff]);
    $counts['sleep_interruptions'] = (int) $stmt->fetchColumn();
    return $counts;
}

/**
 * Opportunistic, once-per-day retention purge. Safe to call on every dashboard load:
 * returns null (no work) when retention is off or already purged today.
 */
function maybeRunRetentionPurge(PDO $db, ?string $today = null): ?array {
    $months = (int) getSetting('data_retention_months', '0');
    if ($months <= 0) { return null; }
    $today = $today ?? date('Y-m-d');
    $last  = getSetting('retention_last_purge_at', '');
    if (is_string($last) && substr($last, 0, 10) === $today) { return null; } // already ran today
    $counts = applyRetentionPurge($db, $months, null, $today);
    setSetting('retention_last_purge_at', $today);
    return $counts;
}

/** Delete rows older than the cutoff, audit the purge, return per-table counts. */
function applyRetentionPurge(PDO $db, int $months, ?int $actorId = null, ?string $today = null): array {
    $today  = $today ?? date('Y-m-d');
    $counts = computeRetentionPurge($db, $months, $today);
    if ($months <= 0 || array_sum($counts) === 0) { return $counts; }
    $cutoff = retentionCutoff($today, $months);
    // Delete sleep_interruptions BEFORE sleep_log so none orphan (FK enforcement is OFF globally).
    $db->prepare(
        "DELETE FROM sleep_interruptions
          WHERE sleep_log_id IN (SELECT id FROM sleep_log WHERE log_date < ?)"
    )->execute([$cutoff]);
    foreach (retentionTables() as $t => $col) {
        $db->prepare("DELETE FROM $t WHERE $col < ?")->execute([$cutoff]);
    }
    writeDeletionAudit($db, 'retention_purge', $actorId, null, $counts);
    return $counts;
}
