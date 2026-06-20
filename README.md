# 🍽️ ComeCome v0.9.1 - ADHD-Friendly Food Tracking

A compassionate nutrition tracking application designed specifically for neuro-divergent children, particularly those with ADHD and medication-induced appetite challenges.

## 🎯 Purpose

ComeCome helps families monitor their children's eating habits with **minimal friction** and **maximum independence**. Built with input from parents of ADHD children on medication regimes that affect appetite.

## ✨ Key Features

### For Children
- **Tap to log, not type** - Big emoji food buttons
- **Auto-detect meal times** - One less decision to make
- **Simple portions** - "A little / Some / A lot / All" (no calorie counting)
- **Favorites rise to top** - Most-used items need fewest taps
- **Daily check-in** - Track appetite, mood, sleep, and medication
- **Weight & growth** - Simple charts; optional height entry (the weight page becomes "Growth")
- **History view** - See what you ate

### For Parents/Guardians
- **Family dashboard** - Visual analytics, trends, and **WHO growth percentiles**
- **Multiple children** - Manage whole family (gender + date of birth enable percentiles)
- **Sleep & medication-timing** - Sleep quality tracking + medication-window analysis of intake
- **Nutrition intelligence** - Optional rule-based "Medication-Aware Nutrition Summary": when in the medication day your child actually eats, growth-supporting food coverage, and plain suggestions (guardian/clinician-only, opt-in; never shown to the child). See the [guardian guide](docs/NUTRITION-INTELLIGENCE-GUIDE.md).
- **Clinician reports** - Export as HTML, CSV, JSON, or a token-shared guest link
- **Full data control** - Backup, restore, or delete; **opt-in at-rest field encryption**

### ADHD-Friendly Design
- ✅ Large touch targets (48px+)
- ✅ Clear visual hierarchy
- ✅ No overwhelming choices
- ✅ Dark mode support
- ✅ `prefers-reduced-motion` respect
- ✅ Haptic feedback
- ✅ Offline-first PWA

## 🚀 Installation

### Requirements
- PHP 7.4+ with SQLite support
- Any web server (Apache, Nginx, etc.)
- Modern web browser

### Quick Start

1. **Clone or download** this repository to your web server
2. **Ensure permissions** on the `db/` directory (writable)
3. **Access via browser** - The app will auto-initialize the database
4. **Default login**: Username: "Guardião", PIN: "0000"

```bash
# Example installation
cd /var/www/html
git clone [this-repo] comecome
chmod 755 -R comecome
chmod 775 comecome/db
chown www-data:www-data comecome/db
```

### No Build Step Required!
This application uses vanilla PHP, SQLite, and pure CSS/JS. Just upload and run.

## 📱 PWA Installation

ComeCome works as a Progressive Web App. On mobile:
1. Open in browser
2. Tap "Add to Home Screen"
3. Use like a native app!

## 🌍 Internationalization (i18n)

### Built-in Languages
- 🇵🇹 **Portuguese** (default)
- 🇬🇧 **English**

### Adding Your Language
1. Go to **Guardian Panel → Translations**
2. Select your target language
3. Edit translations directly in the interface
4. Translations are stored in database and override defaults

### Contributing Translations
ComeCome is designed for easy community translation:
- All strings use key-based translation (`t('key')`)
- Translation management UI included
- JSON files in `locales/` for base translations
- Database overrides for customization

## 📊 Export Formats

### HTML Report (Print-Ready)
Clean, professional format for medical appointments. Includes:
- Weight timeline with changes
- Medication adherence
- Daily meal counts
- Meals by type
- Intake by category

### CSV Export
Opens in Excel, Google Sheets, or LibreOffice. Perfect for:
- Data analysis
- Custom charts
- Clinical software import

### JSON Export
Complete data structure for:
- Technical integrations
- Backup purposes
- Data portability

## 🔐 Security

- **PIN-based authentication** (child-friendly 4-digit PINs)
- **Session management** with secure tokens
- **Guest access tokens** with expiration
- **SQLite database** - No external DB server needed
- **Input sanitization** and prepared statements
- **No external dependencies** for core functionality

## 🔒 Production Hardening (optional, recommended)

> 📘 **For a linear, step-by-step deploy + operations guide — including the
> encryption enablement sequence and the backup / data-portability constraints —
> see [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).** The section below is the
> threat-ordered *reference*; that runbook is the *how-to*.

A fresh install runs zero-config (DB at `db/data.db`). For an internet-reachable
deployment, harden it via a **git-ignored** `config.local.php` (copy `config.local.php.example`):

