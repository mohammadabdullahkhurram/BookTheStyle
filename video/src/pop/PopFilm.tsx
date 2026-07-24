import {
    Armchair,
    AudioLines,
    BellRing,
    CalendarDays,
    ChartColumn,
    Check,
    Clock,
    PhoneCall,
    Scissors,
    User,
    Users,
    type LucideIcon,
} from 'lucide-react';
import React from 'react';
import {AbsoluteFill, Easing, Img, Sequence, interpolate, staticFile, useCurrentFrame} from 'remotion';
import {SCENES, localBeat, type SceneId} from '../beats';
import {Soundtrack} from '../Soundtrack';
import {font, type} from '../theme';
import {
    Burst,
    Chapter,
    DROP_ACCENTS,
    Slap,
    SlantBlock,
    Wipe,
    arriveAt,
    blade,
    butter,
    coral,
    cream,
    ink,
    paper,
    plum,
    sage,
    slap,
    sticker,
    usePopAspect,
} from './pop';

/**
 * THE POP FILM — the chosen look (sticker slam) built out to the full 33.07s
 * beat grid. Same story, same beats as ever: intro slap → four groove
 * chapters (confirm slam on the 6.34s accent hit) → three build hits → the
 * drop as FULL-BLEED color slams (terracotta/violet/sage on 39/42/45, the
 * ink slam + "Yours." on the 24.64s peak) → sticker-sheet poster outro.
 * Flat 2D kinetic — no camera rig, the cuts and slaps ARE the motion.
 */

const CAPS: Array<{icon: LucideIcon; label: string; bg: string; fg: string}> = [
    {icon: CalendarDays, label: 'Booking', bg: cream, fg: ink},
    {icon: AudioLines, label: 'Voice AI', bg: plum, fg: cream},
    {icon: Clock, label: 'Scheduling', bg: butter, fg: ink},
    {icon: Users, label: 'Clients', bg: sage, fg: cream},
    {icon: Armchair, label: 'Rental', bg: coral, fg: cream},
    {icon: BellRing, label: 'Reminders', bg: cream, fg: ink},
    {icon: ChartColumn, label: 'Reports', bg: plum, fg: cream},
];

/** The wordmark as a sticker: white tile, ink outline, hard shadow. */
const MarkSticker: React.FC<{f: number; at: number; size?: number; wordSize?: number}> = ({f, at, size = 190, wordSize = 40}) => {
    const arrive = arriveAt(f, at);
    if (arrive <= 0) return null;
    return (
        <div
            style={{
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                gap: 30,
                scale: String(interpolate(arrive, [0, 1], [1.6, 1])),
                opacity: Math.min(1, arrive * 2),
            }}
        >
            <div
                style={{
                    width: size,
                    height: size,
                    ...sticker('#FFFFFF', size * 0.24, 5, 12),
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                }}
            >
                <Img src={staticFile('brand/icon-logo.png')} style={{width: '70%', height: '70%', objectFit: 'contain'}} />
            </div>
            <div
                style={{
                    padding: '14px 34px',
                    ...sticker(plum, 14, 4, 8),
                    ...type.overline,
                    fontSize: wordSize,
                    letterSpacing: '0.18em',
                    color: cream,
                }}
            >
                BookTheStyle
            </div>
        </div>
    );
};

/* ------------------------------------------------------------------ intro */

