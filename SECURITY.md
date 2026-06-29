# Security Policy

ComeCome handles **sensitive data about children** (health, food, medication, growth,
sleep). We take security reports seriously and ask for **coordinated disclosure**.

## Supported versions

Security fixes target the **latest release** and the `main` branch. Older versions are not
maintained.

| Version | Supported |
|---------|-----------|
| latest / `main` | ✅ |
| older | ❌ |

## Reporting a vulnerability

**Please do not open a public issue for security problems.**

- Preferred: GitHub **private vulnerability reporting** — use the repository's
  **Security → Report a vulnerability** form (GitHub Security Advisories). This opens a
  private channel with the maintainers.

Please include: affected version/commit, a description, reproduction steps or a PoC, and
the impact. **Do not include real children's data** in any report or PoC — use synthetic
data only.

## Response targets

- Acknowledge within **3 business days**.
- Initial assessment + severity within **7 days**.
- Confirmed high-severity issues are prioritised; we will keep you updated.
- **Coordinated disclosure:** we will agree a timeline with you and ask that you allow time
  to patch before any public disclosure.

## Scope

**In scope:** the application code (PIN/auth handling, sessions, CSRF, brute-force
throttling, the optional at-rest field encryption, data access/ownership, SQL handling),
the default configuration, and the published Docker image.

**Out of scope:** vulnerabilities in third-party hosting/services the operator chooses (the app
itself makes no outbound third-party calls — Chart.js and the fonts are self-hosted),
issues requiring an already-compromised host or operator misconfiguration, missing
hardening on a self-hosted instance the operator controls, and volumetric denial-of-service.

## Operator responsibilities (self-host)

ComeCome is self-hosted; the operator is the data controller and is responsible for:
running behind TLS (the bundled Compose uses **Caddy** for automatic HTTPS), keeping the
database **outside the web root** (the image sets `COMECOME_DB_PATH=/data`), configuring the
above-docroot encryption key if using at-rest encryption, and taking **encrypted off-host
backups**. See [`DEPLOYMENT.md`](DEPLOYMENT.md).

## Existing security posture

PIN hashing; hardened session cookies (HttpOnly / SameSite, env-gated Secure) with
regeneration + idle timeout; CSRF tokens on all state-changing POSTs; brute-force
throttling; optional libsodium at-rest field encryption; TLS/HSTS enforcement; prepared
statements throughout. See [`docs/roadmap/SPRINT-SECURITY.md`](docs/roadmap/SPRINT-SECURITY.md).
