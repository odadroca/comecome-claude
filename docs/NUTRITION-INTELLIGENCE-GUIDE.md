# Nutrition Intelligence — Guardian Guide

A step-by-step guide to ComeCome's **Nutrition Intelligence** feature: an optional,
guardian/clinician-only summary that turns the food, medication, growth and sleep data
you already record into a plain-language picture of **when** your child eats around their
medication and **what** growth-supporting foods they get.

> **Audience:** parents/guardians. For the technical/operator side (turning it on at the
> server level, troubleshooting, thresholds), see the
> [operations runbook](RUNBOOK-nutrition-intelligence.md).

---

## What it is (and what it is *not*)

- **It is** a rule-based, descriptive read-out of your own data. The same data, looked at
  from a clinically useful angle.
- **It is *not* AI**, and it is *not* medical advice. There is no chatbot and nothing is
  sent anywhere — the analysis runs entirely on your own ComeCome installation.
- **The child never sees any of it.** Your child logs food exactly as before (same emoji
  foods, same portions, same celebration). Every tag and insight is invisible metadata for
  you and your clinician only.

It is designed around the reality of a neuro-divergent child on appetite-suppressing
stimulant medication, whose best eating window is often *before* the dose and whose
appetite dips during the medication's peak.

---

## Before you start (what makes it useful)

The feature works as soon as you turn it on, but each part needs a little data:

| You want to see… | You need… |
|---|---|
| **Meal-timing around medication** | A **medication schedule** set up for the child (Guardian → Manage Medications → set a dose time + type), and food logged on normal medicated days. |
| **Growth-tag coverage** | A week or so of logged meals. Built-in foods are already tagged for you. |
| **A "weight trending down" suggestion** | Growth **percentiles** turned on (needs the child's gender + date of birth + some weight entries). |
| **A sleep-and-appetite note** | A few daily check-ins with **sleep quality** filled in. |

You don't need all of these — the panel shows whatever it can and quietly skips the rest.
**Rule of thumb:** log food for about **5+ days** before expecting a full read-out.

---

## Step 1 — Turn it on

1. Log in as the **guardian**.
2. Go to **Settings**.
3. Find **🥗 Nutrition intelligence** and tick the checkbox.
4. Save.

It is **off by default**. Turning it off again hides it everywhere instantly — nothing is
deleted.

---

## Step 2 — Where it appears

Once enabled, the **Nutrition Intelligence** section shows up in two places:

- **Guardian dashboard** — a panel below the growth percentiles, for your day-to-day view.
- **Clinician report** — a *"Medication-Aware Nutrition Summary"* in every export (the
  printable **HTML report**, the **CSV**, the **JSON**, and the **guest link** you share
  with a clinician).

If you just enabled it and see a friendly *"not enough logging yet"* message, that's
expected — keep logging meals for a few days.

---

## Step 3 — How to read each part

The panel has up to three parts. Any part with no data is simply omitted.

### A. Meal timing around medication

A small table showing the **share of intake** in each part of the medication day:

| Window | What it means |
|---|---|
| **Before medication** | Eaten before the dose — usually the *best* appetite window. |
| **Onset of effect** | Just after the dose, as it starts working. |
| **Peak (appetite suppression)** | When appetite is typically lowest. |
| **Recovery (rebound)** | After the medication wears off, when appetite often returns. |

This appears only when a medication schedule exists. A common, useful pattern to spot:
most intake sitting in **Recovery (rebound)** with very little **Before medication**.

### B. Growth-supporting food coverage

A table of six **growth tags**, with weekly servings, a trend arrow, and a status dot:

| Tag | Why it matters |
|---|---|
| **Calorie-dense** | Maximizes intake in a small eating window (counters appetite suppression). |
| **Protein-rich** | Supports growth and helps your child feel satisfied. |
| **Bone-building** | Calcium / vitamin D for growing bones. |
| **Brain fuel** | Sustained energy and focus. |
| **Easy to eat** | Low-friction options for low-appetite moments (smoothies, yogurt, crackers). |
| **Hydrating** | Stimulants blunt thirst — water-rich foods/fluids help. |

- **Servings per week** — how much your child got, scaled to a 7-day rate.
- **Trend** — ▲ rising / ▼ falling / — steady, comparing the recent half of the period to
  the earlier half.
- **Status** — 🟢 *Adequate* or 🔴 *Low* against a sensible weekly target. (*Easy to eat* is
  a coping option, not a daily target, so it has no status dot.)

Below the table you'll see a coverage line like *"38 of 46 foods have growth tags."*
Built-in foods are pre-tagged. **Foods you add yourself start untagged** and won't count
toward coverage — that's the gap this line is pointing at (see the FAQ below).

### C. Suggestions

Plain-language, rule-based prompts derived from the parts above, for example:

- ⚠️ *"70% of intake happens after medication wears off. Offer calorie-dense foods before
  the dose and through the morning."*
- ⚠️ *"Protein-rich servings are low (2/week). Protein supports growth and satiety."*
- ℹ️ *"Sleep quality has been low (2.1/5); poor sleep can further reduce next-day appetite."*

A **⚠️** is an actionable gap worth a look; an **ℹ️** is context. They are suggestions to
discuss, not instructions — you know your child best.

---

## Step 4 — Share it with a clinician

Everything in the panel is included in the reports you already use:

1. **Guardian → Export** → choose **HTML** (print/PDF for an appointment), **CSV**
   (spreadsheet), or **JSON** (data).
2. Or generate a **guest link** so a clinician can view the report in their browser
   (you can revoke the link at any time).

**Your child's privacy is preserved in shared exports:** the nutrition summary contains
only aggregated figures (window shares, weekly servings, suggestions). It never includes
your child's name, date of birth, or the individual food-log entries.

---

## Adding your own foods (tag coverage)

When you add a custom food from the child interface, it has no growth tags yet, so it
doesn't contribute to the coverage figures. ComeCome **never guesses tags for you** (these
are nutrition-adjacent, so silent guessing would be misleading). The coverage line simply
tells you how many foods are tagged so you know the picture is based on the tagged ones.
Per-food tag editing is planned as a later enhancement.

