# ComeCome Sprint Plan

## Guiding Principle

> **The child's interaction surface stays flat. New depth goes to the guardian and clinician layers only.**

Every sprint below explicitly defines a **child boundary** — what the child sees (or doesn't) — to preserve the current ADHD-optimized simplicity: four footer buttons, emoji-first interactions, tap-portion-celebrate flow.

---

## Sprint 0 — Bug Fixes

**Goal:** Fix two reported defects before building anything new.
**Child boundary:** No UI changes. Existing experience becomes more reliable.

### 0A. Duplicate last food in catalog

**Symptom:** The last food option in the food grid is always displayed twice (e.g. 2x pears, 2x pizzas).

**Root cause identified:** Classic PHP foreach-by-reference bug in `pages/child/log-food.php`.

At line 25, the `&$food` reference marks favorites:
```php
foreach ($foods as &$food) {
    $food['is_favorite'] = in_array($food['id'], $favoriteIds);
}
```

After this loop, `$food` remains a live reference to the **last element** of `$foods`. When subsequent `foreach ($foods as $food)` loops iterate (lines 93 and 136), each iteration assigns the current element's value to `$food` — which, being still a reference, **overwrites the last element of the array** on every pass. By the time the loop reaches the final element, it has already been replaced by the second-to-last element's data.

**Fix:** Add `unset($food);` immediately after the first foreach loop (after line 27). One line.

**Files touched:**
- `pages/child/log-food.php` (line ~28)

---

### 0B. Favorites not persisting reliably

**Symptom:** A food appears to be successfully favorited (star badge shows), but switching meals or re-logging reveals the favorite was not saved.

**Root causes identified (multiple contributing factors):**

1. **Silent error swallowing.** The JS `toggleFavorite()` function has `.catch(() => {})` at line 355 of `log-food.php`. If the `fetch()` call fails (network timeout, session expiry mid-request, page navigation interrupting the request), the failure is silently discarded. The user saw the star badge appear (via DOM manipulation in `.then()`) but may have navigated away before the response fully completed, or the server returned an error that was swallowed.

2. **`data-is-favorite` attribute not updated.** The toggle handler updates the CSS class (`is-favorite`) and the visual badge, but never updates the `data-is-favorite` attribute on the button element. While this doesn't directly affect persistence, it causes state inconsistency if any other code reads that attribute.

3. **No optimistic-UI rollback.** The current flow is: request fires -> if success, update DOM. But if the request fails silently (point 1), the DOM was never updated anyway. The real problem is that the user may have already navigated away, cancelling the in-flight request. There's no mechanism to detect this.

4. **Interaction with bug 0A.** The foreach reference bug corrupts the last food element's data, including its `is_favorite` flag. If a user favorites the last food in the list, the corrupted data will show incorrect favorite state on reload.

**Fix strategy:**
- Remove silent `.catch(() => {})`, replace with user-visible feedback on failure (brief toast or badge revert)
- Update `data-is-favorite` attribute alongside class and badge changes
- After the reference bug fix (0A), verify that favorite persistence works correctly for all foods including the last one
- Consider adding `navigator.sendBeacon()` as a fallback for favorite toggles during page unload, or debounce navigation after toggle

**Files touched:**
- `pages/child/log-food.php` (JS toggleFavorite function)
- `api/favorites.php` (verify no issues — already reviewed, server-side logic is correct)

---

## Sprint 1 — Feature Visibility Toggles

**Goal:** Let guardians toggle each main child feature on/off, following the existing pattern used by medications.
**Child boundary:** The child may see **fewer** footer buttons, never more. Features toggled off simply disappear.

### Current state

The `settings` table stores key-value pairs. Only one child-facing toggle exists today: `show_medication_to_children`. It controls medication visibility within the check-in flow.

### What to build

Add three new settings:

| Setting key | Default | Effect when off |
|---|---|---|
| `show_food_journal` | `'1'` | Hides `log-food` and `history` from child footer + router |
| `show_checkin` | `'1'` | Hides `check-in` from child footer + router |
| `show_weight_tracking` | `'1'` | Hides `weight` from child footer + router |

### Implementation steps

1. **`pages/guardian/settings.php`** — Add three checkbox toggles in a new "Child Features" section, visually grouped and labelled. Save via `setSetting()` on POST. Follow the exact pattern of the existing medication toggle.

2. **`pages/child/log-food.php`, `check-in.php`, `weight.php`, `history.php`** — At the top of each file, after `requireAuth()`, read the corresponding setting. If `'0'`, redirect to the next available feature or to a friendly "this feature is not available" page.

3. **Footer navigation** — The child footer is duplicated in each child page (`log-food.php`, `check-in.php`, `weight.php`, `history.php`). Extract it into a shared partial `pages/child/footer.php` that reads all toggle settings and renders only enabled buttons. This also removes code duplication (currently ~16 lines repeated four times).

4. **`index.php` router** — In the child route cases, check the toggle before including the page. If disabled, redirect gracefully.

5. **Seed data** — Add three `INSERT OR IGNORE` entries to `db/seed.sql` for the new settings, defaulting to `'1'`.

6. **i18n** — Add translation keys for the new toggle labels and descriptions in `locales/pt.json` and `locales/en.json`.

7. **Dashboard** — On `pages/guardian/dashboard.php`, respect the toggles: if weight tracking is off for a child, don't render the weight chart section (the data won't exist anyway).

