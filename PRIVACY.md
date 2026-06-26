# ComeCome — Privacy Notice

> **Draft / template — this is not legal advice.** Reviewed by a qualified professional: _pending_.
> Have it reviewed before public launch. Assumed context: EU / Portugal (GDPR); adapt for other
> jurisdictions. ComeCome is **self-hosted** — the person or organisation running the instance is the
> **data controller** and is responsible for completing and honouring this notice. See also
> [`DISCLAIMER.md`](DISCLAIMER.md).

## Who is responsible (controller)

ComeCome is software you run yourself (self-host or container). The **operator** of the instance —
typically the guardian, family, or clinic — is the **data controller**. The ComeCome project authors
provide the software only and never receive your data.

## What data ComeCome stores

About each **child** (special-category / health data under GDPR Art. 9, and a child's data under
Art. 8):

- **Identity:** name, optional avatar emoji; **sex** and **date of birth** (used only to compute WHO
  growth percentiles).
- **Food & nutrition:** meals logged, portions, timestamps; favourites.
- **Daily check-ins:** appetite (1–5), mood (1–5), whether medication was taken, free-text notes,
  sleep quality (1–5).
- **Growth:** weight and height measurements over time.
- **Medication:** medications, doses, and schedules; medication-timing windows derived at logging.
- **Sleep:** sleep logs and interruptions.

About **guardians/clinicians:** name, a hashed PIN (never stored in clear), role, and time-limited
guest-access tokens for clinicians.

ComeCome does **not** use third-party analytics or advertising and makes no outbound calls except
loading the **Chart.js** charting library from a CDN (see [`NOTICE`](NOTICE)). Growth percentiles use
embedded WHO reference data; no data leaves your instance to compute them.

## Lawful basis

The operator (controller) must establish and document a lawful basis for processing this
special-category data — typically **explicit consent** of the holder of parental responsibility
(GDPR Art. 9(2)(a) + Art. 8 for a child), recorded in-app via the **guardian consent** step before any
child data is entered. Where ComeCome is used by a clinical service, the controller may rely on a
different basis (e.g. provision of health care, Art. 9(2)(h)) — that is the controller's determination.

## Retention

By **default ComeCome keeps data until you delete it** (appropriate for a longitudinal child health
record). The operator may enable an **opt-in auto-purge** (a retention period in months) after which
older time-series records are removed. The operator should set a retention period appropriate to their
purpose and document it here.

## Your rights & how ComeCome supports them

- **Access / portability:** export a child's full record (or the whole database) as JSON/CSV/HTML from
  the guardian area.
- **Erasure:** delete an individual child's data (cascading), or reset the whole database, from the
  guardian area; deletions are recorded in a non-identifying audit log.
- **Rectification:** edit any record from the guardian area.
- For requests, contact the **operator** of your ComeCome instance (the controller).

## What the child can and cannot see

A child signed in to ComeCome sees **only their own** data — their food log, check-ins, growth chart
(an encouraging line, no clinical bands), and history. Children **cannot** see other children's data,
clinical percentile bands, medication-timing analysis, nutrition insights, or any guardian/clinician
screens. Consent, safeguarding alerts, and all governance surfaces are guardian-only.

## Security

PIN hashing; hardened session cookies; CSRF protection on all changes; brute-force throttling; optional
at-rest field encryption (libsodium) for sensitive columns; TLS/HSTS in production. See
[`SECURITY.md`](SECURITY.md). The operator is responsible for running behind TLS, keeping the database
out of the web root, and taking encrypted off-host backups.

## Children

ComeCome is operated by an adult on behalf of a child. It is not intended for a child to set up or
administer themselves.

## Changes

Material changes to this notice bump the in-app consent notice version, which re-prompts guardians to
review and acknowledge it.
