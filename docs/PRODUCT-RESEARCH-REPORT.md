# BookTheStyle — Product & Market Research Report

_Date: 2026-07-10 · Codebase: `main` @ `a2d9147` · Read-only audit — no code changed._
_Method: codebase inventory (verified against AUDIT-REPORT.md, same commit, plus direct file reads), live web research on 9 competitors + GoHighLevel native, review mining. Every market claim carries a source URL and access date. VERIFIED = read on the vendor's own page; INFERRED = search-surfaced or third-party. A session-limit incident forced most research through search snippets rather than full page fetches — treat INFERRED items as directionally right, re-verify before quoting in sales material (see Appendix)._

---

## Executive summary

**Verdict: the product is well-built and honestly scoped, but it is a scheduling layer, not a salon business platform — and it should say so out loud.** Against Fresha/Vagaro/Booksy feature lists, BookTheStyle "loses" on paper: no payments, no deposits, no client self-booking page, no reporting, no client history. Against the actual ICP — a small salon reached through a GHL agency — most of those gaps are either intentional (payments), covered by GHL (reminders, reviews, forms, deposits-on-booking), or cheap to close (client notes, prices, reporting). Three gaps are real credibility risks in a demo: **client history/notes, no-show protection story, and reporting**. Two structural strengths no competitor can match at this price: **native voice-AI phone booking (via GHL, ~$0.16/min or $97/mo unlimited — Fresha, Vagaro, Booksy, Mangomint, and Boulevard have no native voice agent)** and **zero marketplace commission** (Fresha takes 20% min $6 of a new client's first visit; Booksy Boost takes 30% up to $100 — the single loudest complaint in review mining). The codebase is *not* over-engineered — ~10k lines of app code for the whole platform, complexity concentrated exactly where the problem is hard (bidirectional GHL sync). The pre-launch priority is unchanged from AUDIT-REPORT.md: Phase 7 + a live GHL smoke test beat any new feature.

---

## 1. What exists today (feature inventory)

Verified against the codebase at `a2d9147` (structure re-checked this audit; deep claims cross-checked against AUDIT-REPORT.md, written at the same commit).

**Working end-to-end:** multi-tenancy (agency → salons, subdomain routing, `BelongsToSalon` + `SalonScope`, anti-IDOR test coverage) · RBAC (agency roles × salon roles × staff types) · agency console · staff invites w/ temp password + forced change · services with per-stylist duration overrides (`service_stylist.duration_override`, `DurationResolver`) · availability (weekly hours, breaks, date-specific overrides, time off) · pure interval slot engine (`app/Services/Booking/SlotEngine.php`, DST-safe, policy-gated, 15-min grid) · multi-service bookings split one-booking-per-service-line linked by `visit_group_id`, created under a concurrency lock (`app/Actions/Bookings/CreateBooking.php`) · walk-ins · check-in workflow + status timeline · auto-transitions (`bookings:close-elapsed`: elapsed booked→no-show, elapsed checked-in→completed) · reschedule · custom master/personal calendar (no paid deps) · today dashboard · basic clients page · **full GHL bidirectional sync** (encrypted PIT, outbound queued push, inbound webhook with echo-loop + stale gates and a per-event decision log — `app/Services/Ghl/GhlInboundSync.php`, hourly reconciliation, sync-error surfacing + retry, availability push to per-stylist GHL schedules) · booking `source` attribution (in_app / voice_ai / chat_widget / ghl_manual) · per-user ICS feeds (hashed tokens, show-once) · 5 branded transactional emails · marketing/register public pages.

**Not built (relevant to this report):** client history/notes/preferences · any pricing on services · reporting beyond today-stats · public client self-booking page (the `online_booking` feature flag exists; nothing behind it) · waitlist · recurring appointments · deposits/no-show fees (out of scope by SPEC — payments live in GHL/Stripe) · memberships/packages/gift cards/loyalty · reviews management (delegated to GHL) · audit log (Phase 7) · master ICS feed for owner/front-desk · two-way personal calendar sync.

**Dead surface:** small — see AUDIT-REPORT.md §4 (a few orphaned methods, the vestigial `time_off.type` column, one commented-out CI block). Nothing that changes this report's conclusions.

---

## 2. Competitive landscape (cited)

| Platform | Price (2026) | Model | Notable |
|---|---|---|---|
| **Fresha** | Independent $19.95/mo, Team $14.95/user/mo; processing 1.29% + €0.20; SMS metered after 20 free | Marketplace + SaaS; **20% (min $6) commission on marketplace-sourced new clients** | Waitlist, group bookings, forms, packages/memberships, gift cards, reviews, inventory all included; Loyalty is a €59.95/mo add-on [fresha.com/pricing, accessed 2026-07-10, VERIFIED (fetched)] |
| **Vagaro** | $30/mo + $10/user (tiers to ~$90); add-on bundles push $100–200+; 2.2–3.5% processing | SaaS + marketplace | Waitlist, recurring bookings, deposits/no-show fees, memberships, payroll, POS, forms, marketing [vagaro.com/pro; costbench.com/software/salon-spa/vagaro; accessed 2026-07-10, INFERRED] |
| **Booksy** | $29.99/mo + $20/user; no-show protection requires Boost+ $49.99 | Marketplace-led; **Boost: 30% of a new client's first visit, min $10, max $100** | No-show protection marketed as paying for the plan by itself [biz.booksy.com/pricing; support.booksy.com Boost article; accessed 2026-07-10, INFERRED] |
| **GlossGenius** | $24 / $48 / $148/mo; flat 2.6% processing | SaaS, solo/small-team focus | "Genius AI" = AI-written marketing campaigns, not a booking agent; Reserve with Google on Gold [glossgenius.com/pricing, accessed 2026-07-10, INFERRED] |
| **Mangomint** | $165 / $245 / $375/mo | SaaS, upmarket SMB | Automated Flows (behavior-triggered sequences), integrated call/text/chat with live-call client lookup; **no native voice-AI agent** (their `/features/ai-receptionist/` URL 404s; third parties confirm) [mangomint.com/pricing; mangomint.com/features/call-text-chat; accessed 2026-07-10, VERIFIED-404 + INFERRED] |
| **Boulevard** | $176–410/mo + add-ons (Forms $55/mo); 12-mo contract | Premium SaaS | **Precision Scheduling™** — ranks offered slots to minimize calendar gaps; client photos/notes; strong reporting [joinblvd.com/pricing; thesalonbusiness.com/boulevard-software-review; accessed 2026-07-10, INFERRED] |
| **Squire** | $30/mo solo; ~$50–100/mo full shop | Barbershop-focused SaaS | Walk-ins, loyalty, gift cards, payroll, recurring appointments [getsquire.com/pricing, accessed 2026-07-10, INFERRED] |
| **Square Appointments** | Free / $49 / $149 per location; 2.5–2.9% + fixed processing | POS-led SaaS | Free tier incl. Google/Outlook calendar sync + reminders; waitlist, no-show protection, resource scheduling gated to paid tiers [squareup.com/us/en/appointments/pricing, accessed 2026-07-10, INFERRED] |
| **Schedulicity** | $35 / $69/mo | Simple SaaS | Deposits via Stripe, reminders, Google sync [research.com/software/reviews/schedulicity, accessed 2026-07-10, INFERRED] |

**The channel rival — GoHighLevel native calendars** (the question every agency client asks: "why not plain GHL?"):
- GHL has service calendars + a service-menu booking widget, rooms & equipment resources, and staff availability [help.gohighlevel.com articles 155000001159, 155000001377, accessed 2026-07-10, INFERRED].
- **But:** slot interval on service calendars is fixed at 15 min; each calendar links to at most one equipment; and — critically — **GHL cannot book a multi-service visit as one flow**: "Book multiple appointments at once — HUGE PROBLEM!" is a long-standing idea-board request [ideas.gohighlevel.com/scheduling-calendar/p/book-multiple-appointments-at-once-huge-problem; help article 155000002745; accessed 2026-07-10, INFERRED].
- GHL double-booking misconfiguration is a recurring support theme (overlapping availability, round-robin sync gaps) — GHL ships a dedicated troubleshooting tool for it [help.gohighlevel.com article 48001183861, accessed 2026-07-10, INFERRED]. BookTheStyle's app-owned availability + slot engine exists precisely to remove this failure mode.
- No salon operational layer: no check-in/status workflow, no master multi-stylist day view designed for a front desk, no per-stylist service durations, no visit history UI.
- **Voice AI:** ~$0.163/min pay-per-use (voice engine + TTS + LLM + telephony) or $97/mo/sub-account unlimited inbound; books real appointments against GHL calendars with real-time availability [help.gohighlevel.com article 155000006652, accessed 2026-07-10, INFERRED].

**Review mining — the loudest recurring pains (feeds §7):**
1. **Marketplace commission anger** — Fresha's 20% new-client fee charged even for clients sourced from the salon's own Google/social; data-ownership fear ("can't export my clients if I leave") [timetailor.com Fresha review roundup; thehairandbeauty.directory; accessed 2026-07-10, INFERRED]. Booksy: 30% Boost commissions, disputed charges for clients "not gained through Boost" [trustpilot.com/review/booksy.com; accessed 2026-07-10, INFERRED]. **Pervasive.**
2. **Platform reliability + support** — Vagaro outages/glitches blocking checkout, billing disputes, "support assumes you're at fault" [trustpilot.com/review/vagaro.com; bbb.org Vagaro complaints; complaintsboard.com/vagaro; accessed 2026-07-10, INFERRED]. **Recurring.**
3. **No-show economics** — 15–25% no-show rates without deposits; competitors monetize the fix (Booksy gates it behind Boost+) [pabau.com/blog/booksy-pricing; stxsoftware.com no-show article; accessed 2026-07-10, INFERRED]. **Pervasive.**
4. **Payout/processing friction** — slow payouts, dispute clawbacks (Booksy), surprise processing fees (Vagaro) [trustpilot; accessed 2026-07-10, INFERRED]. **Recurring.**
5. **Booth-renter/hybrid salons underserved** — independent contractors inside one salon need per-renter books/payments; mainstream tools model employees [joinhomebase.com/blog/salon-booth-rental-software, accessed 2026-07-10, INFERRED]. **Isolated-to-recurring signal.**

---

## 3. Q1 — Feature gap matrix

Legend: ✅ have · 🟡 partial / via GHL config · ❌ missing. Priority is for **this ICP** (small salon, agency-distributed, GHL-native), not for cloning Fresha.

### Band 1 — Table stakes (most competitors have it; absence is a demo risk)

| Feature | Competitors | BookTheStyle | Verdict / priority |
|---|---|---|---|
| Automated reminders (SMS/email) | All | ✅ via GHL workflows — every booking reaches GHL | Done — parity achieved differently. Document it as a feature, not plumbing. |
| Client self-booking online | All (widget + marketplace) | 🟡 GHL widget/voice/chat only; no in-app public page (`online_booking` flag is empty) | **Build (later, deliberately):** GHL's widget can't do multi-service visits (cited above). v1: ship with GHL widget + voice/chat and say so. If salons hit the multi-service wall, a branded booking page becomes a *differentiator*, not parity. |
| Client history / notes / preferences | All (notes, photos, formulas on Boulevard/Vagaro) | ❌ clients are name/phone/email | **Build now — P0.** Cheapest credibility gap to close: visit history already exists in bookings; add notes + preferences fields and a client detail view. A stylist asking "what did we do last time?" is the daily workflow. |
| Deposits / no-show fees | All (Booksy sells it as the plan's ROI) | ❌ by design (no payments) | **Config, not code:** GHL booking widget supports payments/deposits via Stripe. Write the onboarding recipe + demo script. In-app, see "No-show shield" (§7) for the paymentless complement. Don't build payments. |
| Reporting / analytics | All | ❌ today-stats only | **Build — P1.** Utilization, no-show rate, rebooking rate, source mix (voice AI vs walk-in vs staff). No revenue reporting possible until services have prices. |
| Service prices (display) | All (even scheduling-only tools) | ❌ `services` has no price column | **Build — P1, trivial.** Needed for the GHL service menu, deposits story, and any future reporting. Display-only keeps the no-payments boundary. |
| Reviews management | Booksy, Fresha, GHL native | 🟡 GHL reputation + post-visit workflow (we emit `completed` status) | Config, not code. Wire "completed → review request" into the GHL template. |
| Calendar sync (staff personal) | Google/Outlook 2-way common (Square free tier has it) | 🟡 one-way ICS feeds | Adequate for v1 (honest about lag). Two-way OAuth stays roadmap; check GHL's native Google sync first (STATUS note already says this). |
| Recurring appointments | Vagaro, Squire, Square | ❌ | **Later.** Standing weekly/bi-weekly appointments are real salon behavior; not launch-blocking for the agency channel. |
| Waitlist | Fresha, Vagaro, Square Plus | ❌ | **Build — P2, as the automated version** (§7 candidate 3): passive waitlists are table stakes, an SMS auto-refill loop is a differentiator. Skip the passive-only version. |
| POS / payments / tips | All except pure schedulers | ❌ by design | **Skip.** Scheduling-only is the strategy; GHL/Stripe handles money. Say it plainly in sales material — do not imply parity. |
| Inventory / payroll / commissions | Vagaro, Fresha, Squire, Mangomint | ❌ | **Skip.** A 3–10 chair salon on this channel doesn't need it inside this app. |

### Band 2 — Differentiators (positioning choices)

| Feature | Competitors | BookTheStyle | Verdict |
|---|---|---|---|
| Voice-AI phone booking | **None native** (Mangomint ❌ verified-404; GlossGenius AI = marketing copy; Boulevard AI = slot ranking; standalone AI receptionists are $200+/mo bolt-ons) | ✅ via GHL Voice AI, books against app-validated availability | **Flagship. Market it as the headline**, not a footnote. |
| Zero marketplace commission | Fresha 20%/min $6; Booksy 30%/max $100 | ✅ structurally (no marketplace) | Turn the biggest industry complaint into the pitch: "your clients stay yours." |
| Multi-service visit, per-line stylists | Strong: Fresha/Boulevard. GHL native: ❌ | ✅ incl. different stylists per line | Ahead of the channel rival; at parity with the leaders. |
| Per-stylist service durations | Premium-tier feature (Boulevard-class) | ✅ | Ahead for the price point. |
| Memberships / packages / gift cards / loyalty | Fresha included; Squire, Vagaro | ❌ | **Skip in-app**; GHL funnels can sell these if a salon insists. |
| Group bookings / classes | Fresha, Square Plus | ❌ | Skip — wrong vertical fit for hair/beauty ICP. |
| Resource/room booking | Square Premium, GHL native rooms | ❌ | **Later** — GHL rooms exist; map them only when a salon actually asks (nail/laser rooms). |
| Marketplace / client discovery app | Fresha, Booksy, Vagaro | ❌ | **Skip.** The agency IS the discovery channel; a marketplace would recreate the commission model we're positioned against. |
| Forms / consultations | Fresha incl.; Boulevard $55/mo; Mangomint add-on | 🟡 GHL forms/surveys | Config, not code. |

### Band 3 — White space (nobody has it) → see §7.

---

## 4. Q2 — Are our choices industry-appropriate?

| Choice | Industry norm | Assessment |
|---|---|---|
| **Single-DB multi-tenant Laravel, global scopes + membership checks** | Exactly how SaaS at this scale does it (Fresha et al. are single-platform multi-tenant) | ✅ Aligned. Comfortable to hundreds of salons; the queue/console scope no-op with explicit job scoping is the correct hard part, done correctly. |
| **Subdomain-per-salon** | Unusual for the segment — competitors give salons a booking *page slug*, staff log into one app domain | 🟡 Defensible but over-provisioned for v1. It buys white-label feel (right for the agency channel) at the cost of wildcard DNS/TLS on shared Hostinger, parent-domain cookies, and a bigger routing surface. It will not "bite at scale," but it is the deploy item most likely to burn a weekend. A path-based fallback would have been cheaper; not worth reversing now. |
| **GHL as CRM/reminders/AI layer** | Nobody in the industry builds on GHL as backbone; incumbents own comms (Twilio) and charge for it (Fresha meters SMS) | ✅ Right for the constraints and the channel — it converts a $0-budget limitation into the differentiator (voice AI, agency workflows). **Named risk:** GHL is a single point of failure and its API drifts (AUDIT-REPORT §8.1); the hourly reconcile + sync panels are the correct mitigation. Keep the OAuth marketplace app as the scale-up path. |
| **One booking per service line (`visit_group_id`)** | Industry models one appointment with N services; GHL forces 1 appointment = 1 calendar = 1 staff | ✅ Correct *given the sync target* — an app-level "visit with items" that fanned out to GHL would have needed the same split at the sync boundary anyway, plus a mapping layer. The cost (visit-level operations must handle groups) is real but paid once. |
| **App-owned availability pushed to GHL, one-way** | Incumbents own availability outright; GHL-native users suffer the double-booking config mess (cited §2) | ✅ Right call — single source of truth removes GHL's biggest scheduling failure mode. The consequences (edits inside GHL get overwritten; conservative under-offering) are documented; put both in onboarding. |
| **Status model + auto-transitions** | Statuses map cleanly to GHL's (confirmed/showed/noshow/cancelled). **Auto-no-show on elapsed "booked" is aggressive** — competitors leave no-show marking manual (Booksy even requires same-day manual marking) | 🟡 The one product decision I'd revisit: a salon lax about check-in gets served clients auto-labeled no-shows — poisoning exactly the client-history and no-show-rate features this report recommends. Make the auto-no-show leg opt-in or grace-period-configurable per salon; keep auto-complete. |
| **No public self-registration, agency provisions salons** | Self-serve signup is the industry norm | ✅ Correct for the distribution model; revisit only if the channel changes. |
| **No prices anywhere** | Universal to store prices even without payments | ❌ Out of step — see gap matrix (P1, trivial). |

---

## 5. Q3 — Over-engineering audit (blunt)

**Overall: this codebase is not over-engineered — it is unusually lean for what it does.** ~10k lines in `app/`, largest file 482 lines, business logic in actions/services, no speculative interfaces found, no unused packages in `composer.json`. The complexity that exists is concentrated where the problem is genuinely hard. Findings, severity-sorted:

| What | Where | Judgment | Severity |
|---|---|---|---|
| **Monolithic Volt pages** | `resources/views/pages/salon/settings.blade.php` (1,043 lines), `availability/index.blade.php` (997) | The only real code-smell: five settings panels and a whole drawer system in single files. Works, tested, but the next contributor pays. Simpler: split into per-panel components. Not architecture — hygiene. | Med |
| **Auto-no-show transition** | `app/Console/Commands/CloseElapsedBookings.php` | Complexity is trivial; the *product* risk isn't (see §4). Make it configurable. | Med |
| **Echo-loop heuristic gates** | `GhlInboundSync.php:145–264` — state-equality echo, last-change-wins, timestamp-less echo/stale gates, decision log | **Justified.** GHL's workflow webhooks carry no timestamps; without these gates a check-in ping-pongs. The per-event decision log is exactly the right instrument for a heuristic system. This *looks* over-built and is the most defensible complexity in the repo. | — (cleared) |
| **Per-service booking split** | migrations `2026_07_09_000005/6`, `CreateBooking.php` | **Justified** by GHL's appointment model (§4). | — (cleared) |
| **Triple safety net** (webhook + hourly reconcile + repair command + sync panels) | `ReconcileGhlAppointments.php`, `RepairGhlSyncState.php`, sync-issue UI | Borderline belt-and-braces, but sync *trust* is the product for a front desk. The reconcile pre-filters to stay cheap. Keep; revisit only if the hourly cron shows up in hosting bills. | — (cleared) |
| **Two-tier staff mapping** | stylists→calendar team members, others→location users | Complexity imported from GHL's own model, not invented here. Needs onboarding docs more than refactoring. | Low |
| **Feature-flag system** (4 flags; `stylist_buffers` dormant; `voice_ai`/`chat_widget`/`online_booking` gate little today) | `config/salon_features.php`, `salons.feature_flags` | Mild speculative abstraction — but it's a config array + JSON column + one `hasFeature()` method, not a framework. SPEC explicitly plans per-salon divergence. Harmless. | Low |
| **Hand-rolled ICS writer, custom calendar UI, custom palettes** | `app/Support/Ics.php` (2 KB), `CalendarData.php` (437 lines) + calendar blade | Direct consequence of the "$0, ask before any dependency" rule. The ICS writer is tiny; the calendar is the biggest owned-code cost (~840 lines total) but avoids the FullCalendar licensing trap and Toast UI's fit issues. Reasonable trade. | Low |
| **Where it's actually UNDER-built** | clients page, reporting, audit log | The inverse finding: the thinnest areas are the ones competitors are strongest in. That's the roadmap, not a refactor. | — |

**Reinvented first-party wheels: none found.** Fortify for auth, database queue + scheduler as designed for shared hosting, Eloquent throughout, notifications not duplicated (mail goes through mailables by design). spatie/laravel-activitylog would be the idiomatic audit-log answer in Phase 7 — flag it for the dependency-approval conversation rather than hand-rolling.

---

## 6. Q4 — Better ways to do X

- **Slot engine** (`SlotEngine.php`): interval subtraction over weekly windows − breaks − time off − bookings is the textbook approach; pure, DST-safe, and tested. No caching/precomputation needed at salon scale (a day's slots = 3 small queries). **The upgrade worth stealing is Boulevard's Precision Scheduling:** rank offered slots so the chosen one minimizes stranded gaps (score candidate starts by resulting fragmentation). Pure function change, no schema, big utilization story. Later, not now.
- **GHL sync architecture**: webhook fast-path + hourly reconciliation + idempotent upsert is the industry-standard two-way-sync shape (it's what calendar-sync vendors converge on). The simpler alternative — polling only, no webhooks — would cost the near-real-time arrival of voice-AI bookings on the front-desk screen, which is the demo moment. Keep. If/when the GHL Marketplace OAuth app happens, adopt signed webhooks (`X-WH-Signature`) over the shared-secret header.
- **Availability**: the alternative (leave GHL calendars open, reject conflicting inbound bookings after the fact) fails clients mid-conversation with the voice AI. Push-down of app-owned availability is right. The conservative mapping (under-offering in GHL) is the correct side to err on; the in-app booking page never under-offers.
- **Booking flow**: staff-driven flow is complete. For client self-booking, **don't pre-build a public flow** — let the GHL widget/voice/chat carry v1, and build the branded page only when a salon hits GHL's multi-service wall. When built, it reuses SlotEngine + `CreateBooking` untouched — the architecture already supports it (that's what the flag anticipates).
- **Tenancy**: single-DB + scopes beats multi-DB packages (stancl/tenancy) at this scale by a mile — migrations, backups, and cross-salon agency queries all stay trivial. Subdomains are the only part I'd have simplified (see §4); keep, but make the deploy runbook explicit about wildcard TLS.
- **Queue/runtime**: database queue + 1-min cron is correct for shared hosting; the upgrade path (VPS + Horizon + websockets instead of 5s polling) is well understood and not urgent. The known local-dev footgun (stale `queue:listen`) is documented.
- **Status model**: keep the state machine; make auto-no-show opt-in (per-salon grace minutes, default off or generous). One migration + one config read.

---

## 7. Q5 — Differentiator candidates (nobody does this; we can)

Scored 1–5 (higher = better; effort inverted: 5 = cheap). Evidence for user value cited in §2's review mining.

| # | Candidate | Value | Effort | Unique | GHL-fit | Total |
|---|---|---|---|---|---|---|
| 0 | **Voice-AI receptionist as the headline** (already built — positioning work) | 5 | 5 | 5 | 5 | 20 |
| 1 | **Campaign-to-chair attribution** | 5 | 3 | 5 | 5 | 18 |
| 2 | **Cancellation → SMS waitlist auto-refill** | 4 | 3 | 4 | 5 | 16 |
| 3 | **No-show shield (paymentless)** | 4 | 3 | 4 | 4 | 15 |
| 4 | **Per-client duration learning** | 4 | 2 | 5 | 3 | 14 |
| 5 | **Agency benchmark dashboard** | 3 | 4 | 4 | 5 | 16* |
| 6 | **Walk-in queue board** | 3 | 3 | 2 | 3 | 11 |
| 7 | **AI reschedule round-trip** (chat AI reschedules, app validates via SlotEngine) | 4 | 1 | 5 | 4 | 14 |

\* high total but value accrues to the agency, not salons — sequence after salon-facing wins.

**Top 3, argued:**

**1. Campaign-to-chair attribution.** Every booking already carries `source` (voice_ai / chat_widget / ghl_manual / in_app) and a GHL contact id; GHL knows which campaign created the contact. Join them and report: *"Your June Facebook campaign produced 14 completed visits and 2 no-shows."* No salon platform does closed-loop marketing→visit attribution (they attribute to *their own marketplace* — that's the commission model salons hate). The buyer of BookTheStyle is an agency whose entire pitch is marketing ROI; this makes the agency's case for them every month. Effort: a report page + pulling campaign/attribution fields on contact sync. This is the moat extended — competitors can't follow without living inside the client's marketing stack.

**2. Cancellation → waitlist auto-refill.** Passive waitlists (Fresha/Vagaro/Square) are lists a human works through. We can close the loop: waitlist entries per service/stylist/date-range; on cancellation, fire a GHL workflow that texts matching clients "2:00 pm opened with Maya — reply YES to take it," first-confirm wins (slot re-validated by SlotEngine, held briefly), voice AI can even call the top of the list. Solves the #3 mined pain (no-show/cancellation economics) without payments. Effort: one table, one trigger, one GHL workflow template + a confirm endpoint.

**3. No-show shield without payments.** Deposits are the industry answer and we deliberately don't hold money — GHL/Stripe can (document that recipe). The complement no one ships at this price: a risk score from data we already have (client's prior no-shows, `source` — an unknown voice-AI caller ≠ a regular —, lead time, booking hour) that triggers an escalating GHL confirmation workflow (reply-to-confirm; unconfirmed high-risk slots auto-release to the waitlist from #2). No-show *prediction* exists only in enterprise suites (Zenoti-class). Effort: a scoring service + workflow trigger; the status history to learn from is already in `booking_status_events`.

**Honorable mention — #4 per-client duration learning** ("Mrs. K's color always runs 15 over"): check-in→completed timestamps exist in the status timeline today; compare actual vs booked per client×service and suggest padding at booking time. Mined straight from stylist workflow pain; genuinely novel (per-stylist durations exist in the market, per-client do not). Slightly deeper build (data quality depends on check-in discipline — another reason to fix auto-no-show first).

---

## 8. Top 5 actions (force-ranked)

1. **Finish Phase 7 + the live GHL smoke test before anything on this list.** Every differentiator above rides on sync trust; AUDIT-REPORT §9 already has the punch list. A feature roadmap on unverified sync is decoration.
2. **Client profiles: notes, preferences, visit history view (P0 build).** The cheapest table-stakes gap, the one a stylist hits daily, and the data foundation for candidates #3/#4.
3. **Add display-only service prices + reporting v1** (utilization, no-show rate, source mix). Closes two demo-risk gaps at once and is the on-ramp to attribution (#1 differentiator).
4. **Make auto-no-show configurable per salon** before it poisons the client-history and no-show data the roadmap depends on.
5. **Write the positioning page: "AI receptionist that answers your phone 24/7, books real appointments, and never takes a commission."** Voice AI + no-commission is the wedge every mined complaint points at; it's already built — it's a marketing artifact away from being the moat.

---

## Appendix — research gaps

- **Session-limit incident:** five research subagents died on an API session cap; research was completed inline via search + selective fetches. Consequence: only Fresha's pricing page (and Mangomint's 404) were fetched directly; all other competitor claims are search-snippet-level (marked INFERRED). Re-verify prices on vendors' own pages before using them in sales collateral.
- **Fresha marketplace fee:** their pricing page says "one-time fee" for new-client introduction without an amount; the 20%/min-$6 figure is third-party-corroborated but not seen on a Fresha page this session.
- **Mangomint AI receptionist:** concluded absent from the 404 + third-party statements; a features re-check before quoting competitively is prudent (they ship fast).
- **GHL Voice AI pricing** is from search results over the official pricing help article; per-minute components change frequently — re-verify at template-build time.
- **Boulevard/Squire/Schedulicity** were covered at summary depth only; fine for this matrix, thin for a head-to-head.
- **Review mining** leaned on aggregators (Trustpilot/BBB/roundups) rather than raw Reddit threads (several Reddit-targeted searches returned nothing); the themes are consistent across sources but per-thread citations are missing.
- **Not researched:** Phorest, Treatwell, Zenoti (EU/enterprise references) beyond incidental mentions; per skill guidance they indicate direction, not direct competition.
