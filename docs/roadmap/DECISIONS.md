# ComeCome — Roadmap Decision Log

Running record of the open-question decisions from the roadmap assessment
(see `docs/roadmap/README.md` and `.claude/SPRINT-PLAN_reconciled.md`). One entry per
decision, captured as it is made.

---

## (i) Growth-reference framework for percentiles — **DECIDED 2026-06-19**

**Decision (revised 2026-06-19): WHO-first, CDC-2–19y as a follow-on.** Build and validate the
percentile engine on **WHO-only across all ages first** — WHO 2006 Growth Standards (0–5y) + WHO 2007
Growth Reference (5–19y) — then add **CDC 2000 (2–19y) + CDC 2022 Extended BMI** as an **additive
follow-on** to reach the full hybrid end state.

The **end-state target remains the hybrid** (WHO 0–<24mo + CDC ≥24mo + CDC 2022 Extended BMI for very
high BMI). WHO-first is a **sequencing** choice — it de-risks the highest-fidelity-risk sprint
(transcribing/validating reference data) by starting with **one provider, one format, and no age-2
provider seam** — not an abandonment of the hybrid. Because the LMS arrays are keyed
`[standard][metric][sex][age]`, adding CDC later is additive, not a rewrite.

### Scientific basis

The WHO-0–2 / CDC-2+ split is the formal recommendation of the **CDC and the American Academy
of Pediatrics**, established in the foundational report:

> Grummer-Strawn LM, Reinold C, Krebs NF (CDC). **Use of World Health Organization and CDC Growth
> Charts for Children Aged 0–59 Months in the United States.** *MMWR Recomm Rep.* 2010;59(RR-9):1–15.

Rationale:
- **0–<24 months → WHO Growth *Standards*.** WHO charts describe how healthy children *should* grow
  under optimal conditions; the reference population was 100% breastfed for ≥12 months and
  predominantly breastfed ≥4 months. Using WHO under 2 prevents **misclassifying healthy breastfed
  infants** as underweight/faltering after ~3 months (breastfed infants grow differently from
  formula-fed). This is the decisive evidence for the WHO segment.
- **≥24 months → CDC 2000 growth *reference*.** CDC describes how a specific population actually grew
  and is the reference clinicians expect for older children/adolescents.
- **Very high BMI (≥2y) → CDC 2022 Extended BMI-for-age** charts, which fix the compression/distortion
  of the original CDC 2000 curves above the 97th percentile.

### Why WHO-first (sequencing rationale)
- **De-risks Sprint 7.** WHO 2006 (0–5y) and WHO 2007 (5–19y) are one provider, one LMS
  format/methodology, and were built to **join continuously at 5y** — so the initial build has **no
  provider seam**, a single ±2 SD flagging convention, and a smaller transcription/validation surface
  to get numerically right (the single highest-correctness risk in the program).
