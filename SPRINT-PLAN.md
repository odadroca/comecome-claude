# ComeCome v0.9+ Sprint Plan

## Codebase Review Summary

### Architecture
- **Stack**: Plain PHP (no framework), SQLite database, Pico CSS + custom CSS, vanilla JavaScript
- **i18n**: Full internationalization via `locales/*.json` (pt/en)
- **Auth**: PIN-based authentication, two roles: `guardian` and `child`
- **Routing**: Single `index.php` entry point with `?page=` query parameter routing

### Current Features
| Feature | Child Pages | Guardian Pages | API |
|---------|------------|----------------|-----|
| Food Journal | `log-food.php` | `manage-foods.php`, `manage-meals.php` | `api/food-log.php` |
| Check-in (appetite/mood/medication) | `check-in.php` | `dashboard.php` | `api/check-in.php` |
| Weight Log | `weight.php` | `dashboard.php` | `api/weight.php` |
| History | `history.php` | `manage-logs.php`, `export.php` | — |
| Medications | — (via check-in) | `manage-medications.php`, `settings.php` | — |

### Current Settings/Toggle Pattern (Reference: Medications)
The existing medication toggle follows this pattern:
1. **Guardian Settings** (`settings.php:9,14`): A checkbox stores `show_medication_to_children` in the `settings` table via `setSetting()`
2. **Child Check-in** (`check-in.php:10,82`): Reads `getSetting('show_medication_to_children', '1')` and conditionally renders the medication section
3. **Child History** (`history.php:76`): Also checks the same setting to show/hide medication in the daily summary

This is the pattern to replicate for the new feature toggles.

### Current Data Model
- `users`: id, name, type, pin, avatar_emoji, created_at, active
- `meals`: id, name_key, sort_order, time_start, time_end, active
- `food_categories`: id, name_key, sort_order, active
- `meal_categories`: meal_id, category_id (many-to-many)
- `foods`: id, name_key, emoji, category_id, sort_order, active
- `user_favorites`: user_id, food_id, created_at
- `food_log`: id, user_id, food_id, meal_id, portion, log_date, log_time, created_at
- `daily_checkin`: id, user_id, check_date, appetite_level, mood_level, medication_taken, notes, created_at
- `weight_log`: id, user_id, weight_kg, log_date, created_at
- `medications`: id, name, dose, active
- `user_medications`: user_id, medication_id
- `settings`: key, value
- `guest_tokens`: token, user_id, expires_at, created_at

**Notable gaps for planned features**: No `gender`, `date_of_birth`, or `height` fields on `users`. No sleep-related tables. No growth tag or medication timing tables.

---

## SPRINT 0 — Bug Fixes (Foundation)

**Goal**: Fix the two reported bugs before building new features on a flawed foundation.

### Task 0.1 — Bug: Last food item displayed twice in catalog (Item 3)

**Symptom**: On the food catalog displayed for each meal, the last food option always appears duplicated (e.g., 2x pears, 2x pizzas).

**Root cause analysis**: Multiple potential sources:

1. **Favorites + All Foods visual duplication**: In `log-food.php`, when a user has favorites, the page renders TWO grids — a favorites grid (lines 109-120) showing `$mealFavorites`, and the "all foods" grid (lines 135-149) showing the FULL `$foods` array including items already shown in favorites. Any favorited food appears on screen twice. However, the reported pattern ("last food") suggests something else.

2. **SQL/category boundary issue**: The `getFoodsForMeal()` query (`db.php:124-134`) uses `SELECT DISTINCT f.*, fc.name_key as category_name_key`. The `DISTINCT` operates across ALL selected columns. Since `fc.name_key` is included and is constant per food (each food has exactly one `category_id`), true SQL duplication should not occur. BUT — verify there are no duplicate `meal_categories` rows in seed data and no edge case where SQLite's DISTINCT fails with `f.*` expansion.

3. **JavaScript DOM rendering**: The `animateCards()` function in `app.js` applies staggered animations to `.food-card` elements. Verify no DOM manipulation clones the last card. Also check if any CSS grid/flexbox wrapping issue creates a visual phantom of the last card.

4. **Off-by-one in PHP rendering loop**: Inspect whether the `foreach` loop at line 136 could be iterated with a corrupted `$foods` array (e.g., last element duplicated by the `$food['is_favorite']` assignment loop at lines 25-27 which uses `&$food` reference — this is a common PHP gotcha with `foreach` by-reference).

**Most likely root cause**: Item 4 — the `foreach ($foods as &$food)` loop on line 25 uses a **by-reference variable**. In PHP, when you iterate by reference and then iterate the same array again (line 136: `foreach ($foods as $food)`), the last element of the array gets overwritten by the second-to-last element's value. This is a well-documented PHP quirk. The `&$food` reference from line 25 is never `unset()`, so the second `foreach` on line 136 causes the last element to be a copy of the second-to-last.

