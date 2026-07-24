import {
    Armchair,
    AudioLines,
    BellRing,
    CalendarDays,
    ChartColumn,
    Check,
    PhoneCall,
    Scissors,
    Users,
    type LucideIcon,
} from 'lucide-react';
import React from 'react';
import {Easing, interpolate} from 'remotion';
import {FRAMES_PER_BEAT} from '../beats';
import {color, font, type} from '../theme';
import {CardTitle, GlassCard, IconBadge} from './glass';
import {lit} from './space';

/**
 * The seven capability vignettes — each card face abstractly ACTS OUT its
 * feature on the beat grid. All timing is a local frame `t` counted from the
 * card's arrival beat; internal hits land on whole/half beats of the track.
 * Palette discipline: cream + ink + the plum accent only (butter reserved
 * for the one revenue figure) so the drop's recolor stays the payoff.
 */

const BEAT = FRAMES_PER_BEAT; // ~15.66 frames — never accumulated, only scaled

/** Snap arrival: 5-frame settle, 2-frame fade — the film's signature. */
const pop = (t: number, at: number, from = 1.14): {scale: number; opacity: number} => ({
    scale: interpolate(t - at, [0, 5], [from, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'}),
    opacity: interpolate(t - at, [0, 2], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'}),
});

/** One-beat decaying kick (scale bump) for pulses. */
const kick = (t: number, at: number, amount = 0.1): number => {
    const local = t - at;
    if (local < 0) return 0;
    return amount * Math.exp(-local / 5);
};

const row: React.CSSProperties = {display: 'flex', alignItems: 'center', gap: 18};

const RowIcon: React.FC<{icon: LucideIcon}> = ({icon: Icon}) => (
    <div
        style={{
            width: 52,
            height: 52,
            borderRadius: 15,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            background: 'rgba(255,248,239,0.09)',
            border: '1px solid rgba(255,248,239,0.16)',
        }}
    >
        <Icon size={26} color={color.marble.paper} strokeWidth={1.8} />
    </div>
);

const rowText: React.CSSProperties = {fontFamily: font.body, fontWeight: 500, fontSize: 27, color: color.marble.paper};
const rowSub: React.CSSProperties = {fontFamily: font.body, fontWeight: 400, fontSize: 21, color: 'rgba(255,248,239,0.55)'};

const Header: React.FC<{icon: LucideIcon; eyebrow: string; title: string}> = ({icon, eyebrow, title}) => (
    <div style={{display: 'flex', alignItems: 'center', gap: 26}}>
        <IconBadge icon={icon} size={84} />
        <CardTitle eyebrow={eyebrow} title={title} />
    </div>
);

const CARD_W = 800;
const CARD_H = 600;
const PAD = 52;

/** 1 — Online Booking: service → stylist → slots → confirmed ON the accent hit. */
export const BookingVignette: React.FC<{t: number}> = ({t}) => {
    const service = pop(t, BEAT * 1);
    const stylist = pop(t, BEAT * 2);
    const chipsAt = BEAT * 2.5;
    const confirmAt = BEAT * 4; // beat 12 — the 6.34s 0.97-energy accent hit
    const confirm = pop(t, confirmAt, 1.35);
    const ring = interpolate(t - confirmAt, [0, 14], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
    const slots = ['1:30', '2:00', '2:30', '3:00'];
    return (
        <GlassCard w={CARD_W} h={CARD_H} glow={1 + kick(t, confirmAt, 1.6)}>
            <div style={{position: 'absolute', inset: 0, padding: PAD, display: 'flex', flexDirection: 'column', gap: 30}}>
                <Header icon={CalendarDays} eyebrow="Online booking" title="Booked in seconds" />
                <div style={{...row, scale: String(service.scale), opacity: service.opacity}}>
                    <RowIcon icon={Scissors} />
                    <div>
                        <div style={rowText}>Balayage & tone</div>
                        <div style={rowSub}>90 min</div>
                    </div>
                </div>
                <div style={{...row, scale: String(stylist.scale), opacity: stylist.opacity}}>
                    <div
                        style={{
                            width: 52,
                            height: 52,
                            borderRadius: '50%',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            background: `${color.accent}44`,
                            border: `1.5px solid ${lit(color.accent)}77`,
                            fontFamily: font.body,
                            fontWeight: 600,
                            fontSize: 20,
                            color: color.marble.paper,
                        }}
                    >
                        MR
                    </div>
                    <div>
                        <div style={rowText}>Maya Rivera</div>
                        <div style={rowSub}>Senior stylist</div>
                    </div>
                </div>
                <div style={{display: 'flex', gap: 16}}>
                    {slots.map((s, i) => {
                        const c = pop(t, chipsAt + i * (BEAT / 4), 1.18);
                        const selected = i === 1 && t >= confirmAt - BEAT / 2;
                        return (
                            <div
                                key={s}
                                style={{
                                    padding: '12px 26px',
                                    borderRadius: 99,
                                    scale: String(c.scale + (selected ? kick(t, confirmAt - BEAT / 2, 0.12) : 0)),
                                    opacity: c.opacity,
                                    fontFamily: font.body,
                                    fontWeight: 600,
                                    fontSize: 23,
                                    color: selected ? color.marble.paper : 'rgba(255,248,239,0.8)',
                                    background: selected ? color.accent : 'rgba(255,248,239,0.08)',
                                    border: `1.5px solid ${selected ? lit(color.accent) : 'rgba(255,248,239,0.2)'}`,
                                    boxShadow: selected ? `0 0 30px ${color.accent}66` : 'none',
                                }}
                            >
                                {s}
                            </div>
                        );
                    })}
                </div>
            </div>
            {/* Confirmation — slams on the accent hit, ring bursts outward. */}
            {t >= confirmAt && (
                <div style={{position: 'absolute', right: PAD, bottom: PAD - 6, display: 'flex', alignItems: 'center', gap: 20}}>
                    <div style={{position: 'relative', width: 84, height: 84}}>
                        <div
                            style={{
                                position: 'absolute',
                                inset: 0,
                                borderRadius: '50%',
                                border: `2px solid ${lit(color.accent)}`,
                                scale: String(1 + ring * 1.1),
                                opacity: 1 - ring,
                            }}
                        />
                        <div
                            style={{
                                position: 'absolute',
                                inset: 0,
                                borderRadius: '50%',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                background: color.accent,
                                boxShadow: `0 0 44px ${color.accent}aa`,
                                scale: String(confirm.scale),
                                opacity: confirm.opacity,
                            }}
                        >
                            <Check size={44} color={color.marble.paper} strokeWidth={2.6} />
                        </div>
                    </div>
                    <div style={{...type.heading, fontSize: 40, color: color.marble.paper, scale: String(confirm.scale), opacity: confirm.opacity}}>
                        Confirmed
                    </div>
                </div>
            )}
        </GlassCard>
    );
};

/** 2 — Voice AI: the orb takes a call, a light line books the calendar. */
export const VoiceVignette: React.FC<{t: number}> = ({t}) => {
    const orbKick = [0, 1, 2, 3, 4, 5].reduce((acc, n) => acc + kick(t, BEAT * n, 0.09), 0);
    const lineDraw = interpolate(t, [BEAT * 1.25, BEAT * 2.25], [0, 1], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
        easing: Easing.bezier(0.5, 0, 0.2, 1),
    });
    const nodeLit = t >= BEAT * 2.25;
    const node = pop(t, BEAT * 2.25, 1.2);
    const pill = pop(t, BEAT * 3, 1.16);
    const a = lit(color.accent);
    return (
        <GlassCard w={CARD_W} h={CARD_H} glow={1 + orbKick * 3}>
            <div style={{position: 'absolute', inset: 0, padding: PAD, display: 'flex', flexDirection: 'column'}}>
                <Header icon={AudioLines} eyebrow="Voice AI receptionist" title="Answers every call" />
                <div style={{flex: 1, position: 'relative'}}>
                    {/* The call orb — breathing with the track. */}
                    <div style={{position: 'absolute', left: 60, top: '52%', translate: '0 -50%'}}>
                        {[0, 1, 2].map((r) => {
                            const emit = ((t / BEAT + r / 3) % 1 + 1) % 1;
                            return (
                                <div
                                    key={r}
                                    style={{
                                        position: 'absolute',
                                        inset: 0,
                                        borderRadius: '50%',
                                        border: `1.5px solid ${a}`,
                                        scale: String(1 + emit * 0.9),
                                        opacity: (1 - emit) * 0.5,
                                    }}
                                />
                            );
                        })}
                        <div
                            style={{
                                width: 150,
                                height: 150,
                                borderRadius: '50%',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                background: `radial-gradient(circle at 34% 30%, ${a}55 0%, ${color.accent}99 45%, ${color.accent}33 100%)`,
                                border: `1.5px solid ${a}88`,
                                boxShadow: `0 0 ${60 + orbKick * 300}px ${color.accent}88`,
                                scale: String(1 + orbKick),
                            }}
                        >
                            <PhoneCall size={54} color={color.marble.paper} strokeWidth={1.7} />
                        </div>
                    </div>
                    {/* Voice → booking: the light line commits the call to the calendar. */}
                    <svg style={{position: 'absolute', inset: 0, width: '100%', height: '100%', overflow: 'visible'}} viewBox="0 0 696 300">
                        <path
                            d="M 220 150 C 330 60, 420 60, 530 128"
                            fill="none"
                            stroke={a}
                            strokeWidth={2.5}
                            strokeLinecap="round"
                            strokeDasharray={400}
                            strokeDashoffset={400 - lineDraw * 400}
                            opacity={lineDraw > 0 ? 0.9 : 0}
                            style={{filter: `drop-shadow(0 0 8px ${color.accent})`}}
                        />
                    </svg>
                    <div style={{position: 'absolute', right: 44, top: '38%', translate: '0 -50%', scale: String(node.scale), opacity: node.opacity}}>
                        <div
                            style={{
                                width: 108,
                                height: 108,
                                borderRadius: 26,
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                background: nodeLit ? `${color.accent}55` : 'rgba(255,248,239,0.07)',
                                border: `1.5px solid ${nodeLit ? a : 'rgba(255,248,239,0.18)'}`,
                                boxShadow: nodeLit ? `0 0 50px ${color.accent}77` : 'none',
                            }}
                        >
                            <CalendarDays size={48} color={nodeLit ? color.marble.paper : 'rgba(255,248,239,0.5)'} strokeWidth={1.7} />
                        </div>
                    </div>
                    <div
                        style={{
                            position: 'absolute',
                            right: 20,
                            bottom: 26,
                            padding: '12px 24px',
                            borderRadius: 99,
                            background: color.accent,
                            border: `1.5px solid ${a}`,
                            boxShadow: `0 0 32px ${color.accent}66`,
                            fontFamily: font.body,
                            fontWeight: 600,
                            fontSize: 22,
                            color: color.marble.paper,
                            scale: String(pill.scale),
                            opacity: pill.opacity,
                        }}
                    >
                        Tomorrow 2:00 pm — booked
                    </div>
                </div>
            </div>
        </GlassCard>
    );
};

/** 3 — Smart calendar: stylist lanes populate into one master view. */
export const CalendarVignette: React.FC<{t: number}> = ({t}) => {
    const lanes = ['MR', 'JD', 'AK', 'TS'];
    // [lane, rowStart, rowSpan, popIndex] — eighth-note arrivals across lanes.
    const blocks: Array<[number, number, number, number]> = [
        [0, 0, 2, 0], [2, 1, 2, 1], [1, 0, 1, 2], [3, 2, 2, 3],
        [1, 2, 2, 4], [0, 3, 1, 5], [3, 0, 1, 6], [2, 4, 1, 7],
    ];
    const grid = pop(t, 0, 1.05);
    const sweep = interpolate(t, [BEAT * 1.5, BEAT * 4], [0, 100], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
    const a = lit(color.accent);
    return (
        <GlassCard w={CARD_W} h={CARD_H}>
            <div style={{position: 'absolute', inset: 0, padding: PAD, display: 'flex', flexDirection: 'column', gap: 28}}>
                <Header icon={CalendarDays} eyebrow="Smart scheduling" title="One master calendar" />
                <div style={{flex: 1, position: 'relative', opacity: grid.opacity, scale: String(grid.scale)}}>
                    <div style={{position: 'absolute', inset: 0, display: 'flex', gap: 18}}>
                        {lanes.map((who, lane) => (
                            <div key={who} style={{flex: 1, display: 'flex', flexDirection: 'column', gap: 12}}>
                                <div
                                    style={{
                                        alignSelf: 'center',
                                        width: 44,
                                        height: 44,
                                        borderRadius: '50%',
                                        display: 'flex',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                        background: 'rgba(255,248,239,0.09)',
                                        border: '1px solid rgba(255,248,239,0.2)',
                                        fontFamily: font.body,
                                        fontWeight: 600,
                                        fontSize: 17,
                                        color: 'rgba(255,248,239,0.85)',
                                    }}
                                >
                                    {who}
                                </div>
                                <div
                                    style={{
                                        flex: 1,
                                        position: 'relative',
                                        borderRadius: 14,
                                        border: '1px solid rgba(255,248,239,0.1)',
                                        backgroundImage: 'repeating-linear-gradient(0deg, rgba(255,248,239,0.06) 0 1px, transparent 1px 20%)',
                                    }}
                                >
                                    {blocks
                                        .filter(([l]) => l === lane)
                                        .map(([, start, span, idx]) => {
                                            const b = pop(t, BEAT * 0.75 + idx * (BEAT / 3), 1.25);
                                            const strong = idx % 3 === 0;
                                            return (
                                                <div
                                                    key={idx}
                                                    style={{
                                                        position: 'absolute',
                                                        left: 7,
                                                        right: 7,
                                                        top: `${start * 20 + 2}%`,
                                                        height: `${span * 20 - 4}%`,
                                                        borderRadius: 10,
                                                        background: strong ? `${color.accent}cc` : `${color.accent}55`,
                                                        border: `1px solid ${a}${strong ? 'cc' : '55'}`,
                                                        boxShadow: strong ? `0 0 22px ${color.accent}55` : 'none',
                                                        scale: String(b.scale),
                                                        opacity: b.opacity,
                                                    }}
                                                />
                                            );
                                        })}
                                </div>
                            </div>
                        ))}
                    </div>
                    {/* The "now" line sweeping the day. */}
                    {sweep > 0 && sweep < 100 && (
                        <div
                            style={{
                                position: 'absolute',
                                left: 0,
                                right: 0,
                                top: `${14 + sweep * 0.8}%`,
                                height: 2,
                                background: `linear-gradient(90deg, transparent, ${a} 18%, ${a} 82%, transparent)`,
                                boxShadow: `0 0 14px ${color.accent}`,
                                opacity: 0.9,
                            }}
                        />
                    )}
                </div>
            </div>
        </GlassCard>
    );
};

/** 4 — Client management: the constellation resolves into one guest. */
export const ClientsVignette: React.FC<{t: number}> = ({t}) => {
    const chips: Array<{x: number; y: number; who: string}> = [
        {x: 80, y: 46, who: 'SM'}, {x: 250, y: 120, who: 'LB'}, {x: 130, y: 226, who: 'KA'},
        {x: 330, y: 26, who: 'RD'}, {x: 396, y: 210, who: 'JP'}, {x: 26, y: 140, who: 'TW'}, {x: 240, y: 268, who: 'NF'},
    ];
    const links: Array<[number, number]> = [[0, 1], [0, 2], [0, 5], [1, 3], [1, 4], [2, 6], [1, 6]];
    const profile = pop(t, BEAT * 2.25, 1.16);
    const a = lit(color.accent);
    const dots = 8;
    return (
        <GlassCard w={CARD_W} h={CARD_H}>
            <div style={{position: 'absolute', inset: 0, padding: PAD, display: 'flex', flexDirection: 'column', gap: 20}}>
                <Header icon={Users} eyebrow="Client management" title="Know every guest" />
                <div style={{flex: 1, position: 'relative'}}>
                    <svg style={{position: 'absolute', left: 0, top: 0, overflow: 'visible'}} width={460} height={300}>
                        {links.map(([f, g], i) => {
                            const draw = interpolate(t, [BEAT * 1 + i * 2.4, BEAT * 2 + i * 2.4], [0, 1], {
                                extrapolateLeft: 'clamp',
                                extrapolateRight: 'clamp',
                            });
                            const A = chips[f];
                            const B = chips[g];
                            return (
                                <line
                                    key={i}
                                    x1={A.x + 28}
                                    y1={A.y + 28}
                                    x2={A.x + 28 + (B.x - A.x) * draw}
                                    y2={A.y + 28 + (B.y - A.y) * draw}
                                    stroke={a}
                                    strokeWidth={1.4}
                                    opacity={0.45}
                                />
                            );
                        })}
                    </svg>
                    {chips.map((c, i) => {
                        const p = pop(t, i * (BEAT / 3), 1.3);
                        const isHero = i === 0;
                        return (
                            <div
                                key={c.who}
                                style={{
                                    position: 'absolute',
                                    left: c.x,
                                    top: c.y,
                                    width: 56,
                                    height: 56,
                                    borderRadius: '50%',
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    background: isHero ? color.accent : 'rgba(255,248,239,0.1)',
                                    border: `1.5px solid ${isHero ? a : 'rgba(255,248,239,0.28)'}`,
                                    boxShadow: isHero ? `0 0 34px ${color.accent}77` : 'none',
                                    fontFamily: font.body,
                                    fontWeight: 600,
                                    fontSize: 19,
                                    color: color.marble.paper,
                                    scale: String(p.scale + (isHero ? kick(t, BEAT * 2.25, 0.14) : 0)),
                                    opacity: p.opacity,
                                }}
                            >
                                {c.who}
                            </div>
                        );
                    })}
                    {/* Sofia's profile — the constellation resolves into a person. */}
                    <div
                        style={{
                            position: 'absolute',
                            right: 0,
                            top: 18,
                            width: 236,
                            borderRadius: 22,
                            padding: '26px 28px',
                            background: 'linear-gradient(165deg, rgba(255,248,239,0.14), rgba(255,248,239,0.05))',
                            border: `1.5px solid ${a}55`,
                            boxShadow: `0 18px 50px rgba(0,0,0,0.4), 0 0 40px ${color.accent}22`,
                            scale: String(profile.scale),
                            opacity: profile.opacity,
                        }}
                    >
                        <div style={{...type.heading, fontSize: 31, color: color.marble.paper}}>Sofia M.</div>
                        <div style={{...rowSub, marginTop: 6}}>12 visits · balayage regular</div>
                        <div style={{display: 'flex', gap: 7, marginTop: 20}}>
                            {Array.from({length: dots}, (_, i) => {
                                const on = t >= BEAT * 2.75 + i * (BEAT / 8);
                                return (
                                    <div
                                        key={i}
                                        style={{
                                            width: 15,
                                            height: 15,
                                            borderRadius: '50%',
                                            background: on ? (i === dots - 1 ? color.marble.butter : color.accent) : 'rgba(255,248,239,0.12)',
                                            border: `1px solid ${on ? a : 'rgba(255,248,239,0.2)'}`,
                                        }}
                                    />
                                );
                            })}
                        </div>
                        <div style={{...type.overline, fontSize: 14, color: 'rgba(255,248,239,0.5)', marginTop: 10}}>Visit history</div>
                    </div>
                </div>
            </div>
        </GlassCard>
    );
};

const BUILD_W = 880;
const BUILD_H = 540;

/** 5 — Chair & booth rental: the team-model tags snap in. The differentiator. */
export const RentalVignette: React.FC<{t: number}> = ({t}) => {
    const tags = ['Employee', 'Booth rental', 'Mix'];
    return (
        <GlassCard w={BUILD_W} h={BUILD_H} glow={1.3}>
            <div style={{position: 'absolute', inset: 0, padding: 56, display: 'flex', flexDirection: 'column', gap: 48}}>
                <Header icon={Armchair} eyebrow="Chair & booth rental" title="Run any team model" />
                <div style={{display: 'flex', gap: 24, flexWrap: 'wrap'}}>
                    {tags.map((tag, i) => {
                        const p = pop(t, i * (BEAT / 2), 1.3);
                        const isMix = i === 2;
                        return (
                            <div
                                key={tag}
                                style={{
                                    padding: '18px 42px',
                                    borderRadius: 99,
                                    fontFamily: font.body,
                                    fontWeight: 600,
                                    fontSize: 34,
                                    color: color.marble.paper,
                                    background: isMix ? color.accent : 'rgba(255,248,239,0.09)',
                                    border: `1.5px solid ${isMix ? lit(color.accent) : 'rgba(255,248,239,0.24)'}`,
                                    boxShadow: isMix ? `0 0 40px ${color.accent}77` : 'none',
                                    scale: String(p.scale),
                                    opacity: p.opacity,
                                }}
                            >
                                {tag}
                            </div>
                        );
                    })}
                </div>
                <div style={{...rowSub, fontSize: 26}}>Commission staff and renters — one roof, one calendar.</div>
            </div>
        </GlassCard>
    );
};

/** 6 — Reminders: the bell pings, the no-show flips to filled. */
export const RemindersVignette: React.FC<{t: number}> = ({t}) => {
    const flipAt = BEAT * 1;
    const flip = interpolate(t, [flipAt, flipAt + 7], [0, 180], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
        easing: Easing.bezier(0.6, 0, 0.3, 1),
    });
    const showFilled = flip >= 90;
    const a = lit(color.accent);
    return (
        <GlassCard w={BUILD_W} h={BUILD_H} glow={1 + kick(t, 0, 1.4)}>
            <div style={{position: 'absolute', inset: 0, padding: 56, display: 'flex', flexDirection: 'column', gap: 46}}>
                <Header icon={BellRing} eyebrow="Reminders" title="No-shows, filled" />
                {/* The reminder pings THROUGH the appointment — rings burst from the card as it flips. */}
                <div style={{position: 'relative', width: 620, height: 130, alignSelf: 'center'}}>
                    {[0, 1].map((r) => {
                        const ring = interpolate(t, [r * 5, 18 + r * 5], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
                        return (
                            <div
                                key={r}
                                style={{
                                    position: 'absolute',
                                    inset: 0,
                                    borderRadius: 24,
                                    border: `2px solid ${a}`,
                                    scale: String(1 + ring * 0.35),
                                    opacity: (1 - ring) * 0.7,
                                }}
                            />
                        );
                    })}
                    <div style={{position: 'absolute', inset: 0, perspective: 900}}>
                        <div
                            style={{
                                width: '100%',
                                height: '100%',
                                borderRadius: 24,
                                display: 'flex',
                                flexDirection: 'column',
                                justifyContent: 'center',
                                paddingLeft: 40,
                                gap: 5,
                                transform: `rotateX(${flip}deg)`,
                                background: showFilled ? `${color.accent}dd` : 'rgba(255,248,239,0.08)',
                                border: `1.5px solid ${showFilled ? a : 'rgba(255,248,239,0.2)'}`,
                                boxShadow: showFilled ? `0 0 44px ${color.accent}66` : 'none',
                            }}
                        >
                            {/* Both faces counter-rotate so the text never mirrors. */}
                            <div style={{transform: showFilled ? 'rotateX(180deg)' : undefined}}>
                                <div style={{...rowText, fontSize: 34}}>{showFilled ? 'Filled from waitlist' : '2:00 pm — no-show risk'}</div>
                                <div style={{...rowSub, fontSize: 23}}>{showFilled ? 'Auto-reminder → rebooked' : 'No reply to reminder'}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </GlassCard>
    );
};

/** 7 — Reports: bars grow, the line draws, one confident number lands. */
export const ReportsVignette: React.FC<{t: number}> = ({t}) => {
    const bars = [0.42, 0.68, 0.5, 0.84, 1];
    const line = interpolate(t, [BEAT * 1, BEAT * 2.2], [0, 1], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
        easing: Easing.bezier(0.4, 0, 0.2, 1),
    });
    const figure = Math.round(
        interpolate(t, [BEAT * 0.5, BEAT * 2], [3100, 8420], {
            extrapolateLeft: 'clamp',
            extrapolateRight: 'clamp',
            easing: Easing.bezier(0.3, 0, 0.25, 1),
        }),
    );
    const numPop = pop(t, BEAT * 2, 1.12);
    const a = lit(color.accent);
    return (
        <GlassCard w={BUILD_W} h={BUILD_H}>
            <div style={{position: 'absolute', inset: 0, padding: 56, display: 'flex', flexDirection: 'column', gap: 34}}>
                <Header icon={ChartColumn} eyebrow="Reports & revenue" title="Know your week" />
                <div style={{display: 'flex', alignItems: 'flex-end', gap: 54, flex: 1}}>
                    <div style={{position: 'relative', display: 'flex', alignItems: 'flex-end', gap: 20, height: 230}}>
                        {bars.map((h, i) => {
                            const grow = interpolate(t, [i * 3, 12 + i * 3], [0, h], {
                                extrapolateLeft: 'clamp',
                                extrapolateRight: 'clamp',
                                easing: Easing.bezier(0.2, 0.9, 0.3, 1),
                            });
                            return (
                                <div
                                    key={i}
                                    style={{
                                        width: 54,
                                        height: `${grow * 100}%`,
                                        borderRadius: 10,
                                        background: i === bars.length - 1 ? `${color.accent}dd` : `${color.accent}66`,
                                        border: `1px solid ${a}66`,
                                        boxShadow: i === bars.length - 1 ? `0 0 26px ${color.accent}55` : 'none',
                                    }}
                                />
                            );
                        })}
                        <svg style={{position: 'absolute', inset: 0, overflow: 'visible'}} width={350} height={230}>
                            <path
                                d="M 27 157 L 101 111 L 175 131 L 249 63 L 323 27"
                                fill="none"
                                stroke={color.marble.paper}
                                strokeWidth={2.5}
                                strokeLinecap="round"
                                strokeDasharray={400}
                                strokeDashoffset={400 - line * 400}
                                opacity={line > 0 ? 0.9 : 0}
                            />
                        </svg>
                    </div>
                    <div style={{scale: String(numPop.scale), opacity: t >= BEAT * 0.5 ? 1 : 0}}>
                        <div style={{...type.display, fontSize: 90, color: color.marble.butter}}>
                            ${figure.toLocaleString('en-US')}
                        </div>
                        <div style={{...type.overline, fontSize: 19, color: 'rgba(255,248,239,0.6)', marginTop: 8}}>This week</div>
                    </div>
                </div>
            </div>
        </GlassCard>
    );
};

/** Compact face for the drop constellation — icon + name only. The accent
 *  rim is deliberately loud here: the recolor IS the story. */
export const MiniCard: React.FC<{icon: LucideIcon; label: string; accent: string; light?: boolean}> = ({icon, label, accent, light = false}) => (
    <GlassCard
        w={330}
        h={224}
        accent={accent}
        light={light}
        radius={24}
        glow={1.8}
        style={light ? undefined : {border: `1.5px solid ${lit(accent)}88`}}
    >
        <div style={{position: 'absolute', inset: 0, padding: 32, display: 'flex', flexDirection: 'column', gap: 22, alignItems: 'flex-start'}}>
            <IconBadge icon={icon} size={72} accent={accent} light={light} />
            <div style={{...type.heading, fontSize: 31, color: light ? color.ink : color.marble.paper}}>{label}</div>
        </div>
    </GlassCard>
);
