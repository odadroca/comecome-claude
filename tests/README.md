# ComeCome Tests

Dependency-free PHP test harness — **no Composer, no PHPUnit, no build step**.
Every test runs against a **throwaway temp SQLite database** and NEVER touches
`db/data.db`.

## Single regression command

```bash
php tests/run.php
```

Exit code `0` = all checks passed; non-zero = one or more failures. This is the
**single regression entry point**: it runs new in-process unit checks AND folds
in the existing sub-runners so coverage stays cumulative across Sprints 0–4.

| Phase | What it covers |
|---|---|
| A1 | `initializeDatabase()` on a fresh temp DB → all 16 expected tables exist, `schema_version` reaches 2, default guardian seeded. |
| A2 | `migrateDatabase()` forward from a **synthetic v1 fixture** → `daily_checkin.sleep_quality` + `sleep_log` + `sleep_interruptions` appear, pre-existing rows survive; re-running migrate twice is a no-op (version unchanged, no error). |
| A3 | `backupDatabase()` / `restoreDatabase()` round-trip → write → backup → mutate → restore → assert original restored. |
| B  | Orchestrates `tests/migration_idempotency.php` and `tests/smoke.php` as isolated child processes (each must exit 0). Smoke = cumulative Sprint 0–3 (auth, toggles, sleep, page renders, clinical-report correlations, JSON pin whitelist, pt/en i18n parity). |
| C  | **Negative self-test** — re-invokes the runner in `--selftest-negative` mode (which fails by design) and asserts a non-zero exit, proving the harness catches a deliberately broken case. |

## Sub-runners (can also be run directly)

```bash
php tests/smoke.php                  # cumulative smoke (Sprints 0–3)
php tests/migration_idempotency.php  # migrate forward + idempotency
```

## Honesty policy

Assertions are never weakened or skipped to make them pass. A check that cannot
be evaluated FAILS loudly rather than being silently skipped.

## Encryption-timing prerequisite (decision v)

Per `docs/roadmap/DECISIONS.md` decision (v), the **Security & Deployment
Foundations** track unblocks the still-deferred **SQLCipher at-rest encryption**.
The encryption timing review requires a `tests` safety net to exist FIRST, so the
`getDB()` / `initializeDatabase()` / `migrateDatabase()` / `backupDatabase()` /
`restoreDatabase()` paths can be re-validated once the driver changes (i.e. prove
they still behave identically under encryption).

**`php tests/run.php` is that prerequisite.** Do not schedule SQLCipher before
this harness runs green.
