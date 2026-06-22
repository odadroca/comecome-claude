# Changelog

Notable changes to ComeCome. This repo (`comecome-claude`) is **staging**; entries are promoted
to public `Come-come` (production) at release. Dates are ISO (YYYY-MM-DD).

## [Unreleased] — staging

### Added
- **Guardian add-item forms auto-derive the i18n key** — adding a food / category / meal no longer
  asks the guardian to hand-author the internal translation key (e.g. `food_mango`), which they had
  no way to know the convention for or which keys were taken (the source of the duplicate-key 500s).
  They now type only the display name; a new `slugifyTranslationKey()` helper derives a stable,
  accent-folded, prefixed key server-side ("Maçã" → `food_maca`), shown live as a read-only
  **"será guardado como `food_maca`"** preview (JS mirrors the PHP, NFD-normalised). A collapsed
  **Advanced** disclosure still exposes the raw key for power users (reused translation keys). On a
  genuine clash the friendly **"already exists"** message (see Fixed) is the backstop. Applies to all
  three create forms; `slugifyTranslationKey()` covered by `tests/run.php` Phase N (356 green).
- **Child sex + date-of-birth input** — the WHO growth-percentile engine (and the `show_percentiles`
  toggle) need each child's sex + DOB, but **no input UI existed** on the live page: `manage-children.php`
  had the fields, yet `index.php` routes both `manage-children` and `manage-users` to `manage-users.php`
  (which lacked them), so the demographics — and thus the whole percentile feature — were unreachable.
  Added the **Sex** (radios) + **Date of birth** fields to the add/edit form on **Manage Users** (shown
  for children; guardian rows untouched via the `updateUser` `__keep__` sentinel). Server-side validation:
  sex against the `users.gender` CHECK, and DOB via a new `validBirthDate()` helper that **rejects**
  malformed/future dates to null (not clamped — a bogus DOB would silently break percentile age + fool the
  missing-DOB prompt); `max=today` also guards the picker. `createUser`/`updateUser` already accepted these
  — only the form + handler wiring was missing. _(The orphaned `manage-children.php` is now redundant —
  flagged for cleanup.)_
- **Backdated meal logging** — record a meal for an earlier day, which the UI never exposed
  before (the backend accepted a date but no screen sent one). Two surfaces: a guardian
  **"Add a meal"** form on **Manage Logs** (food + meal + portion for the selected child/date),
  and a small, understated **➕** beside "Registar comida" on the child **History** page that
  opens the normal food picker carrying that day's date (with a subtle "logging for <date>"
  banner). Time is derived server-side from the meal's start time — no time picker; new
  `clampLogDate()` rejects future/malformed dates and `defaultLogTimeForDate()` keeps med_window
  stamping sensible. Reuses `logFood()`. Tests: `tests/run.php` Phase N (helpers + backdated
  round-trip); full suite 343 green. **No schema change.** _(`manage-logs.php` POST forms — and the
  other guardian pages' — are now CSRF-protected; see **Security** below.)_

### Fixed
- **Guardian "add food category / meal" no longer 500s on a duplicate name** — `food_categories`,
  `foods` and `meals` each have a `UNIQUE name_key`, but the create handlers ran the `INSERT`
  unguarded under `PDO::ERRMODE_EXCEPTION`, so adding an item whose generated i18n key collided with
  an existing one (e.g. a category typed "Fruits" → the already-seeded `category_fruits`) threw an
  uncaught `PDOException` and returned a raw **500**. `manage-foods` and `manage-meals` now wrap the
  POST dispatch in try/catch: a UNIQUE collision redirects with a friendly **"that item already
  exists"** notice (new `error_already_exists` key, pt+en), any other DB error with a generic
  database-error notice — never a raw 500. Verified end-to-end against a real seeded DB.
- **Dashboard charts now stack (regression from the guardian-cards pass)** — Block A put the two
  chart cards side-by-side in a 2-col grid, which left them too small/cramped (especially the
  legend-heavy food chart) on mobile and narrow screens. The dashboard grid is now a single column,
  so "Evolução do Peso" and "Evolução da Alimentação" stack full-width, one above the other (the
  grid only ever existed to pair the charts; every other card was already full-width). Removed the
  now-redundant `:has(.chart-container)` half-width rule + the 900px media query. `sw.js` → `comecome-v0.11.2`.
- **Base framework's legacy green `--primary` retargeted to teal** — `assets/css/pico.min.css`
  defines `--primary:#4CAF50` and styles every `<button>` as `background:var(--primary)`, so any
  button/link/focus the app didn't explicitly colour leaked green (the food-card-hover patch was a
  targeted symptom of this). The theme now remaps `--primary`/`--primary-hover` to the Lagoon teal
  at `:root` (late-bound via `var(--cc-primary)`, so it follows light & dark automatically) — the
  root fix. `sw.js` cache → `comecome-v0.11.1`.

