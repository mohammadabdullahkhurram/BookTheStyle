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
import {paletteFor, usePalette, type Palette} from './mode';

/**
 * The seven capability vignettes — each acts out its feature on the beat
 * grid, and each now has its OWN form language (one film, seven treatments):
 *
 *   booking    a stagger-stacked column of separate chips assembling
 *   voice AI   an organic orb + equalizer, no rectangle at all
 *   calendar   a flat blueprint panel, crisp hairline structure
 *   clients    a free-floating constellation on a soft bloom
 *   rental     three big sharp-cornered tags, mechanical snaps
 *   reminders  a circular dial radiating rings
 *   reports    a tilted paper report sheet
 *
 * All internal timing is unchanged: a local frame `t` counted from the
 * card's arrival beat; hits land on whole/half beats of the track. Palette
 * discipline holds (cream + ink + plum; emphasis reserved for revenue).
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

const rowText = (p: Palette): React.CSSProperties => ({fontFamily: font.body, fontWeight: 500, fontSize: 27, color: p.fg});
const rowSub = (p: Palette): React.CSSProperties => ({fontFamily: font.body, fontWeight: 400, fontSize: 21, color: p.sub});

/** A bespoke solid panel — near-opaque surface, no backdrop sampling. */
const slab = (p: Palette, radius = 18): React.CSSProperties => ({
    background: p.panelBg,
    border: `1px solid ${p.panelBorder}`,
    borderRadius: radius,
    boxShadow: p.panelShadow,
});

/** Free-floating header — no panel behind it; each vignette places it. */
const FloatHeader: React.FC<{icon: LucideIcon; eyebrow: string; title: string; size?: number}> = ({icon, eyebrow, title, size = 46}) => (
    <div style={{display: 'flex', alignItems: 'center', gap: 24}}>
        <IconBadge icon={icon} size={76} />
        <CardTitle eyebrow={eyebrow} title={title} size={size} />
    </div>
);

/** 1 — Online Booking: the booking ASSEMBLES as separate chips, stagger-
 *  stacked with real depth, confirmed ON the accent hit. */