### Files touched
- `pages/guardian/settings.php`
- `pages/child/log-food.php`, `check-in.php`, `weight.php`, `history.php`
- New: `pages/child/footer.php` (extracted partial)
- `index.php`
- `db/seed.sql`
- `locales/pt.json`, `locales/en.json`
- `pages/guardian/dashboard.php`

---

## Sprint 2 — Sleep Tracking

**Goal:** Track daily sleeping patterns — accommodating naps, night interruptions — and surface the data in guardian dashboard and clinician reports.
**Child boundary:** One new emoji-scale row added to the existing check-in form. No new pages, no new footer buttons. The detailed sleep log (naps, interruptions) is entered by the guardian or is optional.

### Data model

#### New table: `sleep_log`

```sql
CREATE TABLE IF NOT EXISTS sleep_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    log_date DATE NOT NULL,           -- the date this sleep record belongs to
    sleep_type TEXT NOT NULL CHECK(sleep_type IN ('night', 'nap')),
    started_at TEXT,                   -- HH:MM (e.g. '21:30' or '14:00')
    ended_at TEXT,                     -- HH:MM (e.g. '07:00' or '15:30')
    quality INTEGER CHECK(quality BETWEEN 1 AND 5),  -- 1=terrible, 5=great
    interruptions INTEGER DEFAULT 0,  -- number of wake-ups during this period
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_sleep_log_user_date ON sleep_log(user_id, log_date);
```

**Design rationale:**
- **`sleep_type`**: distinguishes night sleep from naps. A single day can have one `night` entry and multiple `nap` entries.
- **`started_at` / `ended_at`**: optional time fields. The child doesn't need to enter these — just quality. The guardian can fill in exact times later.
- **`quality`**: the simple 1-5 scale that the child actually interacts with (emoji row in check-in).
- **`interruptions`**: count of night wake-ups. Guardian-entered or optional.
- **No rigid structure**: a nap is just another row with `sleep_type='nap'`. No fixed number of naps. Fully flexible.

#### Also modify: `daily_checkin` table

Add a `sleep_quality` column:
```sql
ALTER TABLE daily_checkin ADD COLUMN sleep_quality INTEGER CHECK(sleep_quality BETWEEN 1 AND 5);
```

