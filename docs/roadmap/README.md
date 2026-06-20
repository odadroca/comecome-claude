# ComeCome Roadmap & Planning Archive

This folder preserves the planning and review documents that were previously
stranded on short-lived `claude/*` working branches. They are kept here as
project history and forward-looking roadmap.

## Canonical roadmap

**[`.claude/SPRINT-PLAN_reconciled.md`](../../.claude/SPRINT-PLAN_reconciled.md)** is the
**canonical** sprint plan. It reconciles the source plans below into the agreed
sequence and guiding principle (*the child's interaction surface stays flat; new
depth goes to the guardian/clinician layers only*).

## Status

> Re-sequenced 2026-06-20 — the original "Sprint 3–5" were split/renumbered. The
> **canonical** sequence + scope is in
> [`.claude/SPRINT-PLAN_reconciled.md`](../../.claude/SPRINT-PLAN_reconciled.md); this table
> is the current high-level snapshot.

| Item | State |
|------|-------|
| Sprint 0 — Bug fixes (duplicate food, favorites persistence) | ✅ Shipped (v0.9) |
| Sprint 1 — Feature visibility toggles | ✅ Shipped (v0.9) |
| Sprint 2 — Sleep tracking | ✅ Shipped (v0.9) |
| Sprint 3 — Clinical report hardening + correlations | ✅ Built on staging |
| Sprints 5–6 — Demographics + Growth page (`gender`/`DOB`/`height_log`) | ✅ Built on staging |
| Sprints 7–8 — WHO percentile engine + display | ✅ Built on staging |
| Sprint 9 — Medication timing (`med_window`) | ✅ Built on staging |
| Sprint 10 — Nutrition-intelligence discovery spike (docs-only) | ✅ Built on staging |
| Sprint 11 — Growth-Support Nutrition Intelligence (rule-based) | ✅ Built on staging (schema v7) |
| Security & Deployment Foundations (Pt 1 + Pt 2, 6 phases) | ✅ Built on staging |
| **Promote staging → public `Come-come` + reconcile to v0.10.0** | ⏳ Pending |
| Sprint 8b — CDC 2–19y hybrid; height chart; per-child toggles; MCP server | 📋 Backlog |
| Database at-rest encryption (SQLCipher) | ⏸️ Deferred (VPS-only; libsodium field encryption shipped instead) |

## Source documents

- **[SPRINT-PLAN.md](SPRINT-PLAN.md)** — full v0.9+ sprint plan with codebase review and per-task breakdown.
- **[SPRINT-PLAN_follow-ups.md](SPRINT-PLAN_follow-ups.md)** — follow-up analysis and open questions.
- **[PLAN-db-encryption.md](PLAN-db-encryption.md)** — SQLCipher AES-256 at-rest encryption proposal.
- **[REVIEW-encryption-timing.md](REVIEW-encryption-timing.md)** — timing review; verdict: sound but **defer** until the app leaves single-family/pre-1.0 scope.
