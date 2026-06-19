<?php
/**
 * ComeCome — DEMO-DATA SEEDER  (additive tooling, NOT app code)
 * =============================================================================
 * Populates the configured SQLite DB with a realistic ~90-day (3-month) demo
 * dataset ENDING on today's date, so the running app can be explored live with
 * believable food/growth/sleep/check-in/medication-window data.
 *
 * WHAT IT CREATES (alongside the existing guardian — NEVER touches it):
 *   - TWO demo children, both marked "(demo)" in their name:
 *       * Eduardo (demo) — BOY, gender male,   ~7 years old, PIN 1111
 *       * Sofia (demo)   — GIRL, gender female, ~9 years old, PIN 2222
 *   - ~weekly weight_log + height_log with a gentle upward trajectory, sized so
 *     WHO weight/height-for-age percentiles land in a believable ~P30–P65 band.
 *   - food_log on most days (several varied meals/day), with the log TIME varied
 *     so med_window classification yields a realistic mix.
 *   - A short-acting medication_schedule for the BOY (dose 08:00, offsets 30/240)
 *     so his food_log rows get a stamped med_window. The GIRL has NO schedule, so
 *     her med_window stays NULL (exercises both paths).
 *   - daily_checkin on most days (appetite/mood/medication_taken/sleep_quality),
 *     with a MILD built-in signal: better sleep_quality -> slightly better NEXT
 *     day's appetite, so computeCorrelations() returns enough=true with a
 *     non-trivial association.
 *   - sleep_log (night) most nights with quality + plausible start/end, plus the
 *     occasional sleep_interruptions row.
 *   - Enables guardian toggles show_percentiles=1 and show_sleep_tracking=1 so the
 *     growth + sleep features are visible.
 *
 * SAFETY / IDEMPOTENCY:
 *   - `php db/seed-demo.php --reset` deletes ONLY the two demo children (matched by
 *     their "(demo)" marker names) and their cascade data, then re-seeds.
 *   - Without --reset, if a demo child already exists it is SKIPPED (not
 *     duplicated); only missing demo children are created.
 *   - NEVER deletes the guardian, the food catalog, or any non-demo data.
 *   - Does NOT change schema_version, app pages, or behavior. The Sprint-11
 *     food_growth_tags table is tagged ONLY if it already exists (it is a future
 *     deliverable); this script never creates it (no schema change).
 *
 * USAGE:
 *   php db/seed-demo.php            # create demo children + data (skip if present)
 *   php db/seed-demo.php --reset    # delete demo children + data, then re-seed
 *
 * The target DB is DB_PATH from config.php unless a DB_PATH constant is already
 * defined before this file runs (the verification harness defines a temp path).
 */

// -----------------------------------------------------------------------------
// Bootstrap — dependency-free. We deliberately do NOT include config.php, because
// config.php calls session_start() and auto-initializes the REAL db/data.db on
// include. Instead we define the same DB_* constants ourselves (honoring a
// pre-defined DB_PATH so a test harness can point us at a throwaway temp DB),
// then pull in only the library files we need.
// -----------------------------------------------------------------------------

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "seed-demo.php is a CLI tool; run it from the command line.\n");
    exit(1);
}

date_default_timezone_set('Europe/Lisbon');

$ROOT = dirname(__DIR__);

// DB_PATH resolution order:
//   1. a DB_PATH constant pre-defined before this file is included (in-process harness),
//   2. the COMECOME_DB_PATH environment variable (out-of-process testing override),
//   3. the same path config.php uses (db/data.db) — the real app DB.
// The env override exists ONLY so the verification harness can point a SUBPROCESS at a
// throwaway temp DB; normal runs fall through to the real db/data.db.
if (!defined('DB_PATH')) {
    $envDbPath = getenv('COMECOME_DB_PATH');
    define('DB_PATH', ($envDbPath !== false && $envDbPath !== '') ? $envDbPath : $ROOT . '/db/data.db');
}
if (!defined('DB_SCHEMA')) define('DB_SCHEMA', $ROOT . '/db/schema.sql');
if (!defined('DB_SEED'))   define('DB_SEED', $ROOT . '/db/seed.sql');

