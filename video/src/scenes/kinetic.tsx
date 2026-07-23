import React from 'react';
import {Img, interpolate, useCurrentFrame, useVideoConfig} from 'remotion';
import {color, type} from '../theme';

/**
 * The music cut's shared motion vocabulary: HARD arrivals, no lingering.
 * Everything lands in ~4–6 frames (a sixteenth at 117.5 BPM) and exits
 * faster than it entered — reference-style SaaS-ad energy, not the VO
 * cut's breathing springs.
 */

export type Aspect = 'wide' | 'square' | 'tall';

export const useAspect = (): Aspect => {
    const {width, height} = useVideoConfig();
    const ratio = width / height;
    return ratio > 1.4 ? 'wide' : ratio > 0.8 ? 'square' : 'tall';
};

/** Snap-settle scale: slams from `from` to 1 in ~5 frames. */
export const slam = (frame: number, at: number, from = 1.12): {scale: number; opacity: number} => {
    const local = frame - at;
    if (local < 0) return {scale: from, opacity: 0};
    return {
        scale: interpolate(local, [0, 5], [from, 1], {extrapolateRight: 'clamp'}),
        opacity: interpolate(local, [0, 2], [0, 1], {extrapolateRight: 'clamp'}),
    };
};

/** A punchy 1–4 word card in the token display face. In on its beat, out fast. */
export const KineticCard: React.FC<{
    at: number;
    until?: number;
    size?: number;
    accent?: boolean;
    /** Centered horizontally at the given bottom offset instead of left-anchored. */
    center?: boolean;
    /** Dark plum chip behind the text — for cards sitting on light product UI. */
    chip?: boolean;
    position?: React.CSSProperties;
    children: React.ReactNode;
}> = ({at, until, size = 84, accent = false, center = false, chip = false, position, children}) => {
    const frame = useCurrentFrame();
    const {scale, opacity} = slam(frame, at, 1.1);
    const out = until === undefined
        ? 1
        : interpolate(frame, [until - 5, until], [1, 0], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
    if (opacity * out <= 0) return null;
    return (
        <div
            style={{
                position: 'absolute',
                left: center ? '50%' : 110,
                bottom: 110,
                ...position,
                transform: `${center ? 'translateX(-50%) ' : ''}scale(${scale})`,
                transformOrigin: center ? 'center bottom' : 'left bottom',
                opacity: opacity * out,
                ...type.display,
                fontSize: size,
                color: accent ? color.marble.butter : color.marble.paper,
                textShadow: chip ? 'none' : '0 4px 26px rgba(0,0,0,0.45)',
                backgroundColor: chip ? 'rgba(36,28,34,0.95)' : 'transparent',
                padding: chip ? '10px 34px 16px' : 0,
                borderRadius: chip ? 18 : 0,
                whiteSpace: 'nowrap',
                textAlign: center ? 'center' : 'left',
            }}
        >
            {children}
        </div>
    );
};

/** Full-bleed product still: slam arrival + a constant confident push-in. */
export const ProductStill: React.FC<{
    src: string;
    at: number;
    /** cover crop focus for non-wide aspects (the interesting region). */
    focus?: string;
}> = ({src, at, focus = '30% 30%'}) => {
    const frame = useCurrentFrame();
    const aspect = useAspect();
    const local = Math.max(0, frame - at);
    const settle = interpolate(local, [0, 5], [1.1, 1.055], {extrapolateRight: 'clamp'});
    const push = interpolate(local, [5, 130], [1.055, 1.1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
    return (
        <Img
            src={src}
            style={{
                position: 'absolute',
                inset: 0,
                width: '100%',
                height: '100%',
                objectFit: 'cover',
                objectPosition: aspect === 'wide' ? '50% 30%' : focus,
                transform: `scale(${local < 5 ? settle : push})`,
                transformOrigin: '50% 35%',
            }}
        />
    );
};

/** The dark feather that keeps type legible over light product UI. */
export const Feather: React.FC<{strength?: number}> = ({strength = 1}) => (
    <div
        style={{
            position: 'absolute',
            inset: 0,
            background: `linear-gradient(94deg, rgba(36,28,34,${0.9 * strength}) 0%, rgba(36,28,34,${0.5 * strength}) 30%, rgba(36,28,34,0) 52%),`
                + `linear-gradient(24deg, rgba(36,28,34,${0.92 * strength}) 0%, rgba(36,28,34,${0.5 * strength}) 18%, rgba(36,28,34,0) 42%)`,
        }}
    />
);

/** Phone shell for widget shots — the product's mobile face. */
export const PhoneFrame: React.FC<{width: number; children: React.ReactNode; style?: React.CSSProperties}> = ({width, children, style}) => {
    const height = width * (872 / 402);
    return (
        <div
            style={{
                width,
                height,
                borderRadius: width * 0.144,
                padding: width * 0.032,
                backgroundColor: color.sidebarDarkPlum,
                boxShadow: '0 22px 60px rgba(0,0,0,0.45)',
                ...style,
            }}
        >
            <div style={{width: '100%', height: '100%', borderRadius: width * 0.117, overflow: 'hidden', position: 'relative'}}>
                {children}
            </div>
        </div>
    );
};
