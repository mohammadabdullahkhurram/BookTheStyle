import type {LucideIcon} from 'lucide-react';
import React from 'react';
import {color, type} from '../theme';
import {useFilmLight} from './mode';
import {lit} from './space';

/**
 * The card language: frosted glass floating in the void. Translucent Marble
 * paper, hairline border, top rim-light, a restrained accent glow. The
 * backdrop-filter genuinely blurs whatever drifts behind (particles, grid);
 * the gradient fill carries the look even where nothing does.
 *
 * `light` defaults from the film mode (mode.tsx); the drop still passes it
 * explicitly to flip the environment mid-scene.
 */
export const GlassCard: React.FC<{
    w: number;
    h?: number;
    accent?: string;
    /** Ink glass on the light field. Defaults from the film mode. */
    light?: boolean;
    glow?: number;
    radius?: number;
    style?: React.CSSProperties;
    children?: React.ReactNode;
}> = ({w, h, accent = color.accent, light: lightProp, glow = 1, radius = 30, style, children}) => {
    const light = lightProp ?? useFilmLight();

    return (
    <div
        style={{
            width: w,
            height: h,
            borderRadius: radius,
            position: 'relative',
            border: light ? '1.5px solid rgba(33,28,24,0.28)' : '1px solid rgba(255,248,239,0.17)',
            background: light
                ? 'linear-gradient(165deg, rgba(255,255,255,0.92), rgba(255,248,239,0.8))'
                : `linear-gradient(160deg, rgba(255,248,239,0.105) 0%, rgba(255,248,239,0.04) 52%, ${accent}14 100%)`,
            // No backdrop blur on light — sampling the grid through it moirés
            // diagonal stripes across the face; the near-opaque fill is clean.
            backdropFilter: light ? undefined : 'blur(22px) saturate(120%)',
            WebkitBackdropFilter: light ? undefined : 'blur(22px) saturate(120%)',
            boxShadow: light
                ? `0 30px 70px rgba(52,33,45,0.16), 0 0 ${44 * glow}px ${accent}22, inset 0 1px 0 rgba(255,255,255,0.85)`
                : `0 34px 90px rgba(0,0,0,0.5), 0 0 ${56 * glow}px ${accent}2e, inset 0 1px 0 rgba(255,248,239,0.22)`,
            ...style,
        }}
    >
        {/* Top rim-light — the "lit edge" that sells the glass. */}
        <div
            style={{
                position: 'absolute',
                top: 0,
                left: radius,
                right: radius,
                height: 1.5,
                borderRadius: 2,
                background: light
                    ? 'linear-gradient(90deg, rgba(33,28,24,0) 0%, rgba(33,28,24,0.3) 50%, rgba(33,28,24,0) 100%)'
                    : `linear-gradient(90deg, rgba(255,248,239,0) 0%, ${lit(accent)}aa 50%, rgba(255,248,239,0) 100%)`,
            }}
        />
        {children}
    </div>
    );
};

/** Rounded icon chip — one clear line icon per capability. */
export const IconBadge: React.FC<{icon: LucideIcon; accent?: string; size?: number; light?: boolean}> = ({
    icon: Icon,
    accent = color.accent,
    size = 78,
    light: lightProp,
}) => {
    const light = lightProp ?? useFilmLight();

    return (
    <div
        style={{
            width: size,
            height: size,
            borderRadius: size * 0.3,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            background: light ? `${accent}1a` : `${accent}30`,
            border: `1.5px solid ${light ? accent : lit(accent)}66`,
            boxShadow: light ? 'none' : `0 0 34px ${accent}38`,
        }}
    >
        <Icon size={size * 0.5} color={light ? accent : lit(accent)} strokeWidth={1.8} absoluteStrokeWidth />
    </div>
    );
};

/** Card header: accent overline + Fraunces title. The label treatment. */
export const CardTitle: React.FC<{eyebrow: string; title: string; accent?: string; light?: boolean; size?: number}> = ({
    eyebrow,
    title,
    accent = color.accent,
    light: lightProp,
    size = 46,
}) => {
    const light = lightProp ?? useFilmLight();

    return (
        <div style={{display: 'flex', flexDirection: 'column', gap: 12}}>
            <div style={{...type.overline, fontSize: 19, color: light ? accent : lit(accent)}}>{eyebrow}</div>
            <div style={{...type.heading, fontSize: size, color: light ? color.ink : color.marble.paper}}>{title}</div>
        </div>
    );
};