**Fix**: Add `unset($food);` after the first `foreach` loop (after line 27), OR change the first loop to not use a reference:
```php
foreach ($foods as $key => $food) {
    $foods[$key]['is_favorite'] = in_array($food['id'], $favoriteIds);
}
```

**Files to modify**: `pages/child/log-food.php` (line 25-28)

**Estimated scope**: 1 file, 1-2 lines changed

---

### Task 0.2 — Bug: Favorites not persisting properly (Item 4)

**Symptom**: A food appears to be successfully added to favorites, but selecting a different meal or logging out and back in shows no recorded favorite.

**Root cause analysis** (multiple contributing factors):

1. **Double invocation from competing event handlers** (HIGH CONFIDENCE): In `log-food.php:270-301`, a long press triggers BOTH:
   - The custom `setTimeout(600ms)` handler (line 275-279) which sets `isLongPress=true` and calls `toggleFavorite()`
   - The native `contextmenu` event (lines 298-301) which ALSO calls `toggleFavorite()`

   On mobile browsers, a long press fires both the custom timer AND `contextmenu`. The first call ADDS the favorite; the second call immediately REMOVES it. Net result: the favorite is toggled twice, returning to its original state. The user sees the star flash on and off (or sees it stay on due to the optimistic UI from the first call, while the second call silently undoes it server-side).

2. **Silent error swallowing** (`log-food.php:355`): The `.catch(() => {})` silently swallows ALL errors. If either toggle call fails, there's no feedback and no recovery.

3. **No request debouncing or in-flight tracking**: There's no mechanism to prevent concurrent toggle requests for the same food. Rapid taps, double-taps, or the long-press + contextmenu race condition all produce undefined behavior.

4. **Non-transactional DB toggle** (`db.php:159-177`): The `toggleFavorite()` function does a SELECT then INSERT/DELETE in two separate statements without a transaction. If two requests arrive near-simultaneously, both SELECTs might see the same state, leading to duplicate INSERT attempts (which would fail on the PRIMARY KEY) or double DELETEs.

**Fix approach**:
1. Add `favoriteInFlight` set/map to prevent concurrent toggle calls on the same food ID.
2. In the `contextmenu` handler (line 298-301), check if `isLongPress` is already true (meaning the timer already called `toggleFavorite`) and skip the duplicate call.
3. Replace `.catch(() => {})` with error feedback and visual rollback.
4. Wrap the `toggleFavorite()` DB function in a `BEGIN/COMMIT` transaction.
5. Optionally: suppress `contextmenu` after a successful long-press toggle.

**Files to modify**: `pages/child/log-food.php` (JS handlers), `includes/db.php` (transaction), `api/favorites.php` (error handling)

**Estimated scope**: 2-3 files, ~20-30 lines changed

---

## SPRINT 1 — Feature Visibility Toggles (Item 1)

**Goal**: Allow guardians to toggle visibility of each main feature area (Food Journal, Check-in, Weight Log) in the child interface, following the same pattern already used for medications.

**Prerequisite**: Sprint 0 complete.

### Task 1.1 — Add toggle settings to Guardian Settings page

Add three new toggles to `settings.php`:

| Setting Key | Default | Purpose |
|---|---|---|
| `show_food_journal` | `'1'` | Show/hide the food journal feature for children |
| `show_checkin` | `'1'` | Show/hide the daily check-in feature for children |
| `show_weight_log` | `'1'` | Show/hide the weight tracking feature for children |

These use the existing `settings` table (key-value store) and existing `getSetting()`/`setSetting()` functions. No schema changes needed.

**Files to modify**: `pages/guardian/settings.php`

### Task 1.2 — Update child navigation to respect toggles

The child footer navigation (present in `log-food.php`, `check-in.php`, `weight.php`, `history.php`) has hardcoded links to all four sections. Each page must:
1. Read the toggle settings at the top
2. Conditionally render footer navigation items
3. Handle the case where the user tries to access a disabled feature directly via URL

**Recommendation**: Extract the footer into a shared partial (e.g., `pages/child/nav-footer.php`) to avoid duplicating the toggle logic across 4+ files.

**Files to modify**: All child pages (`pages/child/*.php`), `index.php` (routing guards)

### Task 1.3 — Route protection in index.php

Add guards to `index.php` so that even direct URL access to a disabled feature redirects the child to their default available page:

```php
case 'log-food':
    requireAuth();
    if (isChild() && getSetting('show_food_journal', '1') != '1') {
        header('Location: index.php');
        exit;
    }
    include 'pages/child/log-food.php';
    break;
```

