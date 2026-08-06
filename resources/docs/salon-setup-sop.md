---
title: How to set up a new salon — step by step
category: SOPs
order: 2
---

**Who this is for:** anyone on the team setting up a new salon. **No technical background needed** — just follow it top to bottom.

**How to read this:**
- `[📸]` means "a screenshot goes here" — a picture of the exact screen you're on.
- `{{ like this }}` means "a specific value we'll fill in" (a web address, a name, etc.).
- Do the parts in order — each one builds on the last.

---

## Before you start — gather these first

It's much smoother if you have everything ready before you begin. From the salon, collect:

- Business name
- Owner's full name, email, and phone number
- List of services and their prices
- Working hours (which days, what times)
- Staff members (names, and whether each one takes bookings)
- Logo image, and brand colors if they have them

And make sure **you** have:

- Login to **BookTheStyle** (your agency account) — `{{ login URL }}`
- Login to **GHL / Loopflo** — `{{ login URL }}`

> Tip: if the salon doesn't have all their info yet, get what you can and come back — you can always add services and staff later.

---

# Part A — Set up the salon in BookTheStyle

This is where the salon's calendar, staff, and services live.

### Step 1 — Create the salon
1. Log in to BookTheStyle and go to `{{ where new salons are created }}`.
2. Start a new salon and enter the **business name** and the **owner's name, email, and phone**.
3. Save.
`[📸 the new-salon screen with the details filled in]`

> The owner will get an email to set their password — that's normal.

### Step 2 — Choose the salon type
Salons work in one of three ways. Pick what the salon told you:
- **Employee** — everyone shares one calendar (a normal salon with staff).
- **Booth Rental** — each stylist runs their own chair/booth and their own calendar.
- **Mix** — a bit of both (some employees, some booth renters).

`[📸 the salon-type choice]`

### Step 3 — Add the owner and staff
Add each person who works at the salon. Each one gets a **role**:
- **Owner** — runs everything.
- **Manager** — helps run the salon.
- **Stylist** — takes bookings (does the services).

> **Important:** if an **owner or manager also does hair** (takes appointments), tick the **"Takes bookings"** checkbox for them. Stylists always take bookings, so they don't need it.

`[📸 the Add user screen showing the role dropdown and the "Takes bookings" checkbox]`

### Step 4 — Add services and prices
1. Go to **Services**.
2. Add each service the salon offers, with its **price** and how **long** it takes.
`[📸 the services list with a couple added]`

### Step 5 — Set business hours
1. Go to **Hours / Availability**.
2. Set the days and times the salon is open.
`[📸 the hours screen]`

### Step 6 — Add branding
1. Go to **Branding**.
2. Upload the salon's **logo** and set their **colors**.
`[📸 the branding screen with the logo uploaded]`

### Step 7 — Get the booking widget
1. Go to the **Widgets** page.
2. This is the little booking tool that goes on the salon's website. Copy the **embed code** `{{ where the embed code is }}` to give to whoever manages their website.
`[📸 the widgets page with the embed code / Preview]`

✅ **The BookTheStyle side is done.** The salon now has a calendar, staff, services, hours, and a booking widget.

---

# Part B — Set up the GHL account

This is where texts, emails, and the **Voice AI** (the assistant that answers the phone) live.

### Step 8 — Create the salon's GHL sub-account
1. Log in to GHL / Loopflo.
2. Create a new sub-account for the salon using the template `{{ Loopflo snapshot name }}`.
3. Enter the salon's business details.
`[📸 the new sub-account screen]`

> The template comes pre-loaded with the standard workflows and Voice AI setup, so you're not building from scratch.

### Step 9 — Contacts and tags
Contacts are the salon's customers. **Tags** are labels that decide which contacts connect to BookTheStyle.
1. Confirm the tag `{{ tag name }}` exists (it comes with the template).
2. You usually don't need to change anything here — just confirm it's there.
`[📸 the tags/contacts area]`

### Step 10 — Turn on the workflows
Workflows are the automatic messages. Confirm these are **on**:
- **Booking confirmation** — texts/emails the customer when they book.
- **Reminders** — nudges before the appointment.
- **No-show follow-up** — reaches out if they miss it.
- `{{ any others in your template }}`

`[📸 the Workflows list showing them enabled]`

### Step 11 — Set up the Voice AI
The Voice AI answers calls and books appointments by talking to BookTheStyle.
1. Open the **Voice AI** `{{ where it lives in your GHL/Loopflo }}`.
2. Confirm the agent's **greeting/persona** reads correctly for this salon `{{ e.g. swap in the salon name }}`.
3. Leave the booking connection alone for now — you'll confirm it in Part C.
`[📸 the Voice AI setup screen]`

---

# Part C — Connect the two

This is the step that lets the Voice AI actually book into the salon's real calendar.

### Step 12 — Link GHL to BookTheStyle
1. In BookTheStyle, open the salon's **integration settings** and copy its **booking link/token** `{{ where this is }}`.
`[📸 the BookTheStyle integration settings with the link/token]`
2. In GHL, open the **Custom Action** the Voice AI uses to book `{{ where this is }}` and paste in:
   - The booking web address: `{{ URL }}`
   - The token/key: `{{ token }}`
3. Save.
`[📸 the GHL Custom Action screen with the address and token pasted in]`

> This is the one place a wrong copy-paste breaks everything — double-check the address and token match what BookTheStyle showed you.

### Step 13 — Test it (make a pretend booking)
1. In BookTheStyle, use the **verify / test** button on the integration `{{ where it is }}` — it should come back green.
`[📸 the verify button showing success]`
2. Then do a real test: call the salon's Voice AI number and book a fake appointment ("a haircut tomorrow afternoon").
3. Check that:
   - The appointment shows up on the salon's **BookTheStyle calendar**, and
   - A **confirmation text/email** went out.
`[📸 the test appointment on the calendar]`

> If the test booking doesn't appear, go back to Step 12 — it's almost always the address or token.

---

# Part D — Go-live checklist

Before you hand it over, tick all of these:

- [ ] Salon created in BookTheStyle with the right **type**
- [ ] **Owner + staff** added, "Takes bookings" ticked for anyone who does hair
- [ ] **Services + prices** added
- [ ] **Hours** set
- [ ] **Logo + colors** added
- [ ] **Booking widget** copied and sent to the salon
- [ ] GHL **sub-account** created from the template
- [ ] **Workflows** on (confirmation, reminders, no-show)
- [ ] **Voice AI** greeting reads right for this salon
- [ ] GHL **connected** to BookTheStyle (address + token pasted)
- [ ] **Verify button** is green
- [ ] **Test booking** through the Voice AI showed up on the calendar
- [ ] **Confirmation message** went out on the test

---

# Stuck? Who to ask

- Something in **BookTheStyle** (salon, staff, calendar): `{{ who / channel }}`
- Something in **GHL / Voice AI / workflows**: `{{ who / channel }}`
- The **connection** between them (test booking won't show up): `{{ who / channel }}` — and mention which step you're on.

> When you ask for help, a screenshot of the screen you're stuck on and which step number you're on gets it solved fastest.
