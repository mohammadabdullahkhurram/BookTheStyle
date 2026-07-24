import {Armchair, AudioLines, BellRing, CalendarDays, ChartColumn, Clock, Users} from 'lucide-react';
import React from 'react';
import {AbsoluteFill, Easing, interpolate, useCurrentFrame} from 'remotion';
import {localBeat} from '../beats';
import {color} from '../theme';
import {BrandLockup} from './Brand';
import {GlassCard} from './glass';
import {KineticCard, useAspect} from './kinetic';
import {useFilmLight} from './mode';
import {cam, cameraPath, GroundGrid, Particles, Plate3D, Stage3D, Void, whip} from './space';
import {MiniCard} from './vignettes';

/**
 * DROP (track 20.06–27.14 — beats 39–52). The payoff: the camera pulls back
 * to the whole system — every capability card orbiting the wordmark — and
 * the ENTIRE ENVIRONMENT recolors on beats 39/42/45: terracotta → violet →
 * sage. Every rim, glow, particle and breath of fog swaps. On beat 48
 * (24.64s, the track's global energy peak) the near-black accent lands as a
 * full inversion — the dark void slams to Marble paper — and "Yours." hits
 * the same frame. Custom branding IS the climax: make it yours.
 */

const ACCENTS = ['#C0613E', '#5B3E96', '#5C7458', '#211C18'];

const FEATURES = [
    {icon: CalendarDays, label: 'Booking'},
    {icon: AudioLines, label: 'Voice AI'},
    {icon: Clock, label: 'Scheduling'},
    {icon: Users, label: 'Clients'},
    {icon: Armchair, label: 'Rental'},
    {icon: BellRing, label: 'Reminders'},
    {icon: ChartColumn, label: 'Reports'},
];

const RING_Z = -420;
const Z_WOBBLE = [-160, 130, -110, 150, -180, 100, -140];

