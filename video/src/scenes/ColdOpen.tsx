import React from 'react';
import {AbsoluteFill, interpolate, spring, useCurrentFrame, useVideoConfig} from 'remotion';
import {voDurationInFrames, voLeadIn} from '../beats';
import {color, font, type} from '../theme';

/**
 * Beat A — Cold open (0:00–0:14). Dark Marble field, kinetic type carrying
 * the missed-booking hook. No product UI. This scene IS the film's
 * typographic voice: Fraunces display on warm darkness, the tracked
 * Hanken overline as the editorial signature, motion that breathes with
 * the narration (enter on the VO phrase, exit ~65% faster than enter —
 * nothing lingers, nothing types itself out).
 *
 * Timing derives from SCRIPT.md word offsets at the provisional VO pace
 * (beats.ts WORDS_PER_SECOND) — retime against the real read when it lands.
 */

type Moment = {
    /** Cumulative word offset in the beat's VO where this line starts/ends. */
    fromWord: number;
    toWord: number;
    overline?: string;
    lines: Array<{text: string; emphasis?: 'butter' | 'coral'}>;
    size?: number;
};

// VO: "It's 2pm on a Tuesday. (5) Your best chair is empty. (10) The call
// came in at 11 last night — (18) after you'd closed, after you'd stopped
// answering. (25) So it went to voicemail. (30) And she booked somewhere
// else. (35)"
const MOMENTS: Moment[] = [
    {fromWord: 0, toWord: 5, overline: 'Tuesday', lines: [{text: '2:00 pm.'}], size: 170},
    {fromWord: 5, toWord: 10, lines: [{text: 'Your best chair'}, {text: 'is empty.', emphasis: 'butter'}], size: 120},
    {fromWord: 10, toWord: 18, overline: 'Last night', lines: [{text: 'The call came'}, {text: 'at 11pm.'}], size: 120},
    {
        fromWord: 18,
        toWord: 25,
        lines: [{text: 'After you’d closed.'}, {text: 'After you’d stopped answering.'}],
        size: 64,
    },
    {fromWord: 25, toWord: 30, lines: [{text: 'Voicemail.'}], size: 170},
    {
        fromWord: 30,
        toWord: 38, // holds through the beat's tail — the gut punch breathes
        lines: [{text: 'She booked'}, {text: 'somewhere else.', emphasis: 'coral'}],
        size: 120,
    },
];

/** VO lead-in + word→frame mapping, scaled to the MEASURED read: the
 *  segment's 36 words span its real duration, so phrase timing tracks the
 *  actual narration instead of a wpm guess. */
const LEAD_IN = voLeadIn('cold-open');
// (The final moment's toWord of 38 > 36 keeps it on screen through the
// beat's hard cut — the Sequence clips it.)
const wordsToFrames = (words: number): number =>
    Math.round((words / 36) * voDurationInFrames('cold-open'));

const EMPHASIS: Record<'butter' | 'coral', string> = {
    butter: color.marble.butter,
    coral: '#E06A43', // marble coral lifted for AA-comfortable contrast on the dark field
};

const KineticLine: React.FC<{moment: Moment}> = ({moment}) => {
    const frame = useCurrentFrame();
    const {fps} = useVideoConfig();

    const from = LEAD_IN + wordsToFrames(moment.fromWord);
    const to = LEAD_IN + wordsToFrames(moment.toWord);
    const local = frame - from;
    const exitLength = 10; // exit faster than enter — motion rules
    const exitStart = to - exitLength;

    if (frame < from - 2 || frame > to + 2) {
        return null;
    }

    const enter = spring({frame: local, fps, config: {damping: 34, stiffness: 110, mass: 0.9}});
    const exit = interpolate(frame, [exitStart, to], [0, 1], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
    });

    // Enter: rise + settle. Hold: a slow, breathing drift upward. Exit: lift away.
    const y = interpolate(enter, [0, 1], [34, 0]) - local * 0.06 - exit * 26;
    const opacity = enter * (1 - exit);

    return (
        <AbsoluteFill style={{justifyContent: 'center', alignItems: 'center'}}>
            <div style={{transform: `translateY(${y}px)`, opacity, textAlign: 'center'}}>
                {moment.overline ? (
                    <div
                        style={{
                            ...type.overline,
                            fontSize: 26,
                            color: color.marble.butter,
                            opacity: 0.82,
                            marginBottom: 34,
                        }}
                    >
                        {moment.overline}
                    </div>
                ) : null}
                {moment.lines.map((line) => (
                    <div
                        key={line.text}
                        style={{
                            ...type.display,
                            fontSize: moment.size ?? 120,
                            color: line.emphasis ? EMPHASIS[line.emphasis] : color.marble.paper,
                        }}
                    >
                        {line.text}
                    </div>
                ))}
            </div>
        </AbsoluteFill>
    );
};

export const ColdOpen: React.FC = () => {
    const frame = useCurrentFrame();

    // The field itself breathes: a barely-perceptible drift of the warm core.
    const drift = Math.sin(frame / 55) * 2.5;

    return (
        <AbsoluteFill style={{backgroundColor: color.sidebarDarkPlum, fontFamily: font.display}}>
            {/* Dark Marble field: warm umber core over plum-black, vignetted. */}
            <AbsoluteFill
                style={{
                    background: `radial-gradient(120% 90% at ${50 + drift}% 42%, ${color.marble.sidebarDark} 0%, ${color.sidebarDarkPlum} 68%, #1B141A 100%)`,
                }}
            />
            {/* A single hairline rule under the type zone — the editorial signature. */}
            <AbsoluteFill style={{justifyContent: 'center', alignItems: 'center'}}>
                <div
                    style={{
                        position: 'absolute',
                        top: '71%',
                        width: 72,
                        height: 1,
                        backgroundColor: color.marble.paper,
                        opacity: 0.22,
                    }}
                />
            </AbsoluteFill>
            {MOMENTS.map((moment) => (
                <KineticLine key={moment.fromWord} moment={moment} />
            ))}
        </AbsoluteFill>
    );
};
