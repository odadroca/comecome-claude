# ComeCome - Sprint Plan

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
The existing toggle follows this pattern:
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

**Notable gaps for planned features**: No `gender`, `date_of_birth`, or `height` fields on `users`. No sleep-related tables.

---

## Sprint Plan

### Sprint 0 — Bug Fixes (Foundation)

**Rationale**: Fix existing bugs before building new features on top of potentially flawed foundations. Both bugs affect the food journal, which is the core feature.

#### Task 0.1 — Bug: Last food item displayed twice in catalog

**Symptom**: On the food catalog displayed for each meal, the last food option always appears duplicated (e.g., 2x pears, 2x pizzas).

**Root cause analysis**: In `log-food.php`, when a user has favorites, the page renders two grids:
1. **Favorites grid** (lines 109-120): Renders `$mealFavorites` — all user favorites that are available in the current meal
2. **All foods grid** (lines 135-149): Renders the full `$foods` array, including items that are also in favorites

This means any favorited food appears twice on screen. The "last item" pattern the user observes is likely because their last item happens to be a favorite, or there may be an additional issue with the SQL query or seed data producing a genuine duplicate at the end of the result set.

**Fix approach**:
- In the "All foods" grid, either: (a) filter out items already shown in favorites, or (b) accept the current design where favorites appear in both sections (some UIs do this intentionally) but investigate whether the SQL query (`getFoodsForMeal` in `db.php:121-135`) is actually returning duplicate rows via the `JOIN meal_categories` path
- Verify seed data in `db/seed.sql` for duplicate `meal_categories` mappings that could defeat `DISTINCT`
- Add integration test or manual verification for each meal type

**Files to modify**: `includes/db.php` (query), `pages/child/log-food.php` (rendering), possibly `db/seed.sql`

#### Task 0.2 — Bug: Favorites not persisting properly

**Symptom**: A food appears to be successfully added to favorites, but selecting a different meal or logging out and back in shows no recorded favorite.

**Root cause analysis** (multiple contributing factors):
1. **Silent error swallowing** (`log-food.php:355`): The `catch(() => {})` on the favorites API call silently swallows ALL network and parsing errors. The UI updates optimistically (classList.toggle) but the server may never receive or process the request.
2. **Non-atomic toggle** (`db.php:159-177`): The `toggleFavorite()` function does a SELECT then INSERT/DELETE in two separate statements without a transaction. Rapid toggling or concurrent requests could produce inconsistent state.
3. **Optimistic UI without rollback**: The JS toggles the `is-favorite` class and adds/removes the star badge immediately on API response, but if the response fails, the UI is left in an incorrect state with no recovery mechanism.
4. **Page reload resets to DB truth**: When switching meals (which reloads the page) or logging out/in, the page re-fetches from the database, revealing the discrepancy between what the user saw and what was actually saved.

**Fix approach**:
- Replace silent `catch(() => {})` with user-facing error feedback and UI rollback on failure
- Wrap the `toggleFavorite()` DB function in a transaction for atomicity
- Consider adding a retry mechanism or at minimum a visible error state
- Add proper error logging server-side

**Files to modify**: `pages/child/log-food.php` (JS error handling), `includes/db.php` (transaction wrapping), `api/favorites.php` (error responses)

---

### Sprint 1 — Feature Toggles for Main Areas

**Rationale**: Establish the toggle infrastructure for all main child-facing features before adding new ones. This mirrors the existing medication toggle pattern but at a higher level (entire feature areas).

#### Task 1.1 — Add toggle settings to Guardian Settings page

Add three new toggles to `settings.php`:
- `show_food_journal` (default: `1`) — Show/hide the food journal feature
- `show_checkin` (default: `1`) — Show/hide the daily check-in feature
- `show_weight_log` (default: `1`) — Show/hide the weight tracking feature

These use the existing `settings` table (key-value store) and the existing `getSetting()`/`setSetting()` functions. No schema changes needed.

