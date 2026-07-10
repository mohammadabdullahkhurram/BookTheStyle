# BookTheStyle — Status & Roadmap

_A living checklist to work down. Reconcile "unconfirmed" items with the audit prompt below, then continue._

## ✅ Done & confirmed (CI green)
- Phase 0 — scaffold, multi-tenant schema, auth (no public reg), forced password change, design tokens
- Phase 1 — agency console, staff invites/temp-password, salon settings, RBAC
- Phase 2 — services, service-stylist pivot, stylist availability, time off
- Phase 3 — slot engine, multi-service bookings, walk-ins, check-in, today dashboard, concurrency lock
- Phase 3.5 / 3.7 — subdomain tenancy; domain split (marketing apex / app / register / salon)
- Phase 5 — ICS personal calendar feeds (per-staff, token-secured, show-once)
- Design rebuild Stages 1–4 — tokens/shell/dashboard, all app screens, per-stylist column calendar, public/auth pages
- Text-contrast fix (dark-mode variant neutralized); logo rebuilt as theme-aware SVG
- Per-stylist service durations (+ dormant buffer behind a flag)
- Sidebar management links (Services / Staff / Availability)

## ❓ Ran recently — VERIFY status (reports not reviewed)
Run the audit prompt to confirm each is on `main` and green:
- [ ] Salon-picker card redesign (richer info, reads live from settings)
- [ ] Salon creation + multi-salon membership permissions
- [ ] Help-docs video system (reusable, calendar-sync popup)
- [ ] Global modal close-button (×) overlap fix
- [ ] Stylist permissions lockdown (own calendar + own availability; check-ins = owner/admin/front-desk)
- [ ] Availability UX redesign (inline weekly grid; bio → profile; time off separated)
- [ ] Service create-with-stylists (assign stylists during create)
- [x] Service auto-color (color by service, stylists by avatar) — ran; needs local `php artisan migrate`

## ⏸ Parked (decided, not built)
- [ ] Staff-type model: Role (Owner / Admin / Member) + Type (Stylist / Front desk / third type). Third-type name TBD (Manager / Office / Operations). Ties into the permissions work.

## 🔜 Major phases remaining
- [ ] **Phase 6 — GoHighLevel bidirectional sync** (heaviest lift): per-salon connection; push app→GHL (store ghl_appointment_id); inbound GHL webhook → dedupe/echo-loop guard; per-stylist GHL calendars so voice/chat book valid slots; tag-based source tracking. Checkpoint: evaluate whether GHL's native Google/Outlook calendar sync covers the "connect my calendar" need before building own OAuth.
- [ ] **Phase 7 — hardening + deploy**: audit log, backups, rate limiting, security pass; Hostinger deploy with wildcard DNS + SSL for subdomains; deploy script.

## 🧩 Smaller open items
- [ ] GHL calendar embed code for the register page (slot + CSP already wired — paste when ready)
- [ ] Record how-to videos, drop into `public/how-to-documentation/…` (system built)
- [ ] Final design-review polish pass (walk every screen; batch fixes)
- [ ] Decide: keep ICS feed "show-once" (hashed) or make re-viewable (encrypted)

## Habit note
Any prompt whose report mentions a new column/table/migration → run `php artisan migrate` locally (or relaunch the launcher, which migrates on start) before testing, or you'll hit "no such column" 500s.

## Suggested order from here
1. `php artisan migrate` / relaunch → clear the current color_key error.
2. Run the audit prompt → reconcile the "verify" list; re-run anything that didn't land.
3. Finish the staff-type model (small, and it completes the permissions story).
4. One consolidated design-review pass.
5. Phase 6 (GHL) — the big one.
6. Phase 7 (deploy) — go live.