export const BookingVignette: React.FC<{t: number}> = ({t}) => {
    const p = usePalette();
    const service = pop(t, BEAT * 1);
    const stylist = pop(t, BEAT * 2);
    const chipsAt = BEAT * 2.5;
    const confirmAt = BEAT * 4; // beat 12 — the 6.34s 0.97-energy accent hit
    const confirm = pop(t, confirmAt, 1.35);
    const ring = interpolate(t - confirmAt, [0, 14], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
    const slots = ['1:30', '2:00', '2:30', '3:00'];
    const a = p.accentText(color.accent);
    const drop = (arr: {opacity: number}, px = 26) => interpolate(arr.opacity, [0, 1], [-px, 0]);
    return (
        <div style={{width: 920, height: 640, position: 'relative'}}>
            <div style={{position: 'absolute', left: 24, top: 0}}>
                <FloatHeader icon={CalendarDays} eyebrow="Online booking" title="Booked in seconds" />
            </div>
            {/* The service chip — its own floating slab. */}
            <div
                style={{
                    position: 'absolute',
                    left: 24,
                    top: 150,
                    width: 470,
                    padding: '22px 30px',
                    display: 'flex',
                    alignItems: 'center',
                    gap: 20,
                    ...slab(p),
                    opacity: service.opacity,
                    scale: String(service.scale),
                    translate: `0 ${drop(service)}px`,
                }}
            >
                <IconBadge icon={Scissors} size={54} />
                <div>
                    <div style={rowText(p)}>Balayage & tone</div>
                    <div style={rowSub(p)}>90 min</div>
                </div>
            </div>
            {/* The stylist chip — offset right, deeper. */}
            <div
                style={{
                    position: 'absolute',
                    left: 96,
                    top: 288,
                    width: 470,
                    padding: '22px 30px',
                    display: 'flex',
                    alignItems: 'center',
                    gap: 20,
                    ...slab(p),
                    opacity: stylist.opacity,
                    scale: String(stylist.scale),
                    translate: `0 ${drop(stylist)}px`,
                }}
            >
                <div
                    style={{
                        width: 54,
                        height: 54,
                        borderRadius: '50%',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        background: `${color.accent}33`,
                        border: `1.5px solid ${a}88`,
                        fontFamily: font.body,
                        fontWeight: 600,
                        fontSize: 20,
                        color: p.fg,
                    }}
                >
                    MR
                </div>
                <div>
                    <div style={rowText(p)}>Maya Rivera</div>
                    <div style={rowSub(p)}>Senior stylist</div>
                </div>
            </div>
            {/* The slot row — a third depth plane. */}
            <div style={{position: 'absolute', left: 48, top: 428, display: 'flex', gap: 16}}>
                {slots.map((s, i) => {
                    const c = pop(t, chipsAt + i * (BEAT / 4), 1.18);
                    const selected = i === 1 && t >= confirmAt - BEAT / 2;
                    return (
                        <div
                            key={s}
                            style={{
                                padding: '14px 30px',
                                fontFamily: font.body,
                                fontWeight: 600,
                                fontSize: 24,
                                color: selected ? color.marble.paper : p.fg,
                                ...slab(p, 99),
                                ...(selected
                                    ? {background: color.accent, border: `1.5px solid ${a}`, boxShadow: p.accentGlow(color.accent, 30)}
                                    : {}),
                                scale: String(c.scale + (selected ? kick(t, confirmAt - BEAT / 2, 0.12) : 0)),
                                opacity: c.opacity,
                            }}
                        >
                            {s}
                        </div>
                    );
                })}
            </div>
            {/* Confirmation — its own accent slab slamming in bottom-right. */}
            {t >= confirmAt && (
                <div
                    style={{
                        position: 'absolute',
                        right: 30,
                        top: 520,
                        display: 'flex',
                        alignItems: 'center',
                        gap: 20,
                        padding: '18px 34px',
                        borderRadius: 99,
                        background: color.accent,
                        border: `1.5px solid ${a}`,
                        boxShadow: p.accentGlow(color.accent, 44, 'aa'),
                        scale: String(confirm.scale),
                        opacity: confirm.opacity,
                    }}
                >
                    <div style={{position: 'relative', width: 44, height: 44}}>
                        <div
                            style={{
                                position: 'absolute',
                                inset: 0,
                                borderRadius: '50%',
                                border: '2px solid rgba(255,255,255,0.9)',
                                scale: String(1 + ring * 1.4),
                                opacity: 1 - ring,
                            }}
                        />
                        <div style={{position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center'}}>
                            <Check size={36} color="#FFFFFF" strokeWidth={2.6} />
                        </div>
                    </div>
                    <div style={{...type.heading, fontSize: 36, color: '#FFFFFF'}}>Confirmed</div>
                </div>
            )}
        </div>
    );
};

/** 2 — Voice AI: fully organic — a breathing orb with an equalizer, no
 *  rectangle anywhere. The call commits to the calendar by light line. */
export const VoiceVignette: React.FC<{t: number}> = ({t}) => {
    const p = usePalette();
    const orbKick = [0, 1, 2, 3, 4, 5].reduce((acc, n) => acc + kick(t, BEAT * n, 0.09), 0);
    const lineDraw = interpolate(t, [BEAT * 1.25, BEAT * 2.25], [0, 1], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
        easing: Easing.bezier(0.5, 0, 0.2, 1),
    });
    const nodeLit = t >= BEAT * 2.25;
    const node = pop(t, BEAT * 2.25, 1.2);
    const pill = pop(t, BEAT * 3, 1.16);
    const a = p.accentText(color.accent);
    return (
        <div style={{width: 920, height: 640, position: 'relative'}}>
            {/* Soft bloom — the vignette's only "surface". */}
            <div
                style={{
                    position: 'absolute',
                    left: 110,
                    top: 90,
                    width: 560,
                    height: 560,
                    borderRadius: '50%',
                    background: `radial-gradient(circle, ${color.accent}1f 0%, ${color.accent}00 68%)`,
                }}
            />
            <div style={{position: 'absolute', left: 24, top: 0}}>
                <FloatHeader icon={AudioLines} eyebrow="Voice AI receptionist" title="Answers every call" />
            </div>
            {/* The orb — breathing with the track. */}
            <div style={{position: 'absolute', left: 210, top: 300, width: 180, height: 180}}>
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
                        width: 180,
                        height: 180,
                        borderRadius: '50%',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        background: `radial-gradient(circle at 34% 30%, ${a}55 0%, ${color.accent}99 45%, ${color.accent}55 100%)`,
                        border: `1.5px solid ${a}88`,
                        boxShadow: p.accentGlow(color.accent, 60 + orbKick * 300, '88'),
                        scale: String(1 + orbKick),
                    }}
                >
                    <PhoneCall size={64} color={color.marble.paper} strokeWidth={1.7} />
                </div>
            </div>
            {/* Equalizer — the voice made visible, dancing on the kicks. */}
            <div style={{position: 'absolute', left: 440, top: 330, display: 'flex', alignItems: 'center', gap: 10, height: 120}}>
                {[0, 1, 2, 3, 4].map((i) => {
                    const h = 26 + Math.abs(Math.sin(t * 0.32 + i * 1.4)) * 54 + orbKick * 260;
                    return (
                        <div
                            key={i}
                            style={{
                                width: 10,
                                height: Math.min(h, 120),
                                borderRadius: 6,
                                background: a,
                                opacity: 0.85,
                            }}
                        />
                    );
                })}
            </div>
            {/* Voice → booking: the light line commits the call to the calendar. */}
            <svg style={{position: 'absolute', inset: 0, width: '100%', height: '100%', overflow: 'visible'}} viewBox="0 0 920 640">
                <path
                    d="M 400 380 C 540 250, 640 240, 740 300"
                    fill="none"
                    stroke={a}
                    strokeWidth={2.5}
                    strokeLinecap="round"
                    strokeDasharray={430}
                    strokeDashoffset={430 - lineDraw * 430}
                    opacity={lineDraw > 0 ? 0.9 : 0}
                    style={{filter: `drop-shadow(0 0 8px ${color.accent}66)`}}
                />
            </svg>
            <div style={{position: 'absolute', left: 700, top: 250, scale: String(node.scale), opacity: node.opacity}}>
                <div
                    style={{
                        width: 112,
                        height: 112,
                        borderRadius: 28,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        ...(nodeLit
                            ? {background: `${color.accent}55`, border: `1.5px solid ${a}`, boxShadow: p.accentGlow(color.accent, 50, '77')}
                            : slab(p, 28)),
                    }}
                >
                    <CalendarDays size={52} color={nodeLit ? p.fg : p.faint} strokeWidth={1.7} />
                </div>
            </div>
            <div
                style={{
                    position: 'absolute',
                    left: 560,
                    top: 470,
                    padding: '14px 28px',
                    borderRadius: 99,
                    background: color.accent,
                    border: `1.5px solid ${a}`,
                    boxShadow: p.accentGlow(color.accent, 32),
                    fontFamily: font.body,
                    fontWeight: 600,
                    fontSize: 23,
                    color: color.marble.paper,
                    whiteSpace: 'nowrap',
                    scale: String(pill.scale),
                    opacity: pill.opacity,
                }}
            >
                Tomorrow 2:00 pm — booked
            </div>
        </div>
    );
};