const PopIntro: React.FC = () => {
    const f = useCurrentFrame();
    const aspect = usePopAspect();
    const b = (n: number) => localBeat('intro', n);

    const statement = arriveAt(f, b(4));

    return (
        <AbsoluteFill style={{backgroundColor: cream}}>
            <SlantBlock f={f} at={0} side="left" bg={butter} />
            <SlantBlock f={f} at={3} side="right" bg={paper} deg={10} />

            <AbsoluteFill style={{alignItems: 'center', justifyContent: 'center', gap: 40, paddingBottom: aspect === 'tall' ? 200 : 120}}>
                <MarkSticker f={f} at={b(0)} size={aspect === 'tall' ? 230 : 190} />
                <Burst f={f} at={b(2)} x="50%" y="34%" />
                {statement > 0 && (
                    <div
                        style={{
                            padding: '26px 54px',
                            rotate: '-2deg',
                            ...sticker('#FFFFFF', 26, 5, 12),
                            ...type.display,
                            fontSize: aspect === 'wide' ? 74 : 56,
                            color: ink,
                            textAlign: 'center',
                            maxWidth: aspect === 'wide' ? 1300 : 900,
                            scale: String(interpolate(statement, [0, 1], [1.5, 1])),
                            opacity: Math.min(1, statement * 2),
                        }}
                    >
                        Everything your salon needs.
                    </div>
                )}
                <Slap f={f} at={b(6)} center y="82%" rotate={2} bg={coral} pad="12px 30px">
                    <div style={{...type.overline, fontSize: 24, color: cream}}>No signup calls · no spreadsheets</div>
                </Slap>
            </AbsoluteFill>
        </AbsoluteFill>
    );
};

/* ----------------------------------------------------------------- groove */

const ChapterBooking: React.FC<{start: number}> = ({start}) => {
    const f = useCurrentFrame() + start; // local frames from showcase start
    const aspect = usePopAspect();
    const b = (n: number) => localBeat('showcase', n);
    const slamAt = b(12); // the 6.34s accent hit
    const slammed = f >= slamAt;
    const confirmIn = arriveAt(f, slamAt);
    const colX = aspect === 'wide' ? 160 : '8%';

    return (
        <AbsoluteFill style={{backgroundColor: slammed ? plum : cream}}>
            {!slammed && (
                <>
                    <SlantBlock f={f} at={b(8)} side="right" bg={butter} />
                    <Chapter f={f} at={b(8) + 2} bg={plum} fg={cream} label="Booking" />
                    <Slap f={f} at={b(9)} x={colX} y={aspect === 'tall' ? 460 : 380} rotate={-2} bg={cream}>
                        <Scissors size={44} color={ink} strokeWidth={2.2} />
                        <div style={{fontFamily: font.body, fontWeight: 700, fontSize: 40, color: ink}}>Balayage & tone</div>
                    </Slap>
                    <Slap f={f} at={b(10)} x={aspect === 'wide' ? 300 : '16%'} y={aspect === 'tall' ? 620 : 560} rotate={3} bg={sage}>
                        <User size={44} color={cream} strokeWidth={2.2} />
                        <div style={{fontFamily: font.body, fontWeight: 700, fontSize: 40, color: cream}}>Maya R.</div>
                    </Slap>
                    <Slap f={f} at={b(11)} x={aspect === 'wide' ? 210 : '11%'} y={aspect === 'tall' ? 780 : 740} rotate={-3} bg={coral}>
                        <div style={{fontFamily: font.body, fontWeight: 700, fontSize: 40, color: cream}}>Thu · 2:00 pm</div>
                    </Slap>
                    {aspect === 'wide' && (
                        <Slap f={f} at={b(10) + 6} x={1150} y={430} rotate={4} bg={paper} pad="20px 34px">
                            <div style={{fontFamily: font.display, fontStyle: 'italic', fontWeight: 500, fontSize: 38, color: ink}}>
                                from her phone, at 11pm
                            </div>
                        </Slap>
                    )}
                </>
            )}
            {slammed && (
                <AbsoluteFill style={{alignItems: 'center', justifyContent: 'center', flexDirection: aspect === 'tall' ? 'column' : 'row', gap: 34}}>
                    <div
                        style={{
                            width: 140,
                            height: 140,
                            borderRadius: '50%',
                            ...sticker(butter, 999, 5, 10),
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            scale: String(interpolate(confirmIn, [0, 1], [2, 1])),
                        }}
                    >
                        <Check size={86} color={ink} strokeWidth={3} />
                    </div>
                    <div
                        style={{
                            ...type.display,
                            fontSize: aspect === 'wide' ? 170 : 120,
                            color: cream,
                            scale: String(interpolate(confirmIn, [0, 1], [1.5, 1])),
                            opacity: confirmIn,
                        }}
                    >
                        Confirmed.
                    </div>
                </AbsoluteFill>
            )}
        </AbsoluteFill>
    );
};

