import React from 'react';
import {interpolate, useCurrentFrame, useVideoConfig} from 'remotion';
import {color, type} from '../theme';

/**
 * The cut's shared typographic vocabulary: HARD arrivals, no lingering.
 * Everything lands in ~4–6 frames (a sixteenth at 117.5 BPM) and exits
 * faster than it entered. The screenshot-era primitives (ProductStill,
 * Feather, PhoneFrame) are retired with the stills themselves — this cut
 * has no screen imagery.
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
    /** paper (default) · butter (the accent moments) · ink (the light world). */
    tone?: 'paper' | 'butter' | 'ink';
    /** Centered horizontally at the given bottom offset instead of left-anchored. */
    center?: boolean;
    /** Dark plum chip behind the text — for cards sitting on light ground. */
    chip?: boolean;
    position?: React.CSSProperties;
    children: React.ReactNode;
}> = ({at, until, size = 84, tone = 'paper', center = false, chip = false, position, children}) => {
    const frame = useCurrentFrame();
    const {scale, opacity} = slam(frame, at, 1.1);
    const out = until === undefined
        ? 1
        : interpolate(frame, [until - 5, until], [1, 0], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
    if (opacity * out <= 0) return null;
    const colors = {paper: color.marble.paper, butter: color.marble.butter, ink: color.ink} as const;
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
                color: colors[tone],
                textShadow: chip || tone === 'ink' ? 'none' : '0 4px 26px rgba(0,0,0,0.45)',
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
