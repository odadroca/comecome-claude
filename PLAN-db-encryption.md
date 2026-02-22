# Plan: SQLite Database Encryption

## Context

ComeCome stores personal health data for children — food intake, weight, mood,
medications, and daily check-ins. If the SQLite file (`db/data.db`) is exposed
through a server breach or backup leak, all of this data becomes readable to
anyone with a text editor. The goal is to encrypt the database at rest so that
the `.db` file is meaningless without the correct key.

---

## Approach Evaluation

### Option A: SQLCipher (Transparent DB-level encryption)

SQLCipher replaces the standard SQLite library with one that encrypts the entire
database file using AES-256-CBC. Every page is encrypted before being written to
disk.

**Pros:**
- Industry standard, audited, battle-tested (used by Signal, 1Password, etc.)
- Fully transparent — zero changes to SQL queries, schema, or application logic
- Encrypts everything: data, indexes, schema metadata, WAL/journal files
- PHP integration exists via `php-sqlite3` compiled against `libsqlcipher`

**Cons:**
- Requires a custom-compiled PHP SQLite extension linked to `libsqlcipher`
  instead of the stock `libsqlite3`. On most systems this means installing
  `sqlcipher` and `php-pdo-sqlite` rebuilt against it, or using a Docker image
  that bundles it.
- Not available as a simple `composer require` — it's a C-level dependency.
- Slightly more complex deployment/ops story.

### Option B: Application-level field encryption (e.g., `openssl_encrypt` per column)

Encrypt sensitive columns individually in PHP before writing, decrypt after
reading.

**Pros:**
- Works with stock PHP and stock SQLite — no C dependencies.
- Fine-grained: only encrypt what matters.

**Cons:**
- Massive application-level changes: every `INSERT`, `UPDATE`, and `SELECT`
  touching sensitive columns must encrypt/decrypt.
- Breaks `WHERE`, `ORDER BY`, `JOIN`, `LIKE` on encrypted columns — the
  dashboard analytics queries (aggregations, date ranges, joins across
  food_log/daily_checkin/weight_log) would either break or need redesigning.
- Schema metadata, indexes, and table structure remain visible in the raw file.
- Partial protection: an attacker still sees the DB structure and unencrypted
  columns.
- High maintenance burden for every future query or schema change.

### Option C: Filesystem-level encryption (LUKS, dm-crypt, eCryptfs)

Encrypt the partition or directory where the DB lives.

**Pros:**
- Zero application changes.
- Protects against physical disk theft.

**Cons:**
- Does NOT protect against a server breach where the attacker has shell access
  (the filesystem is mounted and decrypted while the app is running).
- Requires OS-level configuration — not portable, not application-controlled.
- Doesn't protect backup files unless the backup destination is also encrypted.

---

## Recommendation: SQLCipher (Option A)

**Reasoning:**

1. **Strongest protection for the threat model.** The concern is "the SQLite DB
   ends up exposed" — meaning someone obtains the `.db` file. SQLCipher makes
   that file completely opaque without the key. Filesystem encryption doesn't
   help here (the file was already extracted). Field encryption leaves metadata
   exposed and breaks query functionality.

2. **Zero application logic changes.** ComeCome uses raw PDO with prepared
   statements throughout ~15 PHP files. With SQLCipher, the only change is
   *how the connection is opened* (one `PRAGMA key` statement). Every SQL query,
   every prepared statement, every join and aggregation works identically.

3. **Covers everything.** Schema, indexes, journal/WAL files, and all data are
   encrypted. No column is accidentally left in the clear.

4. **Proven in production.** SQLCipher is used by Signal, Zetetic, 1Password,
   and many others for exactly this use case — protecting local SQLite databases
   containing personal data.

The deployment complexity is real but manageable, especially since this is a
self-hosted PHP application where the operator already controls the server
environment (and could use Docker).

---

## Implementation Plan

### Step 1: Install SQLCipher system dependency

Add `sqlcipher` and ensure PHP's PDO SQLite extension is linked against
`libsqlcipher` instead of `libsqlite3`.

