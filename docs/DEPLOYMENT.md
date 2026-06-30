# ComeCome — Deployment & Operations Runbook

A practical, step-by-step companion to the README's **Production Hardening**
section (which is the threat-ordered *reference*; this is the *how-to*). It covers
a fresh deploy, turning on at-rest encryption, and — most importantly — the
**backup & data-portability constraints** that are easy to get wrong.

> **Audience:** the operator deploying `comecome-claude` (staging) to Hostinger
> shared hosting. The same steps apply to any PHP+SQLite host.

---

## 0. Mental model — read this first

ComeCome separates **code** from **data + secrets**, and they travel by
*different mechanisms*:

| Lives in… | What | Travels via | In git? |
|-----------|------|-------------|---------|
| The repo | PHP/JS/CSS, schema, scripts | **`git push` → auto-deploys to Hostinger** | ✅ tracked |
| The host **only** | `data.db` (your actual data) | nothing automatic — stays on the host | ❌ git-ignored |
| The host **only** | `config.local.php` (your `define()`s) | nothing automatic | ❌ git-ignored |
| The host **only** | `encryption-key.php` (the key, if you encrypt) | nothing automatic | ❌ git-ignored |

**The one sentence that prevents disasters:** a `git push`/clone carries **code,
never your data or your key**. A brand-new host that you deploy the code to is an
**empty, zero-config app** — it will *not* have your database, your config, or your
encryption key until you put them there by hand.

A fresh checkout runs **zero-config**: DB auto-creates at `db/data.db`, no
encryption, no overrides. Everything below is *optional hardening* layered on top.

---

## 1. First deployment (fresh Hostinger)

1. **Get the code on the host.** This repo auto-deploys to Hostinger on a push to
   `main`. (After any child-page/asset change, hard-refresh so the new
   `sw.js CACHE_NAME` service worker activates.)
2. **Confirm the PHP runtime** in **hPanel → PHP Configuration**:
   - PHP **8.x** selected;
   - extensions **`sqlite3`** and **`pdo_sqlite`** enabled (required);
   - **`sodium`** only if you intend to encrypt (see §3).
3. **First load** any page → the DB auto-creates and migrates to the current
   `schema_version` at `db/data.db`.
4. **Change the default guardian PIN.** It is seeded as `0000`; the app
   force-redirects to change it before the dashboard is reachable. Do this first.

At this point you have a working, internet-reachable install. Continue to §2 to
harden it.

---

## 1A. Deploy with Docker (self-host alternative)

The repo ships a `Dockerfile`, `docker-compose.yml`, and a `Caddyfile` — a one-command self-host with
automatic HTTPS, as an alternative to the shared-hosting path in §1.

```bash
git clone <repo> comecome && cd comecome
docker compose up --build -d        # app (PHP/Apache) + Caddy (auto-HTTPS)
# open https://localhost            # local: Caddy's internal CA — accept the warning once
```

- **Data:** the SQLite DB lives in the named volume `comecome-data`, **outside the web root**, via
  `COMECOME_DB_PATH=/data/data.db`. The image never contains a database — it is built with `COPY .` plus a
  `.dockerignore` that excludes every `*.db`/`*.sqlite` (and sidecars). Back the DB up from the volume (§4).
- **Production HTTPS:** edit `Caddyfile` — replace `localhost` with your domain and set a contact `email`
  in the global block; Caddy then obtains and auto-renews a real Let's Encrypt certificate.
- **Update:** `git pull && docker compose up --build -d`.
- **At-rest field encryption (optional — see §3):** mount an above-docroot key/config file and point
  `COMECOME_CONFIG` (or `ENCRYPTION_KEY_FILE`) at it (`docker-compose.yml` has a commented example). Without
  a key the app runs zero-config with data **unencrypted at rest** — the admin UI warns about this.
- **Health:** the container ships a `HEALTHCHECK` (it curls the login page); `docker compose ps` shows
  the `comecome` service as `healthy` once it's up.
- **Try it with demo data:** `docker compose --profile demo up` runs a one-shot `seed` service that
  populates the volume with ~90 days of realistic demo data, then serves it. Log in as guardian
  **Guardião / 0000**, or a child — **Boy (demo) / 1111** or **Girl (demo) / 2222**. Re-seed with
  `docker compose --profile demo run --rm seed php db/seed-demo.php --reset`. Plain `docker compose up`
  (no profile) is a clean, empty install — **do not use the `demo` profile on a real deployment.**