Also update the default landing page logic (lines 121-133): if food journal is disabled, redirect to the first enabled feature.

**Files to modify**: `index.php`

### Task 1.4 — History and dashboard adaptations

- **Child history** (`history.php`): Conditionally show/hide food log section and check-in summary based on toggles
- **Guardian dashboard** (`dashboard.php`): Guardian always sees all data (no changes needed — toggles only affect child-facing interface)

**Files to modify**: `pages/child/history.php`

### Task 1.5 — i18n keys for new toggle labels

Add translation keys for toggle labels and help text in both locale files.

**Files to modify**: `locales/en.json`, `locales/pt.json`

**Estimated total scope**: Medium (6-8 files, ~80-120 lines changed)

---

## SPRINT 2 — Sleep Tracking (Item 2)

**Goal**: Introduce a new Sleep Tracking feature with a data model supporting night sleep, naps, and interruptions. Integrate with history view and guardian dashboard.

**Prerequisite**: Sprint 1 complete (so the new feature is born with its own toggle).

### Task 2.1 — Database schema: Sleep log model

```sql
-- Sleep sessions (one per night, plus optional naps)
CREATE TABLE IF NOT EXISTS sleep_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    log_date DATE NOT NULL,           -- Logical date (night of Feb 20 → log_date='2026-02-20')
    sleep_type TEXT NOT NULL CHECK(sleep_type IN ('night', 'nap')),
    sleep_start DATETIME NOT NULL,    -- Full datetime for cross-midnight support
    sleep_end DATETIME,               -- NULL if still sleeping / not yet filled
    quality INTEGER CHECK(quality BETWEEN 1 AND 5),  -- Same 1-5 scale as appetite/mood
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Night interruptions (0..N per sleep session)
CREATE TABLE IF NOT EXISTS sleep_interruptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sleep_log_id INTEGER NOT NULL,
    wake_time DATETIME NOT NULL,
    back_to_sleep_time DATETIME,      -- NULL if child stayed awake
    reason TEXT,                       -- Optional: 'bathroom', 'nightmare', 'noise', etc.
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sleep_log_id) REFERENCES sleep_log(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_sleep_log_user_date ON sleep_log(user_id, log_date);
CREATE INDEX IF NOT EXISTS idx_sleep_interruptions_log ON sleep_interruptions(sleep_log_id);
```

**Design rationale**:
- **`log_date`**: The "logical" date — a bedtime at 22:00 on Feb 20 ending at 07:00 on Feb 21 belongs to `log_date = '2026-02-20'`. This avoids cross-midnight confusion in queries and history display.
- **`sleep_type`**: Distinguishes night sleep from naps. A child can have one night entry and 0-N nap entries per day.
- **`sleep_interruptions`**: Normalized table (not JSON) consistent with the rest of the codebase. Each row is one waking event with optional back-to-sleep time.
- **`quality`**: Same 1-5 emoji scale as appetite/mood, keeping UX consistent.
- **No calculated fields**: Duration, effective sleep, and interruption counts are all computed in PHP/JS from raw times.

**Duration calculation** (helper function):
```
total_minutes = diff(sleep_end, sleep_start)
interruption_minutes = sum(diff(back_to_sleep_time, wake_time)) for each interruption
effective_sleep = total_minutes - interruption_minutes
```

**Files to modify**: `db/schema.sql`

### Task 2.2 — Backend: DB functions and API endpoint

New functions in `includes/db.php`:
- `saveSleepLog($userId, $date, $type, $start, $end, $quality, $notes)`
- `saveSleepInterruption($sleepLogId, $wakeTime, $backToSleepTime, $reason)`
- `getSleepByDate($userId, $date)` — returns sleep entries + joined interruptions
- `getSleepHistory($userId, $days)` — for charts and trending

New API at `api/sleep.php`:
```
POST /api/sleep.php     — Log sleep entry (+ optional interruptions array)
GET  /api/sleep.php     — Retrieve sleep data by date or range
DELETE /api/sleep.php   — Remove sleep entry
```

Add sleep data to `getDashboardData()` and `getReportData()` in `helpers.php`.

**Files to create**: `api/sleep.php`
**Files to modify**: `includes/db.php`, `includes/helpers.php`

### Task 2.3 — Child-facing sleep page

**New file**: `pages/child/sleep.php`

