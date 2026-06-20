# Nutrition Intelligence — Operations Runbook (Sprint 11)

Operator-facing how-to for the **Growth-Support Nutrition Intelligence** feature: what
shipped, how the schema migrates, how to enable/disable it, how it behaves, how to
troubleshoot it, and how to roll it back.

> **Audience:** the person deploying/operating `comecome-claude`. For the parent-facing
> walkthrough, see the [Guardian Guide](NUTRITION-INTELLIGENCE-GUIDE.md). For deployment
> mechanics in general, see [DEPLOYMENT.md](DEPLOYMENT.md). For the original design and the
> AI/LLM decision, see [`roadmap/SPRINT-11-nutrition-intelligence.md`](roadmap/SPRINT-11-nutrition-intelligence.md).

---

## 0. Mental model

- **Rule-based, not AI.** Pure PHP read-layer over data already collected. No external
  dependency, no network call, no new runtime requirement. Auditable by design.
- **Guardian/clinician-side only.** Zero child-facing surface. The child app is byte-for-byte
  unchanged; `sw.js` was **not** bumped (no child asset changed).
- **Opt-in, default OFF.** Gated by the `show_nutrition_insights` setting. When off, the
  analyzers short-circuit and nothing renders anywhere.
- **Privacy-preserving exports.** The JSON export emits only de-identified aggregates
  (window shares, weekly tag rates, recommendation keys) — never names, DOB, or raw logs.

---

## 1. What shipped

| Area | File(s) |
|---|---|
| New table + migration + tag seed | `includes/db.php` (`migrateDatabase()` v6→v7 block, `seedGrowthTags()`), `db/schema.sql` |
| Analyzers + renderer | `includes/nutrition.php` (new) |
| Data wiring | `includes/helpers.php` (`getDashboardData()`, `getReportData()`, `projectReportForJson()`) |
| Guardian UI | `pages/guardian/dashboard.php`, `pages/guardian/settings.php` |
| Clinician report | `pages/guardian/export-html.php` (also used by `pages/guest-report.php`), `pages/guardian/export-csv.php` |
| i18n | `locales/pt.json` (canonical) + `locales/en.json` |
| Tests | `tests/run.php` (Phase M + A1/A2 migration asserts), `tests/smoke.php`, `tests/migration_idempotency.php` |

---

## 2. Schema migration (v6 → v7) — automatic

There is **nothing to run by hand.** `migrateDatabase()` executes on first request (and is
idempotent), so the table appears automatically when the new code is deployed.

The v6→v7 block:
1. `CREATE TABLE IF NOT EXISTS food_growth_tags (food_id, tag, PRIMARY KEY(food_id,tag), FK→foods)`
   — `tag` is `CHECK`-constrained to the six tags.
