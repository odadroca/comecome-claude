# Review: Database Encryption — Now vs. Roadmap

## Plan Under Review

`PLAN-db-encryption.md` — SQLCipher-based AES-256 at-rest encryption for the
SQLite database, proposed on branch `claude/plan-db-encryption-1ypC2`.

---

## Current State of ComeCome (context for this review)

| Attribute | Value |
|-----------|-------|
| Version | 0.8 (pre-1.0) |
| Stack | Vanilla PHP + SQLite + plain CSS/JS |
| Deployment model | Self-hosted, manual (no Docker, no CI/CD) |
| Test suite | None |
| Environment config | None — no `.env`, no env vars, all config hardcoded in `config.php` |
| User base | Single-family use; typically one server, one family |
| Sensitive data | Children's names, food intake, weight, mood, medication logs, daily check-ins |
| Current security | PIN auth (hashed), session tokens, prepared statements, guest token expiry |
| PHP files | ~30 |
| Backup/restore | Simple file copy of `data.db` |

---

## The Plan in Brief

- Replace stock `libsqlite3` with `libsqlcipher` at the system level.
- Add a single `PRAGMA key` call in `getDB()` — no query changes.
- Manage the key via env var or external file.
- One-time migration script to convert existing plaintext DB.
- Update init, backup/restore, docs.

The plan itself is technically sound and well-scoped. This review focuses
exclusively on **timing**: should it be executed now or placed on the roadmap.

---

## Pros of Implementing Now

### 1. The data is genuinely sensitive
This is children's health data — weight, medication adherence, appetite, mood.
Even for a single-family app, exposure of this data has real consequences. The
earlier this is protected, the less accumulated plaintext data exists in backups,
old servers, or forgotten directories.

### 2. The change surface is minimal
The plan requires editing exactly **one function** (`getDB()`) in application
code, plus config. No queries change, no schema changes, no frontend changes.
In a 30-file PHP application with no test suite, this is about as safe as a
change can get.

### 3. Easier to do before 1.0 than after
Encrypting now means every user starts with an encrypted database from day one.
Doing it post-1.0 means every existing installation needs a migration path,
documentation for the migration, and support for users who hit problems during
conversion. Pre-1.0 is the cheapest time to make this a default.

### 4. Establishes environment-based config early
The plan introduces `DB_ENCRYPTION_KEY` via env var or key file. ComeCome
currently has zero environment-based configuration. Adding this pattern now
creates a foundation for other config that shouldn't be hardcoded (future
secrets, API keys, feature flags). This is net-positive infrastructure.

### 5. Prevents plaintext backup accumulation
The `backupDatabase()` function copies `data.db` as-is. Without encryption,
every backup is a plaintext copy of health data. The longer the app runs
unencrypted, the more plaintext copies accumulate on disk.

---

## Cons of Implementing Now

### 1. Deployment complexity for a project with no deployment story
ComeCome has no Dockerfile, no `docker-compose.yml`, no `.env` file, no CI
pipeline. The README says "just upload and run" with stock PHP. SQLCipher
requires a C-level dependency (`libsqlcipher`) and a PHP PDO extension compiled
against it — this is a significant step up in ops complexity. Implementing
encryption before establishing a proper deployment story (even a basic
Dockerfile) means the encryption instructions will be the *hardest part* of
installation, potentially discouraging adoption of a v0.8 app.

### 2. No test suite to catch regressions
There are zero automated tests. The plan touches the database connection layer
— every single page and API endpoint depends on `getDB()`. A misconfigured key,
a wrong PRAGMA, or a build-linked issue will silently break the entire
application. Without tests, validation is purely manual across all features:
food logging, check-ins, weight tracking, dashboard analytics, exports, guest
tokens, backup/restore, and initialization.

### 3. Key management is a real operational burden for the target audience
The target users are parents of ADHD children, self-hosting a PHP app. The plan
correctly notes "lost key = lost data," but the mitigation is documentation.
For a pre-1.0 app with no `.env` pattern, asking users to manage a cryptographic
key stored outside the web root is a significant operational ask. A forgotten or
lost key means permanent data loss — a harsh failure mode for a family nutrition
tracker.

### 4. The actual threat model is narrow
The threat is "someone obtains the raw `.db` file." For a single-family
self-hosted app, the attack vectors for this are:
- Server breach with shell access (filesystem encryption doesn't help either,
  and SQLCipher does help here)
- Backup file exposure (SQLCipher helps)
- Physical access to the server (SQLCipher helps)

However, the most likely actual threats for this app — someone guessing a
4-digit PIN, session hijacking over HTTP, or accessing the app over a local
network without TLS — are **not addressed by database encryption at all**. The
risk/effort ratio should be compared to these alternatives.

### 5. Blocks the "just upload and run" value proposition
The README proudly states: "No Build Step Required! This application uses
vanilla PHP, SQLite, and pure CSS/JS. Just upload and run." SQLCipher breaks
this promise. It requires system-level package installation, possibly
recompiling a PHP extension, and key provisioning. This is a fundamental change
to the project's deployment identity.

### 6. Migration path has no rollback safety net
The migration script replaces the plaintext DB with an encrypted one. If
something goes wrong — wrong key stored, corrupted migration, incompatible
sqlcipher version — the user's data is at risk. In a project without tests or
a robust deployment pipeline, this is a high-stakes one-way operation that
depends entirely on the user following documentation correctly.

---

## Side-by-Side Summary

| Factor | Now | Later (Roadmap) |
|--------|-----|-----------------|
| Data protection | Immediate; fewer plaintext backups accumulate | Data remains exposed until implemented |
| Code risk | Low (one function change), but no tests to verify | Same code risk, but potentially with a test suite by then |
| Deployment burden | High — no Docker, no `.env`, no infra exists | Lower if Docker/`.env` are established first |
| User experience | Breaks "just upload and run" | Can be introduced alongside a Docker-first deployment model |
| Key management UX | Steep for target audience today | Can be wrapped in better tooling (setup wizard, Docker secrets) |
| Pre-1.0 advantage | Every new user starts encrypted | Post-1.0 requires migration support for existing installs |
| Opportunity cost | Time spent here instead of tests, Docker, TLS, or stronger auth | Encryption comes after foundational infra is in place |

---

## Recommendation

**Defer to the roadmap**, but sequence it deliberately. The plan is good — the
*timing* is premature given the project's infrastructure maturity.

### Suggested sequencing:

1. **First: Add a basic Dockerfile and `.env` support** — This gives the
   project an environment-based config pattern and a reproducible deployment
   target. SQLCipher becomes a `RUN apt-get install` line instead of manual
   documentation.

2. **Second: Add minimal integration tests** — Even a simple script that
   exercises `getDB()`, `initializeDatabase()`, `backupDatabase()`, and
   `restoreDatabase()` would provide confidence for the encryption change.

3. **Third: Implement the encryption plan** — With Docker and `.env` in place,
   SQLCipher becomes a natural part of the container, the key lives in
   `.env` or Docker secrets, and tests verify everything works.

4. **Fourth (optional): Add a setup wizard** — Help self-hosting users generate
   and store their key safely, reducing the "lost key = lost data" risk.

This order means encryption arrives with the infrastructure needed to support it
safely, rather than being the first thing that demands that infrastructure.

### If you still want to do it now:

The plan is technically correct and the code change is small. If the priority is
data protection above all else and you accept the deployment complexity trade-off,
the plan can be executed as-is. But budget for creating the Docker setup
simultaneously — don't ship SQLCipher instructions without a container that
bundles it.