const ChapterVoice: React.FC<{start: number}> = ({start}) => {
    const f = useCurrentFrame() + start;
    const aspect = usePopAspect();
    const b = (n: number) => localBeat('showcase', n);
    const zig = interpolate(f, [b(16), b(18)], [0, 1600], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});

    return (
        <AbsoluteFill style={{backgroundColor: cream}}>
            <div
                style={{
                    position: 'absolute',
                    right: aspect === 'wide' ? -260 : '-38%',
                    top: -260,
                    width: 950,
                    height: 950,
                    borderRadius: '50%',
                    background: sage,
                    border: `4px solid ${ink}`,
                    scale: String(interpolate(f, [b(14), b(14) + 8], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: slap})),
                }}
            />
            <Chapter f={f} at={b(14) + 2} bg={coral} fg={cream} label="Voice AI" />
            <Slap f={f} at={b(15)} x={aspect === 'wide' ? 200 : '8%'} y={aspect === 'tall' ? 500 : 430} rotate={2} bg={cream}>
                <PhoneCall size={52} color={ink} strokeWidth={2.2} />
                <div style={{fontFamily: font.body, fontWeight: 700, fontSize: 42, color: ink}}>Ring… answered.</div>
            </Slap>
            <svg style={{position: 'absolute', left: aspect === 'wide' ? 240 : '10%', top: aspect === 'tall' ? 720 : 620, overflow: 'visible'}} width={aspect === 'wide' ? 1000 : 760} height={200}>
                <polyline
                    points="0,100 90,30 180,150 270,40 360,160 450,50 540,140 630,60 720,120 810,80 900,100"
                    fill="none"
                    stroke={ink}
                    strokeWidth={10}
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeDasharray={1600}
                    strokeDashoffset={1600 - zig}
                />
            </svg>
            <Slap f={f} at={b(18)} x={aspect === 'wide' ? 760 : '20%'} y={aspect === 'tall' ? 900 : 800} rotate={-3} bg={plum}>
                <CalendarDays size={46} color={cream} strokeWidth={2.2} />
                <div style={{fontFamily: font.body, fontWeight: 700, fontSize: 40, color: cream}}>Tomorrow 2:00 — booked</div>
            </Slap>
            <Burst f={f} at={b(18) + 4} x={aspect === 'wide' ? '46%' : '50%'} y={aspect === 'tall' ? '66%' : '72%'} />
        </AbsoluteFill>
    );
};