2. `seedGrowthTags()` — tags the 46 built-in seed foods via `INSERT OR IGNORE` (resolved by
   `name_key`, so it's stable across installs). It **skips silently** if the `foods` table
   isn't present yet (partial/synthetic DB), and re-runs harmlessly once it is.
3. Bumps `schema_version` to `7`.

The table is mirrored in `db/schema.sql`, so a brand-new install matches a migrated one.

**Verify after deploy:**

```sql
SELECT value FROM settings WHERE "key" = 'schema_version';   -- expect 7
SELECT COUNT(*) FROM food_growth_tags;                        -- expect ~98 (seed foods)
```

(98 rows = the seed mapping; the exact number is whatever `seedGrowthTags()` defines.)

---

## 3. Enable / disable

It's a single settings key, **default `'0'` (off)**.

- **Via UI:** Guardian → Settings → tick/untick **🥗 Nutrition intelligence** → Save.
- **Via SQL (if needed):**

```sql
INSERT OR REPLACE INTO settings ("key", value) VALUES ('show_nutrition_insights', '1');  -- on
INSERT OR REPLACE INTO settings ("key", value) VALUES ('show_nutrition_insights', '0');  -- off
```

Disabling hides the panel from the dashboard and from **all** export surfaces immediately;
no data is removed.

---

## 4. Data prerequisites (per analyzer)

The builder (`buildNutritionIntelligence()`) gates on data sufficiency and each analyzer
degrades gracefully:

| Output | Needs |
|---|---|
| Anything at all | `show_nutrition_insights = 1` **and** ≥ `NI_MIN_LOG_DAYS` (5) distinct days with food logs in the period → otherwise `reason = not_enough_data`. |
| **Medication timing** | An active row in `medication_schedules` for the child **and** ≥ `NI_MIN_WINDOWED` (3.0) servings stamped with a non-NULL `food_log.med_window`. (Stimulant schedules stamp the window at insert; `non_stimulant` schedules leave it NULL by design.) |
| **Tag coverage** | Logged intake against tagged foods (built-ins are pre-tagged). |
| **"weight falling" rec** | `show_percentiles = 1` + gender + DOB + enough weight entries for a trend. |
| **Sleep rec** | `daily_checkin.sleep_quality` recorded on recent days. |

---

## 5. Tuning thresholds

All thresholds are named constants at the top of `includes/nutrition.php` (so they're
auditable and unit-tested in `tests/run.php` Phase M1). Change them there:

```
NI_PROTEIN_MIN=5  NI_BONE_MIN=5  NI_CALORIE_DENSE_MIN=7  NI_BRAIN_FUEL_MIN=3  NI_HYDRATING_MIN=7
NI_POST_MED_HEAVY_PCT=60  NI_PRE_MED_LOW_PCT=15  NI_TAG_DROP_PCT=40  NI_SLEEP_LOW_AVG=2.5
NI_MIN_LOG_DAYS=5  NI_MIN_WINDOWED=3.0
```

If you change a threshold, re-run the suite — the Phase M1 unit tests assert specific rules
fire/don't fire at given inputs and will flag an inconsistency.

---

## 6. Growth tags — seeding & maintenance

- **Built-in foods** are seeded by `seedGrowthTags()` (idempotent). The mapping is the single
  source of truth; editing it and re-deploying re-applies via `INSERT OR IGNORE` (it adds
  missing rows but won't overwrite or remove existing ones).
- **Guardian-added foods** are intentionally **left untagged** — by decision, ComeCome does
  not auto-tag (these are nutrition-adjacent; silent guessing would mislead). The panel
  surfaces a **coverage indicator** (`X of Y foods tagged`) instead.
- There is **no per-food tag editor UI** in this sprint (planned follow-on). To tag a custom
  food manually:

```sql
-- find the food id
SELECT id, name_key FROM foods WHERE name_key = 'food_yourcustom';
-- add tags (each must be one of the six allowed values)
INSERT OR IGNORE INTO food_growth_tags (food_id, tag) VALUES (<id>, 'protein_rich');
```

Allowed tags: `calorie_dense`, `protein_rich`, `bone_building`, `brain_fuel`, `easy_to_eat`,
`hydrating` (enforced by the table's `CHECK`).

---

## 7. Performance & concurrency

Per the §5.3 decision: the analyzers are **read-only `SELECT`s** bounded by `user_id` +
date range (covered by `idx_food_log_user_date` / `idx_daily_checkin_user_date`) and the
`food_growth_tags` primary key. They take **no write lock**. At single-family self-hosted
scale, with the panel opt-in/default-off and rendered server-side per page load, no
read-only connection or result cache was added (the existing `PRAGMA busy_timeout = 5000`
covers rare writer overlap). If a future multi-child/high-traffic deployment reports lock
contention, caching the per-child aggregate is the next step.

---

## 8. Privacy / export behavior

- The dashboard + HTML/guest-report render the full panel (these are already
  authenticated guardian/clinician surfaces).
- The **JSON export** passes through `projectReportForJson()`, which whitelists only:
  `available`, `reason`, `window_days`, `timing`, `coverage`, `recommendations`, `tag_index`.
  No `user.name`, no `date_of_birth`, no raw `food_log` rows. New nutrition fields must be
  added to that whitelist deliberately. (`tests/run.php` Phase M3 asserts the name and raw
  rows are absent.)

---

## 9. Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| Panel absent everywhere | `show_nutrition_insights` is `0`. Enable it (Step 3). |
| *"Not enough logging yet"* | < 5 distinct logged days in the selected period. Pick a longer period or wait for more logs. |
| No medication-timing table | No active `medication_schedules` row for the child, or no windowed intake. Confirm a schedule exists and is `active=1`; confirm the med type is a stimulant (non-stimulant leaves `med_window` NULL on purpose). |
| Coverage looks too low | Custom foods are untagged (see §6). Check the coverage indicator and the selected date range. |
| No "weight falling" suggestion | Percentiles off or insufficient weight history; this rule only fires on a downward weight-for-age trend. |
| Tags missing after deploy | Confirm `schema_version = 7` and that `foods` was seeded before migrate; re-hitting any page re-runs the idempotent migrate. |
| pt/en key error / `[missing key]` | A locale key wasn't added to both files. Parity is asserted by the test harness — run it. |

---

## 10. Verification

```powershell
# Full regression (Windows: use PowerShell — the HTTP smokes spawn `php -S`,
# which hangs the Bash tool). Expect "RUN: PASS".
& "C:\SAP\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" tests\run.php
```

Relevant coverage:
- **A1/A2** — fresh init reaches `schema_version 7`, `food_growth_tags` exists + seeded, the
  v1→v7 forward migrate is idempotent.
- **Phase M1** — the rule engine fires/suppresses the right recommendations at given inputs.
- **Phase M2** — toggle gate, data-sufficiency gate, and a live build over a seeded DB.
- **Phase M3** — the JSON export carries the de-identified aggregate but no name/raw logs.

Quick manual smoke: enable the toggle, log a few days of food (ideally with a medication
schedule), open the guardian dashboard, then export the HTML report and confirm the
"Medication-Aware Nutrition Summary" renders.

---

## 11. Rollback

**Preferred:** just **disable the toggle** (`show_nutrition_insights = 0`). This removes the
feature from every surface with zero data/schema change and is fully reversible.

**Full revert (rare):** if you must remove the code, redeploy the previous build. Note:
- `schema_version` does not auto-decrement; the `food_growth_tags` table is harmless if it
  lingers (no other table depends on it).
- To drop it explicitly: `DROP TABLE IF EXISTS food_growth_tags;` and, if you reverted the
  code, optionally set `schema_version` back to `6`. Only do this if you are also reverting
  `includes/db.php` / `db/schema.sql`; otherwise the next request re-creates it.

---

## 12. AI / LLM boundary

This sprint shipped **rule-based only (Option 1)** — no API key, no dependency, no privacy
change. A possible later enhancement is **narrative-only (Option 2)**: the rules stay the
source of truth and an LLM only rephrases the *already-computed, de-identified* metrics into
clinician prose, opt-in and default-off. **Option 3** (LLM deciding clinical content from raw
child data) is ruled out (GDPR special-category data). If/when Option 2 is built, it makes
the `.env`/secrets foundation a hard dependency and must keep the API key above docroot via
the existing `includes/secrets.php` / `config.local.php` mechanism. See
[`roadmap/SPRINT-11-nutrition-intelligence.md`](roadmap/SPRINT-11-nutrition-intelligence.md) §6.