require_once $ROOT . '/includes/db.php';        // getDB, initializeDatabase, migrateDatabase, logFood, logWeight, logHeight, saveCheckIn, saveSleepLog, ...
require_once $ROOT . '/includes/auth.php';      // createUser, getUserById
require_once $ROOT . '/includes/helpers.php';   // calculateAgeInMonths, computeCorrelations (pulls in percentiles.php)
// medication.php is pulled in transitively by db.php (createMedicationSchedule, computeMedWindow).

// -----------------------------------------------------------------------------
// Config / constants for this seeder.
// -----------------------------------------------------------------------------

const DEMO_MARKER = '(demo)';                 // name marker that identifies demo children
const DEMO_DAYS   = 90;                        // ~3 months ending today
const BOY_NAME    = 'Eduardo (demo)';
const GIRL_NAME   = 'Sofia (demo)';
const BOY_PIN     = '1111';
const GIRL_PIN    = '2222';

$RESET = in_array('--reset', array_slice($argv, 1), true);

// Deterministic randomness so re-runs against a fresh DB look stable-ish.
mt_srand(20260101);

$TODAY = new DateTimeImmutable('today');       // ends on today's date (PHP date())
$START = $TODAY->sub(new DateInterval('P' . (DEMO_DAYS - 1) . 'D')); // inclusive 90-day span

// -----------------------------------------------------------------------------
// Small helpers.
// -----------------------------------------------------------------------------

/** Pretty console line. */
function say($msg) { echo $msg . "\n"; }

/** A child's DOB string for a target whole-year age as of today. */
function dobForAge($years, $extraDays = 0) {
    $today = new DateTimeImmutable('today');
    // Subtract the years then nudge a few extra days so the age is solidly inside
    // the year (avoids landing exactly on a birthday edge).
    return $today->sub(new DateInterval('P' . $years . 'Y'))
                 ->sub(new DateInterval('P' . $extraDays . 'D'))
                 ->format('Y-m-d');
}

/** Find a demo child's user id by exact marked name, or null. */
function findDemoChildIdByName(PDO $db, $name) {
    $stmt = $db->prepare("SELECT id FROM users WHERE name = ? AND type = 'child'");
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

/** Ensure a single demo medication row exists; return its id. (Catalog-safe: this
 *  is a demo-only medication, not part of the seeded food catalog.) */
function ensureDemoMedicationId(PDO $db) {
    $name = 'Metilfenidato (demo)';
    $stmt = $db->prepare("SELECT id FROM medications WHERE name = ?");
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id !== false) return (int) $id;

    $stmt = $db->prepare("INSERT INTO medications (name, dose) VALUES (?, ?)");
    $stmt->execute([$name, '10mg']);
    return (int) $db->lastInsertId();
}

/** Does a table exist? (Used for the optional Sprint-11 food_growth_tags tagging.) */
function tableExists(PDO $db, $table) {
    $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?");
    $stmt->execute([$table]);
    return $stmt->fetchColumn() !== false;
}

/** Pick a portion weighted toward smaller portions during peak appetite-suppression. */
function pickPortion($medWindowish) {
    // medWindowish: 'mid'/'onset' => leaner; otherwise fuller.
    if ($medWindowish === 'mid') {
        $bag = ['little', 'little', 'some', 'some', 'lot'];
    } elseif ($medWindowish === 'onset') {
        $bag = ['little', 'some', 'some', 'lot', 'all'];
    } else {
        $bag = ['some', 'some', 'lot', 'lot', 'all'];
    }
    return $bag[mt_rand(0, count($bag) - 1)];
}

// -----------------------------------------------------------------------------
// 1) Ensure the DB is initialized + migrated to v5.
// -----------------------------------------------------------------------------

say("ComeCome demo seeder");
say("====================");
say("Target DB: " . DB_PATH);
say($RESET ? "Mode: --reset (delete demo children, then re-seed)" : "Mode: additive (skip existing demo children)");
say("");