### Security
- **CSRF protection completed across the guardian pages** — six guardian pages had state-changing
  POST forms with **no CSRF token** (`manage-foods`, `manage-meals`, `manage-medications`,
  `manage-sleep`, `manage-children`, `settings`), and `manage-logs` was only partly covered. Added
  the canonical `verifyCsrf()` gate to each POST handler and `csrfField()` to **every** form (~23
  forms), matching the already-protected `manage-users` / `database` / `export` pages. Forged
  cross-site POSTs are now bounced (redirect, no DB write) before any state change. No change to
  legitimate flows (the token is minted per session and embedded in each form); full suite 343 green.
  A follow-up also covered `pages/translations.php` (guardian-gated but living at the `pages/` root,
  so the first `pages/guardian/`-scoped sweep missed it) — **every** guardian POST page now carries CSRF.

## [0.11.0] — 2026-06-21 — staging

A visual refresh plus small UX and privacy improvements on top of 0.10.0; **schema
unchanged** (still `schema_version` 7). Version markers reconciled to **v0.11.0**
(`config.php` `APP_VERSION` / `sw.js` `CACHE_NAME` `comecome-v0.11.0` / README /
CLAUDE.md) and tagged. Built and verified on staging (`php tests/run.php` → 332
green); **not yet promoted to production** (`Come-come`).

### Added
- **Child "undo" on the food-log celebration** — after logging, the success dialog now offers
  **"Oops, undo"** (pt *"Enganei-me"*) that removes the just-logged entry, so a child who taps the
  wrong quantity can fix it without a guardian (previously impossible — the flow auto-logs on tap
  and only a guardian could delete). `logFood()` returns the new row id; `api/food-log.php` echoes it
  on POST; the celebration DELETEs it via the existing CSRF-gated, ownership-scoped endpoint. Child
  smoke (`tests/http_csrf_child_smoke.php`) covers the POST→DELETE round-trip incl. CSRF on DELETE.
  `sw.js` cache → `comecome-v0.10.1`.

### Design
- **"Lagoon" recolor** — re-theme from the warm tangerine/plum/oat palette to a teal-forward
  scheme: primary `#1FA4B5`, chrome `#0F5563`, cool-paper surfaces, cool dark mode — keeping the
  feedback pastels (leaf/honey/clay/sky), Lexend + Atkinson type, radii/shadows/motion. Phase 1
  swaps `comecome-theme.css` (re-applying the post-refresh polish fixes the handover package
  predated); Phase 2 remaps the hardcoded JS/manifest/inline colors tangerine→teal and re-points
  the dark-mode inline-hex overrides. Fully revertible. `sw.js` cache → `comecome-v0.10.2`.
- **Guardian "cards & layout" pass** — the guardian pages rendered every block as a
  container-less `<section>` (the dashboard read as one flat scroll). Extended the Lagoon theme
  (CSS only, zero markup) so every `.dashboard-section`/`.management-section` becomes a calm card
  with a divider header; the dashboard gets a 2-col grid (the two charts pair side-by-side, scoped
  via `:has()`), management/settings pages a constrained ≤880px column, and tables are framed once.
  Dark mode automatic via tokens; collapses to one column under 900px. Appended as Blocks A/B to
  preserve the existing child-side fixes. `sw.js` cache → `comecome-v0.10.5`.
- **Manage-users roster styling** — moved the user tables toward the roster mock (style only):
  **Status pills** (green Active / grey Inactive), **circular icon action buttons** (teal edit /
  outlined-clay delete), and **dimmed inactive rows**. CSS scoped to a new `.roster` class plus
  per-row `is-inactive` / status-badge class hooks added to `manage-users.php` — **no logic or
  data change** (the two Guardiões/Crianças tables and existing edit/delete actions are unchanged;
  the consolidated single-table + Type/PIN columns + deactivate-toggle remain a separate markup
  task). `sw.js` cache → `comecome-v0.10.6`.

