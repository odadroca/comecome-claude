# ComeCome Reconciled Sprint Plan

## Guiding Principle

> **The child's interaction surface stays flat. New depth goes to the guardian and clinician layers only.**

Every sprint explicitly defines a **child boundary** — what the child sees (or doesn't) — to preserve the current ADHD-optimized simplicity: emoji-first interactions, tap-portion-celebrate flow, minimal decision points.

---

## ▶ Plan of Record — updated 2026-06-19

> **This section is the current source of truth for sequencing and scope.** The detailed
> "Sprint 0–5" write-ups further below are retained as **task-level reference**, but the
> original Sprints 3–5 have been **re-sequenced and split** into the plan below, and five
> open questions have been **decided** (see [`docs/roadmap/DECISIONS.md`](../docs/roadmap/DECISIONS.md)).

### Shipped (v0.9.1, `schema_version = 2`)
Sprints **0** (bug fixes), **1** (feature-visibility toggles), **2** (sleep tracking) are **done**.
Footer is at **4 of max 5** items.

### Decisions locked (2026-06-19) — full rationale + citations in `docs/roadmap/DECISIONS.md`
| # | Decision | Choice |
|---|---|---|
| i | Growth reference | **WHO-first** (WHO 2006+2007, all ages) for Sprints 7–8; **CDC 2–19y + 2022 Ext. BMI as a follow-on** → hybrid end state. Arrays keyed `[standard][metric][sex][age]`. |
| ii | Height capture | **Fold into child weight page → "Growth"**, toggle-gated, no new footer item. |
| iii | Privacy (gender/DOB) | **Balanced** — gender+age+percentiles in clinician outputs; exact DOB guardian-side only; **JSON export whitelisted**. |
| iv | Missing demographics | **Graceful degradation + soft-warn**; enabling `show_percentiles` never blocks. |
| v | Security priority | New **Security & Deployment Foundations** track (auth/TLS + `.env` + tests) that also unblocks the deferred encryption. |

### Re-sequenced roadmap (next, on top of shipped 0–2)
| # | Sprint | Effort | Child boundary | Depends on |
|---|---|---|---|---|
| **3** | **Clinical Report Hardening + Correlations** — clinician-grade report + dashboard "Insights" + the sleep→next-day-appetite/mood correlation Sprint 2 specced but never built. **No schema / no migration.** | M | Zero | Sprints 0–2 |
| **4** | **Security & Deployment Foundations — Pt 1: migration/test safety net** — dependency-free `tests/run.php` (getDB / migrate-idempotency / backup-restore). **Lands before any new migration.** | S | Zero (dev tooling) | — |
| **5** | **Demographics Foundation** — `users.gender` + `users.date_of_birth` (the riskiest auth/identity migration), isolated and additive. | S | Zero (guardian-only) | Sprint 1 |
| **6** | **Growth Page Foundation** — `height_log`, `calculateBMI`, `show_percentiles` (default OFF); height folded into the weight page → "Growth". | M | 1 optional field, toggle-gated | Sprint 5 |
| **7** | **Percentiles Engine + WHO Reference Data** — read-only WHO LMS arrays (WHO 2006 0–5y + WHO 2007 5–19y; one provider, no seam) + side-effect-free engine, unit-tested. **No UI.** | L | None | Sprints 5–6 |
| **8** | **Percentiles Display** — dashboard + exports + guest-report; WHO bands/zones/trajectory (±2 SD), guardian/clinician-only. | M | Child chart unchanged | Sprint 7 |
| **8b** | **(Follow-on) CDC 2–19y hybrid** — add CDC 2000 + CDC 2022 Extended BMI under the `[standard]` key; age-based source selection (24-mo cutoff), mixed CDC thresholds, and the age-2 transition annotation. Realizes the hybrid end state (decision i). Additive; does not block 9–11. | M | None | Sprint 8 |
| **9** | **Medication Timing Foundation** — `medication_schedules` + `food_log.med_window` auto-stamped at insert. | L | Zero | shipped meds tables |
| **10** | **Nutrition Intelligence Discovery** (gating spike) — lock recommendation rules, tag-maintenance UX, SQLite read-lock approach. | S | None | Sprint 9 |
| **11** | **Growth-Support Nutrition Intelligence** — food tags + rule-based panel + clinician summary. **AI/LLM decision pending** — see [`docs/roadmap/SPRINT-11-nutrition-intelligence.md`](../docs/roadmap/SPRINT-11-nutrition-intelligence.md). | XL | Zero child UI | Sprints 8, 9, 10 |

**Security & Deployment Foundations track (decision v):** Sprint 4 is its first deliverable.
Its remaining parts — PIN brute-force lockout/throttling, `Secure`/`HttpOnly`/`SameSite` cookies,
session regeneration, deployment TLS guidance, and the `.env`/secrets pattern — form a parallel
workstream that can land any time after Sprint 4 and **unblocks** the still-**deferred** SQLCipher
at-rest encryption (scheduled only after this track + an explicit go decision).

### Cross-cutting rules (apply to every sprint — from the roadmap critique)
- Every `ALTER` in a version-gated `migrateDatabase()` block **and** mirrored in `db/schema.sql`.
- **Bump `sw.js CACHE_NAME`** whenever a child page or shared asset/JS changes (e.g. the Growth page in Sprint 6).
- Keep all **four export surfaces** (HTML, CSV, JSON, guest-report) in parity; **whitelist the JSON path** in `export.php` so new sensitive fields don't auto-leak.
- `pt.json` is **canonical** — real Portuguese clinical strings, key-parity with `en.json` verified each sprint.
- State the **child boundary** per sprint; footer ≤ 5 items.

### Backlog (booked, unscheduled)

Features/improvements captured but not yet sequenced into a sprint:

- **Height chart on the Growth page (motivational).** Add a height-over-time line chart to the
  child's Growth page, mirroring the existing weight chart. Framed **purely as encouragement** —
  a child is only expected to grow taller, so it's a "look how much you've grown!" celebration:
  **no percentile bands/zones/clinical flags on the child surface** (clinical growth context stays
  guardian/clinician-only, per the child boundary). Reuses the existing Chart.js pattern;
  complements Sprint 6 (height input) and Sprint 8 (guardian-side percentiles). Effort ~S.

- **Per-child feature toggles ("All" vs per registered child).** Today the child-feature
  visibility toggles (`show_food_journal` / `show_checkin` / `show_weight_tracking` /
  `show_sleep_tracking` / `show_percentiles` / `show_nutrition_insights` /
  `show_medication_to_children`) are **global** — one value applies to every child. Allow each
  toggle to be set either globally (**"All children"**) **or overridden per registered child**
  (e.g. a younger child sees fewer features than an older sibling). Implementation: a per-child
  override layer over the settings key/value model — e.g. a `user_settings(user_id, key, value)`
  table read by an extended `getSetting($key, $default, $childId)` resolving **per-child override →
  global → default**; the guardian Settings page gains a features × children matrix defaulting to
  "All"; the `index.php` route guards must resolve the toggle for the logged-in child. Effort ~M–L;
  builds directly on Sprint 1's toggle foundation.

Also tracked (quality / cleanup / product):
- BMI percentile **trajectory** (needs a same-date weight+height pairing rule; Sprint 8 ships current-rank only).
- Production-shaped `data.db` migration test; sleep `DATETIME`/`TEXT` normalization on migrated installs; i18n clinical-quality pass; WHO/CDC attribution (revisit with 8b).
- Product/positioning re: ADHD-as-a-label (deferred "thinking exercise"); demo-seeder anonymization (**done 2026-06-19**); child-UX footer budget (merge concepts before a 6th item).

---

## Sprint 0 — Bug Fixes

**Goal:** Fix two reported defects before building anything new.
**Child boundary:** No UI changes. Existing experience becomes more reliable.

### 0A. Duplicate last food in catalog

**Symptom:** The last food option in the food grid is always displayed twice (e.g. 2x pears, 2x pizzas).

**Root cause:** PHP foreach-by-reference bug in `pages/child/log-food.php`. At line 25, `foreach ($foods as &$food)` marks favorites via reference. After this loop, `$food` remains a live reference to the last element. Subsequent `foreach ($foods as $food)` loops overwrite the last element on every iteration, duplicating the second-to-last element.

**Fix:** Add `unset($food);` immediately after the first foreach loop, OR rewrite without reference:
```php
foreach ($foods as $key => $food) {
    $foods[$key]['is_favorite'] = in_array($food['id'], $favoriteIds);
}
```

**Files:** `pages/child/log-food.php` (1-2 lines)

---

### 0B. Favorites not persisting reliably

**Symptom:** A food appears favorited (star badge shows), but switching meals or re-logging reveals the favorite was not saved.

**Root causes (multiple contributing factors):**

1. **Double invocation from competing event handlers (PRIMARY):** In `log-food.php`, a long press triggers BOTH the custom `setTimeout(600ms)` handler (calls `toggleFavorite()`) AND the native `contextmenu` event (also calls `toggleFavorite()`). On mobile, both fire: first call ADDS the favorite, second call immediately REMOVES it. Net result: toggled twice, back to original state.

2. **Silent error swallowing:** `.catch(() => {})` silently discards all fetch errors. No feedback, no recovery.

3. **`data-is-favorite` attribute not updated:** The toggle handler updates CSS class and badge but never updates the `data-is-favorite` attribute, causing state inconsistency.

4. **No request debouncing:** No mechanism to prevent concurrent toggle requests for the same food.

5. **Non-transactional DB toggle:** `toggleFavorite()` does SELECT then INSERT/DELETE without a transaction. Concurrent requests can race.

6. **Interaction with bug 0A:** The foreach reference bug corrupts the last food's `is_favorite` flag.

**Fix:**
- Add `favoriteInFlight` set to prevent concurrent toggle calls on the same food ID
- In `contextmenu` handler, check if `isLongPress` is already true and skip the duplicate call
- Replace `.catch(() => {})` with error feedback and visual rollback
- Update `data-is-favorite` attribute alongside class and badge changes
- Wrap `toggleFavorite()` DB function in a `BEGIN/COMMIT` transaction

**Files:** `pages/child/log-food.php` (JS handlers), `includes/db.php` (transaction), `api/favorites.php` (error handling) — ~20-30 lines changed

---

## Sprint 1 — Feature Visibility Toggles

**Goal:** Let guardians toggle each main child feature on/off, following the existing pattern used by medications.
**Child boundary:** The child may see **fewer** footer buttons, never more. Features toggled off simply disappear.

### Current pattern (reference)

The `settings` table stores key-value pairs. The existing `show_medication_to_children` toggle:
1. Guardian checkbox in `settings.php` saves via `setSetting()`
2. Child pages read via `getSetting('show_medication_to_children', '1')` and conditionally render
3. History page also checks the setting

### New settings

| Setting key | Default | Effect when off |
|---|---|---|
| `show_food_journal` | `'1'` | Hides `log-food` and `history` from child footer + router |
| `show_checkin` | `'1'` | Hides `check-in` from child footer + router |
| `show_weight_tracking` | `'1'` | Hides `weight` from child footer + router |

### Tasks

**1.1 — Guardian settings page:** Add three checkbox toggles in a new "Child Features" section in `pages/guardian/settings.php`. Follow existing medication toggle pattern.

**1.2 — Shared child footer:** Extract duplicated footer navigation (~16 lines × 4 files) into `pages/child/footer.php`. The partial reads all toggle settings and renders only enabled buttons.

**1.3 — Route protection:** In `index.php`, check toggles before including child pages. If disabled, redirect to first available feature. Update default landing page logic.

**1.4 — History adaptation:** In `pages/child/history.php`, conditionally show/hide food log and check-in sections based on toggles.

**1.5 — i18n keys:** Add translation keys for toggle labels and descriptions in `locales/pt.json` and `locales/en.json`.

**Files:** `pages/guardian/settings.php`, all `pages/child/*.php`, new `pages/child/footer.php`, `index.php`, `db/seed.sql`, `locales/pt.json`, `locales/en.json`

---

## Sprint 2 — Sleep Tracking

**Goal:** Track daily sleeping patterns — accommodating naps, night interruptions — and surface the data in guardian dashboard and clinician reports.
**Child boundary:** One new emoji-scale row added to the existing check-in form ("How did you sleep?"). No new pages, no new footer buttons. Detailed sleep data (times, naps, interruptions) is entered by the guardian.

### Data model

#### New table: `sleep_log`

```sql
CREATE TABLE IF NOT EXISTS sleep_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    log_date DATE NOT NULL,
    sleep_type TEXT NOT NULL CHECK(sleep_type IN ('night', 'nap')),
    sleep_start DATETIME,             -- optional, guardian-entered
    sleep_end DATETIME,               -- optional, guardian-entered
    quality INTEGER CHECK(quality BETWEEN 1 AND 5),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_sleep_log_user_date ON sleep_log(user_id, log_date);
```

#### New table: `sleep_interruptions`

```sql
CREATE TABLE IF NOT EXISTS sleep_interruptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sleep_log_id INTEGER NOT NULL,
    wake_time DATETIME NOT NULL,
    back_to_sleep_time DATETIME,
    reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sleep_log_id) REFERENCES sleep_log(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_sleep_interruptions_log ON sleep_interruptions(sleep_log_id);
```

**Design rationale:**
- Normalized `sleep_interruptions` table (not a count column) — each wake event is a row with optional reason and timing, enabling richer analysis.
- `sleep_start`/`sleep_end` are optional — the child only enters quality via check-in. The guardian fills in exact times later via the management page.
- A single day can have one `night` entry and multiple `nap` entries.
- Duration and effective sleep are computed at query time, not stored.

#### Modify: `daily_checkin` table

```sql
ALTER TABLE daily_checkin ADD COLUMN sleep_quality INTEGER CHECK(sleep_quality BETWEEN 1 AND 5);
```

This captures the child's simple self-report. The detailed `sleep_log` entries are the guardian's optional elaboration.

### Feature toggle

Add `show_sleep_tracking` setting (default `'1'`), following Sprint 1's pattern.

### Child-side changes

**`pages/child/check-in.php`** — Add one new emoji-scale row between Mood and Medication:

```
How did you sleep? 😫 😴 😐 😊 🌟
```

Same pattern as appetite and mood — five radio buttons with emojis. Saved as `sleep_quality` in `daily_checkin`. Nothing else changes in the child flow.

### Guardian-side changes

1. **New page: `pages/guardian/manage-sleep.php`** — Detailed sleep logging. Guardian selects child and date, then can:
   - Enter/edit night sleep (bedtime, wake time)
   - Add interruption entries (wake time, back-to-sleep time, reason)
   - Add nap entries (start, end)
   - View weekly sleep summary

2. **Dashboard:** Add "Sleep Patterns" section to `pages/guardian/dashboard.php`:
   - Sleep quality trend (from `daily_checkin.sleep_quality`)
   - Average sleep duration (when times are logged)
   - Interruption frequency
   - Correlation view alongside appetite/mood — clinically valuable since sleep quality directly affects ADHD symptoms and next-day appetite

3. **Navigation:** Add "Sleep" link to `pages/guardian/nav.php`.

4. **Export/reports:** Add sleep data to `getReportData()`, `export-html.php`, `export-csv.php`, `guest-report.php`.

### API

- New endpoint: `api/sleep.php` — POST/GET/DELETE for sleep log entries (guardian use)
- Existing `api/check-in.php` — Accept `sleep_quality` field in POST body

### i18n

All sleep-related labels, emoji descriptions, interruption reasons, chart labels.

**Files:** `db/schema.sql`, `db/seed.sql`, `pages/child/check-in.php`, `api/check-in.php`, `includes/db.php`, new `pages/guardian/manage-sleep.php`, new `api/sleep.php`, `pages/guardian/nav.php`, `pages/guardian/dashboard.php`, `pages/guardian/settings.php`, `includes/helpers.php`, `pages/guardian/export-html.php`, `export-csv.php`, `pages/guest-report.php`, `locales/pt.json`, `locales/en.json`

---

## Sprint 3 — Percentiles Foundation

> **⚠ Re-sequenced (2026-06-19).** The Plan of Record above splits this into **Sprint 5
> (Demographics)** + **Sprint 6 (Growth Page)**, and `manage-users.php` is the route alias —
> the add/edit form lives in **`pages/guardian/manage-children.php`**. Decisions ii/iii/iv apply.
> Content below is task-level reference.

**Goal:** Add demographic fields (gender, date of birth), height tracking, and settings infrastructure required before percentile calculations.
**Child boundary:** One optional height input field added to the existing weight page. No new pages, no new footer buttons.

**Can run in parallel with Sprint 2.**

### 3.1 — Extend user model

```sql
ALTER TABLE users ADD COLUMN gender TEXT CHECK(gender IN ('male', 'female'));
ALTER TABLE users ADD COLUMN date_of_birth DATE;
```

Nullable — existing children work without them. Percentile calculations require both.

**Migration strategy:** Version-based migration using `schema_version` setting key:
```php
if (getSetting('schema_version', '1') < '2') {
    $db->exec("ALTER TABLE users ADD COLUMN gender TEXT ...");
    $db->exec("ALTER TABLE users ADD COLUMN date_of_birth DATE");
    setSetting('schema_version', '2');
}
```

### 3.2 — Manage children: gender & DOB fields

Update `pages/guardian/manage-users.php`:
- Add Gender radio buttons (Male/Female) — required only when percentiles are enabled
- Add Date of Birth date input — required only when percentiles are enabled
- Show validation warning if percentiles are on but child is missing these fields

Update `createUser()` and `updateUser()` in `includes/auth.php`.

### 3.3 — Height tracking

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

Integrate height input INTO the existing weight page (`pages/child/weight.php`), making it a combined "Growth" page. Height input only shown when `show_percentiles` is enabled. Same celebration flow.

### 3.4 — Settings toggle

Add `show_percentiles` to `pages/guardian/settings.php` (default `'0'` — off by default, requires gender/DOB setup). When toggling on, validate all active children have gender and DOB.

### 3.5 — Helper functions

```php
function calculateBMI($weightKg, $heightCm) { ... }
function calculateAgeInMonths($dateOfBirth) { ... }
```

### 3.6 — i18n keys

Gender options, date of birth label, height labels, percentile toggle label, validation messages.

**Files:** `db/schema.sql`, `db/seed.sql`, `includes/db.php`, `pages/guardian/manage-users.php`, `includes/auth.php`, `pages/child/weight.php`, `pages/guardian/settings.php`, `includes/helpers.php`, `locales/pt.json`, `locales/en.json`

---

## Sprint 4 — Percentiles Full

> **⚠ Re-sequenced (2026-06-19).** Split into **Sprint 7 (Engine + reference data, no UI)** +
> **Sprint 8 (Display)**. Per **decision (i, revised)** build **WHO-only first** (WHO 2006 0–5y + WHO
> 2007 5–19y); **CDC 2–19y + 2022 Ext. BMI** land as an additive **follow-on (Sprint 8b)** → hybrid
> end state. Content below is task-level reference.

**Goal:** Implement WHO growth standard percentile calculations and display them in charts and reports.
**Child boundary:** The child's weight chart remains a simple line chart — no percentile curves shown to the child. Encouraging language only (e.g. "Growing well!" not "Below 3rd percentile").

**Prerequisite:** Sprint 3 complete.

### 4.1 — WHO reference data

Store LMS (Lambda-Mu-Sigma) parameters as static PHP arrays in `includes/growth-standards.php`:
- Weight-for-age: 0-120 months (boys and girls)
- Height-for-age: 0-228 months (boys and girls)
- BMI-for-age: 24-228 months (boys and girls)

Source (per **decision (i, revised)**, 2026-06-19): **WHO-first** — build on **WHO 2006 (0–5y) + WHO 2007 (5–19y)** only (one provider, no age-2 seam); add **CDC 2000 (2–19y) + CDC 2022 Extended BMI** as an additive **follow-on (Sprint 8b)** to reach the hybrid end state. Key arrays `[standard][metric][sex][ageMonths]`. Document source/version/license inline.

### 4.2 — Percentile calculation engine

New file: `includes/percentiles.php`

```
WHO LMS method:
Z = ((value/M)^L - 1) / (L * S)     when L ≠ 0
Z = ln(value/M) / S                  when L = 0
Percentile = Φ(Z)  (standard normal CDF)
```

Functions: `calculateZScore()`, `zScoreToPercentile()`, `calculateWeightForAgePercentile()`, `calculateHeightForAgePercentile()`, `calculateBMIForAgePercentile()`.

Standard normal CDF via rational approximation (Abramowitz & Stegun).

### 4.3 — Guardian dashboard: percentile section

New "Growth Percentiles" section (visible only when toggle is on and child has DOB + gender):
- Current percentile rank for weight, height, BMI with colored indicator
- WHO percentile bands overlay on weight/height charts (3rd, 15th, 50th, 85th, 97th)
- Color-coded zones: green (P15-P85), yellow (P3-P15 or P85-P97), red (<P3 or >P97)
- Trend: "Moving from P25 → P35 over last 3 months"

### 4.4 — Export and guest report

Include percentile data in `getReportData()`, `export-html.php`, `export-csv.php`, `guest-report.php`. WHO-standard percentile charts in HTML reports. Clinicians expect growth percentile trajectories as standard clinical metrics.

### 4.5 — i18n keys

Percentile labels, growth standard terminology, zone descriptions, encouraging messages per zone.

**Files:** new `includes/growth-standards.php`, new `includes/percentiles.php`, `pages/guardian/dashboard.php`, `includes/helpers.php`, `pages/guardian/export-html.php`, `export-csv.php`, `pages/guest-report.php`, `locales/pt.json`, `locales/en.json`

---

## Sprint 5 — Growth-Support Nutrition Intelligence

> **⚠ Re-sequenced (2026-06-19).** Now **Sprint 9 (Medication Timing)** + **Sprint 10 (discovery
> spike)** + **Sprint 11 (tags + panel)**. **AI/LLM scope is an open decision** — see the dedicated
> doc [`docs/roadmap/SPRINT-11-nutrition-intelligence.md`](../docs/roadmap/SPRINT-11-nutrition-intelligence.md). Content below is task-level reference.

**Goal:** Add a behind-the-scenes nutrition intelligence layer that provides clinically meaningful insights to guardians and clinicians, WITHOUT adding ANY cognitive load to the child.
**Child boundary:** Absolutely zero changes to the child UI. Same foods, same portions, same confetti. All new data is invisible metadata.

**Prerequisite:** Sprint 4 complete (percentiles provide the growth context that makes nutrition intelligence actionable). Sleep data (Sprint 2) enriches correlation analysis.

### Part A: Growth-support food tags

Instead of micronutrient tracking, tag foods with strategic growth-support categories relevant to ADHD children on stimulant medication.

```sql
CREATE TABLE IF NOT EXISTS food_growth_tags (
    food_id INTEGER NOT NULL,
    tag TEXT NOT NULL CHECK(tag IN (
        'calorie_dense',       -- High kcal per volume (nuts, cheese, avocado)
        'protein_rich',        -- Growth-essential (eggs, chicken, fish, dairy)
        'bone_building',       -- Calcium + Vitamin D (milk, yogurt, cheese)
        'brain_fuel',          -- Omega-3, complex carbs (fish, whole grains, nuts)
        'easy_to_eat',         -- Low-effort for suppressed appetite (smoothies, yogurt, crackers)
        'hydrating'            -- Water-rich (watermelon, cucumber, soups)
    )),
    PRIMARY KEY (food_id, tag),
    FOREIGN KEY (food_id) REFERENCES foods(id) ON DELETE CASCADE
);
```

**Why these tags (ADHD + stimulant medication rationale):**

| Tag | Clinical relevance |
|---|---|
| `calorie_dense` | During peak medication, appetite is severely suppressed. Every bite must count. A handful of nuts (300 kcal) beats a plate of lettuce (15 kcal). |
| `protein_rich` | Critical for growth, stabilizes blood sugar which benefits ADHD focus. |
| `bone_building` | Stimulants (particularly methylphenidate) are associated with reduced bone mineral density. |
| `brain_fuel` | Omega-3s have evidence supporting ADHD symptom management. Complex carbs provide sustained energy vs. sugar spike-crash. |
| `easy_to_eat` | During peak medication, a full plate is overwhelming. Yogurt, nuts, smoothies, crackers with cheese are more likely to be accepted. |
| `hydrating` | Stimulants cause dry mouth and reduced thirst awareness. Water-rich foods supplement fluid intake passively. |

Each existing seed food gets pre-tagged. New guardian-added foods default to no tags (opt-in). Guardian manages tags via checkboxes in `pages/guardian/manage-foods.php`.

### Part B: Medication timing windows

For children on stimulant medications, WHEN they eat matters as much as WHAT they eat.

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

**Offset-based design** — the guardian sets dose time and the system auto-calculates windows:

| Window | Derivation |
|---|---|
| `pre_med` | Before `dose_time` |
| `mid_med` (peak suppression) | `dose_time + peak_start_offset` → `dose_time + peak_end_offset` |
| `onset` | `dose_time` → `dose_time + peak_start_offset` |
| `post_med` (rebound) | After `dose_time + peak_end_offset` |

**Default offsets by medication type:**

| Type | peak_start_offset | peak_end_offset |
|---|---|---|
| Short-acting (Ritalina IR) | 30 min | 240 min |
| Long-acting (Concerta, Ritalina LA) | 30 min | 480 min |
| Non-stimulant (Strattera/Atomoxetine) | N/A (less appetite impact) | N/A |

Guardian can adjust offsets to match their child's actual response pattern (individual variation is significant).

**Automatic food log enrichment:** Add `med_window` column to `food_log`:
```sql
ALTER TABLE food_log ADD COLUMN med_window TEXT CHECK(med_window IN ('pre_med', 'onset', 'mid_med', 'post_med'));
```
Populated automatically at INSERT time by comparing `log_time` against the child's medication schedule. Zero child-side changes.

### Guardian configuration

**`pages/guardian/manage-medications.php`:** When assigning medication to a child, allow guardian to set:
- Typical dose time (e.g. "08:00")
- Medication type dropdown with auto-populated offset defaults
- Manual override of peak start/end offsets

**`pages/guardian/manage-foods.php`:** Checkboxes for growth tags when adding/editing foods.

### Dashboard: Nutrition Intelligence panel

New section in `pages/guardian/dashboard.php`:

**A. Timing Analysis:**
- Calorie distribution by medication window (pie/bar chart)
- Highlight missing pre-med breakfasts or empty mid-med windows

**B. Growth-Tag Coverage (weekly rolling):**
- Servings per tag with trend arrows
- Flag underserved categories

**C. Strategic Recommendations (rule-based, not AI-generated):**
- "Consider offering easy-to-eat snacks during the 10:00–14:00 window when appetite is lowest"
- "Pre-medication breakfast was skipped 4 times this week — this is the golden window for calorie-dense foods"
- Cross-reference with percentile trends and sleep quality when available

### Clinician report

Add "Medication-Aware Nutrition Summary" to `guest-report.php` and `export-html.php`:
- Intake distribution by medication window
- Growth-tag coverage trends
- Weight percentile trajectory alongside nutrition patterns
- Sleep quality correlation with next-day appetite (if sleep tracking enabled)

### Feature toggle

Add `show_nutrition_insights` setting (default `'0'`). When on:
- Nutrition tags shown in food management
- Growth-support summary on dashboard
- Medication timing analysis on dashboard (only if child has medication schedules)
- Export includes nutrition and timing data

### i18n

Growth-tag labels, timing window names, insight messages, recommendation templates, medication type labels.

**Files:** `db/schema.sql`, `db/seed.sql`, `pages/guardian/manage-foods.php`, `pages/guardian/manage-medications.php`, `pages/guardian/settings.php`, `pages/guardian/dashboard.php`, new `includes/nutrition.php`, `includes/helpers.php`, `api/food-log.php`, `pages/guardian/export-html.php`, `export-csv.php`, `pages/guest-report.php`, `locales/pt.json`, `locales/en.json` — zero child-facing page changes

---

## Sprint Dependency Map

```
Sprint 0  (Bug Fixes)
    │
    ▼
Sprint 1  (Feature Toggles)  ← foundational; all later sprints depend on this
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
       Sprint 5
   (Nutrition Intelligence)
```

Sprints 0 and 1 are strictly sequential. Sprints 2 and 3 are independent and can run in parallel. Sprint 5 comes last because it benefits from the full data model (sleep, percentiles, toggles) being in place.

---

## What changes per user type

### Child

| Sprint | What the child sees change |
|---|---|
| 0 | Nothing (bugs fixed invisibly) |
| 1 | Some footer buttons may disappear (if guardian disables features) |
| 2 | One new emoji row in check-in ("How did you sleep?") |
| 3 | One optional height field on the weight page |
| 4 | Nothing (percentile curves are guardian/clinician-only) |
| 5 | Nothing at all |

### Guardian

| Sprint | What the guardian gains |
|---|---|
| 0 | More reliable app behavior |
| 1 | Feature toggles in settings |
| 2 | Sleep management page, sleep trends on dashboard |
| 3 | Gender/DOB fields for children, height tracking |
| 4 | Growth percentile charts on dashboard |
| 5 | Nutrition tag management, medication timing config, nutrition insights dashboard |

### Clinician (guest report)

| Sprint | What the clinician report gains |
|---|---|
| 0 | More accurate data |
| 1 | Awareness of which features are active |
| 2 | Sleep quality trends, duration, interruptions |
| 3 | Height tracking data |
| 4 | WHO percentile curves, growth trajectory |
| 5 | Growth-support nutrient coverage, medication timing patterns |

---

## Risk Considerations

### Database Migrations
No migration system exists. Implement version-based migration using `schema_version` setting key, with each sprint's migrations wrapped in version checks. SQLite's `ALTER TABLE` only supports `ADD COLUMN`.

### SQLite Concurrency
Multiple simultaneous API calls can hit SQLite's write lock. Client-side debouncing (Sprint 0) is sufficient for now. For Sprint 5's analytics queries, consider read-only connections or caching.

### Child UX Complexity Budget
Current footer: 4 items. Adding sleep (Sprint 2) would make 5. **Maximum 5 footer items.** Height integrates into the weight page (not a separate nav item). If sleep becomes a dedicated page in the future, consider combining check-in to encompass appetite + mood + sleep + medication as a single daily reflection.

### Growth Tag Maintenance
Default tags cover seed foods. Guardian-added foods default to no tags (opt-in). Prompt guardians to tag new foods but don't block on it.

### Percentile Data Accuracy
Reference data is published per month of age; use linear interpolation of LMS values between points.
Per **decision (i, revised)** the build is **WHO-first** (WHO 2006 0–5y + WHO 2007 5–19y, continuous,
no seam); **CDC 2–19y + 2022 Extended BMI** land as a **follow-on (Sprint 8b)**. The **age-2 WHO→CDC
z-score discontinuity** (2025 AAP study: mean BMI-z drop ~0.59) applies **only once CDC is
introduced** — annotate it in the report then. Still define behavior for out-of-coverage metric/age
and cap extreme z-scores. Document source/version/license for each dataset.

### Medication Timing Assumptions
Default timing offsets are approximations. Individual response to stimulant medication varies significantly. Manual override is essential — defaults are starting points, not prescriptions.

---

## Summary Table

| Sprint | Key Deliverables | Scope | Dependencies |
|---|---|---|---|
| **0** | Fix duplicate foods bug, fix favorites persistence | Small | None |
| **1** | Feature visibility toggles in guardian settings | Medium | Sprint 0 |
| **2** | Sleep tracking: model, check-in row, guardian page, dashboard, export | Large | Sprint 1 |
| **3** | Gender/DOB fields, height tracking, percentile settings | Medium | Sprint 1 |
| **4** | WHO reference data, percentile calculations, chart overlays | Large | Sprint 3 |
| **5** | Growth tags, medication timing, nutrition insights | Very Large | Sprint 4 (+Sprint 2 for correlations) |