const ChapterCalendar: React.FC<{start: number}> = ({start}) => {
    const f = useCurrentFrame() + start;
    const aspect = usePopAspect();
    const b = (n: number) => localBeat('showcase', n);
    const boardIn = arriveAt(f, b(20) + 3, 8);
    const lanes = 4;
    const blocks: Array<[number, number, number, string]> = [
        [0, 6, 20, plum], [2, 30, 16, coral], [1, 10, 26, sage], [3, 52, 20, plum],
        [1, 44, 18, butter], [0, 34, 14, coral], [3, 14, 16, sage], [2, 56, 18, butter],
    ];
    const boardW = aspect === 'wide' ? 1100 : 900;

    return (
        <AbsoluteFill style={{backgroundColor: cream}}>
            <SlantBlock f={f} at={b(20)} side="right" bg={coral} deg={12} />
            <Chapter f={f} at={b(20) + 2} bg={butter} fg={ink} label="Calendar" />
            {boardIn > 0 && (
                <div
                    style={{
                        position: 'absolute',
                        left: '50%',
                        top: aspect === 'tall' ? '30%' : 300,
                        translate: '-50% 0',
                        rotate: '1.6deg',
                        width: boardW,
                        height: aspect === 'tall' ? 760 : 560,
                        padding: 30,
                        display: 'flex',
                        gap: 22,
                        ...sticker('#FFFFFF', 26, 5, 14),
                        scale: String(interpolate(boardIn, [0, 1], [1.3, 1])),
                        opacity: Math.min(1, boardIn * 2),
                    }}
                >
                    {Array.from({length: lanes}, (_, lane) => (
                        <div key={lane} style={{flex: 1, position: 'relative', borderRadius: 14, border: `3px solid ${ink}22`, background: paper}}>
                            <div
                                style={{
                                    position: 'absolute',
                                    top: -22,
                                    left: '50%',
                                    translate: '-50% 0',
                                    width: 44,
                                    height: 44,
                                    borderRadius: '50%',
                                    ...sticker(['MR', 'JD', 'AK', 'TS'][lane] ? cream : cream, 999, 3, 5),
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    fontFamily: font.body,
                                    fontWeight: 700,
                                    fontSize: 16,
                                    color: ink,
                                }}
                            >
                                {['MR', 'JD', 'AK', 'TS'][lane]}
                            </div>
                            {blocks
                                .filter(([l]) => l === lane)
                                .map(([, topPct, hPct, bg], i) => {
                                    const idx = blocks.findIndex(([l, t]) => l === lane && t === topPct);
                                    const in_ = arriveAt(f, b(21) + idx * 6);
                                    if (in_ <= 0) return null;
                                    return (
                                        <div
                                            key={i}
                                            style={{
                                                position: 'absolute',
                                                left: 10,
                                                right: 10,
                                                top: `${topPct}%`,
                                                height: `${hPct}%`,
                                                ...sticker(bg, 10, 3, 5),
                                                scale: String(interpolate(in_, [0, 1], [1.4, 1])),
                                                opacity: Math.min(1, in_ * 2),
                                            }}
                                        />
                                    );
                                })}
                        </div>
                    ))}
                </div>
            )}
            <Slap f={f} at={b(25)} center y={aspect === 'tall' ? '76%' : 900} rotate={-2} bg={plum} pad="16px 38px">
                <div style={{...type.overline, fontSize: 30, color: cream}}>Every stylist · one master view</div>
            </Slap>
        </AbsoluteFill>
    );
};

