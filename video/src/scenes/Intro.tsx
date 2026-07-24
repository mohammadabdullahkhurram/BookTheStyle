import React from 'react';
import {AbsoluteFill, Img, interpolate, staticFile, useCurrentFrame} from 'remotion';
import {localBeat} from '../beats';
import {color, font, type} from '../theme';
import {GlassCard} from './glass';
import {useAspect} from './kinetic';
import {useFilmLight, usePalette} from './mode';
import {cam, cameraPath, GhostPanels, GroundGrid, Particles, Plate3D, Stage3D, Void, whip} from './space';

/**
 * Intro (track 0:00–4.30 — sparse open). The camera drifts forward through
 * the empty branded void; the wordmark resolves out of blur and light ON
 * beat 0; the statement card slams on beat 4 as the lockup yields upward.
 * Anticipation, not action — the groove does the showing.
 */
export const Intro: React.FC = () => {
    const frame = useCurrentFrame();
    const aspect = useAspect();
    const isLight = useFilmLight();
    const p = usePalette();
    const b0 = localBeat('intro', 0);
    const b2 = localBeat('intro', 2);
    const b4 = localBeat('intro', 4);

    const camera = cameraPath(frame, [
        {f: 0, cam: cam([0, 8, 360], 2.5, 0, 0.6)},
        {f: b4, cam: cam([0, 0, 150], 0.6)},
        {f: 129, cam: cam([0, -6, 30], 0)},
    ]);

    // The mark resolves: blur + tracking converge landing exactly on beat 0.
    const resolve = interpolate(frame, [0, b0], [0, 1], {extrapolateRight: 'clamp'});
    const glow = interpolate(frame, [b0, b0 + 14], [1, 0], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
    const shine = interpolate(frame, [b2, b2 + 16], [-60, 160], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});

    // Beat 4: the lockup yields, the statement takes the frame.
    const yield_ = interpolate(frame, [b4 - 2, b4 + 8], [0, 1], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
        easing: whip,
    });
    const statement = interpolate(frame, [b4, b4 + 5], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
    const statementScale = interpolate(frame, [b4, b4 + 5], [1.16, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});

    const iconSize = aspect === 'tall' ? 196 : 158;

    return (
        <AbsoluteFill>
            <Void />
            <Stage3D camera={camera}>
                <GroundGrid center={[0, 570, -500]} />
                <Particles camera={camera} frame={frame} seed="intro" min={[-1000, -620, -900]} max={[1000, 620, 420]} count={90} />
                <GhostPanels camera={camera} seed="intro" min={[-1300, -430, -1500]} max={[1300, 430, -380]} />

                {/* The wordmark — resolved out of light on beat 0. */}
                <Plate3D pos={[0, -20 - yield_ * 190, 0]} camera={camera} dof={false}>
                    <div
                        style={{
                            display: 'flex',
                            flexDirection: 'column',
                            alignItems: 'center',
                            gap: 34,
                            opacity: resolve,
                            filter: `blur(${(1 - resolve) * 22}px)`,
                            scale: String(1.25 - resolve * 0.25),
                        }}
                    >
                        <div style={{position: 'relative'}}>
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
                                        ? `0 20px 50px rgba(52,33,45,${0.16 + 0.14 * glow}), 0 0 ${60 * glow}px ${color.accent}${glow > 0.4 ? '2e' : '1a'}`
                                        : `0 16px 40px rgba(0,0,0,0.35), 0 0 ${90 * glow}px rgba(255,248,239,${0.55 * glow})`,
                                    overflow: 'hidden',
                                }}
                            >
                                <Img src={staticFile('brand/icon-logo.png')} style={{width: '72%', height: '72%', objectFit: 'contain'}} />
                                {/* One light sweep across the tile on beat 2. */}
                                {/* preserve-3d defeats overflow clipping — fade the sweep out before it exits the tile. */}
                                <div
                                    style={{
                                        position: 'absolute',
                                        inset: 0,
                                        background: 'linear-gradient(115deg, transparent 30%, rgba(255,255,255,0.75) 50%, transparent 70%)',
                                        translate: `${shine}% 0`,
                                        opacity: interpolate(shine, [-60, -45, 85, 105], [0, 1, 1, 0]),
                                    }}
                                />
                            </div>
                        </div>
                        <div
                            style={{
                                fontFamily: font.body,
                                fontWeight: 600,
                                fontSize: aspect === 'tall' ? 40 : 36,
                                letterSpacing: `${0.22 + (1 - resolve) * 0.5}em`,
                                textTransform: 'uppercase',
                                color: p.fg,
                                whiteSpace: 'nowrap',
                            }}
                        >
                            BookTheStyle
                        </div>
                    </div>
                </Plate3D>

                {/* The statement — one glass card, slammed on beat 4. */}
                <Plate3D pos={[0, 155, 40]} camera={camera} dof={false}>
                    <div style={{opacity: statement, scale: String(statementScale)}}>
                        <GlassCard w={aspect === 'wide' ? 1080 : 880} h={aspect === 'wide' ? 210 : 250}>
                            <div
                                style={{
                                    position: 'absolute',
                                    inset: 0,
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    padding: '0 60px',
                                    textAlign: 'center',
                                    ...type.display,
                                    fontSize: aspect === 'wide' ? 64 : 56,
                                    color: p.fg,
                                }}
                            >
                                Everything your salon needs.
                            </div>
                        </GlassCard>
                    </div>
                </Plate3D>
            </Stage3D>
        </AbsoluteFill>
    );
};
