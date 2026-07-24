import {Armchair, AudioLines, BellRing, CalendarDays, ChartColumn, Clock, Users} from 'lucide-react';
import React from 'react';
import {AbsoluteFill, Easing, interpolate, useCurrentFrame} from 'remotion';
import {localBeat} from '../beats';
import {color, type} from '../theme';
import {BrandLockup} from './Brand';
import {IconBadge} from './glass';
import {slam, useAspect} from './kinetic';
import {usePalette} from './mode';
import {cam, cameraPath, Particles, Plate3D, Stage3D, Void} from './space';

/**
 * Outro (track 27.14s → end — the collapse and fade). THE CONVERGENCE: the
 * seven capabilities fly INWARD one last time and are absorbed into the
 * lockup — everything the film showed, collapsing into the brand. Lockup
 * slams on beat 53, the ask on 54, the address on 56 — then DEAD STILL
 * while the track fades out. The strongest single frame; the poster.
 */

const ICONS = [CalendarDays, AudioLines, Clock, Users, Armchair, BellRing, ChartColumn];

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
    // Absorption: icons race in over the first beat, gone by beat 54.
    const absorbEnd = lb(54);
    const pulse = interpolate(frame, [absorbEnd - 2, absorbEnd + 12], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});

    return (
        <AbsoluteFill>
            <Void />
            <Stage3D camera={camera}>
                <Particles camera={camera} frame={frame} seed="outro" min={[-950, -620, -800]} max={[950, 620, 260]} count={70} />
                {/* Everything the film showed, collapsing into the brand. */}
                {ICONS.map((Icon, i) => {
                    const angle = (i / ICONS.length) * Math.PI * 2 + 0.6;
                    const drive = interpolate(frame, [i * 1.5, absorbEnd - 2], [0, 1], {
                        extrapolateLeft: 'clamp',
                        extrapolateRight: 'clamp',
                        easing: Easing.bezier(0.55, 0, 0.25, 1),
                    });
                    const r = 760 * (1 - drive);
                    const opacity = interpolate(drive, [0, 0.06, 0.88, 1], [0, 1, 1, 0]);
                    if (opacity <= 0.01) return null;
                    return (
                        <Plate3D
                            key={i}
                            pos={[Math.cos(angle) * r, Math.sin(angle) * r * 0.62 - 60, -240 * (1 - drive)]}
                            camera={camera}
                            dof={false}
                            style={{opacity}}
                        >
                            <IconBadge icon={Icon} size={78} />
                        </Plate3D>
                    );
                })}
            </Stage3D>
            <AbsoluteFill style={{justifyContent: 'center', alignItems: 'center', gap: aspect === 'tall' ? 56 : 44}}>
                <div style={{position: 'relative', transform: `scale(${lockup.scale})`, opacity: lockup.opacity}}>
                    {/* The absorption ring — the film arriving home. */}
                    <div
                        style={{
                            position: 'absolute',
                            left: '50%',
                            top: aspect === 'tall' ? 76 : 58,
                            width: 200,
                            height: 200,
                            translate: '-50% -50%',
                            borderRadius: '50%',
                            border: `2.5px solid ${p.accentText(color.accent)}`,
                            scale: String(0.7 + pulse * 1.1),
                            opacity: pulse > 0 ? (1 - pulse) * 0.85 : 0,
                        }}
                    />
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
