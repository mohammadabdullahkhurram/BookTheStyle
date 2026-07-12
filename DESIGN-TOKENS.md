# BookTheStyle — Design Tokens

Authoritative design spec. These are exact values — build to them. The accent set is **swappable per salon** (plum default; sage + terracotta included), which maps to the existing per-salon branding accent.

## Visual language — "warm boutique meets sleek premium"

The feeling of a high-end salon's brand: inviting, tactile, human — and polished, editorial, confident. Not cool/techy, not generic SaaS. How the tokens express it:

- **Color.** The violet DNA shifted warm and rich: a **plum** accent (`#824C71`) instead of blue-violet, on a **warm cream/greige** neutral foundation (paper `#F7F4EF`, sand borders) — never cold grey. A **blush** (dusty rose) tint exists for sparing warm-emphasis moments only; it is never an action or status color. Every text/background pair passes WCAG AA (ratios noted inline below).
- **Typography.** Editorial serif + humanist sans: **Fraunces** for headings, titles, and stat numbers (its warmth and character are the boutique voice); **Hanken Grotesk** for body and UI. Serifs carry their own presence — headings sit a weight step lighter (600–700, not 700–800) with near-neutral tracking. The **overline** — 12px / 600 / 0.09em tracking, uppercase, in `--accent-ink` plum — is the signature editorial detail above headings.
- **Layout — flat & editorial.** Structure comes from **whitespace, typography, and hairline rules** (`.bts-rule`, 1px warm sand) — NOT from nested boxes. Most content sits directly on the warm background; a card is a *rare* lift for content that genuinely needs it, and a card never nests inside a card. Stats are open display figures under a hairline rule, not stat-cards. Tables sit on the page between `border-y` rules with flush first/last columns.
- **Spacing.** Generous, intentional whitespace: the scale gains 40 and 48 steps for section breathing room. Proportions stay refined, not big — premium = intentional. The drama is **scale contrast** (large Fraunces figures against small tracked labels), never uniform bigness.
- **Surfaces.** Flat by default: tight radii (button/input/nav 10, list 14, modal 16), **barely-there warm shadows** (a 1px umbra at most; only true overlays — modals, drawers — float, quietly). Hairline borders and dividers are the primary separators.
- **Details.** Sentence case, no emoji. Micro-interactions stay 150–300ms and subtle. Pills, chips, and empty states are considered, never default-looking. Pastel families keep identifying people; service colours keep identifying work.

## Fonts (self-host via the existing self-hosted-fonts setup — no CDN)
- **Fraunces** — display & headings & stat numbers. Weights 500 / 600 / 700. (Editorial serif; the boutique voice.)
- **Hanken Grotesk** — body, labels, UI. Weights 400 / 500 / 600 / 700.
- **Schibsted Grotesk** — retained in the fallback chain during the rollout. Weights 400–800.

## Accent — plum (default theme)  *(4 swappable tokens)*
The violet DNA, shifted warm (aubergine over blue-violet). White-on-accent 6.5:1; ink-on-tint 8.0:1.
- `--accent` `#824C71`
- `--accent-hover` `#6D3C5E`
- `--accent-tint` `#F5EAF0`
- `--accent-ink` `#6B3358`

### Alternate accents (same 4-token structure, per-salon swappable)
- **Sage** `#5C7458` · tint `#E7EEE5`
- **Terracotta** `#C0613E` · tint `#F4E6DD`

### Blush — the sparing warm secondary
Dusty-rose emphasis tint for gentle warmth only (never actions, never status). Ink 5.03:1 on tint, 5.83:1 on card.
- `--color-blush` `#F8ECE6`
- `--color-blush-ink` `#9C4F3F`

## Surfaces & backgrounds (warm cream/greige — never cold grey)
- App background `#F7F4EF`
- Surface / card `#FFFFFF`
- Input field `#FCFAF6`
- Segmented track / muted `#F0EBE3`
- Sidebar (dark option) `#241C22` (warm plum-black)

## Text (warm umber ramp)
- Primary `#211C18`
- Body `#57504A`
- Muted `#6C645C`
- Faint `#746C62` (WCAG AA: 5.17:1 on card, 4.71:1 on paper, 4.96:1 on field)
- Fainter `#A69C8F` — decoration only (borders/hatching); never text or meaningful icons
- Placeholder `#A9A093`

## Borders & dividers (warm sand)
- Card border `#EAE4DB`
- Input border `#E0D8CC`
- Divider `#F1ECE4`
- Row divider `#F4EFE7`

## Stylist avatar pastel families (avatars & client avatars only)
| Stylist (color) | bg | border | Ink | Avatar |
|---|---|---|---|---|
| Simone (green) | `#E7EFE4` | `#D5E4D0` | `#3E5C3A` | `#6E9968` |
| Maya (pink) | `#FBE7EE` | `#F2D2DE` | `#8E3D5A` | `#C76A8C` |
| Jonah (amber) | `#FBEFD6` | `#EEDDB6` | `#8A5A1E` | `#D49A4E` |
| Elise (violet) | `#EAE6FB` | `#D8D1F2` | `#4B3F9E` | `#8C7FE0` |

(These four are the rotating stylist palette — `App\Support\PastelPalette`, keyed by stylist id. They identify **people**: avatars render family bg + family ink initials + family avatar-colour ring (5.2–6.9:1). They are **not** used to colour calendar appointment blocks — those colour by service, below.)

## Service colour palette (calendar blocks, coloured by service)
Twelve soft, on-brand pastels — each a `{ bg, border, ink }` triplet (same aesthetic as the stylist families, wider distinct set) plus a solid `dot` for small swatches. Source of truth: `App\Support\ServicePalette`. Order is hue-spaced so sequential picks land on visibly distinct neighbours.

