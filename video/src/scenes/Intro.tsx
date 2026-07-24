import {Armchair, AudioLines, BellRing, CalendarDays, ChartColumn, Clock, Users} from 'lucide-react';
import React from 'react';
import {AbsoluteFill, Easing, Img, interpolate, staticFile, useCurrentFrame} from 'remotion';
import {localBeat} from '../beats';
import {color, font, type} from '../theme';
import {IconBadge} from './glass';
import {useAspect} from './kinetic';
import {useFilmLight, usePalette} from './mode';
import {cam, cameraPath, GhostPanels, GroundGrid, Particles, Plate3D, Stage3D, Void, type Vec3} from './space';

/**
 * Intro (track 0:00–4.30 — sparse open). THE PREVIEW: the seven capability
 * icons fly in from scattered depth, converge on the centre, and are
 * ABSORBED into the wordmark tile exactly on beat 2 — the brand literally
 * constructed from what the film is about to show. Beat 4 slams the
 * statement as pure kinetic type with a drawing accent rule. Beat 0 pops
 * the tile the icons are racing toward.
 */

const ICONS = [CalendarDays, AudioLines, Clock, Users, Armchair, BellRing, ChartColumn];

/** Scattered origins — wide, deep, off-axis; each converges to the tile. */
const ORIGINS: Vec3[] = [
    [-780, -420, -900], [820, -360, -1200], [-640, 380, -1100], [700, 420, -800],
    [-920, 40, -600], [880, 80, -1000], [0, -560, -700],
];

export const Intro: React.FC = () => {
    const frame = useCurrentFrame();
    const aspect = useAspect();
    const isLight = useFilmLight();
    const p = usePalette();
    const b0 = localBeat('intro', 0);
    const b2 = localBeat('intro', 2);
    const b4 = localBeat('intro', 4);

    // A slow confident push-in; the convergence provides the energy.
    const camera = cameraPath(frame, [
        {f: 0, cam: cam([0, 6, 320], -2, 0, -1.2)},
        {f: b4 + 20, cam: cam([0, -4, 26], 0, 0, 0), ease: Easing.bezier(0.3, 0, 0.25, 1)},
    ]);

    // The tile lands on beat 0; the icons are absorbed on beat 2.
    const tile = interpolate(frame, [b0 - 4, b0 + 4], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
    const absorb = interpolate(frame, [b2 - 2, b2 + 3], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
    const pulse = interpolate(frame, [b2, b2 + 14], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
    const wordIn = interpolate(frame, [b2, b2 + 8], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});

    // Beat 4: the statement, pure type; the lockup yields upward.
    const yield_ = interpolate(frame, [b4 - 2, b4 + 8], [0, 1], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
        easing: Easing.bezier(0.82, 0, 0.1, 1),
    });
    const statement = interpolate(frame, [b4, b4 + 5], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
    const statementScale = interpolate(frame, [b4, b4 + 5], [1.14, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
    const rule = interpolate(frame, [b4 + 4, b4 + 18], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: Easing.bezier(0.4, 0, 0.2, 1)});

    const iconSize = aspect === 'tall' ? 196 : 158;

    return (
        <AbsoluteFill>
            <Void />
            <Stage3D camera={camera}>
                <GroundGrid center={[0, 570, -500]} />
                <Particles camera={camera} frame={frame} seed="intro" min={[-1000, -620, -900]} max={[1000, 620, 420]} count={90} />
                <GhostPanels camera={camera} seed="intro" min={[-1300, -430, -1500]} max={[1300, 430, -380]} />

                {/* The seven capabilities racing to become the brand. */}
                {ICONS.map((Icon, i) => {
                    const drive = interpolate(frame, [i * 2, b2], [0, 1], {
                        extrapolateLeft: 'clamp',
                        extrapolateRight: 'clamp',
                        easing: Easing.bezier(0.5, 0, 0.2, 1),
                    });
                    const opacity = (1 - absorb) * interpolate(drive, [0, 0.08], [0, 1], {extrapolateRight: 'clamp'});
                    if (opacity <= 0.01) return null;
                    const pos: Vec3 = [
                        ORIGINS[i][0] * (1 - drive),
                        ORIGINS[i][1] * (1 - drive),
                        ORIGINS[i][2] * (1 - drive),
                    ];
                    return (
                        <Plate3D key={i} pos={pos} camera={camera} dof={false} style={{opacity}}>
                            <IconBadge icon={Icon} size={86} />
                        </Plate3D>
                    );
                })}

                {/* The wordmark — pops on beat 0, absorbs the icons on beat 2. */}
                <Plate3D pos={[0, -20 - yield_ * 200, 0]} camera={camera} dof={false}>
                    <div
                        style={{
                            display: 'flex',
                            flexDirection: 'column',
                            alignItems: 'center',
                            gap: 34,
                            opacity: tile,
                            scale: String(interpolate(tile, [0, 1], [1.3, 1]) + pulse * 0.06 * (1 - pulse)),
                        }}
                    >
                        <div style={{position: 'relative'}}>
                            {/* Absorption ring — bursts as the icons land. */}
                            <div
                                style={{
                                    position: 'absolute',
                                    inset: -8,
                                    borderRadius: iconSize * 0.28,
                                    border: `2.5px solid ${p.accentText(color.accent)}`,
                                    scale: String(1 + pulse * 0.9),
                                    opacity: pulse > 0 ? (1 - pulse) * 0.9 : 0,
                                }}
                            />
                            <div
                                style={{
                                    width: iconSize,
                                    height: iconSize,
                                    borderRadius: iconSize * 0.24,
                                    backgroundColor: isLight ? '#FFFFFF' : color.marble.paper,
                                    border: isLight ? '1px solid rgba(74,56,46,0.14)' : 'none',
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    boxShadow: isLight
                                        ? `0 20px 50px rgba(52,33,45,0.2), 0 0 ${50 * pulse}px ${color.accent}2e`
                                        : `0 16px 40px rgba(0,0,0,0.35), 0 0 ${80 * pulse}px rgba(255,248,239,0.5)`,
                                }}
                            >
                                <Img src={staticFile('brand/icon-logo.png')} style={{width: '72%', height: '72%', objectFit: 'contain'}} />
                            </div>
                        </div>
                        <div
                            style={{
                                fontFamily: font.body,
                                fontWeight: 600,
                                fontSize: aspect === 'tall' ? 40 : 36,
                                letterSpacing: '0.22em',
                                textTransform: 'uppercase',
                                color: p.fg,
                                whiteSpace: 'nowrap',
                                opacity: wordIn,
                                translate: `0 ${(1 - wordIn) * 14}px`,
                            }}
                        >
                            BookTheStyle
                        </div>
                    </div>
                </Plate3D>

                {/* The statement — pure kinetic type, an accent rule drawing under it. */}
                <Plate3D pos={[0, 165, 40]} camera={camera} dof={false}>
                    <div
                        style={{
                            display: 'flex',
                            flexDirection: 'column',
                            alignItems: 'center',
                            gap: 26,
                            opacity: statement,
                            scale: String(statementScale),
                        }}
                    >
                        <div
                            style={{
                                ...type.display,
                                fontSize: aspect === 'wide' ? 76 : 62,
                                color: p.fg,
                                textAlign: 'center',
                                whiteSpace: 'nowrap',
                            }}
                        >
                            Everything your salon needs.
                        </div>
                        <div
                            style={{
                                width: `${rule * 46}%`,
                                height: 3,
                                borderRadius: 2,
                                background: p.accentText(color.accent),
                            }}
                        />
                    </div>
                </Plate3D>
            </Stage3D>
        </AbsoluteFill>
    );
};
