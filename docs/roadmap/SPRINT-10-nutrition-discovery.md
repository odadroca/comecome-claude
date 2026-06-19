# Sprint 10 — Nutrition Intelligence Discovery (Gating Spike)

> **Status: DECISION DOCUMENT.** This is a *discovery spike*, not a feature build. It produces
> the concrete specification that unblocks **Sprint 11** ("Growth-Support Nutrition
> Intelligence"). **No schema change, no migration, zero UI change, no `sw.js` bump.**
> `schema_version` stays **5**. Read alongside
> [`SPRINT-11-nutrition-intelligence.md`](SPRINT-11-nutrition-intelligence.md) and
> [`DECISIONS.md`](DECISIONS.md).

## 0. Scope and ground truth

Sprint 11 §5 lists three "open / concept-only items" that block the build, and §6 leaves the
AI/LLM boundary as a decision. This document **resolves all of them** so Sprint 11 can be built
directly from it. It also confirms two things Sprint 9 already shipped, so the analytics layer
reads them correctly.

What already exists in the tree (the data this sprint plans against — all verified in code):

| Capability | Where | Signature / shape Sprint 11 reads |
|---|---|---|
| Med-window stamping | `includes/medication.php`, `includes/db.php::logFood()` | `food_log.med_window` ∈ `{pre_med, onset, mid_med, post_med, NULL}`, stamped server-side at INSERT |
| Med-window classifier | `includes/medication.php::computeMedWindow($userId,$logTime)` | priority `mid_med > onset > post_med > pre_med`; NULL when no active stimulant schedule |
| Schedules | `medication_schedules` + `getActiveMedicationSchedules($userId)` | `dose_time`, `peak_start_offset`, `peak_end_offset`, `med_type`, `active` |
| Percentiles | `includes/percentiles.php` | `calculateWeightForAgePercentile($v,$ageMonths,$sex)` etc. → percentile 0–100 or NULL; `calculateMetricZScore(...)` → clamped z or NULL |
| Sleep quality | `includes/db.php::getSleepQualityHistory($userId,$start,$end)` | rows `{check_date, sleep_quality (1–5)}` |
| Sleep detail | `includes/db.php::getSleepHistory(...)` | rows `{log_date, sleep_type, quality, interruption_count}` |
| Report aggregation | `includes/helpers.php::getReportData($userId,$start,$end)` | the single place Sprint 11 should hang new derived fields |
| Connection | `includes/db.php::getDB()` | PDO sqlite, `PRAGMA busy_timeout = 5000` already set (Sprint 3) |

The `food_growth_tags` table is **not yet created** — it is a Sprint 11 deliverable (one
version-gated `ALTER`/`CREATE` bumping `schema_version` 5→6). This spike specifies how it is
consumed; it does **not** create it.

---

## 1. The rule-based strategic-recommendation spec (resolves §5.1)

This is the testable replacement for the prose in Sprint 11 §3.2-C. It is a **deterministic
rules engine** — explicitly NOT AI. Every rule is a pure function of aggregates already
derivable from the tables above over a **rolling 7-day window** ending on the report's
`endDate`.

### 1.1 Definitions (the analyzer's intermediate values)

Computed once per child per report window in `includes/nutrition.php` (Sprint 11):

- **`N`** = number of distinct days in the window that have **≥1 food_log row** (the
  "logged days"). Rules with a "`on ≥M of 7 days`" clause use `M` against `N`, and every rule
  is **suppressed when `N < 3`** (sparse-data floor — see §1.4).
- **`windowShare[w]`** = `count(food_log rows with med_window = w) / count(rows with med_window
  IS NOT NULL)`, for `w ∈ {pre_med, onset, mid_med, post_med}`. Rows with `med_window IS NULL`
  (no active stimulant schedule) are **excluded from the denominator** — timing rules simply do
  not fire for a child with no stimulant schedule.
- **`preMedDays`** = number of logged days with **≥1** `pre_med` row.
- **`tagServings[t]`** = count of food_log rows in the window whose `food_id` carries tag `t`
  (join `food_log` × `food_growth_tags`). A single logged food contributes to every tag it has.
- **`tagServingsPrev[t]`** = same, for the **preceding** 7-day window (days −13..−7), used for
  trend arrows.
- **`pctTrend`** = sign of the change in weight-for-age percentile over the report window:
  computed from the earliest vs. latest weigh-in's `calculateWeightForAgePercentile()` in
  window; one of `rising | stable | falling | unknown` (`unknown` when <2 weigh-ins or missing
  gender/DOB). "falling" = a drop of **>5 percentile points**; "rising" = a gain of >5; else
  "stable".
- **`avgSleep`** = mean of `daily_checkin.sleep_quality` (1–5) over the window via
  `getSleepQualityHistory()`; `null` when no self-reports.

### 1.2 The rule set (id, condition, severity, copy key)

Severity drives ordering and color (`info < suggest < watch`). Each rule emits **at most one**
card; the panel shows the **top 3 by severity then rule order**. All copy is templated and
localized (pt canonical); `{…}` are interpolated integers/percent.

| id | Fires when | Severity | Copy key (en gloss) |
|---|---|---|---|
| `R1_post_med_heavy` | `windowShare[post_med] > 0.50` **and** `N ≥ 4` | watch | "More than half of meals ({pct}%) land in the post-medication rebound window. Consider offering a calorie-dense option **before** the dose, when appetite is highest." |
| `R2_pre_med_skipped` | `preMedDays ≤ (N − 4)` i.e. **no pre-med intake on ≥4 of the logged days** | watch | "No food logged before medication on {missDays} of {N} days. The pre-medication window is the golden window for calorie-dense breakfasts." |
| `R3_mid_med_dominant` | `windowShare[mid_med] > 0.40` **and** `N ≥ 4` | suggest | "{pct}% of intake falls during peak appetite-suppression. Easy-to-eat, calorie-dense snacks (yogurt, nut butter, smoothies) are accepted more readily here than full plates." |
| `R4_tag_underserved` | for any tag `t`: `tagServings[t] == 0` over the window **and** `N ≥ 4` | suggest | "No **{tag}** foods logged this week. {tag_rationale}" (rationale from the tag table, §2.2) |
| `R5_tag_declined` | for any tag `t`: `tagServingsPrev[t] ≥ 3` **and** `tagServings[t] ≤ tagServingsPrev[t] − 3` | info | "**{tag}** servings dropped to {now} this week (from {prev})." |
| `R6_growth_plus_timing` | `pctTrend == 'falling'` **and** (`R1` **or** `R2` fired) | watch | "Weight-for-age percentile is trending down while most intake sits outside the high-appetite window — worth reviewing meal timing with the clinician." |
| `R7_sleep_appetite` | `avgSleep ≤ 2.5` **and** `windowShare[post_med] > 0.40` | info | "Lower self-reported sleep ({avg}/5) is coinciding with mostly post-medication eating; poor sleep can blunt next-day morning appetite." |
| `R8_protein_low` | `tagServings['protein_rich'] < N` (i.e. **<1 protein serving/logged-day on average**) **and** `N ≥ 4` | suggest | "Protein-rich foods averaged under one serving per day ({count} in {N} days). Protein supports catch-up growth and steadier focus." |

Notes that make these testable:

- Every threshold is a literal constant (`0.50`, `0.40`, `4`, `3`, `5pp`, `2.5`) and lives in
  one `const`-style config block at the top of `includes/nutrition.php` so unit tests can assert
  exact boundaries (mirroring how Sprint 9's `computeMedWindow` boundaries are tested in
  `tests/run.php` Phase F).
- `R6` and `R7` are **cross-stream** rules — they are the entire reason the panel "looks like
  AI"; they are nothing but two boolean ANDs over already-computed flags.
- **No rule invents a clinical claim.** Each card states an observation + a non-prescriptive
  suggestion. None says "you should", "diagnose", or names a dose change — they say "consider",
  "worth reviewing with the clinician". This is the deterministic-=-auditable property §6.1
  relies on.

### 1.3 Suggested Sprint-11 unit tests (one per rule, plus boundaries)

For each rule, a fixture child with a hand-built `food_log`/tag set that lands the aggregate
exactly **on** and **one step off** the threshold (e.g. `windowShare[post_med] = 0.50` does
**not** fire `R1`; `0.51` does). Add to `tests/run.php` as "Phase G — Sprint 11 nutrition rules"
modeled on Phase F. The sparse-data floor (`N=2` → zero cards) and the no-stimulant-schedule
path (`med_window` all NULL → no timing cards) are each one assertion.

### 1.4 Sparse-data behavior (graceful, per decision iv's spirit)

- `N < 3` → emit **zero** recommendation cards; the panel shows a single neutral "Keep logging —
  insights appear after a few days of data" line (info, never red).
- A child with **no active stimulant schedule** → all `windowShare` rules are inert (denominator
  0); only tag-coverage rules (`R4/R5/R8`) can fire. Correct and intended.
- Missing gender/DOB → `pctTrend = unknown`, so `R6` cannot fire; the rest are unaffected. This
  matches decision iv (never block; degrade per child).

---

## 2. Growth-tag maintenance UX for guardian-added foods (resolves §5.2)

**Decision: a passive coverage indicator + an inline non-blocking nudge in
`pages/guardian/manage-foods.php`. NO auto-tagging, NO required field, NO blocking save.**

### 2.1 Rationale

Seed foods ship pre-tagged (Sprint 11, `INSERT OR IGNORE`). Guardian-added foods default to
**untagged**, so tag coverage silently erodes as a family customizes its catalog — which would
quietly weaken every `R4/R5/R8` rule above. Auto-tagging is rejected: it would fabricate a
clinical-adjacent classification the app cannot actually derive from a name/emoji, violating the
project's honesty rule. The fix is to make the gap **visible** and make tagging **one click**,
never mandatory.

### 2.2 The six tags and their rationale strings (the i18n source of truth)

These are the strings `R4` interpolates as `{tag_rationale}` and the checkboxes label. (Tag set
is fixed by Sprint 11 §3.1 — this spike does not change it.)

| Tag key | Label (en gloss) | Rationale (en gloss) |
|---|---|---|
| `calorie_dense` | Calorie-dense | Counteracts appetite suppression — every bite counts in a small eating window. |
| `protein_rich` | Protein-rich | Supports catch-up growth and steadier focus. |
| `bone_building` | Bone-building | Calcium/vitamin-D; stimulant use is linked to bone-density concerns. |
| `brain_fuel` | Brain fuel | Sustained energy/focus (omega-3, complex carbs). |
| `easy_to_eat` | Easy to eat | Low-friction options for low-appetite periods. |
| `hydrating` | Hydrating | Stimulants blunt thirst cues. |

### 2.3 Concrete UX (Sprint 11 will implement; gated by `show_nutrition_insights`)

All of this appears **only when `getSetting('show_nutrition_insights','0') === '1'`** — when the
feature is OFF, `manage-foods.php` is byte-for-byte unchanged (no UI change leaks).

1. **Tag checkboxes in the add/edit food form.** A new fieldset of six checkboxes (`name="tags[]"`,
   value = tag key), pre-checked from `food_growth_tags` in edit mode. Plain HTML checkboxes —
   same pattern as the existing `meal_ids[]` checkboxes already in this file (lines ~302–308).
   On `create`/`update`, the handler does a `DELETE FROM food_growth_tags WHERE food_id=?` then
   re-inserts the checked set (mirrors the existing `update_meal_categories` handler exactly).
2. **A per-row "tags" column in the food list table** showing the tag count (e.g. `3 tags`) or a
   muted `—` for none. Untagged foods get a small ⚠ marker so the gap is scannable.
3. **A coverage banner at the top of the food section**:
   `coverage_nudge` (en gloss): *"{untaggedCount} of your foods have no growth tags. Tagging them
   improves the nutrition insights. (Optional.)"* — shown only when `untaggedCount > 0`, dismissable
   per session, never blocking save. `untaggedCount` = `SELECT COUNT(*) FROM foods f WHERE active=1
   AND NOT EXISTS (SELECT 1 FROM food_growth_tags t WHERE t.food_id=f.id)`.

**Explicitly out of scope:** any background job, any heuristic that guesses tags from
emoji/name, and any "are you sure?" gate on saving an untagged food. The nudge informs; it never
obstructs (consistent with "prompt guardians to tag new foods but don't block on it",
SPRINT-PLAN risk note).

---

## 3. SQLite analytics concurrency approach (resolves §5.3)

**Decision: use a dedicated READ-ONLY PDO connection for the Sprint-11 aggregate queries, plus a
short-TTL per-request memoization of the assembled insight payload. Do NOT add a persistent cache
table or a cache file in this product.**

### 3.1 Rationale

- The app already opens **one PDO connection per call to `getDB()`** and sets
  `PRAGMA busy_timeout = 5000` (added Sprint 3, see `includes/db.php` line ~23). Under SQLite's
  single-writer model, the real risk is a long **read** aggregate holding back a concurrent
  child **write** (a food log), or a writer making a reader wait.
- A **read-only connection** (`PDO('sqlite:'.DB_PATH, …, [PDO::SQLITE_ATTR_OPEN_FLAGS =>
  PDO::SQLITE_OPEN_READONLY])`, or opened with `?mode=ro`) for the analytics path means those
  queries can **never** take a write lock and, combined with `busy_timeout`, ride out a brief
  writer without the "database is locked" failure. This is the lowest-complexity, no-new-state
  option and fits "no external dependencies."
- **WAL is the natural complement but is deferred to a deliberate decision**, because switching
  `journal_mode=WAL` is a persistent, file-format-affecting change (extra `-wal`/`-shm` files,
  backup-script implications for `backupDatabase()`). The read-only-connection + `busy_timeout`
  combination is sufficient for a single-family self-hosted load and does **not** touch the
  backup/restore contract the test harness guards. *(Recommended Sprint-11 note: if a future
  benchmark shows contention, enabling WAL is the next lever — but only after auditing
  `backupDatabase()`/`restoreDatabase()` to copy the `-wal` file too.)*
- **Query caching: in-request only.** `getReportData()` is already called once per report render;
  the nutrition analyzers should compute their aggregates **once** and pass the array down to all
  surfaces (dashboard panel, export-html, export-csv, guest-report) rather than re-querying per
  surface. A persistent cache (table/file) is rejected: it adds invalidation complexity and a new
  failure mode for a workload that is a handful of aggregate queries over a single family's data.

### 3.2 Concrete shape for Sprint 11

- Add `getReadOnlyDB()` to `includes/db.php` (a thin sibling of `getDB()` opening read-only with
  the same `busy_timeout`). The three analyzers use it exclusively.
- `getReportData()` calls the analyzers **once** and stores results under a new
  `nutrition` key; every export surface reads that key (single source → parity for free).
- The aggregate queries are **bounded** (single child, ≤ a few weeks of rows) and indexed:
  `food_log(user_id, log_date)` already exists; add an index on `food_growth_tags(food_id)` —
  the PK `(food_id, tag)` already covers food_id-led lookups, so **no new index is required**.
- Acceptance for Sprint 11: a smoke assertion that the analytics path opens read-only (e.g. a
  write attempted on that handle raises), mirroring the rigor of the existing harness.

---

## 4. Confirm the med_window storage model and how Sprint 11 reads it (resolves §5.4)

**Confirmed — the Sprint 9 model is the one Sprint 11 builds on, unchanged.** This spike adds
**no** schema change here.

### 4.1 Storage model (as shipped in Sprint 9, schema_version 5)

- **`food_log.med_window`** — `TEXT CHECK(med_window IN ('pre_med','onset','mid_med','post_med')
  OR med_window IS NULL)`. Stamped **server-side at INSERT** by `logFood()` calling
  `computeMedWindow($userId, $logTime)`; the child request payload is **not** involved (verified:
  `includes/db.php::logFood`, and `tests/run.php` Phase F2 asserts the stamp). It is an immutable
  historical fact about each row — **forward-only**: rows logged before a schedule existed stay
  NULL and are never back-filled.
- **`medication_schedules`** — per child+medication `dose_time` + `peak_start_offset` /
  `peak_end_offset` (+ `med_type`, `active`). The classifier reads **offsets** as the source of
  truth; `med_type='non_stimulant'` carries NULL offsets and is skipped (→ NULL window).
  Boundary convention (from `classifyAgainstDose`): at-dose = `onset`; at `dose+peak_start` =
  `mid_med`; at `dose+peak_end` = `mid_med` (inclusive); one minute past = `post_med`. Priority
  when multiple doses apply: `mid_med > onset > post_med > pre_med`.

### 4.2 Exactly how Sprint 11 analytics read it

- **Timing analysis reads the stamped column, NOT the live classifier.** Aggregates are
  `SELECT med_window, COUNT(*) FROM food_log WHERE user_id=? AND log_date BETWEEN ? AND ? GROUP
  BY med_window`. This is correct because the window was computed against the schedule in force
  **at the time of eating**; re-deriving it now against the *current* schedule would rewrite
  history if the guardian later edited the dose time.
- **NULL handling:** `med_window IS NULL` rows are **excluded from `windowShare` denominators**
  (§1.1) — they represent "no stimulant context", not a fourth-of-four window. A child with zero
  stimulant schedules therefore produces no timing cards (correct, per §1.4).
- `medication_schedules` is read only to **describe** the windows in the report (e.g. "peak
  window 08:30–12:00"), never to reclassify stored rows.
- **No new column, no migration for med timing in Sprint 11.** The only Sprint-11 schema change
  is `food_growth_tags` (v5→v6).

---

## 5. AI/LLM boundary for Sprint 11 (resolves / restates §6)

**Confirmed and locked for Sprint 11: rule-based FIRST and ONLY. Sprint 11 introduces NO LLM, NO
external API, NO new dependency, NO network call.** This is consistent with Sprint 11 §6.5's
recommendation and the project DNA (vanilla PHP, no build step, self-hosted, no external deps).

- The strategic recommendations are the **deterministic rules engine** specified in §1 above.
  Determinism = auditability = safety for medically-adjacent guidance about a child.
- **An LLM is explicitly deferred** to a *possible future, opt-in, default-OFF, narrative-only*
  enhancement (Sprint 11 §6.2 **Option 2**, never Option 3). If it is ever built, the hard
  preconditions are: (a) the `.env`/secrets foundation (decision v) lands first — an API key is a
  new secret; (b) an explicit privacy decision, because the data is GDPR special-category
  children's health data; (c) it only **rephrases facts the rules already computed**, sending
  **de-identified aggregated metrics** (e.g. "weight-for-age ~P40 stable; 70% intake post-med;
  protein 4/wk; sleep 3.2/5"), never names/DOB/raw logs; (d) **never** the recommendation engine
  itself (Option 3 is rejected outright). Model IDs/pricing/SDK come from the `claude-api`
  reference *at that time*, not now.
- **This sprint and Sprint 11 must not add any LLM or external dependency.** Any LLM work is a
  separate, later, gated sprint.

---

## 6. What Sprint 11 will change (carried forward from this decision)

Reference checklist so Sprint 11 can be built directly:

- **Schema (v5→v6):** create `food_growth_tags` in a version-gated `migrateDatabase()` block +
  mirror in `db/schema.sql`; seed-tag existing foods via `INSERT OR IGNORE` in `db/seed.sql`. No
  other migration.
- **`includes/nutrition.php` (new):** three analyzers (timing, tag-coverage, recommendations)
  with the §1 thresholds in a single config block; use `getReadOnlyDB()` (§3).
- **`includes/db.php`:** add `getReadOnlyDB()`.
- **`includes/helpers.php`:** `getReportData()` gains a `nutrition` key computed once (§3.2).
- **`pages/guardian/manage-foods.php`:** the §2 tag checkboxes + coverage nudge, gated on
  `show_nutrition_insights`.
- **`pages/guardian/dashboard.php`:** the "Nutrition Intelligence" panel, gated on
  `show_nutrition_insights` (default `'0'`).
- **Exports — all four surfaces in parity** (`export-html.php`, `export-csv.php`, `export.php`
  JSON via **explicit whitelist** so derived insights don't auto-leak raw data, `guest-report.php`).
- **i18n:** all tag labels/rationales, window names, rule copy keys (`R1…R8`), the coverage
  nudge, and panel headings into `locales/pt.json` (canonical) + `locales/en.json`, key-parity
  verified.
- **`settings.php`:** the `show_nutrition_insights` toggle (default `'0'`).
- **No `sw.js` bump** (zero child-facing asset change). **Child boundary: zero** — the footer
  stays ≤5 items and the child never sees any of this.

---

## 7. Acceptance for THIS spike (Sprint 10)

- This document exists and concretely resolves items (1)–(5) with specific rules, thresholds, and
  copy. ✅ (§1–§5)
- `php tests/run.php` still exits 0 — **no regression** (docs-only change; no code touched).
- App still boots HTTP 200; `schema_version` unchanged (**5**); no UI change; pt/en parity
  unchanged (no locale keys added in this spike — they are specified for Sprint 11, not created).
