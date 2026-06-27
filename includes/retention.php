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