- For Debian/Ubuntu: `apt install sqlcipher libsqlcipher-dev`
- Rebuild or install a PHP PDO SQLite extension linked to libsqlcipher
- Alternatively, provide a `Dockerfile` that bundles this correctly

### Step 2: Key management

Create a new config constant `DB_ENCRYPTION_KEY` that holds the passphrase.

- The key should be loaded from an environment variable or a file outside the
  web root (e.g., `/etc/comecome/db.key`), **never** hardcoded in `config.php`.
- Add a new config entry: `DB_ENCRYPTION_KEY` sourced from
  `getenv('COMECOME_DB_KEY')` or a key file path.
- Document that the key must be kept separate from the database file — if both
  are in the same backup archive, encryption is pointless.

### Step 3: Modify the database connection (`includes/db.php`)

In the `getDB()` function, after opening the PDO connection, execute:

```
PRAGMA key = '<passphrase>';
```

This is the **only application code change required**. All existing queries
continue to work transparently.

Also add a verification query (`SELECT count(*) FROM sqlite_master`) after
setting the key to fail fast if the key is wrong, rather than producing cryptic
errors later.

### Step 4: Migrate existing unencrypted database

Write a one-time migration script (`db/migrate-to-encrypted.php`) that:

1. Opens the existing plaintext `data.db`
2. Attaches a new encrypted database
3. Copies all data using `sqlcipher_export()`
4. Replaces the old file with the encrypted one
5. Verifies the new database is readable with the key

This is a standard SQLCipher migration pattern:

```sql
ATTACH DATABASE 'encrypted.db' AS encrypted KEY 'passphrase';
SELECT sqlcipher_export('encrypted');
DETACH DATABASE encrypted;
```

### Step 5: Update backup/restore functions

Modify `backupDatabase()` and `restoreDatabase()` in `includes/db.php`:

- Backup files are already encrypted (they're copies of the encrypted `.db`
  file), but ensure the backup function copies the raw file rather than
  re-exporting to plaintext.
- The restore function needs to verify the key works on the restored file.

### Step 6: Update `initializeDatabase()`

When creating a fresh database, the `PRAGMA key` must be set before executing
`schema.sql` and `seed.sql`. Adjust the initialization flow to set the key
immediately after creating the PDO connection.

### Step 7: Documentation and deployment

- Update `README.md` with encryption setup instructions
- Document key generation: `openssl rand -hex 32` for a strong passphrase
- Document the migration process for existing installations
- Add a `docker-compose.yml` or `Dockerfile` example that includes SQLCipher
- Add `.env.example` entry for `COMECOME_DB_KEY`

### Step 8: Validation

- Verify the encrypted `.db` file is unreadable without the key (e.g., `file`
  command shows it as "data" not "SQLite 3.x database")
- Verify all existing functionality works: food logging, check-ins, weight
  tracking, dashboard analytics, guest tokens, backup/restore
- Verify `initializeDatabase()` works from scratch with encryption
- Verify migration from plaintext to encrypted works correctly

---

## Files Changed

| File | Change |
|------|--------|
| `config.php` | Add `DB_ENCRYPTION_KEY` constant from env/file |
| `includes/db.php` | Add `PRAGMA key` in `getDB()`, update backup/restore/init |
| `db/migrate-to-encrypted.php` | **New** — one-time migration script |
| `Dockerfile` (or install docs) | Add `sqlcipher` + PHP extension setup |
| `README.md` | Encryption setup and key management documentation |
| `.env.example` | **New** (or update existing) — `COMECOME_DB_KEY` |

**No changes to:** schema.sql, seed.sql, any API endpoint, any frontend file,
or any SQL query in the application.

---

## Risk Mitigation

- **Lost key = lost data.** Document this prominently. Recommend operators store
  the key in a password manager or separate secure location.
- **Performance.** SQLCipher adds ~5-15% overhead for read/write operations.
  For a single-family nutrition tracker this is negligible.
- **Rollback.** The migration script should keep a backup of the original
  plaintext database until the operator confirms encryption works.
