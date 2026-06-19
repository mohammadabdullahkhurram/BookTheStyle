# BookTheStyle — Design Tokens

Authoritative design spec, extracted from the approved Claude Design style guide. These are exact values — build to them. The accent set is **swappable per salon** (violet default; sage + terracotta included), which maps to the existing per-salon branding accent.

## Fonts (self-host via the existing self-hosted-fonts setup — no CDN)
- **Schibsted Grotesk** — display & headings. Weights 400 / 500 / 600 / 700 / 800.
- **Hanken Grotesk** — body, labels, UI. Weights 400 / 500 / 600 / 700.

## Accent — violet (default theme)  *(4 swappable tokens)*
- `--accent` `#6555E4`
- `--accent-hover` `#5544CC`
- `--accent-tint` `#ECEAFB`
- `--accent-ink` `#4B3FA0`

### Alternate accents (same 4-token structure, per-salon swappable)
- **Sage** `#5C7458` · tint `#E7EEE5`
- **Terracotta** `#C0613E` · tint `#F4E6DD`

## Surfaces & backgrounds
- App background `#F6F5F3`
- Surface / card `#FFFFFF`
- Input field `#FCFBF9`
- Segmented track `#EFEDE8`
- Sidebar (dark option) `#1E1D2A`

## Text
- Primary `#1C1B1A`
- Body `#56534C`
- Muted `#6B6862`
- Faint `#9C9890`
- Fainter / icons `#A09C94`
- Placeholder `#A8A49C`

## Borders & dividers
- Card border `#EAE8E3`
- Input border `#E0DDD6`
- Divider `#F0EEE9`
- Row divider `#F4F2ED`

## Stylist / service pastel families (calendar blocks & avatars)
| Stylist (color) | Block bg | Block border | Ink | Avatar |
|---|---|---|---|---|
| Simone (green) | `#E7EFE4` | `#D5E4D0` | `#3E5C3A` | `#6E9968` |
| Maya (pink) | `#FBE7EE` | `#F2D2DE` | `#8E3D5A` | `#C76A8C` |
| Jonah (amber) | `#FBEFD6` | `#EEDDB6` | `#8A5A1E` | `#D49A4E` |
| Elise (violet) | `#EAE6FB` | `#D8D1F2` | `#4B3F9E` | `#8C7FE0` |

(These four are the rotating palette; assign in order as stylists are added.)

## Type scale
| Role | Font | Size | Weight | Notes |
|---|---|---|---|---|
| Display | Schibsted | 60px | 800 | letter-spacing -0.025em, line-height 1 |
| Page heading | Schibsted | 26px | 700 | |
| Stat number | Schibsted | 34px | 700 | |
| Section / card title | Schibsted | 18px | 700 | |
| Subsection | Schibsted | 16px | 600 | |
| Body large | Hanken | 17px | 400 | color #56534C |
| Body | Hanken | 15px | 400 | |
| Body small | Hanken | 14px | 400 | color #4A4843 |
| Label | Hanken | 13px | 600 | color #6B6862 |
| Caption / overline | Hanken | 12.5px | 600 | uppercase, letter-spacing .04em, color #9C9890 |

## Spacing scale (px)
6 · 9 · 12 · 16 · 18 · 22 · 24 · 28 · 32

## Corner radius
| Token | Radius |
|---|---|
| Segment | 8px |
| Chip / small | 9px |
| Input | 11px |
| Button | 12px |
| Nav item | 13px |
| Stat card | 16px |
| List card | 18px |
| Modal / hero | 20px |
| Pill | 99px |
| Avatar | 50% |

## Status pills
Padding `5px 13px` · radius `99px` · `12.5px / 600`
| Status | bg | text |
|---|---|---|
| Booked | `#F0EEEA` | `#6B6862` |
| Arrived | `#E3EDF6` | `#356088` |
| In service | `#FBEFD6` | `#8A5A1E` |
| Completed | `#E7EFE4` | `#3E5C3A` |
| No-show | `#F8E3E3` | `#A23A3A` |
| Cancelled | `#F0EEEA` | `#9C9890` |

## Components
**Primary button** — bg `var(--accent)`, text `#FFFFFF`, radius 12, height 48, 15px/600, shadow `0 2px 10px rgba(0,0,0,.12)`, hover bg `var(--accent-hover)`.

**Secondary button** — bg `#FFFFFF`, text `#3A3833`, border `1px #E0DDD6`, radius 12, height 48, 15px/600.

**Input** — height 48, radius 11, 15px/400, bg `#FCFBF9`, border `1px #E0DDD6`. Label 13px/600 `#6B6862`. Placeholder `#A8A49C`. Focus: border `var(--accent)`, bg `#FFFFFF`.

**Cards** — surface `#FFFFFF`, border `1px #EAE8E3`, shadow `0 1px 2px rgba(0,0,0,.04)`, padding 18–26. Radius: 16 (stat) / 18 (list) / 20 (modal).

**Calendar appointment block** — radius 11, padding `8px 11px`, bg + border + ink from the assigned stylist's pastel family.

## Global
Light mode only. Sentence case (not Title Case). No emoji in UI.
