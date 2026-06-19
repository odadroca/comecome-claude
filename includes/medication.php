<?php
/**
 * ComeCome — Medication Timing Foundation (Sprint 9)
 * =============================================================================
 * Medication-schedule CRUD + the SERVER-SIDE med_window classifier.
 *
 * WHAT THIS IS
 *   A guardian configures, per child + medication, the typical dose time and the
 *   peak-effect window (as minute offsets from the dose). At food-log INSERT time
 *   the server compares the log time to the child's ACTIVE schedules and stamps an
 *   invisible `food_log.med_window` (pre_med / onset / mid_med / post_med, or NULL).
 *
 * CHILD BOUNDARY (ABSOLUTELY ZERO child-facing change)
 *   The child logs food exactly as before — same request payload, same UI. The
 *   med_window is computed entirely on the server from the guardian-configured
 *   schedule and never travels back to, nor is shown on, any child surface. All
 *   configuration lives in the guardian "Manage Medications" page.
 *
 * OFFSET-BASED WINDOW MODEL (relative to dose_time = T)
 *   pre_med   : log strictly BEFORE T
 *   onset     : T            ..  T + peak_start_offset   (rising effect)
 *   mid_med   : T + peak_start_offset .. T + peak_end_offset  (peak appetite suppression)
 *   post_med  : strictly AFTER T + peak_end_offset       (rebound)
 *
 * APPROXIMATION DISCLAIMER (clinical honesty)
 *   The default offsets below are POPULATION APPROXIMATIONS, not prescriptions.
 *   Individual response to stimulant medication varies substantially, so the
 *   guardian can override every offset per child. The med-type defaults are
 *   starting points for per-child tuning, surfaced as such in the UI.
 *
 * DEFAULT OFFSETS BY MEDICATION TYPE  (peak_start / peak_end, minutes after dose)
 *   short_acting  (e.g. Ritalina IR / methylphenidate IR) :  30 / 240
 *   long_acting   (e.g. Concerta, Ritalina LA)            :  30 / 480
 *   non_stimulant (e.g. Strattera / atomoxetine)          :  no acute appetite
 *                                                            window → med_window
 *                                                            stays NULL
 *
 *   NOTE: the storage default (DB column DEFAULT) is the offset-based 60/240 the
 *   schema documents; the med-type table above is the UI auto-fill the guardian
 *   sees. A schedule whose med_type is 'non_stimulant' is stored with active=1 but
 *   is intentionally EXCLUDED from window classification (it has no acute appetite
 *   window), so computeMedWindow() returns NULL for a child whose only active
 *   schedule is non-stimulant.
 */

/**
 * Default peak offsets by medication type. Returned as [start, end] minutes, or
 * null for a type that has no acute appetite window (non_stimulant).
 *
 * These are the ONLY place the med-type → offset mapping lives, so the UI auto-fill
 * and the server-side classifier never drift. Explicitly approximations (see file
 * header) — the guardian overrides per child.
 */
function medTypeDefaultOffsets($medType) {
    switch ($medType) {
        case 'short_acting':  return [30, 240];
        case 'long_acting':   return [30, 480];
        case 'non_stimulant': return null;   // no acute appetite window → NULL med_window
        default:              return null;    // unknown type → treat as no window
    }
}

/** The med-type values the UI offers (kept in one place for validation + the form). */
function medTypeOptions() {
    return ['short_acting', 'long_acting', 'non_stimulant'];
}

/**
 * The four window names (CHECK-constrained on food_log.med_window). NULL is a valid
 * fifth state meaning "no active schedule applied / non-stimulant".
 */
function medWindowNames() {
    return ['pre_med', 'onset', 'mid_med', 'post_med'];
}

/* =========================================================================
 * medication_schedules CRUD
 * ========================================================================= */

