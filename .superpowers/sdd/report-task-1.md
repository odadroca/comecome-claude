# Task 1 — retention purge + A9 fixes

## Fix wave 1 (2026-06-28)

### Changes

**`includes/retention.php`**
- `computeRetentionPurge()`: added `$counts['sleep_interruptions'] = 0` in the early-return path (months <= 0) and a JOIN-based COUNT after the 5 date-column tables so orphaned sleep_interruptions rows are counted via their parent `sleep_log.log_date`.
- `applyRetentionPurge()`: added an explicit `DELETE FROM sleep_interruptions WHERE sleep_log_id IN (SELECT id FROM sleep_log WHERE log_date < ?)` BEFORE deleting `sleep_log`, so no orphans are left when FK enforcement is OFF.

**`tests/run.php`** (Phase A9 block)
- Removed `require_once $ROOT . '/config.php'` (CLI harness forbids it; A9 uses no RETENTION_PRESETS constant).
- Seeded an old `sleep_log` row + child `sleep_interruptions` row in A9.
- Added 3 new assertions: compute counts, apply purged (no orphan), audit counts include sleep_interruptions.

### Suite result

415 passed, 0 failed — all new A9 assertions pass.

### Pristine output

Yes — no "constant already defined" or "headers already sent" warnings in Phase A9 after removing the config.php require.

### Orphans swept

14

### Commit

SHA: f8d598a
Subject: fix(retention): purge + count sleep_interruptions explicitly (FK-off); pristine A9 output (A15)