**Files to modify**: `pages/guardian/settings.php`

#### Task 1.2 — Update child navigation to respect toggles

The child footer navigation (`log-food.php`, `check-in.php`, `weight.php`, `history.php`) has hardcoded links to all four sections. Each page must:
1. Read the toggle settings at the top
2. Conditionally render footer navigation items
3. Handle the case where the user tries to access a disabled feature directly via URL (redirect to an enabled feature)

**Files to modify**: All child pages (`pages/child/*.php`), `index.php` (routing guards)

#### Task 1.3 — Update history and dashboard to respect toggles

- **Child history** (`history.php`): Conditionally show/hide food log section and check-in summary based on toggles
- **Guardian dashboard** (`dashboard.php`): Conditionally show/hide weight chart, appetite/mood history, food intake chart
- **Guardian manage-logs** (`manage-logs.php`): Respect food journal toggle
- **Export pages** (`export.php`, `export-csv.php`, `export-html.php`): Conditionally include/exclude data sections

**Files to modify**: `pages/child/history.php`, `pages/guardian/dashboard.php`, `pages/guardian/manage-logs.php`, `pages/guardian/export*.php`

#### Task 1.4 — i18n keys for new toggle labels

Add translation keys for the new toggle labels and descriptions in both `pt` and `en` locale files.

**Files to modify**: `locales/en.json`, `locales/pt.json`

---

### Sprint 2 — Sleep Tracking Feature

**Rationale**: With the toggle infrastructure from Sprint 1 in place, the sleep tracker can be added as a new toggleable feature. This sprint introduces both the data model and the full UI.

#### Task 2.1 — Database schema: Sleep log model

**Proposed schema**:

```sql
-- Sleep sessions (supports multiple entries per day: night sleep + naps)
CREATE TABLE IF NOT EXISTS sleep_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    log_date DATE NOT NULL,           -- The calendar date this sleep is associated with
    sleep_type TEXT NOT NULL CHECK(sleep_type IN ('night', 'nap')),
    sleep_start DATETIME NOT NULL,    -- When sleep began (full datetime for overnight spans)
    sleep_end DATETIME,               -- When sleep ended (NULL if still sleeping)
    quality INTEGER CHECK(quality BETWEEN 1 AND 5),  -- Optional: 1=terrible, 5=great
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Night interruptions (linked to a sleep session)
CREATE TABLE IF NOT EXISTS sleep_interruptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sleep_log_id INTEGER NOT NULL,
    wake_time DATETIME NOT NULL,      -- When the child woke up
    back_to_sleep_time DATETIME,      -- When the child fell back asleep (NULL if stayed up)
    reason TEXT,                       -- Optional: e.g., 'bathroom', 'nightmare', 'thirst'
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sleep_log_id) REFERENCES sleep_log(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_sleep_log_user_date ON sleep_log(user_id, log_date);
CREATE INDEX IF NOT EXISTS idx_sleep_interruptions_log ON sleep_interruptions(sleep_log_id);
```

**Model design rationale**:
- **`sleep_log`**: Each row is one sleep session. A typical day might have 1 night sleep + 0-2 naps. Using `DATETIME` for start/end allows overnight spans (e.g., 21:00 to 07:00) without date-boundary issues.
- **`sleep_type`**: Distinguishes night sleep from naps, enabling separate analysis.
- **`sleep_interruptions`**: Linked to a night sleep session. Each row represents one waking event. The `reason` field is free text but could be constrained to a set of common reasons via the UI (dropdown).
- **`quality`**: Optional subjective rating, consistent with the 1-5 scale used for appetite and mood in `daily_checkin`.
- **`log_date`**: The "logical" date (e.g., bedtime at 21:00 on Feb 20 and wake at 07:00 on Feb 21 both belong to `log_date = 2026-02-20`).
- **Calculated fields** (derived, not stored): total sleep duration, number of interruptions, effective sleep time (total minus interruption gaps). These are computed at query time or in PHP.