if (!file_exists(DB_PATH)) {
    say("DB does not exist yet — initializing schema + seed + migrations...");
    initializeDatabase();
} else {
    // Existing DB: just make sure migrations have run forward to v5.
    migrateDatabase(getDB());
}

// WAL journal mode lets a writer and readers coexist, which avoids spurious
// "database is locked" errors when the app's helper functions (logFood, logWeight,
// createMedicationSchedule, ...) each open their OWN short-lived getDB() connection
// while this script also holds one. WAL is a persistent, additive PRAGMA on the DB
// file — it changes no schema and is exactly how a busy SQLite app should run.
getDB()->exec('PRAGMA journal_mode=WAL');

$db = getDB();
$schemaVersion = (int) (getSetting('schema_version', '1'));
say("schema_version = $schemaVersion (unchanged by this tool)");
say("");

// -----------------------------------------------------------------------------
// 2) --reset: delete ONLY the demo children (by marked name) + cascade data.
//    FK ON DELETE CASCADE handles food_log / weight_log / height_log /
//    daily_checkin / sleep_log / medication_schedules / user_medications, and
//    sleep_interruptions cascade off sleep_log. We delete sleep_interruptions
//    explicitly first too, in case the running build's PRAGMA foreign_keys is OFF.
// -----------------------------------------------------------------------------

