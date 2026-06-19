# Brand logos

These two files are the single source for the BookTheStyle logo across the whole
app. Replace either file (keep the exact name) and the new artwork shows up
everywhere it's used — no build step, just refresh (hard-refresh to bust the
browser cache).

| File             | What it is            | Used in                                                        |
| ---------------- | --------------------- | -------------------------------------------------------------- |
| `full-logo.png`  | icon + wordmark, wide | marketing nav + footer, register header, expanded sidebar, auth cards |
| `icon-logo.png`  | icon mark only, square| collapsed sidebar rail                                         |

Rendered via the `<x-app-logo />` and `<x-app-logo-icon />` Blade components.

## Requirements for replacements

- **Transparent PNG** (real alpha). The originals supplied here had a flattened
  white/checkerboard background baked in; that was keyed out to true transparency.
  If a new export shows a checkerboard or white box behind it, it isn't actually
  transparent — re-export with a transparent background (or ask and it can be
  cleaned again).
- Dark ink reads on the app's light surfaces. A pre-sized copy is fine
  (`full-logo` ~1000×500, `icon-logo` 512×512); larger is okay, it scales down.

## Favicons

The favicon / apple-touch-icon (`public/favicon.ico`, `favicon-32.png`,
`apple-touch-icon.png`, `icon-maskable-512.png`) are generated from
`icon-logo.png` and do **not** update automatically — they're regenerated when
the icon changes.
