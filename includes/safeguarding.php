<?php
/**
 * Child-safeguarding detection (A4) — deterministic, mood-only, transparent.
 *
 * Notes are NEVER scanned for detection; they are returned only as context for an
 * already-triggered row. The feature toggle is enforced HERE so every consumer
 * (nav badge, page, any future surface) inherits "off = fully off".
 */
require_once __DIR__ . '/db.php';   // getDB / getSetting / setSetting

/**
 * @param PDO         $db
 * @param string|null $today  YYYY-MM-DD window anchor (injectable for tests; defaults to today).
 * @return array  list of ['user_id'=>int, 'triggers'=>array<array{check_date,mood_level,appetite_level,notes}>]
 */
function computeSafeguardingFlags(PDO $db, ?string $today = null): array {
    if (getSetting('show_safeguarding_alerts', '1') !== '1') {
        return [];
    }

    $today = $today ?? date('Y-m-d');
    $windowStart = date('Y-m-d', strtotime($today . ' -' . (SAFEGUARD_WINDOW_DAYS - 1) . ' days'));

    $stmt = $db->prepare(
        "SELECT dc.user_id, dc.check_date, dc.mood_level, dc.appetite_level, dc.notes
           FROM daily_checkin dc
           JOIN users u ON u.id = dc.user_id
          WHERE u.type = 'child'
            AND dc.mood_level IS NOT NULL
            AND dc.check_date >= ?
            AND dc.check_date <= ?
          ORDER BY dc.user_id, dc.check_date"
    );
    $stmt->execute([$windowStart, $today]);

    $byChild = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byChild[(int) $row['user_id']][] = $row;
    }

    $flags = [];
    foreach ($byChild as $userId => $rows) {
        // Acknowledgment: ignore triggers dated on/before the guardian's review date.
        // An empty/absent value means "never reviewed".
        $reviewedTs   = getSetting('safeguard_reviewed_' . $userId, '');
        $reviewedDate = ($reviewedTs !== null && $reviewedTs !== '') ? substr($reviewedTs, 0, 10) : null;

        $triggers = [];
        $hasCritical = false;
        foreach ($rows as $row) {
            if ($reviewedDate !== null && $row['check_date'] <= $reviewedDate) {
                continue; // already acknowledged
            }
            if ((int) $row['mood_level'] <= SAFEGUARD_MOOD_LOW) {
                $triggers[] = $row;
                if ((int) $row['mood_level'] <= SAFEGUARD_MOOD_CRITICAL) {
                    $hasCritical = true;
                }
            }
        }

        if ($hasCritical || count($triggers) >= SAFEGUARD_LOW_COUNT) {
            $flags[] = ['user_id' => (int) $userId, 'triggers' => $triggers];
        }
    }

    return $flags;
}

/**
 * Record guardian review of a child's current flags. computeSafeguardingFlags()
 * then ignores triggers dated on/before this date until a NEW low-mood check-in arrives.
 *
 * @param string|null $at  optional ISO-8601 timestamp (injectable for deterministic
 *                         tests); defaults to now.
 */
function markSafeguardingReviewed(int $userId, ?string $at = null): void {
    setSetting('safeguard_reviewed_' . $userId, $at ?? gmdate('c'));
}