1. **Serve over HTTPS + enable HSTS** (Hostinger shared hosting — concrete steps):
   1. In **hPanel → Security → SSL**, enable the **free Let's Encrypt** certificate for the
      domain and wait until it shows **Active** (issuance is automatic, usually minutes).
   2. The HTTP→HTTPS **301 redirect is already enabled** in `.htaccess` (the `RewriteCond
      %{HTTPS} off` rule). It self-guards against a redirect loop, so once the certificate is
      Active every `http://` request lands on `https://`. *(If you terminate TLS at a proxy,
      the rule also honours `X-Forwarded-Proto`.)*
   3. **Confirm HSTS:** load the site over `https://` and check the response headers — you
      should see `Strict-Transport-Security: max-age=86400; includeSubDomains`. The `.htaccess`
      `Header always set ... env=HTTPS` rule emits it over TLS; the PHP layer
      (`includes/session.php`) emits the same header as a backstop on non-Apache hosts
      (nginx/litespeed). It is **conservative on purpose** — 1 day, **no `preload`**. Once TLS
      has been stable for a while, raise `max-age` (e.g. `31536000` = 1 year) in both `.htaccess`
      and `config.local.php` (`define('HSTS_MAX_AGE', 31536000);`), and only then consider preload.
   - **Optional app-level enforcement:** set `COMECOME_FORCE_HTTPS=1` (env var, or
     `putenv('COMECOME_FORCE_HTTPS=1');` in `config.local.php`) to make PHP itself 301
     HTTP→HTTPS even where `.htaccess` does not apply. Leave it **unset for local `php -S` dev**
     so plain-HTTP development is never redirected.
2. **Change the default guardian PIN** (seeded `0000`) on first login.
3. **Move the database above the web root** so it can't be served over HTTP:
   - create a `private/` folder **above** `public_html` and place `data.db` inside it;
   - copy `config.local.php.example` → `config.local.php` and set
     `define('DB_PATH', '/absolute/path/to/private/data.db');`.
4. **Secrets / `.env` pattern** (per-deployment config out of the tracked `config.php`):
   `config.php` loads, in order, an **above-docroot** config file pointed at by the
   `COMECOME_CONFIG` env var (recommended for secrets — it sits outside `public_html`), then
   the in-tree git-ignored `config.local.php`. A fresh download has neither and runs zero-config.
5. **Scoped at-rest field encryption** (optional; protects identity/free-text columns):
   ComeCome can encrypt the four high-sensitivity columns — `users.name`,
   `daily_checkin.notes`, `medications.name`, `medications.dose` — at rest with **stock
   libsodium** (`sodium_crypto_secretbox` / XChaCha20-Poly1305, no Composer, no build). It is
   **strictly opt-in**: with no key the columns stay plaintext and the app runs zero-config.
   `gender` and `date_of_birth` are deliberately **left cleartext** (the WHO percentile engine
   derives age from them); all numeric/ordinal/date/coded columns stay cleartext too, so the
   dashboard aggregations/correlations keep working.
   - **Requirement:** the PHP **`sodium`** extension must be loaded (bundled with PHP 7.2+; on
     shared hosting enable `extension=sodium`). Verify on your host:
     ```bash
     php -m | grep sodium
     ```
     If a key is configured but sodium is missing, the app **fails closed** (refuses to write
     plaintext under a configured key) — it never silently stores plaintext.
   - **Generate the key** (a 32-byte base64 key in a PHP `return` file — *not* an `.ini`):
     `php scripts/gen-key.php /home/uXXXXXXXX/private/encryption-key.php`;
   - `chmod 0400` it and keep it **above** `public_html`;
   - point the app at it: `define('ENCRYPTION_KEY_FILE', '/abs/path/encryption-key.php');`
     in `config.local.php` (or the `COMECOME_CONFIG` file), or set `COMECOME_KEY_FILE`;
   - `includes/secrets.php` validates the decoded key is exactly 32 bytes and **fails closed**
     on a missing/malformed/wrong-length key. **Never** store the key in the same backup archive
     as the database, and never commit it (the real `encryption-key.php` is git-ignored; only the
     `.example` template is tracked).
   - **Back-fill existing data** once a key is configured (encrypt rows that predate the key).
     The migration is **verify-first** (a dry run decrypts every value back and asserts a
     byte-identical round-trip before writing) and **idempotent** (already-encrypted values are
     skipped, so a half-run is safely resumable and a re-run is a no-op):
     ```bash
     php scripts/encrypt-backfill.php          # DRY RUN — verify round-trips, write nothing
     php scripts/encrypt-backfill.php --apply   # encrypt in place after the verify passes
     ```
     New writes encrypt automatically; reads decrypt transparently, so plaintext (not-yet-backfilled)
     and encrypted rows coexist during the transition. Take an encrypted, off-host backup
     **before** applying if you want a rollback point.
   - **Honest ceiling (shared hosting):** a leaked `.db` still exposes the schema, row ownership,
     and all numeric/date data; only the four scoped free-text/identity columns are ciphertext.
     Whole-file encryption (SQLCipher) is **infeasible on stock shared hosting** and is deferred to
     a VPS/Docker future — see `docs/roadmap/SPRINT-SECURITY.md`.