---

## 2. Hardening (recommended for an internet-facing deployment)

All of this is driven by a **git-ignored `config.local.php`** (copy
`config.local.php.example` next to `config.php` and edit). It is loaded *before*
`config.php`'s own defaults, so anything you `define()` there wins.

1. **HTTPS + HSTS** — hPanel → Security → SSL → enable the free Let's Encrypt cert
   and wait for **Active**. The HTTP→HTTPS 301 is already in `.htaccess`; confirm
   the `Strict-Transport-Security` header appears over `https://`. (Full detail in
   README → Production Hardening §1.)
2. **Move the DB above the web root** so the `.db` can't be served over HTTP:
   - create `private/` **above** `public_html`, put `data.db` there;
   - `define('DB_PATH', '/home/uXXXXXXXX/private/data.db');`
3. **Strongest secret separation (optional):** put an above-docroot config file and
   point `COMECOME_CONFIG` at it (e.g. `.htaccess`:
   `SetEnv COMECOME_CONFIG /home/uXXXXXXXX/private/comecome-config.php`). `config.php`
   loads it too, and being outside `public_html` it can never be served.

---

## 3. Enable at-rest field encryption (optional)

Encrypts the four high-sensitivity columns — `users.name`, `daily_checkin.notes`,
`medications.name`, `medications.dose` — with stock libsodium. `gender`,
`date_of_birth`, and all numeric/date/coded columns stay **cleartext** by design
(the percentile engine and dashboard aggregations need them).

**Do the steps in this order — the sequence is what prevents a lockout.** The app
**fails closed**: the moment a key is configured, if the *web* PHP lacks sodium the
app refuses to write (it never silently stores plaintext). So confirm sodium
*before* pointing the app at a key.

1. **Turn sodium ON for the WEB PHP.** hPanel → PHP Configuration → PHP extensions →
   tick **`sodium`** → Save. Then verify against the **actual web runtime** with a
   throwaway browser probe (delete it after) — *not* `php -m` over SSH, because on
   Hostinger the **CLI `php` is often a different version/config from the web PHP**:
   ```php
   <?php // _sodiumcheck.php — upload, load in browser, then DELETE. Keep it OUT of git.
   header('Content-Type: text/plain');
   echo 'PHP '.PHP_VERSION."\n";
   echo 'sodium loaded: '.(extension_loaded('sodium') ? 'YES' : 'NO')."\n";
   ```
   You want **`sodium loaded: YES`**.
2. **Confirm sodium on the CLI PHP you'll use for the backfill** (step 6). Over SSH
   the default `php` may differ — use the version-specific binary if needed:
   ```bash
   php -m | grep sodium      # or: php8.3 -m | grep sodium
   ```
3. **Back up the DB first** (Guardian → Database backup, or copy `data.db`). Keep
   this backup **separate** from the key file (step 4).
4. **Generate the key above the web root** (`gen-key.php` needs no sodium):
   ```bash
   php scripts/gen-key.php /home/uXXXXXXXX/private/encryption-key.php
   chmod 0400 /home/uXXXXXXXX/private/encryption-key.php
   ```
   It is a base64 key in a PHP `return` file; it refuses to overwrite an existing
   key (clobbering a live key makes every encrypted field unreadable).
5. **Point the app at the key** in `config.local.php`:
   ```php
   define('ENCRYPTION_KEY_FILE', '/home/uXXXXXXXX/private/encryption-key.php');
   ```
   Now **load the site**. With sodium on (step 1) it works normally — new writes
   encrypt, old plaintext rows still read fine. *If any page errors here, sodium is
   not really on the web PHP → remove this `define` to instantly revert.*
