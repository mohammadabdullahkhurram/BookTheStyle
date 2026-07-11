# Backups & restore points

The catalog of every restore point in this repository. **Tags** are permanent
labeled snapshots; **backup branches** are frozen copies of the same commits —
**never commit to a backup branch**. All work, always, continues on `main`.

**Update this file whenever a new checkpoint is made** (new tag + backup
branch → add a row and a section here in the same sitting).

## How to restore

- **Inspect (non-destructive):** `git checkout <tag-or-branch>` — look around,
  or branch from it with `git switch -c experiment <tag>`; `git switch main`
  returns you to the present.
- **Reset main (destructive):** `git reset --hard <tag>` while on `main`,
  then `git push --force-with-lease origin main`. This discards everything
  after the tag — be sure.

## Catalog

| Restore point | Type | Commit | State captured |
|---|---|---|---|
| `pre-prelaunch-v1` | tag | `cb1f3e3` | Phases 0–6 complete |
| `backup-phases-0-6-complete` | branch | `cb1f3e3` | same state as `pre-prelaunch-v1` |
| `v1.0-prelaunch-complete` | tag | `903b295` | + the 5 pre-launch features |
| `backup-v1-prelaunch-complete` | branch | `903b295` | same state as `v1.0-prelaunch-complete` |
| `v1.1-preflight` | tag | `3f57c7a` | + clients directory, voice-AI API, contact sync |
| `backup-v1.1-preflight` | branch | `3f57c7a` | same state as `v1.1-preflight` |

## Details

### pre-prelaunch-v1 — Phases 0–6 complete
- **Commit:** `cb1f3e33da7e229b9cb8f0cd4870b7508013f276`
- **Frozen branch:** `backup-phases-0-6-complete` (same commit)
- **State:** the full SPEC build — tenancy, RBAC, services/availability,
  booking engine + status model, calendars, ICS feeds, the complete GHL
  bidirectional booking sync (6a–6e), transactional email — **before** the
  five pre-launch features.
- **Restore:** `git checkout pre-prelaunch-v1` (inspect) ·
  `git reset --hard pre-prelaunch-v1` (reset main)

### v1.0-prelaunch-complete — feature-complete for launch
- **Commit:** `903b2953cc4e20a72ff85bc2e3e2ba0bf550bedd`
- **Frozen branch:** `backup-v1-prelaunch-complete` (same commit)
- **State:** everything above **plus** the five pre-launch features: dead-code
  cleanup, per-salon auto-no-show configuration, display-only service prices
  (+ per-salon currency), client profiles (history / notes / preferences),
  and the reports dashboard — **before** any voice-AI/API work.
- **Restore:** `git checkout v1.0-prelaunch-complete` (inspect) ·
  `git reset --hard v1.0-prelaunch-complete` (reset main)

### v1.1-preflight — Stage 2 built, pre-deploy
- **Commit:** `3f57c7abe3b09b77b3b612e1d0d4e44a6a8e7572`
- **Frozen branch:** `backup-v1.1-preflight` (same commit)
- **State:** everything above **plus** the Clients directory (full nav tab
  with per-row stats/search/sort/filters), the voice-AI booking API
  (per-salon bearer tokens, availability + create endpoints over the app's
  own engine), and bidirectional client↔GHL-contact sync with tag-gated
  inbound + auto-tagging of real clients — **before** the live GHL smoke
  test, UI audit, and deploy (Phase 7).
- **Restore:** `git checkout v1.1-preflight` (inspect) ·
  `git reset --hard v1.1-preflight` (reset main)