const ChapterClients: React.FC<{start: number}> = ({start}) => {
    const f = useCurrentFrame() + start;
    const aspect = usePopAspect();
    const b = (n: number) => localBeat('showcase', n);
    const spots: Array<{x: number; y: number; who: string; bg: string}> = [
        {x: 300, y: 380, who: 'SM', bg: plum}, {x: 560, y: 500, who: 'LB', bg: cream},
        {x: 360, y: 680, who: 'KA', bg: butter}, {x: 700, y: 330, who: 'RD', bg: sage},
        {x: 760, y: 660, who: 'JP', bg: cream},
    ];
    const links: Array<[number, number]> = [[0, 1], [0, 2], [1, 3], [1, 4]];
    const sc = aspect === 'wide' ? 1 : 0.82;

    return (
        <AbsoluteFill style={{backgroundColor: cream}}>
            <div
                style={{
                    position: 'absolute',
                    left: aspect === 'wide' ? -240 : '-36%',
                    bottom: -300,
                    width: 900,
                    height: 900,
                    borderRadius: '50%',
                    background: butter,
                    border: `4px solid ${ink}`,
                    scale: String(interpolate(f, [b(26), b(26) + 8], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: slap})),
                }}
            />
            <Chapter f={f} at={b(26) + 2} bg={sage} fg={cream} label="Clients" />
            <svg style={{position: 'absolute', left: 0, top: 0, overflow: 'visible', scale: String(sc)}} width={1000} height={900}>
                {links.map(([a, z], i) => {
                    const draw = interpolate(f, [b(28) + i * 4, b(29) + i * 4], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
                    const A = spots[a];
                    const B = spots[z];
                    return (
                        <line
                            key={i}
                            x1={A.x + 45}
                            y1={A.y + 45}
                            x2={A.x + 45 + (B.x - A.x) * draw}
                            y2={A.y + 45 + (B.y - A.y) * draw}
                            stroke={ink}
                            strokeWidth={7}
                            strokeLinecap="round"
                        />
                    );
                })}
            </svg>
            {spots.map((s, i) => {
                const in_ = arriveAt(f, b(27) + i * 5);
                if (in_ <= 0) return null;
                return (
                    <div
                        key={s.who}
                        style={{
                            position: 'absolute',
                            left: s.x * sc,
                            top: s.y * sc,
                            width: 90,
                            height: 90,
                            borderRadius: '50%',
                            ...sticker(s.bg, 999, 4, 7),
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            fontFamily: font.body,
                            fontWeight: 700,
                            fontSize: 28,
                            color: s.bg === cream || s.bg === butter ? ink : cream,
                            scale: String(interpolate(in_, [0, 1], [1.5, 1])),
                            opacity: Math.min(1, in_ * 2),
                        }}
                    >
                        {s.who}
                    </div>
                );
            })}
            <Slap f={f} at={b(30)} x={aspect === 'wide' ? 1080 : '30%'} y={aspect === 'tall' ? '64%' : 470} rotate={3} bg={cream} pad="26px 38px" style={{flexDirection: 'column', alignItems: 'flex-start', gap: 8}}>
                <div style={{...type.heading, fontSize: 44, color: ink}}>Sofia M.</div>
                <div style={{fontFamily: font.body, fontWeight: 600, fontSize: 26, color: ink, opacity: 0.7}}>12 visits · balayage regular</div>
                <div style={{display: 'flex', gap: 8, marginTop: 6}}>
                    {Array.from({length: 8}, (_, i) => (
                        <div key={i} style={{width: 17, height: 17, borderRadius: '50%', background: i === 7 ? butter : plum, border: `2.5px solid ${ink}`}} />
                    ))}
                </div>
            </Slap>
            <Burst f={f} at={b(30) + 4} x={aspect === 'wide' ? '62%' : '58%'} y="40%" />
        </AbsoluteFill>
    );
};

const PopGroove: React.FC = () => {
    const frame = useCurrentFrame();
    const b = (n: number) => localBeat('showcase', n);
    const chapters = [
        {at: b(8), until: b(14), El: ChapterBooking},
        {at: b(14), until: b(20), El: ChapterVoice},
        {at: b(20), until: b(26), El: ChapterCalendar},
        {at: b(26), until: b(32), El: ChapterClients},
    ];
    return (
        <AbsoluteFill>
            {chapters.map(({at, until, El}, i) => (
                <Sequence key={i} name={`pop chapter ${i + 1}`} from={at} durationInFrames={until - at}>
                    <El start={at} />
                </Sequence>
            ))}
            {/* Blade wipes on every chapter boundary. */}
            {[b(14), b(20), b(26)].map((at) => (
                <Wipe key={at} f={frame} at={at} />
            ))}
        </AbsoluteFill>
    );
};

/* ------------------------------------------------------------------ build */

