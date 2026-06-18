# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

ComeCome is an ADHD-friendly food and nutrition tracking PWA for neuro-divergent children, built with vanilla PHP + JavaScript and SQLite. No build step, no frameworks, no external dependencies beyond Pico CSS.

## Development Server

```bash
php -S localhost:8000
```

PHP must have SQLite3 enabled. The database (`db/data.db`) auto-creates on first request using `db/schema.sql` and `db/seed.sql`.

## Architecture

**Routing**: `index.php` is the single entry point. Routes via `?page=` query parameter with a switch-case router. Pages are server-rendered PHP with `ob_start()` buffering via `renderLayout()`.

**Auth**: PIN-based (4-digit, hashed). Two roles: `child` and `guardian`. Session-based with role middleware (`requireAuth()`, `requireGuardian()`). Guest tokens provide temporary clinician access.

**Database**: SQLite3 via PDO with prepared statements everywhere. Key tables: `users`, `meals`, `foods`, `food_log`, `daily_checkin`, `weight_log`, `sleep_log`, `sleep_interruptions`, `settings` (key-value), `guest_tokens`, `translations`. Migrations run via `migrateDatabase()` using `schema_version` setting key.

**API**: JSON endpoints in `api/` (food-log, check-in, weight, favorites, sleep). All require auth, support GET/POST/DELETE, enforce user ownership.

**i18n**: Key-based translation from `locales/*.json` files (pt default, en available). Database `translations` table provides runtime overrides. Use `t('key', $params)` for all user-facing strings.

**Frontend**: Vanilla JS (`assets/js/app.js`) handles AJAX food logging, theme switching, service worker updates, haptic feedback, and celebration animations. CSS in `assets/css/custom.css` uses Pico CSS base with ADHD-optimized large touch targets, animations, and dark mode via CSS variables.

**PWA**: Service worker (`sw.js`) caches static assets (cache-first), pages (network-first), and skips API calls.

## Feature Toggles

Guardians can toggle child-facing features on/off via Settings. The `settings` table stores key-value pairs:

| Setting key | Default | Controls |
|---|---|---|
| `show_food_journal` | `'1'` | Food logging + history pages |
| `show_checkin` | `'1'` | Daily check-in page |
| `show_weight_tracking` | `'1'` | Weight tracking page |
| `show_sleep_tracking` | `'1'` | Sleep quality in daily check-in |
| `show_medication_to_children` | `'1'` | Medication section within check-in |

Read with `getSetting('key', 'default')`, write with `setSetting('key', 'value')`. Child routes in `index.php` enforce toggles via redirect. The shared footer partial (`pages/child/footer.php`) conditionally renders navigation buttons.

When adding new child-facing features, follow the same pattern: add a setting, guard the route in `index.php`, and add the button to `footer.php`.

## Key Conventions

- **PHP functions**: camelCase (`getCurrentMeal`, `logFood`)
- **DB columns/tables**: snake_case, plural table names (`food_log`, `daily_checkin`)
- **Translation keys**: section_descriptor (`meal_breakfast`, `food_apple`, `portion_little`)
- **CSS classes**: kebab-case (`meal-btn`, `user-card`)
- Logic in `includes/`, presentation in `pages/`, config constants in `config.php`
- Meals auto-detect by current time (`getCurrentMeal()` in `db.php`)
- Portion sizes are relative labels (little/some/lot/all), not calorie-based

## Page Organization

- `pages/child/` — Food logging, check-in, weight, history (own data only). Shared footer in `footer.php`
- `pages/guardian/` — Dashboard analytics, user/meal/food/medication/sleep management, export (HTML/CSV/JSON), settings, database backup/restore
- `pages/login.php` — Public auth page
- `pages/guest-report.php` — Token-validated clinician reports

## Timezone & Locale

App timezone is `Europe/Lisbon`. Default locale is Portuguese (`pt`). Date display format is dd-mm-yyyy throughout.

## Roadmap

Canonical sprint plan: `.claude/SPRINT-PLAN_reconciled.md`. Source planning/review docs (sprints 3–5, DB encryption) live in `docs/roadmap/`.
