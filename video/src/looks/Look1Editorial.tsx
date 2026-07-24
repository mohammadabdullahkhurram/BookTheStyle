import {Scissors} from 'lucide-react';
import React from 'react';
import {AbsoluteFill, Easing, interpolate, useCurrentFrame} from 'remotion';
import {font, type} from '../theme';
import {SampleAudio, coral, ink, paper, plum, sb} from './common';

/**
 * LOOK 1 — EDITORIAL. A fashion magazine that moves: enormous Fraunces
 * serif AS the scenery, ink on warm paper, hairline rules, index numerals,
 * italic order-sheet lines. Flat kinetic type — no camera, no 3D; clipped
 * reveals, baseline slides, one giant diagonal CONFIRMED stamp on the hit,
 * a page-turn rule wipe into the next spread.
 */

const clipUp = (f: number, at: number, dur = 9) =>
    interpolate(f, [at, at + dur], [102, 0], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
        easing: Easing.bezier(0.2, 0.9, 0.25, 1),
    });

export const Look1Editorial: React.FC = () => {
    const f = useCurrentFrame();

    // Page turn: a rule sweeps the frame on sample-beat 6; spread 2 follows.
    const turn = interpolate(f, [sb(6) - 3, sb(6) + 6], [0, 100], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
        easing: Easing.bezier(0.7, 0, 0.2, 1),
    });
    const spread2 = f >= sb(6) + 2;

    const stamp = interpolate(f, [sb(4), sb(4) + 4], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
    const stampScale = interpolate(f, [sb(4), sb(4) + 5], [1.6, 1], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
        easing: Easing.bezier(0.2, 1.1, 0.3, 1),
    });

    const orderLines = [
        {at: sb(1), label: 'Balayage & tone', detail: '90 min'},
        {at: sb(2), label: 'with Maya Rivera', detail: 'senior stylist'},
        {at: sb(3), label: 'Thursday · 2:00 pm', detail: 'one tap'},
    ];

    return (
        <AbsoluteFill style={{backgroundColor: paper}}>
            {/* The page furniture — folio, baseline grid whisper. */}
            <AbsoluteFill
                style={{
                    backgroundImage: `repeating-linear-gradient(0deg, ${ink}08 0 1px, transparent 1px 96px)`,
                }}
            />
            <div style={{position: 'absolute', top: 54, left: 70, ...type.overline, fontSize: 20, color: ink, opacity: 0.55}}>
                BookTheStyle — the launch issue
            </div>
            <div style={{position: 'absolute', top: 54, right: 70, ...type.overline, fontSize: 20, color: coral}}>
                {spread2 ? '02 / 07' : '01 / 07'}
            </div>
            <div style={{position: 'absolute', top: 96, left: 70, right: 70, height: 1.5, background: ink, opacity: 0.8}} />

            {!spread2 ? (
                <>
                    {/* SPREAD 1 — Booking. The headline IS the set. */}
                    <div style={{position: 'absolute', left: 64, top: 150, overflow: 'hidden'}}>
                        <div
                            style={{
                                ...type.display,
                                fontSize: 250,
                                lineHeight: 0.96,
                                color: ink,
                                translate: `0 ${clipUp(f, sb(0))}%`,
                            }}
                        >
                            Booking.
                        </div>
                    </div>
                    <div style={{position: 'absolute', left: 78, top: 470, display: 'flex', alignItems: 'center', gap: 14, opacity: f >= sb(0) + 10 ? 1 : 0}}>
                        <Scissors size={30} color={coral} strokeWidth={1.6} />
                        <div style={{...type.overline, fontSize: 21, color: ink, opacity: 0.6}}>Every chair, one page</div>
                    </div>

                    {/* The order sheet — italic serif rows arriving per beat. */}
                    <div style={{position: 'absolute', left: 82, top: 560, display: 'flex', flexDirection: 'column', gap: 34, width: 900}}>
                        {orderLines.map((line, i) => {
                            const vis = interpolate(f, [line.at, line.at + 6], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
                            return (
                                <div key={i} style={{display: 'flex', alignItems: 'baseline', gap: 26, opacity: vis, translate: `${(1 - vis) * -34}px 0`}}>
                                    <div style={{fontFamily: font.display, fontStyle: 'italic', fontWeight: 500, fontSize: 54, color: ink}}>
                                        {line.label}
                                    </div>
                                    <div style={{flex: 1, borderBottom: `1.5px dotted ${ink}55`, translate: '0 -12px'}} />
                                    <div style={{...type.overline, fontSize: 20, color: plum}}>{line.detail}</div>
                                </div>
                            );
                        })}
                    </div>

                    {/* THE STAMP — slams diagonally on the accent hit. */}
                    {stamp > 0 && (
                        <div
                            style={{
                                position: 'absolute',
                                right: 90,
                                top: 330,
                                rotate: '-8deg',
                                padding: '18px 44px',
                                border: `6px solid ${plum}`,
                                borderRadius: 10,
                                ...type.overline,
                                fontSize: 64,
                                letterSpacing: '0.14em',
                                color: plum,
                                opacity: stamp * 0.94,
                                scale: String(stampScale),
                            }}
                        >
                            Confirmed
                        </div>
                    )}
                </>
            ) : (
                <>
                    {/* SPREAD 2 — Voice AI. The phone call set in type. */}
                    <div style={{position: 'absolute', left: 64, top: 150, overflow: 'hidden'}}>
                        <div
                            style={{
                                ...type.display,
                                fontSize: 230,
                                lineHeight: 0.96,
                                color: ink,
                                translate: `0 ${clipUp(f, sb(6) + 4)}%`,
                            }}
                        >
                            Voice AI.
                        </div>
                    </div>
                    <div
                        style={{
                            position: 'absolute',
                            left: 84,
                            top: 480,
                            fontFamily: font.display,
                            fontStyle: 'italic',
                            fontWeight: 500,
                            fontSize: 66,
                            color: plum,
                            opacity: interpolate(f, [sb(8), sb(8) + 6], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'}),
                        }}
                    >
                        “It answered at 11pm. She booked.”
                    </div>
                    {/* A waveform set in em-dashes — typography as data. */}
                    <div style={{position: 'absolute', left: 86, top: 640, display: 'flex', alignItems: 'center', gap: 10}}>
                        {Array.from({length: 26}, (_, i) => {
                            const on = f >= sb(9) + i * 1.2;
                            const h = 8 + Math.abs(Math.sin(i * 1.1 + f * 0.25)) * 46;
                            return (
                                <div key={i} style={{width: 7, height: on ? h : 0, borderRadius: 4, background: i % 5 === 0 ? coral : ink, opacity: on ? 0.85 : 0}} />
                            );
                        })}
                    </div>
                </>
            )}

            {/* The page-turn rule sweeping the frame. */}
            {turn > 0 && turn < 100 && (
                <div style={{position: 'absolute', top: 0, bottom: 0, left: `${turn}%`, width: 3, background: ink}} />
            )}
            {turn > 0 && turn < 100 && (
                <AbsoluteFill style={{backgroundColor: paper, clipPath: `inset(0 ${100 - turn}% 0 0)`}} />
            )}

            <SampleAudio />
        </AbsoluteFill>
    );
};
