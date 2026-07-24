import {CalendarDays, PhoneCall} from 'lucide-react';
import React from 'react';
import {AbsoluteFill, Easing, Img, interpolate, staticFile, useCurrentFrame} from 'remotion';
import {font, type} from '../theme';
import {SampleAudio, butter, coral, cream, ink, plum, sb} from './common';

/**
 * LOOK 2 — AURORA. Soft dimensional light: enormous blurred color fields
 * (plum/coral/butter) drifting over cream, a glass LENS as the recurring
 * stage — features dock into it as floating pods. 2.5D parallax by scale,
 * dreamy drift punctuated by spring docks on beats; the aurora itself
 * shifts hue on the transition (the recolor mechanic previewed).
 */

const spring = Easing.bezier(0.2, 1.15, 0.3, 1);

const Blob: React.FC<{x: number; y: number; size: number; color: string; drift: number; f: number; hueShift?: number}> = ({x, y, size, color, drift, f, hueShift = 0}) => (
    <div
        style={{
            position: 'absolute',
            left: x + Math.sin(f * 0.014 + drift) * 60,
            top: y + Math.cos(f * 0.011 + drift) * 44,
            width: size,
            height: size,
            borderRadius: '50%',
            background: color,
            filter: `blur(110px) hue-rotate(${hueShift}deg)`,
            opacity: 0.5,
        }}
    />
);

export const Look2Aurora: React.FC = () => {
    const f = useCurrentFrame();

    // The aurora re-tints on the transition beat — the recolor mechanic.
    const hue = interpolate(f, [sb(6), sb(6) + 10], [0, -38], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
    const phase2 = f >= sb(6);

    const lensIn = interpolate(f, [sb(0), sb(0) + 8], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: spring});
    const ripple = (at: number) => interpolate(f, [at, at + 16], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});

    const pods = [
        {at: sb(1), label: 'Balayage & tone', x: 330, y: 300},
        {at: sb(2), label: 'Maya Rivera', x: 380, y: 700},
        {at: sb(3), label: 'Thu 2:00 pm', x: 1310, y: 640},
    ];
    const dock = interpolate(f, [sb(4) - 3, sb(4) + 3], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: spring});

    return (
        <AbsoluteFill style={{backgroundColor: cream}}>
            <Blob x={-260} y={-300} size={1050} color={plum} drift={0} f={f} hueShift={hue} />
            <Blob x={1150} y={-200} size={880} color={butter} drift={2.2} f={f} hueShift={hue} />
            <Blob x={800} y={620} size={950} color={coral} drift={4.1} f={f} hueShift={hue} />
            <AbsoluteFill style={{backdropFilter: 'saturate(112%)'}} />

            {/* Floating title — soft, weightless. */}
            <div
                style={{
                    position: 'absolute',
                    left: 110,
                    top: 120,
                    ...type.display,
                    fontSize: 92,
                    color: ink,
                    opacity: interpolate(f, [sb(0) + 4, sb(0) + 12], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'}),
                }}
            >
                {phase2 ? 'It answers every call.' : 'Booked in seconds.'}
            </div>

            {/* THE LENS — the film's recurring stage. */}
            <div
                style={{
                    position: 'absolute',
                    left: 960 - 210,
                    top: 540 - 210 + Math.sin(f * 0.03) * 10,
                    width: 420,
                    height: 420,
                    scale: String(lensIn * (1 + (dock > 0 ? (1 - dock) * 0 : 0) + interpolate(dock, [0, 0.5, 1], [0, 0.07, 0]))),
                    opacity: lensIn,
                }}
            >
                {/* Ripple rings on the hits. */}
                {[sb(0), sb(4), sb(6)].map((at, i) => {
                    const r = ripple(at);
                    if (r <= 0 || r >= 1) return null;
                    return (
                        <div
                            key={i}
                            style={{
                                position: 'absolute',
                                inset: 0,
                                borderRadius: '50%',
                                border: `2px solid ${plum}`,
                                scale: String(1 + r * 0.8),
                                opacity: (1 - r) * 0.7,
                            }}
                        />
                    );
                })}
                <div
                    style={{
                        position: 'absolute',
                        inset: 0,
                        borderRadius: '50%',
                        background: 'radial-gradient(circle at 32% 26%, rgba(255,255,255,0.95) 0%, rgba(255,255,255,0.55) 42%, rgba(255,255,255,0.25) 100%)',
                        border: '1.5px solid rgba(255,255,255,0.9)',
                        boxShadow: `0 40px 90px rgba(52,33,45,0.16), inset 0 2px 30px rgba(255,255,255,0.9)`,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                    }}
                >
                    {phase2 ? (
                        <div style={{position: 'relative', display: 'flex', alignItems: 'center', justifyContent: 'center'}}>
                            <PhoneCall size={110} color={plum} strokeWidth={1.4} />
                        </div>
                    ) : dock < 1 ? (
                        <Img src={staticFile('brand/icon-logo.png')} style={{width: 170, height: 170, objectFit: 'contain'}} />
                    ) : (
                        <div style={{display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 14}}>
                            <CalendarDays size={96} color={plum} strokeWidth={1.4} />
                            <div style={{...type.heading, fontSize: 40, color: ink}}>Confirmed</div>
                        </div>
                    )}
                </div>
            </div>

            {/* The pods — features floating in, docking on the hit. */}
            {!phase2 &&
                pods.map((pod, i) => {
                    const arrive = interpolate(f, [pod.at, pod.at + 9], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: spring});
                    // On the accent hit every pod flies INTO the lens.
                    const gone = interpolate(f, [sb(4) - 4, sb(4) + 2], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: Easing.bezier(0.6, 0, 0.8, 0.4)});
                    const x = pod.x + (960 - pod.x) * gone;
                    const y = pod.y + (540 - pod.y) * gone;
                    const opacity = arrive * (1 - interpolate(gone, [0.7, 1], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'}));
                    if (opacity <= 0.01) return null;
                    return (
                        <div
                            key={i}
                            style={{
                                position: 'absolute',
                                left: x,
                                top: y + Math.sin(f * 0.05 + i * 2) * 8,
                                translate: '-50% -50%',
                                padding: '20px 40px',
                                borderRadius: 99,
                                background: 'rgba(255,255,255,0.75)',
                                border: '1.5px solid rgba(255,255,255,0.95)',
                                boxShadow: '0 24px 60px rgba(52,33,45,0.14)',
                                backdropFilter: 'blur(14px)',
                                fontFamily: font.body,
                                fontWeight: 600,
                                fontSize: 30,
                                color: ink,
                                opacity,
                                scale: String(0.8 + arrive * 0.2),
                            }}
                        >
                            {pod.label}
                        </div>
                    );
                })}

            {/* Phase 2 — sound dots orbiting the lens. */}
            {phase2 &&
                Array.from({length: 9}, (_, i) => {
                    const angle = (i / 9) * Math.PI * 2 + f * 0.05;
                    const r = 290 + Math.sin(f * 0.14 + i * 1.6) * 34;
                    return (
                        <div
                            key={i}
                            style={{
                                position: 'absolute',
                                left: 960 + Math.cos(angle) * r,
                                top: 540 + Math.sin(angle) * r * 0.72,
                                width: 13,
                                height: 13,
                                borderRadius: '50%',
                                background: i % 3 === 0 ? coral : plum,
                                opacity: 0.75,
                            }}
                        />
                    );
                })}

            <SampleAudio />
        </AbsoluteFill>
    );
};