---

## Why a section might be missing

| You see… | Reason |
|---|---|
| Nothing at all | The toggle is off, or it's off and there's nothing to show. |
| *"Not enough logging yet"* | Fewer than ~5 days of food logs in the selected period. |
| No **meal-timing** table | No medication schedule set up for the child (or no logged intake fell in a medication window). |
| No *"weight trending down"* suggestion | Growth percentiles are off, or there aren't enough weight entries to show a trend. |
| No sleep note | No recent check-ins with sleep quality recorded. |

---

## Turning it off

Settings → untick **🥗 Nutrition intelligence** → Save. The panel disappears from the
dashboard and from all exports immediately. No data is deleted; re-enable any time.

---

## FAQ

**Does any of my child's data leave my device/server?**
No. The analysis is 100% local rule-based code. There is no AI service and no external
call. (A future, *optional, opt-in* version could rephrase the findings using an AI
service, but only with de-identified aggregates and never on by default.)

**Is this a diagnosis or a meal plan?**
No. It's a descriptive summary to help you and your clinician have a more informed
conversation. Always defer to your healthcare professional.

**Can my child see it?**
No. It only exists in the guardian dashboard and clinician reports. The child app is
unchanged.

**The numbers look off / too low.**
Coverage is based on *tagged* foods over the selected date range. Check the coverage line
(custom foods are untagged), and try a longer period from the dashboard's period selector.

---

*Medical disclaimer: ComeCome is a tracking aid, not a medical device. Nutrition
Intelligence is a descriptive, rule-based read-out of data you entered and does not
provide diagnosis or treatment. Consult your child's healthcare provider for medical
decisions.*