/**
 * Create a medication schedule for a child + medication.
 *
 * $doseTime is "HH:MM". $medType (short_acting|long_acting|non_stimulant) drives the
 * stored offsets unless explicit $peakStart/$peakEnd overrides are passed. A
 * non_stimulant schedule is stored (so the guardian's intent is recorded) but carries
 * NULL offsets and is skipped by the classifier. Returns the new row id.
 */
function createMedicationSchedule($userId, $medicationId, $doseTime, $medType = 'short_acting', $peakStart = null, $peakEnd = null, $active = 1) {
    $db = getDB();

    [$start, $end] = resolveOffsets($medType, $peakStart, $peakEnd);

    $stmt = $db->prepare("
        INSERT INTO medication_schedules
            (user_id, medication_id, dose_time, med_type, peak_start_offset, peak_end_offset, active)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        (int) $userId, (int) $medicationId, $doseTime, $medType,
        $start, $end, (int) $active
    ]);
    return $db->lastInsertId();
}

/**
 * Update an existing schedule. Re-resolves offsets from the med-type/overrides the
 * same way create does, so editing the type re-applies its defaults unless explicit
 * offsets are supplied.
 */
function updateMedicationSchedule($id, $doseTime, $medType, $peakStart = null, $peakEnd = null, $active = 1) {
    $db = getDB();

    [$start, $end] = resolveOffsets($medType, $peakStart, $peakEnd);

    $stmt = $db->prepare("
        UPDATE medication_schedules
        SET dose_time = ?, med_type = ?, peak_start_offset = ?, peak_end_offset = ?, active = ?
        WHERE id = ?
    ");
    return $stmt->execute([$doseTime, $medType, $start, $end, (int) $active, (int) $id]);
}

/** Delete a schedule by id. */
function deleteMedicationSchedule($id) {
    $db = getDB();
    return $db->prepare("DELETE FROM medication_schedules WHERE id = ?")->execute([(int) $id]);
}

/**
 * All schedules for a child (newest first), joined to the medication name for display.
 * Used by the guardian config page.
 */
function getMedicationSchedules($userId) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT ms.*, m.name AS medication_name, m.dose AS medication_dose
        FROM medication_schedules ms
        JOIN medications m ON ms.medication_id = m.id
        WHERE ms.user_id = ?
        ORDER BY ms.active DESC, ms.dose_time
    ");
    $stmt->execute([(int) $userId]);
    return $stmt->fetchAll();
}

/**
 * ACTIVE schedules for a child, used by the classifier. Non-stimulant schedules are
 * returned too (so callers can see them) but are skipped during classification
 * because their offsets are NULL.
 */
function getActiveMedicationSchedules($userId) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT * FROM medication_schedules
        WHERE user_id = ? AND active = 1
        ORDER BY dose_time
    ");
    $stmt->execute([(int) $userId]);
    return $stmt->fetchAll();
}

/**
 * Internal: resolve the [start, end] offsets to store. Explicit numeric overrides win;
 * otherwise fall back to the med-type defaults. Returns [null, null] for a type with
 * no acute window (non_stimulant) unless the guardian explicitly set offsets.
 */
function resolveOffsets($medType, $peakStart, $peakEnd) {
    $defaults = medTypeDefaultOffsets($medType); // [start,end] or null

    $start = is_numeric($peakStart) ? (int) $peakStart : ($defaults[0] ?? null);
    $end   = is_numeric($peakEnd)   ? (int) $peakEnd   : ($defaults[1] ?? null);

    // Guard against an inverted/zero-width window from manual entry: if both present
    // but end <= start, fall back to the type defaults (or leave NULL when none).
    if ($start !== null && $end !== null && $end <= $start) {
        $start = $defaults[0] ?? null;
        $end   = $defaults[1] ?? null;
    }
    return [$start, $end];
}

/* =========================================================================
 * The med_window classifier (SERVER-SIDE, called at food-log INSERT).
 * ========================================================================= */

