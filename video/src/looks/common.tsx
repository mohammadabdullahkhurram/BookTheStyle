import React from 'react';
import {Audio, staticFile} from 'remotion';
import beatMap from '../beat-map.json';

/**
 * LOOK SAMPLES — a scratch lab for aesthetic direction tests. Each sample is
 * the SAME ~6.1s slice of the real track (beats 8–19: groove entry, the
 * 6.34s accent hit on sample-beat 4, one transition) so directions are
 * judged on-beat and against each other. Nothing here touches the shipped
 * compositions.
 */

/** The slice starts on beat 8 — frame 129 of the film. */
export const SAMPLE_START_FRAME = beatMap.beat_frames[8];

/** Beats 8..19 → 12 beats ≈ 183 frames. */
export const SAMPLE_DURATION = beatMap.beat_frames[19] - beatMap.beat_frames[8] + 30;

/** Sample-local frame of sample-beat n (0-based from beat 8). */
export const sb = (n: number): number => beatMap.beat_frames[8 + n] - SAMPLE_START_FRAME;

export const SampleAudio: React.FC = () => (
    <Audio src={staticFile('audio/music.mp3')} trimBefore={SAMPLE_START_FRAME} />
);

/** Brand family for the look tests (theme.ts values). */
export const ink = '#211C18';
export const paper = '#F7F4EF';
export const cream = '#FFF8EF';
export const plum = '#824C71';
export const coral = '#BC4A28';
export const butter = '#F7D774';
export const sage = '#5C7458';