| Key | Block bg | Block border | Ink | Dot |
|---|---|---|---|---|
| green | `#E7EFE4` | `#D5E4D0` | `#3E5C3A` | `#6E9968` |
| rose | `#FBE7EE` | `#F2D2DE` | `#8E3D5A` | `#C76A8C` |
| sky | `#E1EDF6` | `#C8DFEF` | `#2F5D7C` | `#5B92BD` |
| amber | `#FBEFD6` | `#EEDDB6` | `#8A5A1E` | `#D49A4E` |
| violet | `#EAE6FB` | `#D8D1F2` | `#4B3F9E` | `#8C7FE0` |
| teal | `#DDEEEA` | `#C2E0D9` | `#2C6E63` | `#4E9C8C` |
| coral | `#FBE5E0` | `#F3CFC6` | `#A24433` | `#D87A66` |
| blue | `#E4E8F7` | `#CDD4F0` | `#3A4A93` | `#6E80D6` |
| peach | `#FBEBDB` | `#F2D8BF` | `#9A5A2A` | `#D98E55` |
| pink | `#FAE6F3` | `#F0D0E7` | `#94407A` | `#C56FAC` |
| sage | `#E8EDE3` | `#D6DECB` | `#4C5E43` | `#7E916C` |
| lavender | `#ECE9F7` | `#DBD5EE` | `#5A4E92` | `#9A8DD6` |

**Auto-assignment (per salon, tenant-isolated):** each service stores a stable `color_key`. On create, pick the palette colour not yet used by another active service in the salon; among unused, the one furthest (RGB distance on `dot`) from the colours already in use, ties broken by palette order — so it's never a duplicate or a near-identical colour while a distinct one is free. Beyond 12 services, reuse the least-used colour, ties broken by furthest from the other used colours (spread, not clustered). Colours are never reshuffled when other services are added/removed.

**Calendar block colouring:** one block = one service item, coloured by that item's service triplet (block style: radius 11, padding 8/11). A multi-service visit appears as adjacent blocks, each in its own service colour; the booking-detail modal lists every service with its `dot`. The booking's primary colour (where a single representative is needed) is its first item's service.

## Type scale
| Role | Font | Size | Weight | Notes |
|---|---|---|---|---|
| Display | Fraunces | 56px | 600 | letter-spacing -0.01em, line-height 1.05 |
| Page heading | Fraunces | 28px | 600 | letter-spacing -0.005em |
| Stat number | Fraunces | 36px | 600 | open figure under a hairline rule; tabular where columns align |
| Section / card title | Fraunces | 19px | 600 | letter-spacing -0.005em |
| Subsection | Hanken | 16px | 600 | |
| Body large | Hanken | 17px | 400 | color #57504A |
| Body | Hanken | 15px | 400 | line-height 1.5+ |
| Body small | Hanken | 14px | 400 | |
| Label | Hanken | 13px | 600 | color #6C645C |
| Caption / overline | Hanken | 12px | 600 | uppercase, letter-spacing .09em, color `--accent-ink` |

## Spacing scale (px)
6 · 9 · 12 · 16 · 18 · 22 · 24 · 28 · 32 · **40 · 48** (section breathing room)

## Corner radius (tight and intentional — the structure is rules + whitespace)
| Token | Radius |
|---|---|
| Segment | 8px |
| Chip / small | 8px |
| Input | 10px |
| Button | 10px |
| Nav item | 10px |
| Stat surface (rare) | 12px |
| List card | 14px |
| Modal / hero | 16px |
| Pill | 99px |
| Avatar | 50% |

## Shadows (flat by default — warm umbra, never pure black)
- Card `0 1px 2px rgb(63 47 38 / .03)` (barely-there; prefer the hairline border)
- Button (primary) `0 1px 2px rgb(63 47 38 / .08)`
- Overlay / modal `0 16px 40px rgb(52 33 45 / .14)` (true overlays only)

## Status pills
Padding `5px 13px` · radius `99px` · `12.5px / 600`
| Status | bg | text |
|---|---|---|
| Booked | `#F0EEEA` | `#6B6862` |
| Arrived | `#E3EDF6` | `#356088` |
| In service | `#FBEFD6` | `#8A5A1E` |
| Completed | `#E7EFE4` | `#3E5C3A` |
| No-show | `#F8E3E3` | `#A23A3A` |
| Cancelled | `#F0EEEA` | `#6B6862` (AA 4.79:1) |

## Components
**Primary button** — bg `var(--accent)`, text `#FFFFFF`, radius 10, height 44, 14.5px/600, hover bg `var(--accent-hover)`. Quiet 1px shadow at most.

**Secondary button** — bg `#FFFFFF`, text `#3A3833`, border `1px #E0D8CC`, radius 10, height 44, 14.5px/600.

**Input** — height 44–48, radius 10, 15px/400, bg `#FCFAF6`, border `1px #E0D8CC`. Label 13px/600 `#6C645C`. Placeholder `#A9A093`. Focus: border `var(--accent)`, bg `#FFFFFF`.

**Cards (rare)** — surface `#FFFFFF`, hairline border `1px #EAE4DB`, flat, padding 16–22. Radius: 14 (list) / 16 (modal). Reach for `.bts-rule` + whitespace first; never nest cards.

**Open stat** — hairline top rule, 12.5px/600 tracked uppercase label in muted, Fraunces 36/600 figure, optional 13px faint sub-line. Sits directly on the page.

**Calendar appointment block** — radius 11, padding `8px 11px`, bg + border + ink from the assigned service's palette triplet.

## Global
Light mode only. Sentence case (not Title Case). No emoji in UI.