if ($RESET) {
    $deleted = [];
    foreach ([BOY_NAME, GIRL_NAME] as $demoName) {
        $cid = findDemoChildIdByName($db, $demoName);
        if ($cid === null) continue;

        // Safety: only ever touch rows we positively identified as a demo child.
        $db->beginTransaction();
        try {
            // sleep_interruptions are linked via sleep_log; remove them first in case
            // foreign_keys enforcement is off in this build.
            $db->prepare("
                DELETE FROM sleep_interruptions
                WHERE sleep_log_id IN (SELECT id FROM sleep_log WHERE user_id = ?)
            ")->execute([$cid]);

            // Best-effort explicit child-scoped deletes (idempotent even with FKs on).
            foreach (['food_log', 'weight_log', 'height_log', 'daily_checkin',
                      'sleep_log', 'medication_schedules', 'user_medications'] as $t) {
                $db->prepare("DELETE FROM $t WHERE user_id = ?")->execute([$cid]);
            }

            // Finally the child row itself.
            $db->prepare("DELETE FROM users WHERE id = ? AND type = 'child'")->execute([$cid]);
            $db->commit();
            $deleted[] = "$demoName (id $cid)";
        } catch (Exception $e) {
            $db->rollBack();
            fwrite(STDERR, "Failed to reset $demoName: " . $e->getMessage() . "\n");
            exit(1);
        }
    }
    // Remove the demo medication if it is now unreferenced (keeps the table tidy;
    // never touches non-demo medications).
    $demoMedName = 'Metilfenidato (demo)';
    $stmt = $db->prepare("SELECT id FROM medications WHERE name = ?");
    $stmt->execute([$demoMedName]);
    $demoMedId = $stmt->fetchColumn();
    if ($demoMedId !== false) {
        $ref = $db->prepare("SELECT COUNT(*) FROM medication_schedules WHERE medication_id = ?
                             UNION ALL SELECT COUNT(*) FROM user_medications WHERE medication_id = ?");
        $ref->execute([$demoMedId, $demoMedId]);
        $counts = $ref->fetchAll(PDO::FETCH_COLUMN);
        if (array_sum(array_map('intval', $counts)) === 0) {
            $db->prepare("DELETE FROM medications WHERE id = ?")->execute([$demoMedId]);
        }
    }

    if ($deleted) {
        say("Reset: deleted demo children + cascade data: " . implode(', ', $deleted));
    } else {
        say("Reset: no existing demo children found (nothing to delete).");
    }
    say("");
}

// -----------------------------------------------------------------------------
// 3) Enable the relevant guardian toggles so features are visible.
//    show_nutrition_insights is intentionally left as-is (Sprint 11 not built).
// -----------------------------------------------------------------------------

setSetting('show_percentiles', '1');
setSetting('show_sleep_tracking', '1');
say("Settings: enabled show_percentiles=1 and show_sleep_tracking=1");
say("          (show_nutrition_insights left as-is — Sprint 11 not built)");
say("");

// -----------------------------------------------------------------------------
// 4) Ensure the two demo children exist (additive; skip if already present).
// -----------------------------------------------------------------------------

/**
 * Demo-child descriptors. Weight/height start+end are sized (against the WHO
 * engine) so weight/height-for-age land in a believable ~P30–P65 band across the
 * window, with a gentle visible upward trajectory.
 */
$children = [
    'boy' => [
        'name'      => BOY_NAME,
        'pin'       => BOY_PIN,
        'avatar'    => '👦',
        'gender'    => 'male',
        'dob'       => dobForAge(7, 40),   // ~7y, solidly inside the year
        'w_start'   => 21.0, 'w_end' => 23.0,   // kg: ~P26 -> ~P51 at 84mo
        'h_start'   => 119.0, 'h_end' => 123.0, // cm: ~P30 -> ~P59 at 84mo
        'has_med'   => true,
    ],
    'girl' => [
        'name'      => GIRL_NAME,
        'pin'       => GIRL_PIN,
        'avatar'    => '👧',
        'gender'    => 'female',
        'dob'       => dobForAge(9, 40),   // ~9y
        'w_start'   => 26.5, 'w_end' => 29.0,   // kg: ~P34 -> ~P56 at 108mo
        'h_start'   => 130.0, 'h_end' => 134.0, // cm: ~P34 -> ~P60 at 108mo
        'has_med'   => false,
    ],
];

$summary = [];

foreach ($children as $key => &$c) {
    $existing = findDemoChildIdByName($db, $c['name']);
    if ($existing !== null) {
        $c['id'] = $existing;
        $c['created'] = false;
        say("Child '{$c['name']}' already exists (id {$existing}) — skipping creation (additive mode).");
        continue;
    }
    $c['id'] = (int) createUser($c['name'], 'child', $c['pin'], $c['avatar'], $c['gender'], $c['dob']);
    $c['created'] = true;
    say("Created child '{$c['name']}' (id {$c['id']}, gender {$c['gender']}, DOB {$c['dob']}, PIN {$c['pin']}).");
}
unset($c);
say("");

// -----------------------------------------------------------------------------
// 5) Medication schedule for the BOY (short-acting, dose 08:00, offsets 30/240).
//    The GIRL gets none, so her food_log med_window stays NULL.
// -----------------------------------------------------------------------------

$boyId = $children['boy']['id'];
$girlId = $children['girl']['id'];

// Only (re)create the schedule if the boy has none active (idempotent top-up).
$hasSchedule = false;
$stmt = $db->prepare("SELECT COUNT(*) FROM medication_schedules WHERE user_id = ? AND active = 1");
$stmt->execute([$boyId]);
$hasSchedule = ((int) $stmt->fetchColumn()) > 0;

if (!$hasSchedule) {
    $medId = ensureDemoMedicationId($db);
    // Link the medication to the boy so report-data med adherence has something to read.
    $db->prepare("INSERT OR IGNORE INTO user_medications (user_id, medication_id) VALUES (?, ?)")
       ->execute([$boyId, $medId]);
    // Short-acting: explicit offsets 30/240 (matches the documented short_acting defaults).
    createMedicationSchedule($boyId, $medId, '08:00', 'short_acting', 30, 240, 1);
    say("Medication: boy '{$children['boy']['name']}' -> short-acting schedule (dose 08:00, peak 30–240 min).");
} else {
    say("Medication: boy already has an active schedule — leaving it as-is.");
}
say("Medication: girl '{$children['girl']['name']}' -> NO schedule (med_window stays NULL).");
say("");

// -----------------------------------------------------------------------------
// 6) Build the ~90-day time series for each child.
//
//    We iterate day-by-day from $START to $TODAY (inclusive). For each child:
//      - weight/height: ~weekly (every 7th day + the final day), interpolated
//        start->end with a touch of noise, backdated explicitly.
//      - food_log: on most days, several meals at varied times.
//      - daily_checkin: most days, with the lag-1 sleep->next-day-appetite signal.
//      - sleep_log (night): most nights, occasional interruptions.
// -----------------------------------------------------------------------------

// Meal layout: meal_id => [hourMin, hourMax] used to vary the log TIME so med_window
// classification spreads across pre_med/onset/mid_med/post_med for the boy.
$mealTimes = [
    1 => [7, 9],    // breakfast  -> mostly pre_med / onset (dose 08:00)
    2 => [10, 11],  // morning snack -> onset / mid_med
    3 => [12, 14],  // lunch      -> mid_med
    4 => [15, 17],  // afternoon snack -> mid_med / post_med
    5 => [18, 20],  // dinner     -> post_med
];

// Candidate foods per meal (by name_key) — varied, drawn from the seed catalog.
$mealFoods = [
    1 => ['food_milk', 'food_cereal', 'food_banana', 'food_bread', 'food_yogurt', 'food_orange', 'food_toast'],
    2 => ['food_apple', 'food_yogurt', 'food_cheese', 'food_grapes', 'food_pear', 'food_juice'],
    3 => ['food_rice', 'food_chicken', 'food_broccoli', 'food_fish', 'food_pasta', 'food_carrot', 'food_potato', 'food_tomato'],
    4 => ['food_cookie', 'food_milk', 'food_strawberry', 'food_cheese', 'food_popcorn', 'food_water'],
    5 => ['food_rice', 'food_meat', 'food_egg', 'food_fish', 'food_broccoli', 'food_potato', 'food_bread', 'food_water'],
];

// Resolve name_key -> food_id once.
$foodIdByKey = [];
$rows = $db->query("SELECT id, name_key FROM foods")->fetchAll();
foreach ($rows as $r) { $foodIdByKey[$r['name_key']] = (int) $r['id']; }

$counts = [
    'weight' => [$boyId => 0, $girlId => 0],
    'height' => [$boyId => 0, $girlId => 0],
    'food'   => [$boyId => 0, $girlId => 0],
    'checkin'=> [$boyId => 0, $girlId => 0],
    'sleep'  => [$boyId => 0, $girlId => 0],
    'interr' => [$boyId => 0, $girlId => 0],
];

// Per-child: remember yesterday's sleep_quality so we can bias TODAY's appetite
// (lag-1 signal: better sleep last night -> slightly better appetite today). This
// is exactly the relationship computeCorrelations() looks for (sleep on D vs
// appetite on D+1).
$prevSleepQuality = [$boyId => null, $girlId => null];

// IMPORTANT (SQLite single-writer): the app's helper functions (logFood, logWeight,
// logHeight, saveCheckIn, saveSleepLog, ...) each open their OWN getDB() connection
// and write through it. If THIS script held a competing open write transaction on a
// second connection, those helper writes would block ("database is locked"). So we
// release our own connection for the duration of the helper-driven loop and let the
// helpers be the sole serial writer. WAL (set above) keeps this efficient.
$db = null;

try {
    for ($dayIndex = 0; $dayIndex < DEMO_DAYS; $dayIndex++) {
        $date = $START->add(new DateInterval('P' . $dayIndex . 'D'));
        $dateStr = $date->format('Y-m-d');
        $frac = (DEMO_DAYS > 1) ? ($dayIndex / (DEMO_DAYS - 1)) : 1.0; // 0..1 across the window
        $isWeekly = ($dayIndex % 7 === 0) || ($dayIndex === DEMO_DAYS - 1);

        foreach ($children as $c) {
            $cid = $c['id'];

            // -- weight + height: ~weekly, interpolated with mild noise, backdated. --
            if ($isWeekly) {
                $w = $c['w_start'] + ($c['w_end'] - $c['w_start']) * $frac;
                $w += (mt_rand(-15, 15) / 100.0);          // ±0.15 kg jitter
                logWeight($cid, round($w, 1), $dateStr);
                $counts['weight'][$cid]++;

                $h = $c['h_start'] + ($c['h_end'] - $c['h_start']) * $frac;
                $h += (mt_rand(-3, 3) / 10.0);             // ±0.3 cm jitter
                logHeight($cid, round($h, 1), $dateStr);
                $counts['height'][$cid]++;
            }

            // -- sleep last night drives today's appetite (lag-1 signal). --
            // Most days have a check-in; skip ~1 in 9 to look realistic.
            $hasCheckin = (mt_rand(1, 9) !== 1);

            // Sleep quality for the night ENDING this morning (1..5). Mild weekly
            // rhythm + noise so there is genuine variance across the 1..5 range.
            $baseSleep = 3 + (int) round(sin($dayIndex / 6.0));   // oscillates ~2..4
            $sleepQuality = max(1, min(5, $baseSleep + mt_rand(-1, 1)));

            if ($hasCheckin) {
                // Appetite biased by LAST night's sleep quality (the lag-1 driver):
                // good sleep yesterday -> appetite leans up today; poor -> leans down.
                $ps = $prevSleepQuality[$cid];
                $appetiteBase = 3;
                if ($ps !== null) {
                    if ($ps >= 4) $appetiteBase = 4;        // after good sleep
                    elseif ($ps <= 2) $appetiteBase = 2;    // after poor sleep
                }
                $appetite = max(1, min(5, $appetiteBase + mt_rand(-1, 1)));
                $mood     = max(1, min(5, $appetiteBase + mt_rand(-1, 1)));
                // Boy takes medication ~85% of days; girl has no med -> 0.
                $medTaken = $c['has_med'] ? (mt_rand(1, 100) <= 85 ? 1 : 0) : 0;

                saveCheckIn($cid, $dateStr, $appetite, $mood, $medTaken, null, $sleepQuality);
                $counts['checkin'][$cid]++;
            }
            // Remember this morning's sleep quality to drive TOMORROW's appetite.
            $prevSleepQuality[$cid] = $sleepQuality;

            // -- sleep_log (night) most nights, with plausible start/end. --
            if (mt_rand(1, 10) !== 1) {
                // Bedtime ~21:00–22:00 the PREVIOUS evening; wake ~06:30–07:30.
                $bedHour = 21 + (mt_rand(0, 59) >= 30 ? 0 : 0);
                $bedMin  = mt_rand(0, 59);
                $prevDay = $date->sub(new DateInterval('P1D'))->format('Y-m-d');
                $start = sprintf('%s %02d:%02d:00', $prevDay, 21, $bedMin);
                $wakeMin = mt_rand(0, 59);
                $wakeHour = (mt_rand(0, 1) === 0) ? 6 : 7;
                $end = sprintf('%s %02d:%02d:00', $dateStr, $wakeHour, $wakeMin);
                $sleepLogId = saveSleepLog($cid, $dateStr, 'night', $start, $end, $sleepQuality, null);
                $counts['sleep'][$cid]++;

                // Occasional interruption (~1 in 5 nights).
                if (mt_rand(1, 5) === 1) {
                    $wt = sprintf('%s %02d:%02d:00', $dateStr, mt_rand(1, 4), mt_rand(0, 59));
                    $bt = sprintf('%s %02d:%02d:00', $dateStr, mt_rand(1, 4), mt_rand(0, 59));
                    saveSleepInterruption($sleepLogId, $wt, $bt, 'woke_up');
                    $counts['interr'][$cid]++;
                }
            }

            // -- food_log on most days (~5 in 6), several varied meals. --
            if (mt_rand(1, 6) !== 1) {
                // Log 3–5 meals/day, choosing 1–2 foods each, with varied times.
                $mealsToday = mt_rand(3, 5);
                $mealIds = array_slice([1, 2, 3, 4, 5], 0, $mealsToday);
                foreach ($mealIds as $mealId) {
                    [$hMin, $hMax] = $mealTimes[$mealId];
                    $foodsForMeal = $mealFoods[$mealId];
                    $nFoods = mt_rand(1, 2);
                    for ($k = 0; $k < $nFoods; $k++) {
                        $foodKey = $foodsForMeal[mt_rand(0, count($foodsForMeal) - 1)];
                        $foodId = $foodIdByKey[$foodKey] ?? null;
                        if ($foodId === null) continue;

                        $hh = mt_rand($hMin, $hMax);
                        $mm = mt_rand(0, 59);
                        $logTime = sprintf('%02d:%02d:00', $hh, $mm);

                        // Hint portion by where the time falls relative to an 08:00 dose
                        // (only matters for appetite realism; med_window is computed
                        // server-side inside logFood()).
                        $minsAfterDose = ($hh * 60 + $mm) - (8 * 60);
                        if ($minsAfterDose >= 30 && $minsAfterDose <= 240) $hint = 'mid';
                        elseif ($minsAfterDose >= 0 && $minsAfterDose < 30) $hint = 'onset';
                        else $hint = 'other';
                        $portion = pickPortion($c['has_med'] ? $hint : 'other');

                        // logFood() stamps med_window via computeMedWindow($cid, $logTime):
                        // non-null for the boy (has schedule), NULL for the girl.
                        logFood($cid, $foodId, $mealId, $portion, $dateStr, $logTime);
                        $counts['food'][$cid]++;
                    }
                }
            }
        }
    }
} catch (Exception $e) {
    fwrite(STDERR, "Seeding failed: " . $e->getMessage() . "\n");
    exit(1);
}

// Re-acquire our own connection now the helper-driven write phase is over.
$db = getDB();

// -----------------------------------------------------------------------------
// 7) OPTIONAL Sprint-11 food_growth_tags tagging — ONLY if the table already
//    exists. This script never CREATES it (that is a future schema change that
//    would bump schema_version and break the regression suite). Create rows only.
// -----------------------------------------------------------------------------

if (tableExists($db, 'food_growth_tags')) {
    // A small, strategic set of demo tags drawn from the specced vocabulary.
    $tags = [
        'food_cheese'  => 'calorie_dense',
        'food_milk'    => 'bone_building',
        'food_chicken' => 'protein_rich',
        'food_egg'     => 'protein_rich',
        'food_banana'  => 'easy_to_eat',
        'food_water'   => 'hydrating',
    ];
    $tagged = 0;
    $ins = $db->prepare("INSERT OR IGNORE INTO food_growth_tags (food_id, tag) VALUES (?, ?)");
    foreach ($tags as $foodKey => $tag) {
        if (isset($foodIdByKey[$foodKey])) {
            $ins->execute([$foodIdByKey[$foodKey], $tag]);
            $tagged++;
        }
    }
    say("food_growth_tags exists — tagged $tagged demo foods (rows only, no schema change).");
} else {
    say("food_growth_tags does not exist (Sprint-11 deliverable) — skipped tagging (no schema change).");
}
say("");

// -----------------------------------------------------------------------------
// 8) Summary.
// -----------------------------------------------------------------------------

say("Summary of demo data created");
say("----------------------------");
$windowEnd = $TODAY->format('Y-m-d');
$windowStart = $START->format('Y-m-d');
say("Window: $windowStart .. $windowEnd (" . DEMO_DAYS . " days, ends today)");
foreach ($children as $c) {
    $cid = $c['id'];
    $tag = $c['created'] ? 'created' : 'existing (skipped creation)';
    say("");
    say("  {$c['name']}  [$tag]  id={$cid}  gender={$c['gender']}  DOB={$c['dob']}  PIN={$c['pin']}");
    say("    weight_log : {$counts['weight'][$cid]} rows");
    say("    height_log : {$counts['height'][$cid]} rows");
    say("    food_log   : {$counts['food'][$cid]} rows");
    say("    daily_checkin : {$counts['checkin'][$cid]} rows");
    say("    sleep_log  : {$counts['sleep'][$cid]} rows ({$counts['interr'][$cid]} interruptions)");
    say("    medication : " . ($c['has_med'] ? 'short-acting schedule (med_window stamped on food_log)' : 'none (med_window stays NULL)'));
}

say("");
say("Login PINs (guardian PIN is unchanged): boy={$children['boy']['name']} -> " . BOY_PIN .
    " | girl={$children['girl']['name']} -> " . GIRL_PIN);
say("");
say("Done. To wipe and re-seed only the demo children:  php db/seed-demo.php --reset");
