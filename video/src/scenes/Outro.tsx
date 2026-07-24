import React from 'react';
import {AbsoluteFill, interpolate, useCurrentFrame} from 'remotion';
import {localBeat} from '../beats';
import {type} from '../theme';
import {BrandLockup} from './Brand';
import {slam, useAspect} from './kinetic';
import {usePalette} from './mode';
import {cam, cameraPath, Particles, Stage3D, Void} from './space';

/**
 * Outro (track 27.14s → end — the collapse and fade). Hard cut back to the
 * dark void: lockup on beat 53, the ask on 54, the address on 56 — then
 * DEAD STILL while the track fades out under it. The strongest single frame
 * in the film; the poster.
 */
export const Outro: React.FC = () => {
    const frame = useCurrentFrame();
    const aspect = useAspect();
    const p = usePalette();
    const lb = (n: number) => localBeat('outro', n);

    // The last breath of camera: a barely-there settle, then stillness.
    const camera = cameraPath(frame, [
        {f: 0, cam: cam([0, 0, 70], 0)},
        {f: lb(56) + 8, cam: cam([0, 0, 0], 0)},
    ]);

    const lockup = slam(frame, lb(53), 1.16);
    const ask = slam(frame, lb(54), 1.1);
    const url = interpolate(frame, [lb(56), lb(56) + 6], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});

    return (
        <AbsoluteFill>
            <Void />
            <Stage3D camera={camera}>
                <Particles camera={camera} frame={frame} seed="outro" min={[-950, -620, -800]} max={[950, 620, 260]} count={70} />
            </Stage3D>
            <AbsoluteFill style={{justifyContent: 'center', alignItems: 'center', gap: aspect === 'tall' ? 56 : 44}}>
                <div style={{transform: `scale(${lockup.scale})`, opacity: lockup.opacity}}>
                    <BrandLockup iconSize={aspect === 'tall' ? 170 : 132} wordSize={aspect === 'tall' ? 36 : 31} />
                </div>
                <div
                    style={{
                        ...type.display,
                        fontSize: aspect === 'wide' ? 118 : 92,
                        color: p.fg,
                        transform: `scale(${ask.scale})`,
                        opacity: ask.opacity,
                        textAlign: 'center',
                    }}
                >
                    Fill every chair.
                </div>
                <div style={{display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 24, opacity: url}}>
                    <div style={{width: 72, height: 1, backgroundColor: p.fg, opacity: 0.22}} />
                    <div style={{...type.overline, fontSize: 26, color: p.emphasis}}>
                        bookthestyle.com
                    </div>
                </div>
            </AbsoluteFill>
        </AbsoluteFill>
    );
};