const PopBuild: React.FC = () => {
    const f = useCurrentFrame();
    const aspect = usePopAspect();
    const b = (n: number) => localBeat('build', n);
    const cut2 = b(34);
    const cut3 = b(36);
    const phase = f >= cut3 ? 2 : f >= cut2 ? 1 : 0;

    const bars = [0.45, 0.7, 0.55, 1];
    const figure = Math.round(interpolate(f, [cut3 + 4, cut3 + 26], [3100, 8420], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: Easing.bezier(0.3, 0, 0.25, 1)}));

    return (
        <AbsoluteFill style={{backgroundColor: phase === 1 ? butter : cream}}>
            {phase === 0 && (
                <>
                    <SlantBlock f={f} at={0} side="left" bg={plum} deg={16} />
                    <Chapter f={f} at={1} bg={cream} fg={ink} label="Chair rental" size={44} x="34%" y={aspect === 'tall' ? 260 : 180} rotate={-3} />
                    {['Employee', 'Booth rental', 'Mix'].map((tag, i) => (
                        <Slap
                            key={tag}
                            f={f}
                            at={6 + i * 8}
                            center
                            y={(aspect === 'tall' ? 460 : 400) + i * 150}
                            rotate={i % 2 ? 2 : -2}
                            bg={i === 2 ? plum : i === 1 ? butter : cream}
                        >
                            <div style={{fontFamily: font.body, fontWeight: 700, fontSize: 46, color: i === 2 ? cream : ink}}>{tag}</div>
                        </Slap>
                    ))}
                </>
            )}
            {phase === 1 && (
                <>
                    <Chapter f={f} at={cut2 + 1} bg={plum} fg={cream} label="Reminders" size={44} x="34%" y={aspect === 'tall' ? 260 : 180} rotate={3} />
                    {[0, 1].map((r) => {
                        const ring = interpolate(f, [cut2 + 4 + r * 5, cut2 + 22 + r * 5], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
                        if (ring <= 0 || ring >= 1) return null;
                        return (
                            <div
                                key={r}
                                style={{
                                    position: 'absolute',
                                    left: '50%',
                                    top: '52%',
                                    width: 260,
                                    height: 260,
                                    translate: '-50% -50%',
                                    borderRadius: '50%',
                                    border: `8px solid ${ink}`,
                                    scale: String(1 + ring * 2),
                                    opacity: 1 - ring,
                                }}
                            />
                        );
                    })}
                    <Slap f={f} at={cut2 + 3} center y="42%" rotate={-2} bg={cream} pad="28px 34px">
                        <BellRing size={64} color={ink} strokeWidth={2.4} />
                    </Slap>
                    <Slap f={f} at={cut2 + 8} until={cut2 + 20} center y="60%" rotate={2} bg={coral}>
                        <div style={{fontFamily: font.body, fontWeight: 700, fontSize: 40, color: cream}}>2:00 pm — no-show risk</div>
                    </Slap>
                    <Slap f={f} at={cut2 + 20} center y="60%" rotate={-1} bg={sage}>
                        <Check size={44} color={cream} strokeWidth={3} />
                        <div style={{fontFamily: font.body, fontWeight: 700, fontSize: 40, color: cream}}>Filled from waitlist</div>
                    </Slap>
                </>
            )}
            {phase === 2 && (
                <>
                    <SlantBlock f={f} at={cut3} side="right" bg={sage} deg={12} />
                    <Chapter f={f} at={cut3 + 1} bg={butter} fg={ink} label="Reports" size={44} x="34%" y={aspect === 'tall' ? 240 : 160} rotate={-2} />
                    <div style={{position: 'absolute', left: '50%', top: aspect === 'tall' ? '40%' : 380, translate: '-50% 0', display: 'flex', alignItems: 'flex-end', gap: 26, height: 300}}>
                        {bars.map((h, i) => {
                            const grow = interpolate(f, [cut3 + 4 + i * 4, cut3 + 18 + i * 4], [0, h], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: slap});
                            return (
                                <div
                                    key={i}
                                    style={{
                                        width: 92,
                                        height: `${Math.max(0.02, grow) * 100}%`,
                                        ...sticker([plum, coral, butter, sage][i], 14, 4, 7),
                                    }}
                                />
                            );
                        })}
                    </div>
                    <Slap f={f} at={cut3 + 22} center y={aspect === 'tall' ? '68%' : 760} rotate={-2} bg={cream} pad="20px 46px">
                        <div style={{...type.display, fontSize: 100, color: ink}}>${figure.toLocaleString('en-US')}</div>
                        <div style={{...type.overline, fontSize: 22, color: coral, translate: '0 18px'}}>this week</div>
                    </Slap>
                </>
            )}
            <Wipe f={f} at={cut2} />
            <Wipe f={f} at={cut3} />
        </AbsoluteFill>
    );
};

/* ------------------------------------------------------------------- drop */