/** 3 — Smart calendar: a flat BLUEPRINT panel — crisp hairline structure
 *  materializing lane by lane, one master view. */
export const CalendarVignette: React.FC<{t: number}> = ({t}) => {
    const p = usePalette();
    const lanes = ['MR', 'JD', 'AK', 'TS'];
    // [lane, rowStart, rowSpan, popIndex] — arrivals across lanes.
    const blocks: Array<[number, number, number, number]> = [
        [0, 0, 2, 0], [2, 1, 2, 1], [1, 0, 1, 2], [3, 2, 2, 3],
        [1, 2, 2, 4], [0, 3, 1, 5], [3, 0, 1, 6], [2, 4, 1, 7],
    ];
    const grid = pop(t, 0, 1.05);
    const sweep = interpolate(t, [BEAT * 1.5, BEAT * 4], [0, 100], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
    const a = p.accentText(color.accent);
    return (
        <div
            style={{
                width: 960,
                height: 580,
                position: 'relative',
                padding: '38px 44px',
                ...slab(p, 14),
                opacity: grid.opacity,
                scale: String(grid.scale),
            }}
        >
            {/* Compact in-panel header — the blueprint's title block. */}
            <div style={{display: 'flex', alignItems: 'center', gap: 18, marginBottom: 26}}>
                <IconBadge icon={CalendarDays} size={56} />
                <div style={{display: 'flex', alignItems: 'baseline', gap: 18}}>
                    <div style={{...type.heading, fontSize: 40, color: p.fg}}>One master calendar</div>
                    <div style={{...type.overline, fontSize: 16, color: a}}>Smart scheduling</div>
                </div>
            </div>
            <div style={{position: 'relative', height: 380}}>
                <div style={{position: 'absolute', inset: 0, display: 'flex', gap: 20}}>
                    {lanes.map((who, lane) => {
                        const laneIn = pop(t, lane * 3, 1.04);
                        return (
                            <div key={who} style={{flex: 1, display: 'flex', flexDirection: 'column', gap: 12, opacity: laneIn.opacity}}>
                                <div
                                    style={{
                                        alignSelf: 'center',
                                        width: 44,
                                        height: 44,
                                        borderRadius: '50%',
                                        display: 'flex',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                        background: p.chipBg,
                                        border: `1px solid ${p.chipBorder}`,
                                        fontFamily: font.body,
                                        fontWeight: 600,
                                        fontSize: 17,
                                        color: p.fg,
                                    }}
                                >
                                    {who}
                                </div>
                                <div
                                    style={{
                                        flex: 1,
                                        position: 'relative',
                                        borderRadius: 8,
                                        border: `1px solid ${p.line}`,
                                        backgroundImage: `repeating-linear-gradient(0deg, ${p.line} 0 1px, transparent 1px 20%)`,
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
                                                        borderRadius: 6,
                                                        background: strong ? `${color.accent}cc` : `${color.accent}55`,
                                                        border: `1px solid ${a}${strong ? 'cc' : '55'}`,
                                                        boxShadow: strong ? p.accentGlow(color.accent, 22, '55') : 'none',
                                                        scale: String(b.scale),
                                                        opacity: b.opacity,
                                                    }}
                                                />
                                            );
                                        })}
                                </div>
                            </div>
                        );
                    })}
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
                            boxShadow: p.accentGlow(color.accent, 14, 'ff'),
                            opacity: 0.9,
                        }}
                    />
                )}
            </div>
        </div>
    );
};