/**
 * Classify a food-log time into the child's medication window.
 *
 * Compares $logTime (HH:MM or HH:MM:SS) against the child's ACTIVE schedules and
 * returns the most clinically-salient window, or NULL.
 *
 * Resolution rules:
 *   - Schedules with NULL offsets (non_stimulant / unknown type) are SKIPPED — they
 *     have no acute appetite window, so they never produce a med_window.
 *   - For each remaining schedule, classify the log time relative to its dose time:
 *       before dose                              → pre_med
 *       dose .. dose+peak_start                  → onset
 *       dose+peak_start .. dose+peak_end         → mid_med
 *       after dose+peak_end                      → post_med
 *   - When several schedules apply (multiple doses/day), the SUPPRESSION-relevant
 *     windows win by priority  mid_med > onset > post_med > pre_med  so the stamped
 *     value reflects the strongest appetite context at that moment.
 *
 * Returns one of pre_med|onset|mid_med|post_med, or NULL when the child has no active
 * appetite-affecting schedule (the common case — zero child-facing impact, and a NULL
 * is perfectly valid for the CHECK-constrained column).
 *
 * Times are treated as same-day clock minutes (0..1439). This is an intentional
 * simplification: dose times and meals both live on the same calendar day in this
 * app's flow; overnight wrap is out of scope for the foundation sprint.
 */
function computeMedWindow($userId, $logTime) {
    $schedules = getActiveMedicationSchedules($userId);
    if (empty($schedules)) {
        return null;
    }

    $logMin = clockToMinutes($logTime);
    if ($logMin === null) {
        return null; // unparseable time — degrade to NULL rather than guess
    }

    // Priority: a higher number wins when multiple schedules classify the same moment.
    $priority = ['mid_med' => 4, 'onset' => 3, 'post_med' => 2, 'pre_med' => 1];
    $best = null;

    foreach ($schedules as $sched) {
        $start = $sched['peak_start_offset'];
        $end   = $sched['peak_end_offset'];
        // Skip non-appetite schedules (non_stimulant / unknown → NULL offsets).
        if ($start === null || $end === null || $start === '' || $end === '') {
            continue;
        }
        $doseMin = clockToMinutes($sched['dose_time']);
        if ($doseMin === null) {
            continue;
        }
        $start = (int) $start;
        $end   = (int) $end;

        $window = classifyAgainstDose($logMin, $doseMin, $start, $end);

        if ($best === null || $priority[$window] > $priority[$best]) {
            $best = $window;
        }
    }

    return $best;
}

/**
 * Internal: classify a log minute against a single dose.
 *   < dose                       → pre_med
 *   [dose, dose+start)           → onset
 *   [dose+start, dose+end]       → mid_med   (inclusive of the peak-end boundary)
 *   > dose+end                   → post_med
 *
 * Boundary convention: exactly AT the dose time is onset (the rising window has
 * begun); exactly at dose+peak_start is mid_med; exactly at dose+peak_end is still
 * mid_med; one minute past peak_end is post_med.
 */
function classifyAgainstDose($logMin, $doseMin, $startOffset, $endOffset) {
    if ($logMin < $doseMin) {
        return 'pre_med';
    }
    $peakStartMin = $doseMin + $startOffset;
    $peakEndMin   = $doseMin + $endOffset;

    if ($logMin < $peakStartMin) {
        return 'onset';
    }
    if ($logMin <= $peakEndMin) {
        return 'mid_med';
    }
    return 'post_med';
}

/**
 * Internal: parse "HH:MM" or "HH:MM:SS" into minutes-since-midnight (0..1439), or
 * null when malformed/out of range. Seconds are ignored (minute resolution).
 */
function clockToMinutes($clock) {
    if (!is_string($clock) && !is_numeric($clock)) {
        return null;
    }
    $clock = trim((string) $clock);
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $clock, $m)) {
        return null;
    }
    $h = (int) $m[1];
    $min = (int) $m[2];
    if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
        return null;
    }
    return ($h * 60) + $min;
}