const PopDrop: React.FC = () => {
    const f = useCurrentFrame();
    const aspect = usePopAspect();
    const lb = (n: number) => localBeat('drop', n);
    const swaps = [0, lb(42), lb(45), lb(48)];
    const peak = lb(48);
    const active = swaps.filter((s) => f >= s).length - 1;
    const bg = DROP_ACCENTS[active];
    const isInk = active === 3;

    const yoursIn = arriveAt(f, peak);
    const ringSize = aspect === 'tall' ? [340, 620] : [420, 760];

    return (
        <AbsoluteFill style={{backgroundColor: bg}}>
            {/* Each swap: a burst + the sticker ring kicks. */}
            {swaps.map((s, i) => (
                <Burst key={i} f={f} at={s + 1} x="50%" y="42%" colors={[cream, butter, '#FFFFFF']} />
            ))}

            {/* The seven capability stickers orbiting — they ride every slam. */}
            {CAPS.map((cap, i) => {
                const angle = (-90 + i * (360 / CAPS.length)) * (Math.PI / 180);
                const [rx, ry] = aspect === 'wide' ? [640, 340] : aspect === 'square' ? [430, 430] : ringSize;
                const kickS = swaps.reduce((acc, s) => acc + (f >= s ? Math.exp(-(f - s) / 5) * 0.14 : 0), 0);
                const in_ = arriveAt(f, i * 2);
                if (in_ <= 0) return null;
                const Icon = cap.icon;
                const stBg = isInk ? cream : cap.bg === bg ? cream : cap.bg;
                const stFg = stBg === cream || stBg === butter ? ink : cream;
                return (
                    <div
                        key={cap.label}
                        style={{
                            position: 'absolute',
                            left: `calc(50% + ${Math.cos(angle) * rx}px)`,
                            top: `calc(${aspect === 'tall' ? '46%' : '44%'} + ${Math.sin(angle) * ry}px)`,
                            translate: '-50% -50%',
                            rotate: `${(i % 2 ? 1 : -1) * 3}deg`,
                            display: 'flex',
                            alignItems: 'center',
                            gap: 14,
                            padding: '16px 28px',
                            ...sticker(stBg, 18, 4, 7),
                            scale: String(interpolate(in_, [0, 1], [1.5, 1]) + kickS),
                            opacity: Math.min(1, in_ * 2),
                        }}
                    >
                        <Icon size={34} color={stFg} strokeWidth={2.4} />
                        <div style={{fontFamily: font.body, fontWeight: 700, fontSize: 28, color: stFg}}>{cap.label}</div>
                    </div>
                );
            })}

            {/* Pre-peak: the wordmark rides the slams. Post-peak: "Yours." */}
            {f < peak ? (
                <AbsoluteFill style={{alignItems: 'center', justifyContent: aspect === 'tall' ? 'center' : 'center', paddingBottom: 100}}>
                    <MarkSticker f={f} at={4} size={aspect === 'tall' ? 210 : 180} />
                </AbsoluteFill>
            ) : (
                <AbsoluteFill style={{alignItems: 'center', justifyContent: 'center', paddingBottom: 120}}>
                    <div
                        style={{
                            ...type.display,
                            fontSize: aspect === 'wide' ? 230 : 170,
                            color: cream,
                            scale: String(interpolate(yoursIn, [0, 1], [1.6, 1])),
                            opacity: yoursIn,
                        }}
                    >
                        Yours.
                    </div>
                </AbsoluteFill>
            )}

            {/* Swatch sticker rail — the live accent, no hex. */}
            <div
                style={{
                    position: 'absolute',
                    left: '50%',
                    translate: '-50% 0',
                    bottom: aspect === 'tall' ? 170 : 90,
                    display: 'flex',
                    alignItems: 'center',
                    gap: 22,
                }}
            >
                {DROP_ACCENTS.map((a, i) => (
                    <div
                        key={a}
                        style={{
                            width: i === active ? 56 : 34,
                            height: i === active ? 56 : 34,
                            borderRadius: '50%',
                            background: a,
                            border: `4px solid ${isInk ? cream : ink}`,
                            boxShadow: i === active ? `6px 6px 0 ${isInk ? 'rgba(255,248,239,0.35)' : ink}` : 'none',
                        }}
                    />
                ))}
            </div>
        </AbsoluteFill>
    );
};