export const Drop: React.FC = () => {
    const frame = useCurrentFrame();
    const aspect = useAspect();
    const lb = (n: number) => localBeat('drop', n);
    const swaps = [0, lb(42), lb(45), lb(48)];
    const end = lb(53);
    const peak = lb(48);

    const active = swaps.filter((s) => frame >= s).length - 1;
    const accent = ACCENTS[active];
    // THE MIRROR: the peak inverts the environment. The dark film slams to
    // the bright Marble field (ink cards, near-black accent); the LIGHT film
    // slams to the dark void — its single dark beat, pure contrast — so the
    // climax lands with equal force instead of near-black-on-light mud.
    const filmLight = useFilmLight();
    const inverted = frame >= peak;
    const light = filmLight ? !inverted : inverted;

    const pull = aspect === 'wide' ? 0 : aspect === 'square' ? 90 : 170;
    // The film's biggest camera moment: a slow majestic S-ARC around the
    // constellation while the recolors hit (each swap mid-sweep), then a
    // violent RUSH-IN that lands exactly on the 24.64s peak with the
    // inversion flash. Arc = ceremony; rush = slam.
    const camera = cameraPath(frame, [
        {f: 0, cam: cam([-280, 30, 200 + pull], -14, 0, -2.5)},
        {f: swaps[1], cam: cam([210, -30, 420 + pull], 8, 1, 1.2), ease: Easing.bezier(0.4, 0, 0.2, 1)},
        {f: swaps[2], cam: cam([-150, 20, 580 + pull], -9, -1, -1.5), ease: Easing.bezier(0.4, 0, 0.2, 1)},
        {f: peak, cam: cam([0, 0, 240 + pull], 0, 0, 0), ease: whip},
        {f: end, cam: cam([0, 0, 212 + pull], 0)},
    ]);

    const [rx, ry] = aspect === 'wide' ? [730, 400] : aspect === 'square' ? [545, 545] : [430, 740];

    // The lockup yields the centre to "Yours." exactly on the peak.
    const lockupOut = interpolate(frame, [peak - 4, peak], [1, 0], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
    // Gated: interpolate would clamp LEFT to 0.85 and whitewash the whole pre-peak drop.
    const flash = frame < peak ? 0 : interpolate(frame, [peak, peak + 5], [0.85, 0], {extrapolateRight: 'clamp'});
    const settle = interpolate(frame, [0, 5], [1.05, 1], {extrapolateRight: 'clamp'});

    return (
        <AbsoluteFill>
            <Void accent={accent} light={light} breath={light ? '30' : '4d'} />
            <AbsoluteFill style={{scale: String(settle)}}>
                <Stage3D camera={camera}>
                    <GroundGrid center={[0, 600, -700]} light={light} />
                    <Particles
                        camera={camera}
                        frame={frame}
                        seed="drop"
                        min={[-1100, -700, -1500]}
                        max={[1100, 700, 300]}
                        count={130}
                        accent={accent}
                        light={light}
                    />
                    {FEATURES.map((f, i) => {
                        const angle = (-90 + i * (360 / FEATURES.length)) * (Math.PI / 180);
                        const pos = [Math.cos(angle) * rx, Math.sin(angle) * ry, RING_Z + Z_WOBBLE[i]] as const;
                        const bob = Math.sin((frame + i * 34) * 0.05) * 8;
                        return (
                            <Plate3D key={f.label} pos={pos} yaw={(i % 2 ? -1 : 1) * 5} camera={camera} local={`translateY(${bob}px)`} fog={[1900, 3600]}>
                                <MiniCard icon={f.icon} label={f.label} accent={accent} light={light} />
                            </Plate3D>
                        );
                    })}
                    {/* The wordmark holds the centre of the system... */}
                    <Plate3D pos={[0, 0, RING_Z]} camera={camera} dof={false}>
                        <div style={{opacity: lockupOut, scale: String(1 + (1 - lockupOut) * 0.1)}}>
                            <GlassCard w={480} h={430} accent={accent} light={light} glow={1.6}>
                                <div style={{position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center'}}>
                                    <BrandLockup iconSize={168} wordSize={30} light={light} />
                                </div>
                            </GlassCard>
                        </div>
                    </Plate3D>
                </Stage3D>
            </AbsoluteFill>

            {/* Shockwave on every recolor — the arriving accent, except the
                peak's ring, which reads in contrast against the JUST-inverted
                environment (ink on light, paper on dark). */}
            {swaps.slice(1).map((s) => {
                const w = interpolate(frame, [s, s + 16], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
                if (w <= 0 || w >= 1) return null;
                const isPeakRing = swaps.indexOf(s) === 3;
                return (
                    <AbsoluteFill key={s} style={{alignItems: 'center', justifyContent: 'center'}}>
                        <div
                            style={{
                                width: 340,
                                height: 340,
                                borderRadius: '50%',
                                border: `3px solid ${isPeakRing ? (light ? color.ink : color.marble.paper) : ACCENTS[swaps.indexOf(s)]}`,
                                scale: String(0.4 + w * 5),
                                opacity: (1 - w) * 0.5,
                            }}
                        />
                    </AbsoluteFill>
                );
            })}

            {/* ...and on the peak, the inversion flash + the word. */}
            {flash > 0 && <AbsoluteFill style={{backgroundColor: '#FFFFFF', opacity: flash}} />}
            <KineticCard
                at={peak}
                center
                tone={filmLight ? 'paper' : 'ink'}
                size={aspect === 'wide' ? 190 : 150}
                position={{bottom: aspect === 'tall' ? '47%' : '42%'}}
            >
                Yours.
            </KineticCard>

            {/* The swatch rail — the live accent, snapping with each recolor. */}
            <div
                style={{
                    position: 'absolute',
                    left: '50%',
                    translate: '-50% 0',
                    bottom: aspect === 'tall' ? 150 : 84,
                    display: 'flex',
                    alignItems: 'center',
                    gap: 20,
                }}
            >
                {/* Swatches only — no hex readout; the color speaks for itself. */}
                {ACCENTS.map((a, i) => (
                    <div
                        key={a}
                        style={{
                            width: i === active ? 50 : 30,
                            height: i === active ? 50 : 30,
                            borderRadius: '50%',
                            backgroundColor: a,
                            border: `3px solid ${light ? 'rgba(33,28,24,0.65)' : i === active ? '#FFFFFF' : 'rgba(255,255,255,0.45)'}`,
                            boxShadow: i === active && !light ? `0 0 26px ${a}aa` : 'none',
                        }}
                    />
                ))}
            </div>
        </AbsoluteFill>
    );
};
