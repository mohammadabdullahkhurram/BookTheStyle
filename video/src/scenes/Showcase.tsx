import React from 'react';
import {AbsoluteFill, Img, OffthreadVideo, Sequence, interpolate, useCurrentFrame} from 'remotion';
import {FPS, localBeat} from '../beats';
import {getAsset} from '../manifest';
import {color, font, type} from '../theme';
import {DarkField} from './Brand';
import {Feather, KineticCard, PhoneFrame, ProductStill, useAspect} from './kinetic';

/**
 * Showcase (track 4.30–16.51 — the groove, beats 8–31). Rapid feature reel,
 * every cut ON a beat: the real widget recording in three tight cuts (the
 * confirmation lands on the track's 0.97 hit at beat 12), the week filling
 * itself in column by column on half-beats, the dashboard with stats
 * ticking up, the client book, the numbers. Hard cuts, no dissolves —
 * the product is the motion.
 */

/** Windows (seconds) inside the motion recording (see --motion pacing). */
const MOTION_CUTS = {
    booking: {from: 0.9, to: 2.1},   // landing + the service tap
    slot: {from: 4.5, to: 5.7},      // calendar day + slot tap
    confirmed: {from: 9.7, to: 12.0} // submit → "You're booked"
};

const lb = (n: number) => localBeat('showcase', n);

const WidgetCut: React.FC<{window: {from: number; to: number}}> = ({window}) => {
    const aspect = useAspect();
    const motion = getAsset('widget-motion');
    return (
        <AbsoluteFill style={{justifyContent: 'center', alignItems: aspect === 'wide' ? 'flex-end' : 'center'}}>
            <PhoneFrame width={aspect === 'tall' ? 640 : 430} style={{marginRight: aspect === 'wide' ? 190 : 0}}>
                <OffthreadVideo
                    src={motion.src}
                    muted
                    startFrom={Math.round(window.from * FPS)}
                    endAt={Math.round(window.to * FPS)}
                    style={{position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover', objectPosition: 'top'}}
                />
            </PhoneFrame>
        </AbsoluteFill>
    );
};

/** The master week filling itself in: columns snap on on half-beats. */
const WeekFills: React.FC<{at: number}> = ({at}) => {
    const frame = useCurrentFrame();
    const halfBeat = (60 / 117.5) * FPS / 2;
    // Steps aligned to the still's actual day-column edges, snapping on
    // half-beats — the week visibly fills, column by column.
    const EDGES = [22, 33, 44, 55, 66, 78, 89, 100];
    const steps = Math.max(0, Math.floor((frame - at) / halfBeat) + 1);
    const revealed = EDGES[Math.min(EDGES.length - 1, steps - 1)] ?? 22;
    return (
        <AbsoluteFill>
            <ProductStill src={getAsset('owner-calendar-week').src} at={at} focus="30% 30%" />
            {/* Unfilled future: the paper field waiting for bookings. */}
            <div
                style={{
                    position: 'absolute',
                    inset: 0,
                    background: color.marble.paper,
                    clipPath: `inset(0 0 0 ${revealed}%)`,
                }}
            />
        </AbsoluteFill>
    );
};

/** Film-voice count-ups on the dark feather — the morning's numbers
 *  arriving, without faking UI over the real dashboard's identical stats. */
const TickingStats: React.FC<{at: number}> = ({at}) => {
    const frame = useCurrentFrame();
    const aspect = useAspect();
    const lines = [
        {value: 6, suffix: ' booked.', beatOffset: 0.5},
        {value: 0, suffix: ' no-shows.', beatOffset: 2},
    ];
    return (
        <div
            style={{
                position: 'absolute',
                left: aspect === 'wide' ? 110 : 70,
                bottom: aspect === 'tall' ? 240 : 210,
                display: 'flex',
                flexDirection: 'column',
                gap: 14,
            }}
        >
            {lines.map(({value, suffix, beatOffset}) => {
                const start = at + Math.round(beatOffset * (60 / 117.5) * FPS);
                const inAt = interpolate(frame, [start, start + 4], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
                const ticked = Math.round(interpolate(frame, [start, start + 22], [0, value], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'}));
                return (
                    <div
                        key={suffix}
                        style={{
                            fontFamily: font.display,
                            fontWeight: 600,
                            fontSize: aspect === 'wide' ? 72 : 58,
                            lineHeight: 1.12,
                            color: color.marble.paper,
                            opacity: inAt,
                            transform: `translateY(${(1 - inAt) * 16}px)`,
                            textShadow: '0 4px 26px rgba(0,0,0,0.45)',
                        }}
                    >
                        {ticked}
                        {suffix}
                    </div>
                );
            })}
        </div>
    );
};

type Shot = {fromBeat: number; toBeat: number; label?: string; chipLabel?: boolean; render: (at: number) => React.ReactNode};

const SHOTS: Shot[] = [
    {fromBeat: 8, toBeat: 10, label: 'She books.', render: () => <WidgetCut window={MOTION_CUTS.booking} />},
    {fromBeat: 10, toBeat: 12, render: () => <WidgetCut window={MOTION_CUTS.slot} />},
    {fromBeat: 12, toBeat: 16, label: 'Booked.', render: () => <WidgetCut window={MOTION_CUTS.confirmed} />},
    {fromBeat: 16, toBeat: 20, label: 'Your week.', chipLabel: true, render: (at) => <WeekFills at={at} />},
    {
        fromBeat: 20,
        toBeat: 24,
        render: (at) => (
            <AbsoluteFill>
                <ProductStill src={getAsset('owner-dashboard--marble').src} at={at} focus="35% 25%" />
                <Feather strength={0.8} />
                <TickingStats at={at + 2} />
            </AbsoluteFill>
        ),
    },
    {
        fromBeat: 24,
        toBeat: 28,
        label: 'Every client.',
        render: (at) => (
            <AbsoluteFill>
                <ProductStill src={getAsset('owner-client-profile').src} at={at} focus="45% 18%" />
                <Feather strength={0.7} />
            </AbsoluteFill>
        ),
    },
    {
        fromBeat: 28,
        toBeat: 32,
        label: 'Every number.',
        render: (at) => (
            <AbsoluteFill>
                <ProductStill src={getAsset('owner-reports').src} at={at} focus="55% 45%" />
                <Feather strength={0.7} />
            </AbsoluteFill>
        ),
    },
];

export const Showcase: React.FC = () => {
    const aspect = useAspect();
    return (
        <AbsoluteFill>
            <DarkField />
            {SHOTS.map(({fromBeat, toBeat, label, chipLabel, render}) => (
                <Sequence key={fromBeat} from={lb(fromBeat)} durationInFrames={lb(toBeat) - lb(fromBeat)} name={`shot@b${fromBeat}`}>
                    {render(0)}
                    {label ? (
                        <KineticCard at={2} chip={chipLabel} size={aspect === 'wide' ? 76 : 62} position={aspect === 'tall' ? {bottom: 200} : undefined}>
                            {label}
                        </KineticCard>
                    ) : null}
                </Sequence>
            ))}
        </AbsoluteFill>
    );
};
