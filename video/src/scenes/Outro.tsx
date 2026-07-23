import React from 'react';
import {AbsoluteFill, interpolate, useCurrentFrame} from 'remotion';
import {localBeat} from '../beats';
import {color, type} from '../theme';
import {BrandLockup, DarkField} from './Brand';
import {slam, useAspect} from './kinetic';

/**
 * Outro (track 27.14s → end — the collapse and fade). The poster frame,
 * assembled on the last real beats: lockup on beat 53, the ask on 54, the
 * address on 56 — then DEAD STILL while the track fades out under it. The
 * film ends exactly where the audio does.
 */
export const Outro: React.FC = () => {
    const frame = useCurrentFrame();
    const aspect = useAspect();
    const lb = (n: number) => localBeat('outro', n);

    const lockup = slam(frame, lb(53), 1.16);
    const ask = slam(frame, lb(54), 1.1);
    const url = interpolate(frame, [lb(56), lb(56) + 6], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});

    return (
        <AbsoluteFill>
            <DarkField />
            <AbsoluteFill style={{justifyContent: 'center', alignItems: 'center', gap: aspect === 'tall' ? 56 : 44}}>
                <div style={{transform: `scale(${lockup.scale})`, opacity: lockup.opacity}}>
                    <BrandLockup iconSize={aspect === 'tall' ? 170 : 132} wordSize={aspect === 'tall' ? 36 : 31} />
                </div>
                <div
                    style={{
                        ...type.display,
                        fontSize: aspect === 'wide' ? 118 : 92,
                        color: color.marble.paper,
                        transform: `scale(${ask.scale})`,
                        opacity: ask.opacity,
                        textAlign: 'center',
                    }}
                >
                    Fill every chair.
                </div>
                <div style={{display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 24, opacity: url}}>
                    <div style={{width: 72, height: 1, backgroundColor: color.marble.paper, opacity: 0.22}} />
                    <div style={{...type.overline, fontSize: 26, color: color.marble.butter}}>
                        bookthestyle.com
                    </div>
                </div>
            </AbsoluteFill>
        </AbsoluteFill>
    );
};