This captures the child's simple self-report during check-in. The detailed `sleep_log` entries are the guardian's (or older child's) optional elaboration.

### Feature toggle

Add `show_sleep_tracking` setting (default `'1'`), following Sprint 1's pattern. When off, the sleep quality row is hidden from check-in, and the sleep section is hidden from the dashboard.

### Child-side changes

**`pages/child/check-in.php`** — Add one new emoji-scale section between Mood and Medication:

```
How did you sleep? 😫 😴 😐 😊 🌟
```

Same pattern as appetite and mood — five radio buttons with emojis. Saved as `sleep_quality` in `daily_checkin`. Nothing else changes in the child flow.

### Guardian-side changes

1. **New management page: `pages/guardian/manage-sleep.php`** — Optional detailed sleep logging for a child. Guardian selects a child and date, then can:
   - Enter/edit the night sleep entry (bedtime, wake time, interruptions)
   - Add nap entries (start, end)
   - View weekly sleep summary

2. **Dashboard integration** — Add a "Sleep Quality" trend line to the dashboard (from `daily_checkin.sleep_quality`). If detailed `sleep_log` entries exist, show a breakdown: average night duration, nap frequency, interruption count.

3. **Export/reports** — Add sleep data to `getReportData()` and the export templates. Include: average sleep quality, total sleep duration (when times are logged), interruption frequency, nap patterns.

4. **Navigation** — Add "Sleep" link to `pages/guardian/nav.php`.

### API

New endpoint: `api/sleep.php` — POST/GET for sleep log entries (guardian use). The child's sleep quality is saved through the existing `api/check-in.php` endpoint (just a new field in the POST body).

### Files touched
- `db/schema.sql` (new table + ALTER)
- `db/seed.sql` (new setting)
- `pages/child/check-in.php` (one new emoji row)
- `api/check-in.php` (accept sleep_quality field)
- `includes/db.php` (saveCheckIn updated, new sleep log functions)
- New: `pages/guardian/manage-sleep.php`
- New: `api/sleep.php`
- `pages/guardian/nav.php`
- `pages/guardian/dashboard.php` (sleep section)
- `pages/guardian/settings.php` (sleep toggle)
- `includes/helpers.php` (getDashboardData, getReportData updated)
- `pages/guardian/export-html.php`, `export-csv.php` (sleep columns)
- `locales/pt.json`, `locales/en.json`

---

## Sprint 3 — Percentiles (Weight-for-age, Height-for-age, BMI-for-age)

**Goal:** Calculate and display WHO/CDC growth percentiles for each child.
**Child boundary:** One optional height input field added to the existing weight page. The child never sees percentile numbers, curves, or BMI. All percentile visualization is guardian/clinician-only.

### Prerequisites on the child profile

The `users` table needs three new columns:

```sql
ALTER TABLE users ADD COLUMN birth_date DATE;
ALTER TABLE users ADD COLUMN gender TEXT CHECK(gender IN ('male', 'female'));
ALTER TABLE users ADD COLUMN height_cm REAL;
```

**Gender requirement:** If the percentiles feature is toggled on, the guardian must set `birth_date` and `gender` for each child. The settings page (or manage-children form) should validate this: display a clear message that percentiles require gender and birth date.

### New table: `height_log`

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

### Feature toggle

Add `show_percentiles` setting (default `'0'` — off by default, since it requires gender/DOB setup). When toggled on:
- The manage-children form shows gender and birth_date as **required** fields
- The weight page shows an optional height input
- The dashboard renders percentile charts
- Export reports include percentile data

### WHO/CDC reference data

Store the LMS reference tables (Lambda, Mu, Sigma) as static PHP arrays or a JSON file in a new `data/` directory:
- `data/who-wfa-boys.json` (weight-for-age, boys, 0-10 years)
- `data/who-wfa-girls.json`
- `data/who-hfa-boys.json` (height-for-age)
- `data/who-hfa-girls.json`
- `data/who-bfa-boys.json` (BMI-for-age)
- `data/who-bfa-girls.json`

Each file contains the LMS parameters per age-in-months. Percentile calculation:
```
Z = ((measurement / M)^L - 1) / (L * S)
percentile = normal_CDF(Z) * 100
```

A PHP helper function `calculatePercentile($type, $gender, $ageMonths, $value)` will encapsulate this.

### Child-side changes

**`pages/child/weight.php`** — If percentiles toggle is on and child has DOB/gender set, add a height input field below the weight input:

```
Weight: [____] kg
Height: [____] cm  (optional, once in a while is enough)
```

Same form, same submit flow, same confetti. The height field is marked as optional with a friendly hint: "You don't need to measure every day!"

The child's weight chart remains the same simple line chart — no percentile curves shown to the child.

### Guardian-side changes

1. **`pages/guardian/manage-children.php`** — Add birth_date (date picker) and gender (select: boy/girl) fields. When percentiles are enabled, these become required.

2. **Dashboard** — New "Growth Percentiles" section (visible only when percentiles toggle is on and child has DOB + gender):
   - Weight-for-age percentile with WHO curve overlay
   - Height-for-age percentile (when height data exists)
   - BMI-for-age percentile (computed from weight + height)
   - Current percentile value displayed prominently (e.g. "P50" or "25th percentile")
   - Color-coded zones: green (P15-P85), yellow (P3-P15 or P85-P97), red (<P3 or >P97)

3. **Export/reports** — Add percentile data to HTML and CSV exports.

### API

Extend `api/weight.php` to accept optional `height` field in POST. Add height logging function to `includes/db.php`.

### Files touched
- `db/schema.sql` (ALTER users, new height_log table)
- `db/seed.sql` (new setting)
- New: `data/who-*.json` (6 reference data files)
- New: `includes/percentiles.php` (LMS calculation functions)
- `pages/guardian/manage-children.php` (DOB, gender fields)
- `pages/guardian/settings.php` (percentiles toggle)
- `pages/child/weight.php` (optional height input)
- `api/weight.php` (accept height)
- `includes/db.php` (height log functions)
- `pages/guardian/dashboard.php` (percentile charts)
- `includes/helpers.php` (percentile data in getDashboardData, getReportData)
- `pages/guardian/export-html.php`, `export-csv.php`
- `locales/pt.json`, `locales/en.json`

---

## Sprint 4 — ADHD Nutrition: Growth-Support Categories and Medication Timing

**Goal:** Add strategic nutritional value to logged food intake, with ADHD-specific medication timing analysis — without changing the child's logging experience at all.
**Child boundary:** Absolutely zero changes to the child UI. The child taps the same foods, picks the same portions, gets the same confetti. All new data is metadata managed by the guardian and computed by the system.

### Part A: Growth-support food categories

#### Concept

Instead of micronutrient tracking (complex, clinical, overwhelming), tag each food with **growth-support categories** — simple, strategic groupings that answer: "Is this child eating enough of the foods that matter most for growth?"

#### New table: `food_nutrition_tags`

```sql
CREATE TABLE IF NOT EXISTS food_nutrition_tags (
    food_id INTEGER NOT NULL,
    tag TEXT NOT NULL CHECK(tag IN (
        'protein_rich',        -- chicken, eggs, fish, meat, beans, nuts
        'calcium_rich',        -- milk, cheese, yogurt, fortified foods
        'iron_rich',           -- red meat, spinach, beans, fortified cereals
        'calorie_dense',       -- nuts, avocado, cheese, whole milk, pasta
        'fiber_rich',          -- fruits, vegetables, whole grains, beans
        'hydration'            -- water, juice, milk, soups, fruits
    )),
    PRIMARY KEY (food_id, tag),
    FOREIGN KEY (food_id) REFERENCES foods(id) ON DELETE CASCADE
);
```

**Why these six categories:**
- **`protein_rich`**: Critical for growth, often under-consumed when ADHD meds suppress appetite.
- **`calcium_rich`**: Bone development; some stimulant medications may affect bone density.
- **`iron_rich`**: Iron deficiency is prevalent in ADHD children and worsens symptoms.
- **`calorie_dense`**: When appetite windows are narrow (medication effect), calorie-dense foods maximize intake.
- **`fiber_rich`**: Digestive health, often compromised by stimulant side effects.
- **`hydration`**: Stimulant medications are dehydrating; tracking fluid intake is clinically relevant.

#### Seed data

Each existing food in `seed.sql` gets tagged. A single food can have multiple tags. Examples:
- `food_chicken` -> `protein_rich`
- `food_milk` -> `calcium_rich`, `protein_rich`, `calorie_dense`, `hydration`
- `food_cheese` -> `calcium_rich`, `protein_rich`, `calorie_dense`
- `food_water` -> `hydration`
- `food_nuts` -> `protein_rich`, `calorie_dense`, `iron_rich`

#### Guardian food management

**`pages/guardian/manage-foods.php`** — When adding or editing a food, show checkboxes for the six nutrition tags. This is guardian-only management. Existing foods from seed data come pre-tagged.

#### Dashboard: Growth-support summary

New dashboard section: "Growth Support Overview" showing:
- Daily/weekly breakdown of tag coverage (e.g. "Protein: 3 items, Calcium: 1 item, Iron: 0 items")
- Visual indicator: which categories are being met vs. underserved
- Trend over the selected period

### Part B: Medication timing windows

#### Concept

For children on ADHD medications (e.g. methylphenidate), **when** they eat matters as much as **what** they eat. The system already knows:
- The child's medication assignments (`user_medications`)
- Whether medication was taken (`daily_checkin.medication_taken`)
- What time each food was logged (`food_log.log_time`)
- The meal time ranges (`meals.time_start`, `meals.time_end`)

From this, we can **derive** the timing window without asking the child anything new.

#### New table: `medication_schedules`

```sql
CREATE TABLE IF NOT EXISTS medication_schedules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    medication_id INTEGER NOT NULL,
    dose_time TEXT NOT NULL,              -- HH:MM when medication is typically taken
    peak_start_offset INTEGER DEFAULT 60, -- minutes after dose when peak effect starts
    peak_end_offset INTEGER DEFAULT 240,  -- minutes after dose when peak effect ends
    active INTEGER DEFAULT 1,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (medication_id) REFERENCES medications(id) ON DELETE CASCADE
);
```

**Default offsets for methylphenidate:**
- Immediate-release: peak 60-180 min
- Extended-release: peak 60-240 min

Guardian sets the dose time and can adjust offsets.

#### Automatic timing classification

A helper function classifies each food log entry:

```
function classifyMedTiming($logTime, $doseTime, $peakStart, $peakEnd):
    if logTime < doseTime:                    return 'pre_med'
    if logTime >= doseTime + peakStart
       AND logTime <= doseTime + peakEnd:     return 'mid_med'  (peak suppression)
    if logTime > doseTime + peakEnd:          return 'post_med' (rebound window)
    else:                                     return 'onset'    (medication kicking in)
```

This runs **at query time**, not at insertion. No changes to how food is logged.

#### Guardian management

**`pages/guardian/manage-medications.php`** — When a medication is assigned to a child, allow guardian to set:
- Typical dose time (e.g. "08:00")
- Peak effect duration (with sensible defaults — guardian can accept defaults)

#### Dashboard: Timing analysis

New dashboard section: "Eating & Medication Timing" showing:
- Intake distribution across `pre_med`, `mid_med`, `post_med` windows
- Which growth-support categories are consumed in each window
- Highlight: "Protein intake during peak suppression is low — consider calorie-dense snacks during rebound"
- Trend over the selected period

**Key clinical insight this enables:** A clinician looking at the report can see: "This child is eating 80% of daily protein in the pre-med window and almost nothing during peak suppression, with a calorie-dense rebound at dinner. The timing pattern suggests appetite suppression is significant. Consider adjusting dose timing or adding a structured pre-med protein-rich breakfast."

### Feature toggle

Add `show_nutrition_insights` setting (default `'0'` — off by default). When on:
- Nutrition tags are shown in food management
- Growth-support summary appears on dashboard
- Medication timing analysis appears on dashboard (only if child has medication schedules)
- Export includes nutrition and timing data

### Files touched
- `db/schema.sql` (two new tables)
- `db/seed.sql` (food_nutrition_tags seed data, new settings)
- `pages/guardian/manage-foods.php` (nutrition tag checkboxes)
- `pages/guardian/manage-medications.php` (dose time, peak offsets)
- `pages/guardian/settings.php` (nutrition insights toggle)
- `pages/guardian/dashboard.php` (two new sections)
- New: `includes/nutrition.php` (tag queries, timing classification)
- `includes/helpers.php` (getDashboardData, getReportData updated)
- `pages/guardian/export-html.php`, `export-csv.php`
- `locales/pt.json`, `locales/en.json`
- Zero child-facing page changes

---

## Sprint Sequence and Dependencies

```
Sprint 0  (Bug Fixes)
   |
   v
Sprint 1  (Feature Toggles)  -- foundational; all later sprints depend on this
   |
   +------+------+
   |             |
   v             v
Sprint 2      Sprint 3        -- independent of each other, can be parallelized
(Sleep)    (Percentiles)       -- or done in either order
   |             |
   +------+------+
          |
          v
       Sprint 4               -- depends on understanding the final data model
   (ADHD Nutrition)            -- builds on food catalog, medication, timing
```

Sprint 0 and Sprint 1 are strictly sequential (fixes first, then the toggle infrastructure everything else relies on). Sprints 2 and 3 are independent and can be done in either order or even in parallel. Sprint 4 comes last because it benefits from the full data model (sleep, percentiles, toggles) being in place.

---

## Summary: What changes per user type

### Child

| Sprint | What the child sees change |
|---|---|
| 0 | Nothing (bugs fixed invisibly) |
| 1 | Some footer buttons may disappear (if guardian disables features) |
| 2 | One new emoji row in check-in ("How did you sleep?") |
| 3 | One optional height field on the weight page |
| 4 | Nothing at all |

### Guardian

| Sprint | What the guardian gains |
|---|---|
| 0 | More reliable app behavior |
| 1 | Feature toggles in settings |
| 2 | Sleep management page, sleep trends on dashboard |
| 3 | Growth percentile charts, DOB/gender fields for children |
| 4 | Nutrition tag management, timing analysis on dashboard |

### Clinician (guest report)

| Sprint | What the clinician report gains |
|---|---|
| 0 | More accurate data |
| 1 | Awareness of which features are active |
| 2 | Sleep quality trends, duration, interruptions |
| 3 | WHO percentile curves, growth trajectory |
| 4 | Growth-support nutrient coverage, medication timing patterns |
