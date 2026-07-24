import React from 'react';
import {color} from '../theme';

/** Local hex mix (duplicated from space.tsx to avoid a module cycle). */
const mix = (a: string, b: string, t: number): string => {
    const pa = [1, 3, 5].map((i) => parseInt(a.slice(i, i + 2), 16));
    const pb = [1, 3, 5].map((i) => parseInt(b.slice(i, i + 2), 16));
    return `#${pa.map((v, i) => Math.round(v + (pb[i] - v) * t).toString(16).padStart(2, '0')).join('')}`;
};

/**
 * The film's theme axis: ONE scene tree, two palettes. `dark` is the original
 * cut (paper-on-dark, glow does the depth work); `light` sets the same film
 * on the bright Marble field (ink-on-light, shadows and layering do the depth
 * work, the accent runs saturated because it must POP rather than glow).
 *
 * Components that already grew a `light` prop for the drop's inversion keep
 * it — the context only supplies their DEFAULT — so the drop can still flip
 * the environment mid-scene in either film.
 */

export type FilmMode = 'dark' | 'light';

export const FilmModeContext = React.createContext<FilmMode>('dark');

export const useFilmMode = (): FilmMode => React.useContext(FilmModeContext);

export const useFilmLight = (): boolean => useFilmMode() === 'light';

export type Palette = {
    /** Primary label/figure text on the card faces. */
    fg: string;
    /** Secondary text (sublines, metadata). */
    sub: string;
    /** Muted structural text (overlines on glass, axis labels). */
    faint: string;
    /** Neutral chip/tile fill on glass. */
    chipBg: string;
    /** Neutral chip/tile hairline. */
    chipBorder: string;
    /** Hairline for empty structure (calendar lanes, dividers). */
    line: string;
    /** The accent as TEXT/STROKE on this ground. Lifted toward paper on
     *  dark; the full-saturation accent ink on light. */
    accentText: (accent: string) => string;
    /** Accent-glow shadow — the dark film's depth cue; near-none on light,
     *  where soft ink shadows carry depth instead. */
    accentGlow: (accent: string, px: number, alphaHex?: string) => string;
    /** Screen-space speed streak color. */
    streak: string;
    /** The revenue figure / butter emphasis moments. */
    emphasis: string;
    /** Fill for a card nested ON the glass (the profile card). */
    nestedBg: string;
    /** Shadow for that nested card — glow-assisted on dark, soft ink on light. */
    nestedShadow: (accent: string) => string;
    /** Bespoke vignette panel: near-opaque surface (no backdrop moiré). */
    panelBg: string;
    panelBorder: string;
    panelShadow: string;
};

const dark: Palette = {
    fg: color.marble.paper,
    sub: 'rgba(255,248,239,0.55)',
    faint: 'rgba(255,248,239,0.5)',
    chipBg: 'rgba(255,248,239,0.09)',
    chipBorder: 'rgba(255,248,239,0.2)',
    line: 'rgba(255,248,239,0.1)',
    accentText: (accent) => mix(accent, color.marble.paper, 0.5),
    accentGlow: (accent, px, alphaHex = '66') => `0 0 ${px}px ${accent}${alphaHex}`,
    streak: 'rgba(255,248,239,0.5)',
    emphasis: color.marble.butter,
    nestedBg: 'linear-gradient(165deg, rgba(255,248,239,0.14), rgba(255,248,239,0.05))',
    nestedShadow: (accent) => `0 18px 50px rgba(0,0,0,0.4), 0 0 40px ${accent}22`,
    panelBg: 'linear-gradient(165deg, rgba(56,42,34,0.92), rgba(42,31,26,0.9))',
    panelBorder: 'rgba(255,248,239,0.16)',
    panelShadow: '0 34px 90px rgba(0,0,0,0.5)',
};

const light: Palette = {
    fg: color.ink,
    sub: 'rgba(74,56,46,0.62)',
    faint: 'rgba(74,56,46,0.52)',
    chipBg: 'rgba(74,56,46,0.06)',
    chipBorder: 'rgba(74,56,46,0.2)',
    line: 'rgba(74,56,46,0.12)',
    accentText: (accent) => mix(accent, color.ink, 0.18),
    // Glow reads weakly on light — a whisper of tint, never the depth cue.
    accentGlow: (accent, px) => `0 ${Math.round(px / 4)}px ${px}px ${accent}22`,
    streak: 'rgba(74,56,46,0.4)',
    emphasis: color.marble.coral,
    nestedBg: 'linear-gradient(165deg, rgba(255,255,255,0.95), rgba(255,248,239,0.85))',
    nestedShadow: (accent) => `0 16px 40px rgba(52,33,45,0.16), 0 4px 18px ${accent}1e`,
    panelBg: 'linear-gradient(165deg, rgba(255,255,255,0.94), rgba(255,248,239,0.86))',
    panelBorder: 'rgba(74,56,46,0.14)',
    panelShadow: '0 30px 70px rgba(52,33,45,0.16)',
};

const palettes: Record<FilmMode, Palette> = {dark, light};

export const usePalette = (): Palette => palettes[useFilmMode()];

/** Palette for an explicit light flag (the drop flips mid-scene). */
export const paletteFor = (isLight: boolean): Palette => (isLight ? light : dark);
