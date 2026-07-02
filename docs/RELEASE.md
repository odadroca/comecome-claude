# ComeCome — Release & Promotion Runbook (dev → public production)

> Promotes the private dev/staging line (`comecome-claude`) to the public production repo
> (`github.com/odadroca/come-come`). Promotion is **manual, deliberate, and gated** — it happens at a
> `vX.0.0` milestone, not on every merge. Backup/restore/host-migration mechanics live in
> `DEPLOYMENT.md` §4/§4.3; this runbook governs the **promotion + rollback** on top of them.

## 0. Topology (why this is manual)
- `comecome-claude` = **dev/staging**; its `main` auto-deploys (webhook) to the maintainer's **real
  daily-usage instance** (real data). `come-come` (public) = **production distribution**, currently out of
  sync, promoted **manually at v1**. Never auto-publish from dev.

## 1. Pre-check gate — ALL green before promoting
- [ ] 7 go/no-go gates green: license+revenue · **legal (A2) sign-off received** · security baseline ·
      privacy/data-governance · installs-cleanly · core-docs · incident-runbook.
- [ ] `php tests/run.php` green locally **and** `ci.yml` green on the exact release commit (incl. PHASE A2
      migration upgrade-path + the A16 row-preservation assertions).
- [ ] Clean-install verified: `docker compose up --build` (Docker) **and** a shared-hosting smoke.
- [ ] Version bumped: `APP_VERSION` (config) + `sw.js` `CACHE_NAME`; `CHANGELOG.md` updated.
- [ ] No tracked DB/secrets: `git ls-files | grep -iE '\.(db|sqlite3?)$'` is empty; gitleaks clean.

## 2. Back up production BEFORE promoting
- The real instance rides schema migrations, so **before any schema-bumping release**: take a DB backup
  (Guardian → Database backup, or copy `data.db`) + an **off-host** copy of the key file, stored
  **separately**. See `DEPLOYMENT.md` §4. This backup is the ONLY rollback path (migrations don't downgrade).

## 3. Promote — clean-snapshot publish to `come-come`
Publish a **fresh-history snapshot** (no dev history/test-data/secrets). Run from a **FRESH clone** of
`comecome-claude` checked out at the release commit:
```bash
git checkout --orphan release-vX.Y.Z
git add -A
git commit -s -m "release: vX.Y.Z (ComeCome public launch)"
# verify cleanliness — MUST show exactly one commit and no leaked artifacts:
git log --oneline                        # -> exactly ONE line
git ls-files | grep -iE '\.(db|sqlite3?)$|(^|/)\.(remember|claude)/|config\.local\.php|encryption-key' \
  && echo "STOP: leak detected" || echo "clean: no DB/secrets/internal"
# publish:
git remote add public git@github.com:odadroca/come-come.git
git push public release-vX.Y.Z:main --force
git push public vX.Y.Z                    # tag -> fires publish.yml (signed GHCR image + SBOM)
```
Then **prod smoke:** clone the public repo fresh → `docker compose up --build` → `curl -k
https://localhost/?page=login` → 200; confirm `schema_version` = HEAD.

## 4. Rollback
- **v1.0.0 (first publish — no prior public commit):** **take down / unpublish** (make `come-come` private
  or revert the snapshot commit on `main`). If a bad migration reached the real instance, **restore it from
  the §2 pre-promote backup**. There is no "previous tag" to roll back to.
- **vX.Y.Z where a prior public tag exists:** re-publish the **prior tag's** snapshot to `main` +
  **restore the pre-deploy DB backup** (additive migrations can't cleanly downgrade → the backup IS the
  downgrade). Re-tagging the prior version re-runs `publish.yml` for a clean image.
- **Rollback owner:** maintainer `odadroca` (sole operator). Decide within one review cycle of a failed
  prod smoke or a reported data-integrity issue.

## 5. Post-promote
- Set the GHCR package **public** if the quickstart image should be pullable (first publish is private).
- Announce per the A29 growth matrix (S7); start the A24 metrics clock.

## 6. Ongoing schema-migration safety (every schema-bumping release)
- Migrations run per-request (`includes/db.php` `migrateDatabase()`), additive-only, idempotent, **no
  auto-backup**. Before a schema-bumping merge auto-deploys to the real instance: (a) **DB backup** (§2),
  (b) `php tests/run.php` green — PHASE A2 proves a v1→HEAD forward migrate preserves every row.