### Fixed
- **Medication UI in dark mode** (two pre-existing bugs found in live testing, not recolor-caused):
  (1) the guardian medication-timing **disclaimer** was a hardcoded inline `background:#fff8e1` box
  not covered by the dark-mode override list, so it rendered light-cream with low-contrast text —
  now uses the themed `alert alert-warning` class (proper light + dark). (2) the child **"took your
  medication?" Yes/No cards** had a markup/CSS mismatch (`<label class="option-card">` vs CSS that
  styled `.option-card label` and made the card a 2-col grid) — the card chrome never applied and
  the emoji/label split into columns; corrected to the label-is-card pattern with
  `:has(input:checked)` selection, in `custom.css` + `comecome-theme.css`. `sw.js` → `comecome-v0.10.3`.
- **Food-card hover/focus showed Pico green in light mode** — `.food-card` is a `<button>`, and
  Pico's `--primary` is the legacy `#4CAF50`, so its hover/focus/active fill leaked green wherever
  the theme didn't set a background. The dark block already overrode it; added the same for light
  (teal select tint via tokens, covering `:focus`/`:active` too), keeping favorites' honey tint.
  `sw.js` → `comecome-v0.10.4`.

### Privacy
- **Self-hosted fonts** — Lexend + Atkinson Hyperlegible now load from `assets/fonts/` (9 woff2)
  instead of the Google Fonts CDN, removing a third-party request that exposed visitor IPs. The
  theme `@import` is replaced with local `@font-face`; the fonts are precached in `sw.js`; SIL
  OFL 1.1 licenses are shipped (`assets/fonts/Lexend-OFL.txt`, `AtkinsonHyperlegible-OFL.txt`).

## [0.10.0] — 2026-06-20 — staging

Built and verified on staging (`schema_version` 5 → **7**); version markers reconciled to
**v0.10.0** and tagged. **Not yet promoted to production** (`Come-come`).

### Added — Sprint 11: Growth-Support Nutrition Intelligence (rule-based, NOT AI)
- **`food_growth_tags`** table (schema `6 → 7`, mirrored in `db/schema.sql`); the 46 seed foods are
  pre-tagged with six strategic growth tags (`calorie_dense`, `protein_rich`, `bone_building`,
  `brain_fuel`, `easy_to_eat`, `hydrating`). Guardian-added foods stay untagged (coverage indicator,
  no auto-tagging).
- **`includes/nutrition.php`** — three deterministic analyzers (medication-timing distribution over
  `food_log.med_window`; weekly growth-tag coverage + trend; a templated recommendation engine
  cross-referencing tag frequency × med-window × percentile trajectory × sleep quality). Tunable
  `NI_*` thresholds; degrades gracefully on sparse data.
- **Guardian dashboard** "Nutrition Intelligence" panel + clinician **"Medication-Aware Nutrition
  Summary"** across HTML/CSV/JSON/guest-report. Gated by **`show_nutrition_insights`** (default off);
  JSON export whitelists only de-identified aggregates. **Zero child-facing change.**
- Decision: shipped rule-based (Option 1) only; an opt-in, de-identified, narrative-only LLM layer
  (Option 2) remains a possible later sprint — Option 3 ruled out (GDPR). See
  `docs/roadmap/SPRINT-11-nutrition-intelligence.md` §6.
- Tests: `tests/run.php` Phase M (rule-engine unit + live build + JSON-privacy) and A1/A2 migration
  asserts; full suite green (332 tests).

### Added — features (Sprints 3–10)
- **Clinical report hardening** — sleep-quality → next-day appetite/mood correlations; a
  "Clinical Summary" across the dashboard + all export surfaces.
- **Demographics** — `users.gender` + `date_of_birth` (guardian-only); child age on the dashboard.
- **Growth page** — optional height entry (`height_log`); the child weight page becomes "Growth"
  when percentiles are on. New `api/height.php`.
- **WHO growth percentiles** — `includes/growth-standards.php` (WHO 2006 + 2007 LMS) +
  `includes/percentiles.php` engine; percentile bands/zones/trajectory in the guardian dashboard +
  clinician reports (child chart stays clinical-overlay-free). WHO-first; CDC 2–19y is a planned
  follow-on. New `show_percentiles` toggle (default off).
- **Medication timing** — `medication_schedules` + `food_log.med_window` stamped server-side at
  insert; guardian timing config. Invisible to the child.
