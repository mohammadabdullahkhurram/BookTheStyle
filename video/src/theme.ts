/**
 * The film's design tokens — MIRRORED from DESIGN-TOKENS.md (the repo's
 * authoritative spec) and resources/css/app.css (the Marble theme block).
 * The film must speak the product's exact visual language: same hexes, same
 * faces, same radii. If a value isn't in those two files, it doesn't belong
 * here. Fonts are the app's own self-hosted woff2 binaries, synced from
 * public/build/assets by scripts/sync-fonts.mjs (see src/fonts.ts).
 */

export const color = {
    // Accent — plum (the default brand accent, DESIGN-TOKENS "Accent")
    accent: '#824C71',
    accentHover: '#6D3C5E',
    accentTint: '#F5EAF0',
    accentInk: '#6B3358',

    // Marble — the app-standard theme the film is set in (app.css marble block)
    marble: {
        paper: '#FFF8EF', // butter-cream surfaces
        ink: '#4A382E',
        coral: '#BC4A28', // marble's own accent
        butter: '#F7D774', // marble swatch #3 — warm emphasis
        sidebarDark: '#3A2B21', // the dark Marble field
    },

    // Global surfaces & text ramp (DESIGN-TOKENS)
    paper: '#F7F4EF',
    card: '#FFFFFF',
    ink: '#211C18',
    body: '#57504A',
    muted: '#6C645C',
    faint: '#746C62',
    border: '#EAE4DB',
    divider: '#F1ECE4',
    sidebarDarkPlum: '#241C22', // global dark option (warm plum-black)
} as const;

export const font = {
    display: "'Fraunces', Georgia, serif", // headings, titles, stat figures
    body: "'Hanken Grotesk', ui-sans-serif, system-ui, sans-serif",
} as const;

/** DESIGN-TOKENS type scale, expressed for a 1920x1080 canvas. The app's UI
 *  sizes are for ~15px body screens; film display type scales up but keeps
 *  the SAME faces, weights, and tracking relationships. */
export const type = {
    display: {fontFamily: font.display, fontWeight: 600, letterSpacing: '-0.01em', lineHeight: 1.05},
    heading: {fontFamily: font.display, fontWeight: 600, letterSpacing: '-0.005em', lineHeight: 1.1},
    body: {fontFamily: font.body, fontWeight: 400, lineHeight: 1.5},
    // The signature editorial detail: 600, uppercase, 0.09em tracking.
    overline: {
        fontFamily: font.body,
        fontWeight: 600,
        letterSpacing: '0.09em',
        textTransform: 'uppercase' as const,
    },
} as const;

export const radius = {
    button: 10,
    listCard: 14,
    modal: 16,
    pill: 99,
} as const;

/** Overlay shadow (DESIGN-TOKENS "Shadows" — true overlays only). */
export const overlayShadow = '0 16px 40px rgba(52, 33, 45, 0.14)';