ADHD-friendly UX flow (minimal friction path = 3 taps + 2 time inputs):
1. **"How did you sleep?"** — 5-face emoji scale (same as appetite/mood)
2. **Bedtime** — Time picker (default: previous night's logged bedtime, or 21:00)
3. **Wake time** — Time picker (default: current time)
4. **"Wake up during the night?"** — Yes/No toggle (optional expansion)
   - If Yes: "How many times?" (1-5 buttons), then optional time inputs per interruption
5. **"Any naps today?"** — Yes/No toggle (optional expansion)
   - If Yes: Nap start/end time pickers, "Add another nap" button
6. Save → Confetti celebration

**Key design principle**: The minimum viable log is quality + bed time + wake time. Everything else is optional. This matches the ADHD-optimized philosophy of low entry barriers.

### Task 2.4 — Settings and routing integration

- Add `show_sleep_tracking` toggle to `pages/guardian/settings.php` (default `'1'`)
- Add route to `index.php`: `case 'sleep':`
- Add sleep icon (😴) to child footer navigation (conditional, per Sprint 1 pattern)

**Files to modify**: `pages/guardian/settings.php`, `index.php`, child footer nav

### Task 2.5 — History page integration

Add "Sleep Summary" section to `pages/child/history.php`:
- Bedtime → Wake time with duration
- Quality emoji
- Number of interruptions (if any)
- Naps listed with times
- Position this between check-in summary and food log sections

**Files to modify**: `pages/child/history.php`

### Task 2.6 — Guardian dashboard integration

Add "Sleep Patterns" section to `pages/guardian/dashboard.php`:
- Average sleep duration over selected period (line chart)
- Sleep quality trend (line chart)
- Interruption frequency (bar chart)
- **Correlation view**: Side-by-side with appetite/mood data — this is clinically valuable because sleep quality directly affects ADHD symptom severity and next-day appetite

**Files to modify**: `pages/guardian/dashboard.php`

### Task 2.7 — Export integration

Add sleep data to exports:
- `getReportData()` in `helpers.php`: include sleep summary
- `export-html.php`: sleep trends section
- `export-csv.php`: sleep data columns
- `guest-report.php`: sleep patterns for clinicians

**Files to modify**: `includes/helpers.php`, `pages/guardian/export-html.php`, `pages/guardian/export-csv.php`, `pages/guest-report.php`

### Task 2.8 — i18n keys

All sleep-related labels, encouragements, interruption reasons, and chart labels.

**Files to modify**: `locales/en.json`, `locales/pt.json`

**Estimated total scope**: Large (10-12 files, ~400-600 lines new code)

---

## SPRINT 3 — Percentiles Foundation (Item 5, Part 1)

**Goal**: Add the demographic fields (gender, date of birth), height tracking, and settings infrastructure required before percentile calculations can be implemented.

**Prerequisite**: Sprint 1 complete. Can run in parallel with Sprint 2.

### Task 3.1 — Extend user model for demographics

Add columns to the `users` table:
```sql
ALTER TABLE users ADD COLUMN gender TEXT CHECK(gender IN ('male', 'female'));
ALTER TABLE users ADD COLUMN date_of_birth DATE;
```

These are nullable — existing children work without them. Percentile calculations require both fields.

**Migration strategy**: Add `ALTER TABLE` statements wrapped in try/catch to `initializeDatabase()`, or introduce a lightweight version check via the `settings` table:
```php
if (getSetting('schema_version', '1') < '2') {
    $db->exec("ALTER TABLE users ADD COLUMN gender TEXT ...");
    $db->exec("ALTER TABLE users ADD COLUMN date_of_birth DATE");
    setSetting('schema_version', '2');
}
```

**Files to modify**: `db/schema.sql`, `includes/db.php`

### Task 3.2 — Manage Children: Gender & DOB fields

Update `pages/guardian/manage-children.php`:
- Add **Gender** radio buttons (Male/Female) — required only when percentile feature is enabled
- Add **Date of Birth** date input — required only when percentile feature is enabled
- Show validation warning if percentiles are on but child is missing these fields

Update `createUser()` and `updateUser()` in `includes/auth.php` to accept and store `gender` and `date_of_birth`.

**Files to modify**: `pages/guardian/manage-children.php`, `includes/auth.php`

### Task 3.3 — Height tracking

**New table**:
```sql
CREATE TABLE IF NOT EXISTS height_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    height_cm REAL NOT NULL,
    log_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, log_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_height_log_user_date ON height_log(user_id, log_date);
```

**Approach**: Integrate height input INTO the existing weight page (`pages/child/weight.php`), making it a combined "Growth" page with both weight and height inputs. Both share the same celebration flow. Height input is only shown when `show_percentiles` is enabled (since height is only needed for BMI percentiles).

**New file**: `api/height.php` (same pattern as `api/weight.php`)
**Files to modify**: `pages/child/weight.php`, `includes/db.php` (add `logHeight`, `getHeightHistory`), `db/schema.sql`

### Task 3.4 — Settings toggle for percentiles

Add toggle to `pages/guardian/settings.php`:
- `show_percentiles` (default `'0'` — off by default since it requires gender/DOB)
- When toggling ON: validate that all active children have gender and DOB. If not, show warning with link to manage-children.

**Files to modify**: `pages/guardian/settings.php`

### Task 3.5 — BMI and age calculation helpers

**File**: `includes/helpers.php`

```php
function calculateBMI($weightKg, $heightCm) {
    if ($heightCm <= 0) return null;
    $heightM = $heightCm / 100;
    return round($weightKg / ($heightM * $heightM), 1);
}

function calculateAgeInMonths($dateOfBirth) {
    $dob = new DateTime($dateOfBirth);
    $now = new DateTime();
    $diff = $dob->diff($now);
    return ($diff->y * 12) + $diff->m;
}
```

**Files to modify**: `includes/helpers.php`

### Task 3.6 — i18n keys

Gender options, date of birth label, height labels, percentile toggle label, validation messages.

**Files to modify**: `locales/en.json`, `locales/pt.json`

**Estimated scope**: Medium (8-10 files, ~200-300 lines)

---

## SPRINT 4 — Percentiles Full (Item 5, Part 2)

**Goal**: Implement WHO growth standard percentile calculations and display them in charts and dashboards.

**Prerequisite**: Sprint 3 complete.

### Task 4.1 — WHO reference data

**New file**: `includes/growth-standards.php`

WHO provides LMS (Lambda-Mu-Sigma) parameters for:
- **Weight-for-age**: 0-120 months (boys and girls)
- **Height/Length-for-age**: 0-228 months (boys and girls)
- **BMI-for-age**: 24-228 months (boys and girls)

Source: [WHO Child Growth Standards](https://www.who.int/tools/child-growth-standards/standards) (0-5 years) and [WHO Growth Reference 5-19 years](https://www.who.int/tools/growth-reference-data-for-5to19-years).

**Storage approach**: Static PHP arrays in a dedicated include file (~2000 rows total). This is read-only reference data — it doesn't belong in the user database.

```php
// includes/growth-standards.php
$WHO_WEIGHT_FOR_AGE = [
    'male' => [
        0 => ['L' => ..., 'M' => ..., 'S' => ...],
        1 => ['L' => ..., 'M' => ..., 'S' => ...],
        // ... up to 120 months
    ],
    'female' => [ /* same structure */ ]
];
```

### Task 4.2 — Percentile calculation engine

**New file**: `includes/percentiles.php`

```php
/**
 * WHO LMS method:
 * Z = ((value/M)^L - 1) / (L * S)     when L ≠ 0
 * Z = ln(value/M) / S                  when L = 0
 * Percentile = Φ(Z)  (standard normal CDF)
 */
function calculateZScore($value, $ageMonths, $gender, $indicator) { ... }
function zScoreToPercentile($zScore) { ... }
function calculateWeightForAgePercentile($weightKg, $ageMonths, $gender) { ... }
function calculateHeightForAgePercentile($heightCm, $ageMonths, $gender) { ... }
function calculateBMIForAgePercentile($bmi, $ageMonths, $gender) { ... }
```

The `zScoreToPercentile()` function implements the standard normal CDF. For PHP without stats extensions, use the rational approximation (Abramowitz & Stegun) which is accurate to 6 decimal places.

### Task 4.3 — Weight page: Percentile overlay

**File**: `pages/child/weight.php`

When percentiles are enabled AND the child has gender + DOB:
- Show current percentile rank next to the latest weight (e.g., "P50" or "50th percentile")
- On the weight trend chart, overlay WHO percentile bands (3rd, 15th, 50th, 85th, 97th) as reference lines
- Color coding: green zone (15th-85th), yellow zone (3rd-15th or 85th-97th), red zone (<3rd or >97th)
- The child sees encouraging language, not clinical flags (e.g., "Growing well!" not "Below 3rd percentile")

### Task 4.4 — Height percentile display

Same overlay approach for height data on the growth/weight page.

### Task 4.5 — BMI tracking section

When both weight and height exist for the same date, calculate and display BMI with age-percentile. Show on the growth page as an additional card.

### Task 4.6 — Guardian dashboard: Percentile summary

**File**: `pages/guardian/dashboard.php`

Add "Growth Percentiles" section:
- Current percentile rank for weight, height, BMI (with colored indicator dot)
- Trend line: "Moving from P25 → P35 over last 3 months"
- Historical percentile chart (percentile rank over time, not just raw values)

### Task 4.7 — Export and guest report

- Include percentile data in `getReportData()` output
- Show WHO-standard percentile charts in `export-html.php` and `guest-report.php`
- Add percentile columns to `export-csv.php`

Clinicians will find this particularly valuable — growth percentile trajectories are standard clinical metrics.

### Task 4.8 — i18n keys

Percentile labels, growth standard terminology, zone descriptions, encouraging messages per zone.

**Estimated scope**: Large (8-10 files, ~500-700 lines)

---

## SPRINT 5 — Growth-Support Nutrition Intelligence (Item 6)

**Goal**: Add a behind-the-scenes nutrition intelligence layer that provides clinically meaningful insights to guardians and clinicians, WITHOUT adding ANY cognitive load to the child's experience.

**Prerequisite**: Sprint 4 complete (percentiles provide the growth context that makes nutrition intelligence actionable).

### Task 5.1 — Food growth tags (not micronutrients)

**Design philosophy**: We are NOT building a calorie counter or micronutrient tracker. For ADHD children with appetite suppression from stimulant medications (methylphenidate/Ritalina, Concerta, lisdexamfetamine/Vyvanse, atomoxetine/Strattera), the goal is to **maximize nutritional density in the small eating windows they have**.

Instead of tracking grams of protein or milligrams of calcium, we use strategic tags that map directly to growth-support interventions:

**New table**:
```sql
CREATE TABLE IF NOT EXISTS food_growth_tags (
    food_id INTEGER NOT NULL,
    tag TEXT NOT NULL CHECK(tag IN (
        'calorie_dense',       -- High kcal per volume (nuts, cheese, avocado, butter)
        'protein_rich',        -- Growth-essential (eggs, chicken, fish, dairy)
        'bone_building',       -- Calcium + Vitamin D sources (milk, yogurt, cheese)
        'brain_fuel',          -- Omega-3, complex carbs (fish, whole grains, nuts)
        'easy_to_eat',         -- Low-effort for suppressed appetite (smoothies, yogurt, crackers)
        'hydrating'            -- Water-rich (watermelon, cucumber, soups)
    )),
    PRIMARY KEY (food_id, tag),
    FOREIGN KEY (food_id) REFERENCES foods(id) ON DELETE CASCADE
);
```

**Why these specific tags** (clinical rationale for ADHD with appetite suppression):

| Tag | Why it matters for ADHD + stimulant medication |
|---|---|
| `calorie_dense` | During peak medication effect, appetite is severely suppressed. When the child DOES eat, every bite needs to count. A handful of nuts (300 kcal) beats a plate of lettuce (15 kcal). This is the #1 lever for growth support. |
| `protein_rich` | Protein is critical for growth, especially during puberty and for children tracking below weight-for-age percentiles. Protein also stabilizes blood sugar, which benefits ADHD focus. |
| `bone_building` | Stimulant medications (particularly methylphenidate) have been associated with reduced bone mineral density in some studies. Calcium and Vitamin D intake becomes especially important. |
| `brain_fuel` | Omega-3 fatty acids have evidence supporting ADHD symptom management. Complex carbs provide sustained energy vs. sugar spike-crash cycles that worsen ADHD symptoms. |
| `easy_to_eat` | During peak medication, a full plate is overwhelming. Foods requiring minimal effort (a yogurt cup, a handful of nuts, a smoothie, crackers with cheese) are far more likely to be accepted. This tag identifies "path of least resistance" foods. |
| `hydrating` | Stimulant medications commonly cause dry mouth and reduced thirst awareness. Water-rich foods supplement fluid intake passively. |

**Default tag assignments** (seed data for existing foods):

| Food | Tags |
|---|---|
| 🥛 Milk | protein_rich, bone_building, easy_to_eat |
| 🧀 Cheese | calorie_dense, protein_rich, bone_building |
| 🍶 Yogurt | protein_rich, bone_building, easy_to_eat |
| 🧈 Butter | calorie_dense |
| 🥚 Egg | protein_rich, calorie_dense |
| 🍗 Chicken | protein_rich |
| 🐟 Fish | protein_rich, brain_fuel |
| 🥩 Meat | protein_rich, calorie_dense |
| 🥜 Nuts | calorie_dense, protein_rich, brain_fuel |
| 🍌 Banana | easy_to_eat, brain_fuel |
| 🍉 Watermelon | hydrating, easy_to_eat |
| 🥒 Cucumber | hydrating |
| 🍞 Bread | brain_fuel |
| 🍚 Rice | brain_fuel |
| 🥣 Cereal | brain_fuel, easy_to_eat |
| 🥛 Chocolate Milk | calorie_dense, bone_building, easy_to_eat |
| 🍿 Popcorn | easy_to_eat, brain_fuel |
| 🧈 Crackers | easy_to_eat |
| 🍪 Cookie | calorie_dense, easy_to_eat |
| 🍕 Pizza | calorie_dense, protein_rich |

**CHILD UX IMPACT: ZERO.** The child sees the exact same food cards with the same emojis. They tap, pick a portion, celebrate. No new screens, no new inputs, no new cognitive load. The tags are invisible metadata consumed only by the guardian/clinician analytics layer.

### Task 5.2 — Medication timing layer

**Clinical context**: For children on stimulant ADHD medications, WHEN they eat matters as much as WHAT they eat. A typical methylphenidate day looks like:

| Time Window | What happens | Nutritional strategy |
|---|---|---|
| **Pre-medication** (before med taken) | Normal/best appetite of the day | **Golden window** — prioritize calorie-dense, protein-rich breakfast. This may be the biggest meal of the day. |
| **Mid-medication** (peak effect, ~1-8h post-dose) | Appetite most suppressed, child often says "I'm not hungry" | Offer easy-to-eat, calorie-dense snacks. Don't pressure. Even a yogurt or handful of nuts is a win. |
| **Post-medication** (rebound, evening) | Appetite rebounds, sometimes excessively | Good window for substantial dinner. Watch for compensatory binge eating on low-quality foods. Offer protein-rich and brain-fuel options. |

**New table**:
```sql
CREATE TABLE IF NOT EXISTS medication_timing (
    medication_id INTEGER NOT NULL,
    window_tag TEXT NOT NULL CHECK(window_tag IN ('pre_med', 'mid_med', 'post_med')),
    time_start TEXT NOT NULL,      -- HH:MM
    time_end TEXT NOT NULL,        -- HH:MM
    PRIMARY KEY (medication_id, window_tag),
    FOREIGN KEY (medication_id) REFERENCES medications(id) ON DELETE CASCADE
);
```

**Guardian configuration** in `pages/guardian/manage-medications.php`:
- Add **Administration time** input (e.g., "08:00")
- Add **Medication type** dropdown with auto-populated timing defaults:

| Type | pre_med | mid_med | post_med |
|---|---|---|---|
| Short-acting (Ritalina IR) | admin-1h → admin | admin+0.5h → admin+4h | admin+5h → admin+10h |
| Long-acting (Concerta, Ritalina LA) | admin-1h → admin | admin+0.5h → admin+8h | admin+9h → admin+14h |
| Non-stimulant (Strattera/Atomoxetine) | No timing windows (less appetite impact) | — | — |

The guardian can manually adjust the calculated windows to match their child's actual response pattern (individual variation is significant).

### Task 5.3 — Food log enrichment (automatic, invisible)

**File**: `api/food-log.php`

When a food is logged, the system automatically tags it with:
1. The `food_growth_tags` of the food
2. The `medication_timing` window active at `log_time` (if any)

This is stored either as additional columns on `food_log` or in a separate join table. The child does nothing different — this enrichment happens server-side.

**Recommended approach**: Add a `med_window` column to `food_log`:
```sql
ALTER TABLE food_log ADD COLUMN med_window TEXT CHECK(med_window IN ('pre_med', 'mid_med', 'post_med', NULL));
```

Populated automatically at INSERT time by comparing `log_time` against the child's medication timing windows.

### Task 5.4 — Guardian dashboard: Nutrition Intelligence panel

**File**: `pages/guardian/dashboard.php` (new section)

**"Nutrition Insights"** panel showing three types of intelligence:

**A. Timing Analysis**:
- Calorie distribution by medication window (pie/bar chart)
- "70% of Maria's food intake occurs in the post_med window" ✅
- "Maria ate nothing during the pre_med window on 4 of the last 7 days" ⚠️

**B. Growth-Tag Coverage** (weekly rolling summary):
- "Protein-rich foods: 12 servings this week" (with trend arrow ↑↓→)
- "Bone-building foods: 3 servings this week" (below suggested frequency, flagged ⚠️)
- "Calorie-dense foods during mid_med: 5 servings" (good strategy ✅)
- "Easy-to-eat foods offered during mid_med: 8 of 12 items" ✅

**C. Strategic Recommendations** (rule-based, NOT AI-generated):
- "Consider offering easy-to-eat snacks (yogurt, nuts) during the 10:00–14:00 window when appetite is lowest"
- "Bone-building food intake has decreased this week. Consider adding cheese or yogurt to the morning snack"
- "Pre-medication breakfast was skipped 4 times this week. This is the golden window for calorie-dense foods"

These insights are generated by cross-referencing:
- `food_log.log_time` + `food_log.med_window` with `medication_timing` windows
- `food_growth_tags` with intake frequency per tag
- `weight_log` / percentile trends with food category coverage

### Task 5.5 — Clinician report: Medication-aware nutrition summary

**Files**: `pages/guest-report.php`, `pages/guardian/export-html.php`

Add a "Medication-Aware Nutrition Summary" section:
- Intake distribution by medication window (pre/mid/post chart)
- Growth-tag coverage trends over the report period
- Weight percentile trajectory alongside nutrition patterns
- Sleep quality correlation with next-day appetite (if sleep tracking is enabled)

This gives pediatricians and child psychiatrists an objective, data-driven view of how the child's medication schedule affects their nutritional intake — information that is extremely difficult to gather through traditional clinic visit conversations.

### Task 5.6 — Guardian food management: Tag editor

**File**: `pages/guardian/manage-foods.php`

When adding or editing foods, show checkboxes for growth tags. This allows guardians to:
- Tag custom foods they've added
- Adjust default tags if they disagree with the defaults
- See at a glance which tags each food has

### Task 5.7 — Medication management: Timing editor

**File**: `pages/guardian/manage-medications.php`

Add to the medication edit form:
- Administration time input
- Medication type dropdown (short-acting / long-acting / non-stimulant)
- Auto-calculated timing windows with manual override fields
- Visual timeline showing the three windows on a 24-hour axis

### Task 5.8 — i18n keys

All growth-tag labels, timing window names, insight messages, recommendation templates, medication type labels.

**Files to modify**: `locales/en.json`, `locales/pt.json`

**Estimated scope**: Very Large (12-15 files, ~600-900 lines)

---

## Sprint Dependency Map

```
Sprint 0 (Bug Fixes)
    │
    ▼
Sprint 1 (Feature Toggles)
    │
    ├──────────────────┐
    ▼                  ▼
Sprint 2            Sprint 3
(Sleep Tracking)    (Percentiles Foundation)
    │                  │
    │                  ▼
    │              Sprint 4
    │              (Percentiles Full)
    │                  │
    └──────┬───────────┘
           ▼
    Sprint 5 (Nutrition Intelligence)
```

**Parallelism**: Sprints 2 and 3 are independent and can run concurrently after Sprint 1. Sprint 5 benefits from BOTH sleep data and percentile data being available, so it comes last.

---

## Risk Considerations

### Database Migrations
The current app uses `CREATE TABLE IF NOT EXISTS` and `INSERT OR IGNORE` — no migration system exists. Sprints 3+ need `ALTER TABLE` on the `users` table (gender, DOB) and `food_log` table (med_window). SQLite's `ALTER TABLE` only supports `ADD COLUMN` (no modify/drop). Recommendation: Implement a simple version-based migration using a `schema_version` setting key, with each sprint's migrations wrapped in version checks.

### SQLite Concurrency
Multiple simultaneous API calls (e.g., rapid favorite toggles, concurrent sleep + food logging) can hit SQLite's write lock. The current codebase creates a new PDO connection per function call (`getDB()`). For Sprint 0's favorites fix, client-side debouncing is sufficient. For Sprint 5's analytics queries, consider read-only connections or caching.

### Child UX Complexity Budget
The app's core value is low-friction tracking for ADHD children. Current footer nav has 4 items. Adding sleep (Sprint 2) and potentially height makes 5-6 items.

**UX ceiling recommendation**: Maximum 5 footer items. Beyond that, consider:
- Combining "Check-in" to encompass appetite + mood + sleep + medication as a single daily reflection form
- Or making height a sub-section of the weight/growth page (not a separate nav item)
- The Sprint 2 plan already suggests integrating height into the weight page, keeping it at 5 items max

### Growth Tag Maintenance
Default tag assignments cover the 46 seed foods. When guardians add custom foods via manage-foods, they should be prompted to tag them. New foods default to NO tags (opt-in) to avoid incorrect assumptions.

### Percentile Data Accuracy
WHO growth standards are published as tables by month of age. For ages between published data points, linear interpolation of LMS values is standard practice. Document the data source and version (WHO 2006 for 0-5 years, WHO 2007 for 5-19 years) for clinical traceability.

### Medication Timing Assumptions
The default timing windows are approximations. Individual response to stimulant medication varies significantly (some children metabolize faster/slower). The manual override capability in Sprint 5 is essential — the defaults are starting points, not prescriptions.

---

## Summary Table

| Sprint | Items | Key Deliverables | Scope | Dependencies |
|---|---|---|---|---|
| **0** | #3, #4 | Fix duplicate foods bug, fix favorites persistence | Small | None |
| **1** | #1 | Feature visibility toggles in guardian settings | Medium | Sprint 0 |
| **2** | #2 | Sleep tracking: model, page, API, history, dashboard | Large | Sprint 1 |
| **3** | #5a | Gender/DOB fields, height tracking, BMI helpers, settings | Medium | Sprint 1 |
| **4** | #5b | WHO reference data, percentile calculations, chart overlays | Large | Sprint 3 |
| **5** | #6 | Growth tags, medication timing, nutrition insights | Very Large | Sprint 4 |
