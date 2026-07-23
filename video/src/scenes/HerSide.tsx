import React from 'react';
import {
    AbsoluteFill,
    Freeze,
    OffthreadVideo,
    Sequence,
    interpolate,
    spring,
    useCurrentFrame,
    useVideoConfig,
} from 'remotion';
import {voDurationInFrames, voLeadIn} from '../beats';
import {getAsset} from '../manifest';
import {color, overlayShadow, type} from '../theme';
import {DarkField} from './Brand';

/**
 * Beat — Her side (~0:19–0:35). The REAL booking, as recorded screen motion
 * (scripts/capture-launch-assets.mjs --motion, re-paced to this exact read):
 * the taps land under "She picks the service. / Her stylist. / A time that
 * works." When the recording ends, its own last frame FREEZES (same source,
 * no takeover jump) while "No maybe-I'll-try-later." runs out. Kinetic
 * echoes of the three "No"s ride the left column — the only type this beat
 * needs.
 */

const MOTION_START = 10; // video begins a breath into the beat

const NOES: Array<{text: string; word: number}> = [
    // Word offsets in the 26-word segment where each "No…" begins.
    {text: 'No call.', word: 19},
    {text: 'No wait.', word: 21},
    {text: 'No maybe-I’ll-try-later.', word: 23},
];

export const HerSide: React.FC = () => {
    const frame = useCurrentFrame();
    const {fps} = useVideoConfig();
    const motion = getAsset('widget-motion');

    const voStart = voLeadIn('her-side');
    const voFrames = voDurationInFrames('her-side');
    const at = (word: number) => voStart + Math.round((word / 26) * voFrames);

    const phoneIn = spring({frame, fps, config: {damping: 30, stiffness: 110, mass: 0.9}});
    const motionFrames = Math.round(((motion.duration_ms ?? 12300) / 1000) * fps);
    // Freeze a hair before the measured end — recording duration is wall-clock
    // approximate, and a frozen frame must certainly exist.
    const freezeAt = motionFrames - 12;

    const video = (
        <OffthreadVideo
            src={motion.src}
            muted
            style={{position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover', objectPosition: 'top'}}
        />
    );

    return (
        <AbsoluteFill>
            <DarkField />

            {/* Overline: where and when she is. */}
            <div
                style={{
                    position: 'absolute',
                    left: 150,
                    top: 132,
                    ...type.overline,
                    fontSize: 24,
                    color: color.marble.butter,
                    opacity: interpolate(frame, [14, 34], [0, 0.9], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'}),
                }}
            >
                11:04 pm · from her phone
            </div>

            {/* The three "No"s, echoing the read down the left column. */}
            <div style={{position: 'absolute', left: 150, top: 400, display: 'flex', flexDirection: 'column', gap: 26}}>
                {NOES.map(({text, word}) => {
                    const lineIn = spring({frame: frame - at(word), fps, config: {damping: 30, stiffness: 120}});
                    return (
                        <div
                            key={text}
                            style={{
                                ...type.display,
                                fontSize: 58,
                                color: color.marble.paper,
                                opacity: lineIn,
                                transform: `translateY(${interpolate(lineIn, [0, 1], [22, 0])}px)`,
                            }}
                        >
                            {text}
                        </div>
                    );
                })}
            </div>

            {/* The phone — real recorded motion, then its own frozen last frame. */}
            <div
                style={{
                    position: 'absolute',
                    right: 190,
                    top: '50%',
                    transform: `translateY(-50%) translateY(${interpolate(phoneIn, [0, 1], [60, 0])}px)`,
                    opacity: phoneIn,
                    width: 402,
                    height: 872,
                    borderRadius: 58,
                    padding: 13,
                    backgroundColor: color.sidebarDarkPlum,
                    boxShadow: overlayShadow,
                }}
            >
                <div style={{width: '100%', height: '100%', borderRadius: 47, overflow: 'hidden', position: 'relative'}}>
                    <Sequence from={MOTION_START} durationInFrames={freezeAt} layout="none">
                        {video}
                    </Sequence>
                    <Sequence from={MOTION_START + freezeAt} layout="none">
                        <Freeze frame={freezeAt - 1}>{video}</Freeze>
                    </Sequence>
                </div>
            </div>
        </AbsoluteFill>
    );
};