6. **Keep backups out of the web tree and encrypt them off-host:** `backupDatabase()` writes a
   full DB copy. Point it above `public_html` with `define('BACKUP_DIR', '/abs/path/private/backups');`
   (or `COMECOME_BACKUP_DIR`), then sync those copies **off-host, encrypted** (e.g. `age`/`gpg`) —
   and **never** in the same archive as the encryption key file.

`config.local.php` / the key file are per-deployment and never committed; the override mechanism
itself lives in `config.php`, so the procedure is reproducible across installs. See the full
threat-ordered plan in `docs/roadmap/SPRINT-SECURITY.md`.

## 🗄️ Database Schema

ComeCome uses SQLite with a clean, normalized schema (auto-migrated to **`schema_version` 7**):
- **users** - Children and guardians (incl. `gender`, `date_of_birth` for percentiles)
- **meals** / **foods** / **food_categories** - Meals + extensible food catalog
- **food_growth_tags** - Strategic growth tags per food (powers the nutrition intelligence panel)
- **food_log** - Daily intake tracking (incl. `med_window` medication-timing stamp)
- **daily_checkin** - Appetite, mood, **sleep quality**, medication
- **weight_log** / **height_log** - Weight + height for WHO growth percentiles
- **medications** / **medication_schedules** - Medication management + dosing windows
- **sleep_log** / **sleep_interruptions** - Detailed sleep tracking
- **guest_tokens** - Temporary clinician access (revocable)
- **login_attempts** - PIN brute-force throttling state
- **settings** - Key/value feature toggles + config; **translations** - i18n overrides

## 🎨 Customization

### Meals
Portuguese meals are pre-configured:
- Pequeno Almoço
- Lanche da Manhã
- Almoço
- Lanche da Tarde
- Jantar
- Ceia

Edit meal names via the Translation interface.

### Foods
60+ foods included with emoji. Categories:
- Fruits, Vegetables, Proteins, Grains
- Dairy, Snacks, Drinks, Sweets

Add custom foods from the child interface!

### Settings
- Toggle medication visibility for young children
- Toggle **Nutrition intelligence** (guardian/clinician-only; default off)
- Change default language
- Configure meal times

## 📖 Usage Guide

### First-Time Setup
1. Log in with default guardian account
2. Add your children (name, PIN, emoji avatar)
3. Add medications if needed
4. Configure settings

### Daily Use (Child)
1. Tap your name and enter PIN
2. Select current meal (auto-detected by time)
3. Tap food emoji
4. Choose portion size
5. Done! 🎉

### Monitoring (Guardian)
1. View dashboard for insights
2. (Optional) Enable **Nutrition intelligence** in Settings for the medication-aware nutrition panel — see the [step-by-step guardian guide](docs/NUTRITION-INTELLIGENCE-GUIDE.md)
3. Export reports for doctor visits
4. Generate guest links for clinicians
5. Manage family settings

> **Operators:** to enable/troubleshoot the nutrition intelligence feature (migration, data prerequisites, thresholds, rollback), see the [operations runbook](docs/RUNBOOK-nutrition-intelligence.md).

## 🤝 Contributing

This is a FOSS project built with love for families managing ADHD.

**Ways to contribute:**
- 🌍 Add translations for your language
- 🍎 Suggest food items for your culture
- 🐛 Report bugs or suggestions
- 📖 Improve documentation
- 💻 Submit pull requests

## 📋 Technical Stack

- **Backend**: Vanilla PHP (no frameworks)
- **Database**: SQLite3
- **Frontend**: HTML5, CSS3, JavaScript
- **Styling**: Custom CSS (ADHD-optimized)
- **Charts**: Chart.js (CDN)
- **PWA**: Service Worker + Web Manifest

**Design Philosophy**: Keep it simple, accessible, and offline-capable.

## 🏥 Medical Disclaimer

ComeCome is a tracking tool, not medical software. Always consult healthcare professionals for medical advice. This tool is meant to facilitate communication with clinicians, not replace it.

## 🗺️ Roadmap

Planning and review documents live in [`docs/roadmap/`](docs/roadmap/). The
canonical sprint plan is [`.claude/SPRINT-PLAN_reconciled.md`](.claude/SPRINT-PLAN_reconciled.md).
Sprints 0–2 (bug fixes, feature toggles, sleep tracking) shipped in v0.9; Sprints 3–5
(percentiles, growth-support nutrition) and at-rest encryption are planned/deferred.

## 📄 License

Open Source - Free to use, modify, and distribute.

## ❤️ Acknowledgments

Built with love for all children navigating ADHD with courage.

Special thanks to parents and caregivers who provided insights into real-world challenges.

---

**Made with 💚 by parents, for parents**

*"Making food tracking simple, so families can focus on what matters."*
