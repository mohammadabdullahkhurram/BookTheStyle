---
name: product-audit
description: Run a structured product audit of BookTheStyle (multi-tenant Laravel salon booking platform). Inventories implemented features from the actual codebase, researches live competitor offerings (Fresha, Vagaro, Booksy, GlossGenius, Mangomint, Boulevard, Squire), produces a cited feature gap matrix, flags over-engineering and non-idiomatic tech choices, and brainstorms differentiator features grounded in competitor review mining. Use this whenever the user asks to audit the product, compare BookTheStyle to competitors or "the industry", asks whether the app is over-complicated, asks "is there a better way to do X", requests a feature gap analysis, or wants ideas for features no competitor has — even if they never say the word "audit".
---

# Product Audit — BookTheStyle

Produce an evidence-based product audit, not a pep talk. Every claim about the codebase must point to a file. Every claim about a competitor must point to a URL with an access date. Every recommendation must survive the question "does this serve a small salon reached through a marketing agency?"

## Project context (verify against code — the code is the source of truth)

BookTheStyle is a multi-tenant salon booking platform built by a solo developer under the Bluejaypro agency. Stack: Laravel, Livewire/Volt, Tailwind, Flux UI, MySQL. Its structural moat is **bidirectional GoHighLevel (GHL) sync** — bookings flow both ways between the app and GHL calendars via queued outbound pushes and inbound webhooks with echo-loop suppression. Distribution is through the agency channel (salons already on GHL via white-label), not self-serve SaaS signups.

This matters for every phase below: a feature that makes sense for Fresha's marketplace model may be wrong here, and vice versa. The GHL sync is the differentiator to protect and extend, not incidental plumbing.

If any of the above contradicts what you find in the repo, trust the repo and note the discrepancy in the report.

## Phase 0 — Scope and mode

Ask nothing if the request is clear. Determine:

1. **Full audit or delta?** Check for `audits/PREVIOUS.md`. If it exists and the user didn't ask for a fresh audit, run a delta: perform the phases normally but report primarily *what changed* — in the codebase and in the market — since that file. Deltas are sharper than snapshots because they show movement.
2. **Single-phase requests.** If the user asked only one question ("am I over-engineering?", "what do competitors have that I don't?"), run only the relevant phase plus Phase 1 (you always need the inventory), and skip the rest.

## Phase 1 — Feature inventory (codebase, not memory)

Build the ground truth of what BookTheStyle actually does today. Read, at minimum:

- `routes/` — every user-facing and webhook route
- Livewire components and Volt pages — the real feature surface
- `database/migrations/` — the data model reveals features docs forget
- `app/Jobs/`, `app/Listeners/` — background behavior (sync, emails, reminders)
- `app/Policies/`, middleware — multi-tenancy and role boundaries
- `composer.json` — installed capabilities that may be unused

Produce a table: **Feature | Status (working / partial / scaffolded / dead) | Evidence (file paths)**.

Rules that keep this honest:
- Claim a feature only if code implements it end-to-end. A migration without UI is "scaffolded", not a feature.
- Flag **dead surface**: routes, components, or tables with no reachable user path. These feed Phase 4.
- Do not consult this SKILL.md's context section as evidence. Only the repo counts.

## Phase 2 — Live competitor research

Training data about this market is stale by definition — pricing and feature sets change monthly. Fetch live pages; never answer from memory.

**Core set (always):** Fresha, Vagaro, Booksy, GlossGenius, Mangomint.
**Extended (when time permits):** Boulevard, Squire (barbershops), Phorest and Treatwell (EU-relevant), Zenoti (enterprise reference — shows where the market is heading, not a direct competitor).
**Channel rival (always, briefly):** GHL's native calendar/booking itself — the real question a Bluejaypro client asks is "why this app instead of plain GHL?"

For each competitor, pull from their own pricing and feature pages plus changelogs/release notes where available:
- Booking flow capabilities (multi-service, multi-staff, group, recurring, deposits, no-show protection)
- Client-facing surface (marketplace presence, client app, reminders, reviews, loyalty)
- Business-side surface (POS/payments, payroll/commissions, inventory, marketing automation, reporting)
- Pricing model and tiers

Evidence discipline — these rules are what make the output trustworthy rather than plausible:
- Never state a competitor price or feature without a **source URL and the date you accessed it**.
- If a page is paywalled, 404s, or is ambiguous, record that as a research gap. Do not guess.
- Distinguish **verified** (seen on their page) from **inferred** (mentioned in a review or third-party article) in the matrix.