- **The interim is itself clinically defensible.** WHO Growth Standards across all ages are the
  **EU/Portuguese clinical norm** (Portugal's DGS uses WHO curves), so shipping WHO-only first is not a
  degraded state for the app's primary (pt) audience.
- **The hybrid stays the target** because the CDC ≥2y reference is the US CDC/AAP consensus (above); it
  lands as Phase 2 once the WHO engine is validated against published WHO checkpoints.

### Screening thresholds (differ by segment)
- **WHO segment (0–2y):** flag at **±2 SD = P2.3 / P97.7**.
- **CDC segment (≥2y):** CDC conventions — P5 underweight; P85 overweight; P95 obese; Extended charts above P97.
- **Display bands** remain P3/P15/P50/P85/P97 as specced; flagging logic uses the segment rule above.

### Known caveat to handle (evidence-based) — *applies in Phase 2 only*
This caveat is **irrelevant to the WHO-first Phase 1** (WHO 2006→2007 is continuous, no seam). It
arises **only when CDC is introduced** in the follow-on: the abrupt WHO→CDC switch at exactly 24
months produces a **clinically meaningful z-score discontinuity**. A 2025 AAP *Pediatrics* study found a **mean BMI-for-age z-score drop of ~0.59 at
the transition, with 28.3% of children dropping >1.0 z** — in children whose growth was actually
stable. Implications for ComeCome:
- **Annotate the report around the 24-month boundary** so a one-time jump is not misread as a real
  growth change.
- Optionally adopt the **gradual WHO→CDC blend** the AAP 2025 paper proposes, if/when feasible.

### Implementation impact (Sprints 6 / 7 / 8 + follow-on)
- **Phase 1 (Sprints 7–8):** `includes/growth-standards.php` holds **WHO only** — WHO 2006 (0–5y) +
  WHO 2007 (5–19y) — keyed `[standard][metric][sex][ageMonths]`. Single **±2 SD** flagging convention;
  **no provider seam**; no transition annotation. Document source/version/license inline.
- **Phase 2 (follow-on after Sprint 8):** add **CDC 2000 (2–19y) + CDC 2022 Extended BMI** under the
  same `[standard]` key; introduce age-based source selection (24-month cutoff), the mixed CDC
  thresholds (P5/P85/P95), and the age-2 transition annotation. This realizes the hybrid end state.
- `includes/percentiles.php` selects the LMS source by age + standard and applies the
  segment-appropriate flagging convention.
- **BMI-for-age:** WHO BMI-for-age is available from birth (Phase 1); in Phase 2 the CDC segment uses
  BMI-for-age **≥24 months**, with weight-for-age + length/height-for-age below 2y.
- Add a roadmap **follow-on item (after Sprint 8): "CDC 2–19y hybrid"** (additive).

### Citations
- Grummer-Strawn et al., CDC. MMWR Recomm Rep. 2010;59(RR-9). — https://www.cdc.gov/mmwr/pdf/rr/rr5909.pdf
- CDC, *Recommended growth charts*. — https://www.cdc.gov/growth-chart-training/hcp/overview/recommended.html
- WHO, *Child Growth Standards Q&A*. — https://www.who.int/news-room/q-a-detail/child-growth-standards
- AAP *Pediatrics* (2025), gradual WHO→CDC transition. — https://publications.aap.org/pediatrics/article/156/3/e2025070697/203208/Creation-and-Evaluation-of-New-Growth-Charts-With
- Maintainer-referenced source (full text paywalled / not quoted here): *Current Developments in
  Nutrition* (2026), PII S2475-2991(26)00079-X. — https://www.sciencedirect.com/science/article/pii/S247529912600079X

---

## (ii) Height capture UX — **DECIDED 2026-06-19**

**Decision:** Fold height into the existing **child weight page, relabeled "Growth"** — one
optional `height_cm` field shown **only when `show_percentiles` is ON**, reusing the existing
tap-log-celebrate flow. **No new footer item** (stays 4/5). When the toggle is OFF, the weight
page is unchanged.

**Rationale:** honors the max-5-footer cap and the flat-child-surface principle; the child already
logs weight on that page, so height pairs naturally; zero new decision point for the child.

**Implementation impact (Sprint 6):** `height_log` table; `api/height.php` (mirror `api/weight.php`);
optional height input in `pages/child/weight.php` gated on `getSetting('show_percentiles','0')`;
`growth`/`height_cm` i18n keys (pt canonical); **bump `sw.js CACHE_NAME`** (child-facing asset
change). BMI needs a same-date (or nearest-within-N-days) pairing rule between `weight_log` and
`height_log` — track as a percentile edge case for Sprints 7/8.

---

## (iii) Privacy posture for gender / DOB / percentiles — **DECIDED 2026-06-19**

**Decision:** **Balanced.** Gender + percentiles + **derived age** appear in all clinician outputs;
**exact `date_of_birth` only in guardian-side exports** (HTML/CSV/JSON), shown as **age (not raw
birthdate) in the externally-shared guest-report**. The **JSON export uses an explicit field
whitelist** before `json_encode`. `gender`/`date_of_birth` are **never** shown on the child surface.

**Rationale:** gender + percentiles are clinically necessary and age is sufficient for a shared
clinician artifact; the externally-tokenized guest-report should not carry an exact birthdate;
GDPR special-category data (EU/PT families) warrants minimizing PII on shareable surfaces.

**Implementation impact:**
- `pages/guardian/export.php`: replace the blanket `echo json_encode($reportData …)` with a
  **whitelisted projection** (the critic's flagged leak); include `gender`, `age`, percentiles;
  include raw `date_of_birth` **only** in guardian-authenticated exports, never in the guest-token path.
- `getReportData()` derives `age` from DOB; `pages/guest-report.php` renders **age**, not DOB.
- Keep all four surfaces (HTML, CSV, JSON, guest-report) in **parity** as a single checklist.
- **Retention/deletion:** gender/DOB live on the `users` row and cascade-delete with the child
  (existing FK behavior); no separate retention store.
- Intersects the **deferred at-rest encryption** decision (these are the most sensitive at-rest fields).

---

## (iv) Completeness UX for percentiles with missing demographics — **DECIDED 2026-06-19**

**Decision:** **Graceful degradation + soft-warn.** Enabling `show_percentiles` **never blocks**.
At enable time, show a **one-time warning** listing active children missing `gender`/`date_of_birth`
(linking to `manage-children`). Thereafter, **per child**: complete data → percentiles shown;
missing data → a gentle "add gender/DOB to enable" prompt in the **guardian view only**.

**Rationale:** lets a guardian enable the feature for the child(ren) who have data without being
gated by a sibling's missing fields; keeps the cause visible (no silent blanks); never leaks the
prompt to the child surface.

**Implementation impact (Sprints 6/8):** `settings.php` enable-handler scans active children and
surfaces the soft warning; dashboard/report percentile sections render per-child with the
"complete gender/DOB" prompt when data is absent.

---

## (v) Security priority vs at-rest encryption — **DECIDED 2026-06-19**

**Decision:** Fold transport + auth hardening into a single **"Security & Deployment Foundations"
track** that also delivers the `.env`/secrets pattern and the integration-test harness — the
prerequisites the encryption review demands. This **hardens the most-likely threats now** and
**unblocks** the (still-deferred) SQLCipher encryption as the track's eventual payoff.

**Track scope:**
- **Auth/transport hardening:** PIN brute-force lockout/throttling; `Secure`/`HttpOnly`/`SameSite`
  cookie flags; session-ID regeneration on login; deployment **TLS guidance** (reverse-proxy +
  Let's Encrypt) in the deploy docs.
- **Deployment foundations:** `.env` / environment-config pattern (move secrets/config out of
  `config.php`); secrets handling.
- **Test harness:** the dependency-free integration tests (`getDB`/`migrate`/`backup`-`restore`) —
  i.e. the "Test & Migration Safety Net" sprint, now part of this track.
- **Unblocks:** SQLCipher at-rest encryption (key in `.env`/secret), scheduled **after** this track
  and a deliberate go decision.

**Rationale:** the encryption review's own point is that PIN brute-force and HTTP session hijacking
are the more likely threats and are untouched by at-rest encryption; bundling hardening with the
exact foundations encryption needs addresses both concerns in one coherent effort.

**Roadmap impact:** the **migrate/idempotency test-net portion still lands BEFORE the demographics
migration** (critic's ordering); the auth/`.env` hardening can run alongside or just after the
percentile arc; **encryption stays DEFERRED** but now has a named predecessor track and revisit trigger.

---

## Summary (at a glance)

| # | Decision | Choice |
|---|---|---|
| i | Growth reference framework | **WHO-first** (WHO 2006+2007, all ages) for Sprints 7–8; **CDC 2–19y + 2022 Ext. BMI added as a follow-on** to reach the hybrid end state. Arrays keyed `[standard][metric][sex][age]`. |
| ii | Height capture UX | **Fold into child weight page → "Growth"**, toggle-gated, no new footer item |
| iii | Privacy posture (gender/DOB) | **Balanced** — gender+age+percentiles in clinician outputs; exact DOB guardian-side only; JSON whitelisted |
| iv | Missing-demographics UX | **Graceful degradation + soft-warn**; never blocks the toggle |
| v | Security priority | **Security & Deployment Foundations track** (auth/TLS + .env + tests) that also unblocks deferred encryption |