#### Task 2.2 — Backend: DB functions and API endpoint

- Add `logSleep()`, `getSleepByDate()`, `getSleepHistory()`, `addSleepInterruption()` functions to `includes/db.php`
- Create `api/sleep.php` for CRUD operations
- Add `sleep_log` data to `getDashboardData()` and `getReportData()` in `helpers.php`

**Files to create**: `api/sleep.php`
**Files to modify**: `includes/db.php`, `includes/helpers.php`, `db/schema.sql`

#### Task 2.3 — Child-facing sleep logging page

Create `pages/child/sleep.php` with an ADHD-friendly interface:
- **Night sleep entry**: Bedtime picker, wake time picker, quality rating (emoji scale like check-in)
- **Add interruptions**: Simple "Add wake-up" button that collects wake time and optional reason
- **Nap entry**: Quick-add for naps with start/end time
- **Today's sleep summary**: Visual summary of last night + any naps
- **Celebration**: Confetti + encouragement on save (consistent with existing UX)

**Files to create**: `pages/child/sleep.php`
**Files to modify**: `index.php` (add route), all child footer navs (add sleep icon)

#### Task 2.4 — Guardian sleep management and overview

- Add sleep data to the guardian dashboard (`dashboard.php`): sleep duration trend chart, interruption frequency, average quality
- Add sleep entries to `manage-logs.php` for guardian editing
- Add sleep data to export functionality
- Add `show_sleep_tracking` toggle to settings (following Sprint 1 pattern)

**Files to modify**: `pages/guardian/dashboard.php`, `pages/guardian/manage-logs.php`, `pages/guardian/settings.php`, `pages/guardian/export*.php`

#### Task 2.5 — History integration

- Add sleep summary section to `pages/child/history.php`
- Show: last night duration, quality, number of interruptions, naps taken
- Correlate with check-in data (e.g., show mood alongside sleep quality for pattern recognition)

**Files to modify**: `pages/child/history.php`

#### Task 2.6 — i18n keys for sleep feature

Add all translation keys for sleep-related labels, placeholders, encouragements, and interruption reasons.

**Files to modify**: `locales/en.json`, `locales/pt.json`

---

### Sprint 3 — Growth Percentiles

**Rationale**: This is the most complex feature, requiring external reference data (WHO growth standards), schema extensions for child demographic data, and careful calculation logic. It depends on weight log data already being collected.

#### Task 3.1 — Extend user model for demographics

Add columns to the `users` table:
```sql
ALTER TABLE users ADD COLUMN gender TEXT CHECK(gender IN ('male', 'female'));
ALTER TABLE users ADD COLUMN date_of_birth DATE;
```

