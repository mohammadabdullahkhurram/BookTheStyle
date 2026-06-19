# BookTheStyle — Design bundle

Drop this `design/` folder into the repo root, and move `DESIGN-TOKENS.md` to the repo root (or leave it here and point prompts at design/DESIGN-TOKENS.md — your call). Commit it so Claude Code can read it.

## Contents
- **DESIGN-TOKENS.md** — the authoritative spec: exact hexes, fonts, type scale, spacing, radii, and component styles. This is the source of truth Claude Code builds to.
- **screenshots/** — rendered target screens from Claude Design (dashboard + login states). The visual targets to match.
- **references/**
  - `style-guide.dc.html` — the Claude Design style-guide page (template-driven; values already captured in DESIGN-TOKENS.md).
  - `app-screens.dc.html` — Claude Design export of the app screens (reference markup).
  - `marketing-site.dc.html` — Claude Design export of the marketing/site pages (useful starting reference for Stage 4 public pages).
  - Fresha `*.jpg` — the aesthetic references the design was based on.

Note: the `.dc.html` files are Claude Design's export format and are for reference only — they are React/HTML, not the Laravel/Livewire app. Use DESIGN-TOKENS.md + screenshots as the build target; treat the HTML as a look reference.
