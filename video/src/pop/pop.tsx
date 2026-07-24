import React from 'react';
import {Easing, interpolate, useVideoConfig} from 'remotion';
import {type} from '../theme';

/**
 * THE POP LANGUAGE — the film's shared vocabulary (chosen look: sticker
 * slam). Flat vivid brand fields on cream, sticker cards with thick ink
 * outlines and hard offset shadows, springy squash-and-settle arrivals,
 * full-bleed color slams, diagonal ink wipes. Light mode, loud, joyful.
 */

export const ink = '#211C18';
export const paper = '#F7F4EF';
export const cream = '#FFF8EF';
export const plum = '#824C71';
export const coral = '#BC4A28';
export const butter = '#F7D774';
export const sage = '#5C7458';

/** The recolor family for the drop — matches the product's accent presets. */
export const DROP_ACCENTS = ['#C0613E', '#5B3E96', '#5C7458', '#211C18'];

/** The slap: heavy overshoot spring — the look's signature easing. */
export const slap = Easing.bezier(0.2, 1.35, 0.3, 1);

/** Hard blade: for wipes and slams. */
export const blade = Easing.bezier(0.7, 0, 0.2, 1);

export const sticker = (bg: string, radius = 22, outline = 4, shadow = 10): React.CSSProperties => ({
    background: bg,
    border: `${outline}px solid ${ink}`,
    borderRadius: radius,
    boxShadow: `${shadow}px ${shadow}px 0 ${ink}`,
});

/** Arrival progress for a slap at frame `at` (6-frame settle). */
export const arriveAt = (f: number, at: number, dur = 6): number =>
    interpolate(f, [at, at + dur], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: slap});

/** Aspect of the current composition. */
export const usePopAspect = (): 'wide' | 'square' | 'tall' => {
    const {width, height} = useVideoConfig();
    const ratio = width / height;
    return ratio > 1.4 ? 'wide' : ratio > 0.8 ? 'square' : 'tall';
};

/** A positioned sticker that slaps in at `at` and (optionally) exits. */
export const Slap: React.FC<{
    f: number;
    at: number;
    until?: number;
    x?: number | string;
    y?: number | string;
    center?: boolean;
    rotate?: number;
    bg: string;
    radius?: number;
    pad?: string;
    style?: React.CSSProperties;
    children: React.ReactNode;
}> = ({f, at, until, x, y, center = false, rotate = 0, bg, radius = 22, pad = '24px 40px', style, children}) => {
    const arrive = arriveAt(f, at);
    const out = until === undefined ? 1 : interpolate(f, [until - 3, until], [1, 0], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
    if (arrive <= 0 || out <= 0) return null;
    return (
        <div
            style={{
                position: 'absolute',
                ...(center ? {left: '50%', translate: '-50% 0'} : {left: x}),
                top: y,
                rotate: `${rotate}deg`,
                display: 'flex',
                alignItems: 'center',
                gap: 20,
                padding: pad,
                ...sticker(bg, radius),
                scale: String(interpolate(arrive, [0, 1], [1.5, 1])),
                opacity: Math.min(1, arrive * 2) * out,
                ...style,
            }}
        >
            {children}
        </div>
    );
};

/** The chapter label sticker — one per capability. */
export const Chapter: React.FC<{f: number; at: number; bg: string; fg: string; label: string; x?: number | string; y?: number | string; rotate?: number; size?: number}> = ({
    f,
    at,
    bg,
    fg,
    label,
    x = 120,
    y = 120,
    rotate = -4,
    size = 54,
}) => (
    <Slap f={f} at={at} x={x} y={y} rotate={rotate} bg={bg} pad="24px 48px">
        <div style={{...type.overline, fontSize: size, letterSpacing: '0.1em', color: fg, whiteSpace: 'nowrap'}}>{label}</div>
    </Slap>
);

/** Diagonal ink wipe blade sweeping the frame around frame `at`. */
export const Wipe: React.FC<{f: number; at: number; color?: string}> = ({f, at, color = ink}) => {
    const w = interpolate(f, [at - 2, at + 5], [0, 130], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: blade});
    if (w <= 0 || w >= 130) return null;
    return (
        <div
            style={{
                position: 'absolute',
                inset: 0,
                background: color,
                clipPath: `polygon(${w - 24}% 0, ${w}% 0, ${w - 12}% 100%, ${w - 36}% 100%)`,
            }}
        />
    );
};

/** A big slanted color block (the backdrop shape of the look). */
export const SlantBlock: React.FC<{f: number; at: number; side?: 'left' | 'right'; bg: string; deg?: number}> = ({f, at, side = 'left', bg, deg = 14}) => {
    const arrive = interpolate(f, [at, at + 8], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: blade});
    if (arrive <= 0) return null;
    const off = interpolate(arrive, [0, 1], [-1100, -300]);
    return (
        <div
            style={{
                position: 'absolute',
                ...(side === 'left' ? {left: off} : {right: off}),
                top: '-20%',
                width: '48%',
                height: '150%',
                rotate: `${side === 'left' ? deg : -deg}deg`,
                background: bg,
                border: `4px solid ${ink}`,
            }}
        />
    );
};

/** Tiny confetti shapes that pop with a hit — used sparingly. */
export const Burst: React.FC<{f: number; at: number; x: number | string; y: number | string; colors?: string[]}> = ({f, at, x, y, colors = [plum, coral, butter, sage]}) => {
    const life = interpolate(f, [at, at + 14], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
    if (life <= 0 || life >= 1) return null;
    return (
        <div style={{position: 'absolute', left: x, top: y}}>
            {Array.from({length: 8}, (_, i) => {
                const angle = (i / 8) * Math.PI * 2;
                const r = life * 130;
                return (
                    <div
                        key={i}
                        style={{
                            position: 'absolute',
                            left: Math.cos(angle) * r,
                            top: Math.sin(angle) * r,
                            width: 14,
                            height: 14,
                            borderRadius: i % 2 ? '50%' : 3,
                            rotate: `${life * 180}deg`,
                            background: colors[i % colors.length],
                            border: `2.5px solid ${ink}`,
                            opacity: 1 - life,
                        }}
                    />
                );
            })}
        </div>
    );
};