- **Nutrition-intelligence discovery** — `docs/roadmap/SPRINT-10-nutrition-discovery.md`: the
  concrete rule set/thresholds that make **Sprint 11** build-ready.

### Added — security ("Security & Deployment Foundations, Pt 2")
- Secure/HttpOnly/SameSite cookies; `session_regenerate_id` on login; idle timeout; default-`0000`
  PIN force-change guard + `scripts/reset-pin.php`.
- PIN brute-force **throttling/lockout** (`includes/throttle.php`, `login_attempts`).
- TLS/HTTPS 301 + **HSTS** (env-gated, + PHP backstop).
- **CSRF** on every state-changing POST + the 6 api endpoints (`includes/csrf.php`); login-page
  output escaping; **revocable guest tokens**.
- `.env`/secrets pattern (`includes/secrets.php`, `scripts/gen-key.php`) + base64 key container.
- **Opt-in libsodium field encryption** (`includes/crypto.php`) on scoped sensitive columns
  (`users.name`, `daily_checkin.notes`, `medications.name`/`dose`); `scripts/encrypt-backfill.php`
  (verify-first, idempotent). SQLCipher **deferred (VPS-only)** — infeasible on shared hosting.

### Added — tooling & deployment
- `tests/run.php` — single dependency-free regression entry point (298+ checks) + `tests/http_*_smoke.php`.
- `db/seed-demo.php` — 3-month anonymized demo dataset (`--reset` to re-seed).
- `config.local.php` per-deployment override (keeps `data.db` above the web root) +
  `config.local.php.example`; README "Production Hardening" steps.
- `docs/DEPLOYMENT.md` — step-by-step deploy + operations runbook: first deploy,
  the fail-closed-safe encryption enablement sequence, and the backup /
  data-portability constraints (load-bearing key, what's git-ignored vs deployed,
  restore/migration). Linked from the README hardening section.

### Changed
- `migrateDatabase()` advances to `schema_version` 6 (additive only; existing installs migrate cleanly).
- `sw.js` cache → `comecome-v0.9.6`.

### Fixed
- **schema.sql ↔ migration parity** — `db/schema.sql` now carries `daily_checkin.sleep_quality`
  so a fresh DB matches a v2-migrated one (it was previously only added by the migration). No
  functional change — fresh installs already gained the column via `migrateDatabase()`. Added the
  matching A1 parity assertion in `tests/run.php`.
- **Design refresh polish** (Hostinger testing) — light-mode portion-modal labels were invisible
  (white-on-white; the theme set the dark-mode color only); child-nav theme toggle rendered as a
  vertical oval (a 48px `min-height` touch-target floor fought the 32px width) → now a 44px circle;
  the logout button could be clipped by page-level horizontal overflow → `.child-interface` now
  uses `overflow-x: clip`. Verified via headless-render measurements (no x-overflow; 44×44 toggle).

### Design
- **Visual refresh** — off the old Duolingo-style palette onto the ComeCome system: tangerine
  `#E8722C` / plum `#7E3A5D` / leaf `#5E9A45` / oat `#FBF7EF`, Lexend + Atkinson Hyperlegible type,
  warm dark mode, calmer (reduced-motion-safe) celebration. Ships as a drop-in `comecome-theme.css`
  override (Phase 1) + remapped hardcoded JS/manifest/inline colors (Phase 2) + self-hosted fonts
  and SW precache (Phase 3). Fully revertible by removing the one `<link>`. Folding the theme into
  `custom.css` (making it canonical) is a deferred follow-on.

### Version
- **Reconciled to v0.10.0** — `README.md` header, `config.php` `APP_VERSION`, and `sw.js`
  `CACHE_NAME` (was drifted at 0.9.1 / 0.9.1 / comecome-v0.9.6) all set to **v0.10.0**; tagged
  `v0.10.0` on staging.

### Pending
- Promote staging → public `Come-come` (prod `main` is at schema v2 / Sprints 0–2 — this lands
  Sprints 3–11 + Security + design refresh at once).
- Decisions i–v in `docs/roadmap/DECISIONS.md`; backlog in `.claude/SPRINT-PLAN_reconciled.md`.

## [0.9.1] — 2026-06-19
- Housekeeping release: consolidated the two repos, preserved roadmap/planning docs into
  `docs/roadmap/`, retired obsolete branches, archived as tags.

## [0.9.0] — 2026-03-08
- Sprints 0–2: bug fixes (duplicate food, favorites persistence), feature-visibility toggles, sleep tracking.