Add a `height_log` table (required for BMI calculation):
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
```

**Migration strategy**: Since this is SQLite and the app uses `CREATE TABLE IF NOT EXISTS`, add `ALTER TABLE` statements wrapped in try/catch to the initialization flow, or add a migration version system.

**Files to modify**: `db/schema.sql`, `includes/db.php` (migration logic)

#### Task 3.2 — Update child management for gender and DOB

- Add gender selector (required when percentiles are enabled) and date of birth input to `manage-children.php`
- Enforce: if `show_percentiles` setting is `1`, gender and DOB become required fields for children
- Add validation feedback if percentiles are enabled but child is missing gender/DOB

**Files to modify**: `pages/guardian/manage-children.php`, `includes/auth.php` (createUser/updateUser functions)

#### Task 3.3 — Height tracking page (child-facing)

Create a height entry interface similar to the weight page:
- Simple numeric input for height in cm
- Height trend chart
- History table

**Files to create**: `pages/child/height.php`, `api/height.php`
**Files to modify**: `index.php` (route), child footer navs, `includes/db.php`

#### Task 3.4 — WHO growth standard reference data

Incorporate WHO Child Growth Standards LMS data for:
- **Weight-for-age** (0-10 years, or 0-120 months)
- **Height-for-age** (0-19 years)
- **BMI-for-age** (2-19 years)

Each has separate tables for boys and girls with L (lambda), M (mu), S (sigma) values by age in months.

**Approach options**:
- **Option A**: Store LMS tables as PHP arrays in a dedicated file (simplest, no DB changes, ~200-300 rows per indicator)
- **Option B**: Store in SQLite tables (more structured, queryable)

Recommendation: **Option A** — a PHP include file with static arrays. The data is read-only reference data and doesn't belong in the user database.

**Files to create**: `includes/growth-standards.php` (WHO LMS data), `includes/percentiles.php` (calculation functions)

#### Task 3.5 — Percentile calculation engine

Implement the WHO z-score / percentile calculation:
```
Z = ((measurement / M)^L - 1) / (L * S)    when L ≠ 0
Z = ln(measurement / M) / S                 when L = 0
```

Then convert Z-score to percentile using the standard normal cumulative distribution function.

Functions needed:
- `calculateWeightForAgePercentile($weightKg, $ageMonths, $gender)`
- `calculateHeightForAgePercentile($heightCm, $ageMonths, $gender)`
- `calculateBMIForAgePercentile($bmi, $ageMonths, $gender)`
- `calculateBMI($weightKg, $heightCm)`
- `getChildAgeInMonths($dateOfBirth)`

**Files to create**: `includes/percentiles.php`

#### Task 3.6 — Percentile display in dashboard and child views

- **Guardian dashboard** (`dashboard.php`): Add percentile cards/charts showing current percentile position and trend over time for each indicator
- **Child weight page** (`weight.php`): Optionally show percentile alongside weight entry (if toggle is on)
- **Child height page** (`height.php`): Show percentile alongside height entry
- **Export**: Include percentile data in reports

**Files to modify**: `pages/guardian/dashboard.php`, `pages/child/weight.php`, `pages/child/height.php`, `pages/guardian/export*.php`

#### Task 3.7 — Percentile toggle and gender enforcement

- Add `show_percentiles` toggle to `settings.php`
- When enabled, validate that all active children have `gender` and `date_of_birth` set
- Show warning/prompt on the settings page if any child is missing this data
- Link to `manage-children.php` for completing the required fields

**Files to modify**: `pages/guardian/settings.php`

#### Task 3.8 — i18n keys for percentile feature

Add translation keys for percentile labels, growth standard terminology, gender options, and validation messages.

**Files to modify**: `locales/en.json`, `locales/pt.json`

---

## Sprint Dependency Graph

```
Sprint 0 (Bug Fixes)
    │
    ▼
Sprint 1 (Feature Toggles)
    │
    ├──────────────────┐
    ▼                  ▼
Sprint 2            Sprint 3
(Sleep Tracking)    (Percentiles)
```

- **Sprint 0 → Sprint 1**: Bug fixes first, then the toggle system. The toggles need a stable food catalog and favorites to be meaningful.
- **Sprint 1 → Sprint 2**: Sleep tracking should be a toggleable feature from day one.
- **Sprint 1 → Sprint 3**: Percentiles should also be toggleable. Sprint 2 and Sprint 3 are independent of each other and could be developed in parallel or in either order.

## Risk Notes

- **Sprint 3 complexity**: The WHO growth standards data and percentile calculations are the most technically involved. Consider whether a simpler approach (e.g., pre-built percentile lookup tables instead of LMS calculation) would be sufficient.
- **SQLite migrations**: The project has no migration system. Adding columns to existing tables in SQLite requires careful handling (ALTER TABLE is limited in SQLite). A lightweight migration versioning system should be considered.
- **Sleep tracking UX**: The sleep logging interface needs careful design for ADHD-friendliness. Time pickers can be frustrating; consider sensible defaults (e.g., pre-fill bedtime based on patterns) and minimal-tap interactions.
- **Child footer navigation**: Adding a 5th item (sleep) to the footer nav may crowd the mobile layout. Consider either a scrollable nav or combining related features.