/** 4 — Client management: a free constellation on a soft bloom — no card at
 *  all; the network resolves into one guest. */
export const ClientsVignette: React.FC<{t: number}> = ({t}) => {
    const p = usePalette();
    const chips: Array<{x: number; y: number; who: string}> = [
        {x: 120, y: 210, who: 'SM'}, {x: 290, y: 284, who: 'LB'}, {x: 170, y: 390, who: 'KA'},
        {x: 370, y: 190, who: 'RD'}, {x: 436, y: 374, who: 'JP'}, {x: 66, y: 304, who: 'TW'}, {x: 280, y: 432, who: 'NF'},
    ];
    const links: Array<[number, number]> = [[0, 1], [0, 2], [0, 5], [1, 3], [1, 4], [2, 6], [1, 6]];
    const profile = pop(t, BEAT * 2.25, 1.16);
    const a = p.accentText(color.accent);
    const dots = 8;
    return (
        <div style={{width: 920, height: 640, position: 'relative'}}>
            <div
                style={{
                    position: 'absolute',
                    left: 20,
                    top: 130,
                    width: 520,
                    height: 440,
                    borderRadius: '50%',
                    background: `radial-gradient(circle, ${color.accent}14 0%, transparent 70%)`,
                }}
            />
            {/* Header floats top-RIGHT here — each vignette owns its frame. */}
            <div style={{position: 'absolute', right: 10, top: 0}}>
                <FloatHeader icon={Users} eyebrow="Client management" title="Know every guest" />
            </div>
            <svg style={{position: 'absolute', left: 0, top: 0, overflow: 'visible'}} width={560} height={520}>
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
                            x1={A.x + 32}
                            y1={A.y + 32}
                            x2={A.x + 32 + (B.x - A.x) * draw}
                            y2={A.y + 32 + (B.y - A.y) * draw}
                            stroke={a}
                            strokeWidth={1.6}
                            opacity={0.5}
                        />
                    );
                })}
            </svg>
            {chips.map((c, i) => {
                const arrive = pop(t, i * (BEAT / 3), 1.3);
                const isHero = i === 0;
                const bob = Math.sin(t * 0.05 + i * 1.3) * 6;
                return (
                    <div
                        key={c.who}
                        style={{
                            position: 'absolute',
                            left: c.x,
                            top: c.y,
                            width: 64,
                            height: 64,
                            borderRadius: '50%',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            background: isHero ? color.accent : p.panelBg,
                            border: `1.5px solid ${isHero ? a : p.panelBorder}`,
                            boxShadow: isHero ? p.accentGlow(color.accent, 34, '77') : p.panelShadow,
                            fontFamily: font.body,
                            fontWeight: 600,
                            fontSize: 21,
                            color: isHero ? color.marble.paper : p.fg,
                            scale: String(arrive.scale + (isHero ? kick(t, BEAT * 2.25, 0.14) : 0)),
                            opacity: arrive.opacity,
                            translate: `0 ${bob}px`,
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
                    right: 40,
                    top: 220,
                    width: 280,
                    borderRadius: 30,
                    padding: '30px 32px',
                    background: p.nestedBg,
                    border: `1.5px solid ${a}55`,
                    boxShadow: p.nestedShadow(color.accent),
                    scale: String(profile.scale),
                    opacity: profile.opacity,
                }}
            >
                <div style={{...type.heading, fontSize: 34, color: p.fg}}>Sofia M.</div>
                <div style={{...rowSub(p), marginTop: 6}}>12 visits · balayage regular</div>
                <div style={{display: 'flex', gap: 8, marginTop: 22}}>
                    {Array.from({length: dots}, (_, i) => {
                        const on = t >= BEAT * 2.75 + i * (BEAT / 8);
                        return (
                            <div
                                key={i}
                                style={{
                                    width: 16,
                                    height: 16,
                                    borderRadius: '50%',
                                    background: on ? (i === dots - 1 ? p.emphasis : color.accent) : p.chipBg,
                                    border: `1px solid ${on ? a : p.chipBorder}`,
                                }}
                            />
                        );
                    })}
                </div>
                <div style={{...type.overline, fontSize: 14, color: p.faint, marginTop: 12}}>Visit history</div>
            </div>
        </div>
    );
};

/** 5 — Chair & booth rental: MECHANICAL — three big sharp-cornered tags
 *  snapping dead-center. The differentiator, played as a hard statement. */
export const RentalVignette: React.FC<{t: number}> = ({t}) => {
    const p = usePalette();
    const tags = ['Employee', 'Booth rental', 'Mix'];
    const a = p.accentText(color.accent);
    return (
        <div style={{width: 920, height: 560, position: 'relative', display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 44, paddingTop: 30}}>
            <FloatHeader icon={Armchair} eyebrow="Chair & booth rental" title="Run any team model" />
            <div style={{display: 'flex', gap: 26}}>
                {tags.map((tag, i) => {
                    const arrive = pop(t, i * (BEAT / 2), 1.3);
                    const isMix = i === 2;
                    return (
                        <div
                            key={tag}
                            style={{
                                padding: '26px 48px',
                                fontFamily: font.body,
                                fontWeight: 600,
                                fontSize: 40,
                                color: isMix ? color.marble.paper : p.fg,
                                ...slab(p, 10),
                                ...(isMix
                                    ? {background: color.accent, border: `1.5px solid ${a}`, boxShadow: p.accentGlow(color.accent, 40, '77')}
                                    : {}),
                                scale: String(arrive.scale),
                                opacity: arrive.opacity,
                            }}
                        >
                            {tag}
                        </div>
                    );
                })}
            </div>
            <div style={{...rowSub(p), fontSize: 27}}>Commission staff and renters — one roof, one calendar.</div>
        </div>
    );
};

/** 6 — Reminders: a circular DIAL radiating rings; the no-show flips to
 *  filled across its chord. */
export const RemindersVignette: React.FC<{t: number}> = ({t}) => {
    const p = usePalette();
    const flipAt = BEAT * 1;
    const flip = interpolate(t, [flipAt, flipAt + 7], [0, 180], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
        easing: Easing.bezier(0.6, 0, 0.3, 1),
    });
    const showFilled = flip >= 90;
    const a = p.accentText(color.accent);
    return (
        <div style={{width: 920, height: 600, position: 'relative'}}>
            <div style={{position: 'absolute', left: 30, top: 40}}>
                <FloatHeader icon={BellRing} eyebrow="Reminders" title="No-shows, filled" size={42} />
            </div>
            {/* The dial — a circular panel, rings radiating past its edge. */}
            <div style={{position: 'absolute', left: 420, top: 110, width: 460, height: 460}}>
                {[0, 1, 2].map((r) => {
                    const ring = interpolate(t, [r * 5, 20 + r * 5], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
                    return (
                        <div
                            key={r}
                            style={{
                                position: 'absolute',
                                inset: 0,
                                borderRadius: '50%',
                                border: `2px solid ${a}`,
                                scale: String(1 + ring * 0.5),
                                opacity: (1 - ring) * 0.6,
                            }}
                        />
                    );
                })}
                <div
                    style={{
                        position: 'absolute',
                        inset: 0,
                        borderRadius: '50%',
                        display: 'flex',
                        flexDirection: 'column',
                        alignItems: 'center',
                        paddingTop: 74,
                        gap: 34,
                        ...slab(p, 999),
                    }}
                >
                    <IconBadge icon={BellRing} size={92} />
                    <div style={{perspective: 900, width: 350, height: 108}}>
                        <div
                            style={{
                                width: '100%',
                                height: '100%',
                                borderRadius: 20,
                                display: 'flex',
                                flexDirection: 'column',
                                alignItems: 'center',
                                justifyContent: 'center',
                                gap: 5,
                                textAlign: 'center',
                                transform: `rotateX(${flip}deg)`,
                                background: showFilled ? `${color.accent}dd` : p.chipBg,
                                border: `1.5px solid ${showFilled ? a : p.chipBorder}`,
                                boxShadow: showFilled ? p.accentGlow(color.accent, 44) : 'none',
                            }}
                        >
                            {/* Both faces counter-rotate so the text never mirrors. */}
                            <div style={{transform: showFilled ? 'rotateX(180deg)' : undefined}}>
                                <div style={{...rowText(p), fontSize: 30, color: showFilled ? color.marble.paper : p.fg}}>{showFilled ? 'Filled from waitlist' : '2:00 pm — no-show risk'}</div>
                                <div style={{...rowSub(p), fontSize: 21, color: showFilled ? 'rgba(255,248,239,0.75)' : p.sub}}>{showFilled ? 'Auto-reminder → rebooked' : 'No reply to reminder'}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};

/** 7 — Reports: a tilted PAPER SHEET — bars grow, the line draws, one
 *  confident number lands. */
export const ReportsVignette: React.FC<{t: number}> = ({t}) => {
    const p = usePalette();
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
    const a = p.accentText(color.accent);
    return (
        <div
            style={{
                width: 680,
                height: 620,
                position: 'relative',
                padding: '42px 48px',
                rotate: '-1.4deg',
                ...slab(p, 8),
                borderTop: `6px solid ${color.accent}`,
            }}
        >
            <div style={{display: 'flex', alignItems: 'center', gap: 16, marginBottom: 10}}>
                <IconBadge icon={ChartColumn} size={54} />
                <div>
                    <div style={{...type.overline, fontSize: 16, color: a}}>Reports & revenue</div>
                    <div style={{...type.heading, fontSize: 40, color: p.fg}}>Know your week</div>
                </div>
            </div>
            <div style={{width: '100%', height: 1, background: p.line, margin: '18px 0 30px'}} />
            <div style={{position: 'relative', display: 'flex', alignItems: 'flex-end', gap: 20, height: 220}}>
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
                                borderRadius: 6,
                                background: i === bars.length - 1 ? `${color.accent}dd` : `${color.accent}66`,
                                border: `1px solid ${a}66`,
                                boxShadow: i === bars.length - 1 ? p.accentGlow(color.accent, 26, '55') : 'none',
                            }}
                        />
                    );
                })}
                <svg style={{position: 'absolute', inset: 0, overflow: 'visible'}} width={350} height={220}>
                    <path
                        d="M 27 150 L 101 106 L 175 125 L 249 60 L 323 26"
                        fill="none"
                        stroke={p.fg}
                        strokeWidth={2.5}
                        strokeLinecap="round"
                        strokeDasharray={400}
                        strokeDashoffset={400 - line * 400}
                        opacity={line > 0 ? 0.9 : 0}
                    />
                </svg>
            </div>
            <div style={{marginTop: 34, scale: String(numPop.scale), opacity: t >= BEAT * 0.5 ? 1 : 0}}>
                <div style={{...type.display, fontSize: 92, color: p.emphasis}}>
                    ${figure.toLocaleString('en-US')}
                </div>
                <div style={{...type.overline, fontSize: 19, color: p.faint, marginTop: 6}}>This week</div>
            </div>
        </div>
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
        style={light ? undefined : {border: `1.5px solid ${paletteFor(false).accentText(accent)}88`}}
    >
        <div style={{position: 'absolute', inset: 0, padding: 32, display: 'flex', flexDirection: 'column', gap: 22, alignItems: 'flex-start'}}>
            <IconBadge icon={icon} size={72} accent={accent} light={light} />
            <div style={{...type.heading, fontSize: 31, color: light ? color.ink : color.marble.paper}}>{label}</div>
        </div>
    </GlassCard>
);
