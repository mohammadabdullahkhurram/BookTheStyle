/**
 * THE TIMING SHEET — the film's spine. Every scene reads its window from
 * here; nothing hardcodes frame numbers inline. Beats breathe to the
 * narration (video/SCRIPT.md — the single source of truth for the VO), not
 * to type reveals.
 *
 * `provisional: true` = timed against SCRIPT.md word counts at a natural
 * ~2.7 words/sec because no VO read exists yet. When the real read lands in
 * public/audio/voiceover-final.mp3, retime those beats to the actual audio
 * and drop the flag.
 */

export const FPS = 30;

/** 78 seconds — the single total-duration constant. */
export const TOTAL_DURATION_IN_FRAMES = 78 * FPS; // 2340

/** Provisional VO pace used to lay type against narration pre-read. */
export const WORDS_PER_SECOND = 2.7;

export const wordsToFrames = (words: number): number =>
    Math.round((words / WORDS_PER_SECOND) * FPS);

export type BeatId =
    | 'cold-open'
    | 'logo-promise'
    | 'her-side'
    | 'your-side'
    | 'accent-hero'
    | 'proof'
    | 'close';

export type Beat = {
    id: BeatId;
    /** SCRIPT.md section this beat carries. */
    script: string;
    startFrame: number;
    durationInFrames: number;
    /** Manifest keys the scene draws (see src/manifest.ts). */
    assets: string[];
    built: boolean;
    provisional?: boolean;
};

const sec = (s: number) => Math.round(s * FPS);

export const BEATS: Beat[] = [
    {
        id: 'cold-open',
        script: '[0:00–0:14 — Cold open]',
        startFrame: sec(0),
        durationInFrames: sec(14),
        assets: [],
        built: true,
        provisional: true, // 35 words ≈ 13.0s VO + breathing room
    },
    {
        id: 'logo-promise',
        script: '[0:14–0:20 — Logo promise]',
        startFrame: sec(14),
        durationInFrames: sec(6),
        assets: [],
        built: false,
    },
    {
        id: 'her-side',
        script: '[0:20–0:36 — Her side]',
        startFrame: sec(20),
        durationInFrames: sec(16),
        assets: ['widget-motion'], // real screen motion — scripts/capture-launch-assets.mjs --motion
        built: false,
    },
    {
        id: 'your-side',
        script: '[0:36–0:50 — Your side]',
        startFrame: sec(36),
        durationInFrames: sec(14),
        assets: ['owner-dashboard--marble', 'owner-calendar-week', 'owner-client-profile'],
        built: false,
    },
    {
        id: 'accent-hero',
        script: '[0:50–1:02 — Make it yours]',
        startFrame: sec(50),
        durationInFrames: sec(12),
        assets: [
            'owner-dashboard--accent-01',
            'owner-dashboard--accent-02',
            'owner-dashboard--accent-03',
            'owner-dashboard--accent-04',
            'widget-calendar--accent-01',
            'widget-calendar--accent-02',
            'widget-calendar--accent-03',
            'widget-calendar--accent-04',
        ],
        built: true,
        provisional: true, // 22 words ≈ 8.1s VO inside a 12s visual beat
    },
    {
        id: 'proof',
        script: '[1:02–1:12 — Proof]',
        startFrame: sec(62),
        durationInFrames: sec(10),
        assets: ['owner-reports', 'crop-embed-code'],
        built: false,
    },
    {
        id: 'close',
        script: '[1:12–1:18 — Close]',
        startFrame: sec(72),
        durationInFrames: sec(6),
        assets: [],
        built: false,
    },
];

// The sheet must tile the film exactly — no gaps, no overlaps, no drift.
BEATS.reduce((cursor, beat) => {
    if (beat.startFrame !== cursor) {
        throw new Error(`Timing sheet gap/overlap at "${beat.id}": starts ${beat.startFrame}, expected ${cursor}`);
    }
    return beat.startFrame + beat.durationInFrames;
}, 0);

if (BEATS.reduce((sum, b) => sum + b.durationInFrames, 0) !== TOTAL_DURATION_IN_FRAMES) {
    throw new Error('Timing sheet does not sum to TOTAL_DURATION_IN_FRAMES');
}

export const beat = (id: BeatId): Beat => {
    const found = BEATS.find((b) => b.id === id);
    if (!found) {
        throw new Error(`Unknown beat "${id}"`);
    }
    return found;
};
