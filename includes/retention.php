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
 * ON DELETE CASCADE wipes the time-series tables. Writes a PII-free 'child' audit row.
 * @return array per-table counts that were erased.
 */
function eraseChildData(PDO $db, int $childId, ?int $actorId = null): array {
    // The child time-series tables (sleep_interruptions cascades via sleep_log).
    $tables = ['food_log', 'daily_checkin', 'weight_log', 'height_log', 'sleep_log'];
    $counts = [];
    foreach ($tables as $t) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM $t WHERE user_id = ?");
        $stmt->execute([$childId]);
        $counts[$t] = (int) $stmt->fetchColumn();
    }
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