**Review mining (feeds Phase 5).** For the core set, search recent user complaints on G2, Capterra, Trustpilot, app-store reviews, and r/salonowners / r/Esthetics / barber and stylist communities. You are hunting for repeated pain: pricing anger, sync failures, double-bookings, support gaps, missing workflows. Log each recurring complaint with source links. Unmet needs of competitors' users are the only place "nobody does this but people want it" is actually written down.

## Phase 3 — Gap matrix and verdicts

Merge Phase 1 and Phase 2 into one matrix with three bands:

1. **Table stakes** — most competitors have it. Missing items here are credibility risks for demos and sales, weight them accordingly.
2. **Differentiators** — some competitors have it; it's a positioning choice.
3. **White space** — nobody has it (candidates come from Phase 5).

For every gap, issue a verdict: **Build / Later / Skip**, each with one honest sentence of reasoning tied to the ICP (small salons, agency-distributed, GHL-native). Apply pressure in both directions:
- Do not recommend building something merely because competitors have it. Fresha has payroll; a 3-chair salon on a GHL white-label may never need it inside this app.
- Challenge the P0 pile. If everything is a must-have, nothing is. Force-rank the Build list.
- Call out where BookTheStyle is *ahead* — the report should show strengths, not only holes, or the user can't defend the product to clients.

## Phase 4 — Tech-fit and over-engineering audit

Answer two questions: "would a senior Laravel developer solve it this way?" and "is anything here more complicated than the problem it solves?" Look specifically for:

- **Reinvented first-party wheels** — custom code duplicating Cashier, Sanctum, Socialite, Scout, notifications, task scheduling, or well-adopted Spatie packages. Naming the package is the finding.
- **Speculative abstraction** — interfaces with one implementation, config toggles nothing toggles, "future-proofing" for tenants/scales that don't exist yet.
- **Non-idiomatic patterns** — fighting Livewire instead of using it, raw queries where Eloquent is cleaner (or the reverse where it costs N+1s), controllers doing job-queue work inline.
- **Dead weight** — the unused surface flagged in Phase 1.
- **Justified complexity** — explicitly clear the things that *look* over-built but earn it. The GHL echo-loop suppression and per-service booking split are complex because two-way sync is genuinely hard; say so, or the user learns to distrust the audit.

Report format per finding: **What | Where (paths) | Why it's a problem (or isn't) | Simpler alternative | Severity (high/med/low)**. Report only — never refactor during an audit. Recommend, don't touch.

## Phase 5 — Differentiator brainstorm

Generate 5–10 candidate features drawn from three wells, in order of reliability:

1. **Review-mined pain** (Phase 2): recurring complaints about incumbents that BookTheStyle could structurally avoid.
2. **The GHL position**: things only possible because the app lives inside the client's marketing stack — sync-driven automations, agency-level multi-salon views, campaign-to-booking attribution. Competitors can't follow here without rebuilding their architecture; that's the moat extended.
3. **Boring white space**: unglamorous workflows nobody serves (walk-in queue handling, chair-rental bookkeeping, assistant/apprentice scheduling — verify demand via mining before proposing).

Score each candidate 1–5 on: **user value / build effort on the current stack / uniqueness / GHL-architecture fit**, and state the evidence behind the user-value score. Reject anything that requires capabilities the stack doesn't have. No sci-fi, no "AI-powered" hand-waving without a concrete mechanism.

## Output

Write the report to `audits/PRODUCT_AUDIT_<YYYY-MM-DD>.md`, then copy it over `audits/PREVIOUS.md` so the next run can delta. Use exactly this structure:

```markdown
# BookTheStyle Product Audit — <date>
## Executive summary            (10 lines max: verdict, top risks, top opportunities)
## 1. Feature inventory          (table + dead-surface list)
## 2. Competitive landscape      (per-competitor summary, all claims cited URL + date)
## 3. Gap matrix                 (table stakes / differentiators / white space, with verdicts)
## 4. Over-engineering & tech-fit findings   (per-finding format, severity-sorted)
## 5. Differentiator candidates  (scored table + one paragraph each on the top 3)
## 6. Top 5 actions              (force-ranked, one line of reasoning each)
## Appendix: research gaps       (what couldn't be verified and why)
```

Tone rules for the whole report: specific over flattering, numbers over adjectives, and when the honest answer is "you're behind here", say it plainly — the user is using this to make roadmap decisions, and a comfortable audit is a useless one.
