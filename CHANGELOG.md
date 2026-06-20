# Changelog

Notable changes to ComeCome. This repo (`comecome-claude`) is **staging**; entries are promoted
to public `Come-come` (production) at release. Dates are ISO (YYYY-MM-DD).

## [Unreleased] — staging

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