/* ------------------------------------------------------------------ outro */

const PopOutro: React.FC = () => {
    const f = useCurrentFrame();
    const aspect = usePopAspect();
    const lb = (n: number) => localBeat('outro', n);
    const ask = arriveAt(f, lb(54));
    const url = arriveAt(f, lb(56));

    return (
        <AbsoluteFill style={{backgroundColor: cream}}>
            {/* The sticker sheet border — everything the film showed, at rest. */}
            {CAPS.map((cap, i) => {
                const Icon = cap.icon;
                const across = aspect === 'tall' ? 3 : 7;
                const col = i % across;
                const row = Math.floor(i / across);
                const in_ = arriveAt(f, lb(53) + i * 2);
                if (in_ <= 0) return null;
                return (
                    <div
                        key={cap.label}
                        style={{
                            position: 'absolute',
                            left: `${aspect === 'tall' ? 18 + col * 32 : 8 + col * 13}%`,
                            bottom: aspect === 'tall' ? 90 + row * 90 : 60,
                            translate: '-50% 0',
                            rotate: `${(i % 2 ? 1 : -1) * 4}deg`,
                            display: 'flex',
                            alignItems: 'center',
                            gap: 10,
                            padding: '10px 18px',
                            ...sticker(cap.bg, 14, 3, 5),
                            scale: String(interpolate(in_, [0, 1], [1.4, 1])),
                            opacity: Math.min(1, in_ * 2) * 0.95,
                        }}
                    >
                        <Icon size={22} color={cap.fg} strokeWidth={2.4} />
                        <div style={{fontFamily: font.body, fontWeight: 700, fontSize: 19, color: cap.fg}}>{cap.label}</div>
                    </div>
                );
            })}

            <AbsoluteFill style={{alignItems: 'center', justifyContent: 'center', gap: 44, paddingBottom: aspect === 'tall' ? 120 : 100}}>
                <MarkSticker f={f} at={lb(53)} size={aspect === 'tall' ? 200 : 160} wordSize={32} />
                {ask > 0 && (
                    <div
                        style={{
                            ...type.display,
                            fontSize: aspect === 'wide' ? 128 : 96,
                            color: ink,
                            scale: String(interpolate(ask, [0, 1], [1.4, 1])),
                            opacity: ask,
                        }}
                    >
                        Fill every chair.
                    </div>
                )}
                {url > 0 && (
                    <div
                        style={{
                            padding: '16px 40px',
                            rotate: '-1.5deg',
                            ...sticker(plum, 99, 4, 8),
                            ...type.overline,
                            fontSize: 30,
                            color: cream,
                            scale: String(interpolate(url, [0, 1], [1.3, 1])),
                            opacity: url,
                        }}
                    >
                        bookthestyle.com
                    </div>
                )}
            </AbsoluteFill>
        </AbsoluteFill>
    );
};

/* ------------------------------------------------------------------- film */

const COMPONENTS: Record<SceneId, React.ReactNode> = {
    intro: <PopIntro />,
    showcase: <PopGroove />,
    build: <PopBuild />,
    drop: <PopDrop />,
    outro: <PopOutro />,
};

export const PopFilm: React.FC = () => {
    const frame = useCurrentFrame();
    return (
        <AbsoluteFill style={{backgroundColor: cream}}>
            {SCENES.map((s) => (
                <Sequence key={s.id} name={`pop ${s.id} (b${s.fromBeat}–${s.toBeat})`} from={s.startFrame} durationInFrames={s.durationInFrames}>
                    {COMPONENTS[s.id]}
                </Sequence>
            ))}
            {/* Blade wipes stitching the sections. */}
            {SCENES.slice(1, 4).map((s) => (
                <Wipe key={s.id} f={frame} at={s.startFrame} />
            ))}
            <Soundtrack />
        </AbsoluteFill>
    );
};
