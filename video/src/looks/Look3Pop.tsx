import {Check, PhoneCall, Scissors, User} from 'lucide-react';
import React from 'react';
import {AbsoluteFill, Easing, interpolate, useCurrentFrame} from 'remotion';
import {font, type} from '../theme';
import {SampleAudio, butter, coral, cream, ink, plum, sage, sb} from './common';

/**
 * LOOK 3 — POP. Bold flat graphic: sticker cards with thick ink outlines
 * and hard offset shadows slapping down on beats, full-bleed color slams,
 * diagonal wipes, springy squash-and-settle motion. Risograph-adjacent
 * energy — social-native, loud, joyful.
 */

const slap = Easing.bezier(0.2, 1.35, 0.3, 1);

const sticker = (bg: string): React.CSSProperties => ({
    background: bg,
    border: `4px solid ${ink}`,
    borderRadius: 22,
    boxShadow: `10px 10px 0 ${ink}`,
});

const Sticker: React.FC<{
    f: number;
    at: number;
    x: number;
    y: number;
    rotate: number;
    bg: string;
    children: React.ReactNode;
}> = ({f, at, x, y, rotate, bg, children}) => {
    const arrive = interpolate(f, [at, at + 6], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: slap});
    if (arrive <= 0) return null;
    return (
        <div
            style={{
                position: 'absolute',
                left: x,
                top: y,
                rotate: `${rotate}deg`,
                display: 'flex',
                alignItems: 'center',
                gap: 20,
                padding: '24px 40px',
                ...sticker(bg),
                scale: String(interpolate(arrive, [0, 1], [1.5, 1])),
                opacity: Math.min(1, arrive * 2),
            }}
        >
            {children}
        </div>
    );
};

export const Look3Pop: React.FC = () => {
    const f = useCurrentFrame();

    // The accent hit SLAMS the whole background to plum.
    const slam = f >= sb(4) && f < sb(6);
    // Diagonal wipe into scene 2 on sample-beat 6.
    const wipe = interpolate(f, [sb(6) - 2, sb(6) + 5], [0, 130], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
        easing: Easing.bezier(0.7, 0, 0.2, 1),
    });
    const scene2 = f >= sb(6) + 3;

    const headIn = interpolate(f, [sb(0), sb(0) + 6], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: slap});
    const confirmIn = interpolate(f, [sb(4), sb(4) + 5], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: slap});

    const bg = slam ? plum : cream;

    return (
        <AbsoluteFill style={{backgroundColor: bg}}>
            {/* Giant color block backdrop — slides in, sits at a slant. */}
            {!slam && !scene2 && (
                <div
                    style={{
                        position: 'absolute',
                        left: interpolate(headIn, [0, 1], [-900, -280]),
                        top: -200,
                        width: 900,
                        height: 1600,
                        rotate: '14deg',
                        background: butter,
                        border: `4px solid ${ink}`,
                    }}
                />
            )}

            {!scene2 ? (
                <>
                    {/* The chapter sticker. */}
                    <div
                        style={{
                            position: 'absolute',
                            left: 120,
                            top: 130,
                            rotate: '-4deg',
                            padding: '26px 52px',
                            ...sticker(slam ? cream : plum),
                            scale: String(interpolate(headIn, [0, 1], [1.6, 1])),
                            opacity: headIn,
                        }}
                    >
                        <div style={{...type.overline, fontSize: 58, letterSpacing: '0.1em', color: slam ? ink : cream}}>Booking</div>
                    </div>

                    {/* Stickers slapping down per beat. */}
                    <Sticker f={f} at={sb(1)} x={200} y={420} rotate={-2} bg={cream}>
                        <Scissors size={44} color={ink} strokeWidth={2.2} />
                        <div style={{fontFamily: font.body, fontWeight: 700, fontSize: 40, color: ink}}>Balayage & tone</div>
                    </Sticker>
                    <Sticker f={f} at={sb(2)} x={330} y={620} rotate={3} bg={sage}>
                        <User size={44} color={cream} strokeWidth={2.2} />
                        <div style={{fontFamily: font.body, fontWeight: 700, fontSize: 40, color: cream}}>Maya R.</div>
                    </Sticker>
                    <Sticker f={f} at={sb(3)} x={230} y={820} rotate={-3} bg={coral}>
                        <div style={{fontFamily: font.body, fontWeight: 700, fontSize: 40, color: cream}}>Thu · 2:00 pm</div>
                    </Sticker>

                    {/* THE SLAM — full-bleed plum + giant CONFIRMED. */}
                    {slam && (
                        <AbsoluteFill style={{alignItems: 'center', justifyContent: 'center', gap: 30, flexDirection: 'row'}}>
                            <div
                                style={{
                                    width: 130,
                                    height: 130,
                                    borderRadius: '50%',
                                    background: butter,
                                    border: `5px solid ${ink}`,
                                    boxShadow: `10px 10px 0 ${ink}`,
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    scale: String(interpolate(confirmIn, [0, 1], [2, 1])),
                                }}
                            >
                                <Check size={80} color={ink} strokeWidth={3} />
                            </div>
                            <div
                                style={{
                                    ...type.display,
                                    fontSize: 170,
                                    color: cream,
                                    scale: String(interpolate(confirmIn, [0, 1], [1.5, 1])),
                                    opacity: confirmIn,
                                }}
                            >
                                Confirmed.
                            </div>
                        </AbsoluteFill>
                    )}
                </>
            ) : (
                <>
                    {/* SCENE 2 — Voice AI, same sticker language, sage field. */}
                    <AbsoluteFill style={{backgroundColor: cream}} />
                    <div
                        style={{
                            position: 'absolute',
                            right: -260,
                            top: -260,
                            width: 950,
                            height: 950,
                            borderRadius: '50%',
                            background: sage,
                            border: `4px solid ${ink}`,
                        }}
                    />
                    <div
                        style={{
                            position: 'absolute',
                            left: 120,
                            top: 150,
                            rotate: '-3deg',
                            padding: '26px 52px',
                            ...sticker(coral),
                        }}
                    >
                        <div style={{...type.overline, fontSize: 58, letterSpacing: '0.1em', color: cream}}>Voice AI</div>
                    </div>
                    <Sticker f={f} at={sb(7)} x={220} y={460} rotate={2} bg={cream}>
                        <PhoneCall size={52} color={ink} strokeWidth={2.2} />
                        <div style={{fontFamily: font.body, fontWeight: 700, fontSize: 42, color: ink}}>Ring… answered.</div>
                    </Sticker>
                    {/* A chunky zigzag voice line drawing across. */}
                    <svg style={{position: 'absolute', left: 240, top: 680, overflow: 'visible'}} width={1000} height={200}>
                        <polyline
                            points="0,100 90,30 180,150 270,40 360,160 450,50 540,140 630,60 720,120 810,80 900,100"
                            fill="none"
                            stroke={ink}
                            strokeWidth={10}
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            strokeDasharray={1600}
                            strokeDashoffset={1600 - interpolate(f, [sb(8), sb(10)], [0, 1600], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'})}
                        />
                    </svg>
                </>
            )}

            {/* Diagonal wipe blade. */}
            {wipe > 0 && wipe < 130 && (
                <AbsoluteFill
                    style={{
                        background: ink,
                        clipPath: `polygon(${wipe - 24}% 0, ${wipe}% 0, ${wipe - 12}% 100%, ${wipe - 36}% 100%)`,
                    }}
                />
            )}

            <SampleAudio />
        </AbsoluteFill>
    );
};