6. **Back-fill the existing rows.** Encryption only happens on **write**, so rows
   that predate the key (and that you haven't edited since) are **still plaintext on
   disk** until you run this. It is verify-first (dry run asserts a byte-identical
   round-trip) and idempotent (skips already-encrypted values):
   ```bash
   php scripts/encrypt-backfill.php           # DRY RUN — verifies, writes nothing
   php scripts/encrypt-backfill.php --apply    # encrypts the remaining plaintext rows
   ```
   (Use the step-2 binary that has sodium.) After `--apply`, the four columns in
   `data.db` should all read as `enc:v1:…` blobs while the app still shows them
   correctly.
7. **Take a fresh off-host backup** now that data is encrypted (see §4).

> **Honest ceiling (shared hosting):** a leaked `.db` still exposes the schema, row
> ownership, and all numeric/date data — only the four scoped columns are
> ciphertext. Whole-file encryption (SQLCipher) is infeasible on stock shared
> hosting and is deferred (see `docs/roadmap/SPRINT-SECURITY.md`).

---

## 4. Backup & data-portability constraints

This is the section to re-read before any host migration, restore, or "let me just
redeploy."

### 4.1 What must be backed up (and where)

| Artifact | Why it matters | Backup rule |
|----------|----------------|-------------|
| **`data.db`** | All your data. Never in git. | Back it up regularly. `backupDatabase()` writes a copy; point `BACKUP_DIR` above `public_html`. |
| **`encryption-key.php`** *(if encrypting)* | **The only thing that can read your encrypted columns.** | Keep an **off-host** copy, stored **separately** from the DB backup. |
| **`config.local.php`** | The `define()`s that tell the app where the DB and key live. | Back it up (or record the values); it's git-ignored, so it won't redeploy. |

### 4.2 ⚠️ The encryption key is load-bearing

Once data is encrypted, **lose the key and the encrypted columns are gone** — there
is no recovery, by design (that's what "encrypted" means). Because the key is
git-ignored and lives only on the host:

- A `git push` to a **new** host does **not** carry the key. The new install can't
  read an encrypted DB you copy over to it until you also restore the key.
- **Never** put the key in the same archive as the DB backup (an attacker who gets
  that archive gets both). **Never** commit it.
- Keep at least one **off-host** copy of the key in a separate, secure place.

### 4.3 Restoring / migrating to a new host

To stand up an existing install elsewhere, restore **all three** layers — code,
data, secrets — or it won't work:

1. **Code** — deploy the repo (git).
2. **Data** — copy `data.db` into place and set `DB_PATH` to it.
3. **Secrets** — restore `config.local.php` (with `DB_PATH` + `ENCRYPTION_KEY_FILE`)
   **and** the `encryption-key.php` file. *Without the key, an encrypted DB is
   unreadable — the app will fail closed.*
4. **Runtime** — ensure the new host's web PHP has **`sodium`** (and `sqlite3`).

> Skipping step 3 is the classic migration failure: the site comes up, the schema
> migrates, but every encrypted name/note/med reads as a fail-closed error because
> the key didn't travel with the code.

### 4.4 Exports are a plaintext escape hatch

The four export surfaces (HTML / CSV / JSON / guest-report) **decrypt on read**, so
an export is plaintext and host-independent — a useful portability/escape path if
you ever lose the key but still have a running install. The JSON projection is
whitelisted (never emits `pin` or raw `date_of_birth`); the guest-report uses age,
not DOB.

### 4.5 Key rotation — current limitation

The toolkit encrypts plaintext→ciphertext under the **current** key and `gen-key.php`
refuses to overwrite a key in use. There is **no built-in re-key** (decrypt-with-old
→ re-encrypt-with-new) flow yet. Treat the initial key as long-lived; rotating it
today is a manual operation (export → new key → re-import, or a one-off script) and
is tracked as future work.

---

## 5. Routine operations

- **Ship a change:** `git push` → auto-deploys → hard-refresh (or bump
  `sw.js CACHE_NAME` for child-page/asset changes so the service worker updates).
- **Restore from a backup:** Guardian → Database restore (file-copy round-trip), or
  swap `data.db` directly. After a restore the default-PIN guard re-derives from the
  restored guardian hash, so it can't wrongly lock/unlock.
- **Verify encryption coverage:** peek at `data.db` (`SELECT name FROM users`) — the
  four scoped columns should read `enc:v1:…`. Re-run the backfill (it's idempotent)
  if you suspect stragglers.

---

*Reference:* README → **Production Hardening** (rationale, header details) and
`docs/roadmap/SPRINT-SECURITY.md` (threat-ordered plan). This runbook is the linear
how-to; those are the why.
