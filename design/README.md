# BookTheStyle — Design bundle

This `design/` folder is the committed design bundle (reference markup, target screenshots, and aesthetic references). The authoritative spec, `DESIGN-TOKENS.md`, lives in the **repo root** ([`../DESIGN-TOKENS.md`](../DESIGN-TOKENS.md)) — that is the single source of truth Claude Code builds to. (It used to be duplicated here; the copy was removed to avoid drift.)

## Contents
- [`../DESIGN-TOKENS.md`](../DESIGN-TOKENS.md) (repo root) — the authoritative spec: exact hexes, fonts, type scale, spacing, radii, and component styles. This is the source of truth Claude Code builds to.
- **screenshots/** — rendered target screens from Claude Design (dashboard + login states). The visual targets to match.
- **references/**
  - `style-guide.dc.html` — the Claude Design style-guide page (template-driven; values already captured in DESIGN-TOKENS.md).
  - `app-screens.dc.html` — Claude Design export of the app screens (reference markup).
  - `marketing-site.dc.html` — Claude Design export of the marketing/site pages (useful starting reference for Stage 4 public pages).
  - Fresha `*.jpg` — the aesthetic references the design was based on.

Note: the `.dc.html` files are Claude Design's export format and are for reference only — they are React/HTML, not the Laravel/Livewire app. Use DESIGN-TOKENS.md + screenshots as the build target; treat the HTML as a look reference.
