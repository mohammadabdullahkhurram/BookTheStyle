/**
 * THE TIMING SHEET — the film's spine. Every scene reads its window from
 * here; nothing hardcodes frame numbers inline. Beats breathe to the
 * narration (video/SCRIPT.md), and since the real read exists the numbers
 * come from src/vo-timing.json — the SINGLE timing source shared with the
 * VO assembler (scripts/generate-vo.mjs --assemble), so the sheet and the
 * audio can never drift apart. Regenerating the VO with different lengths
 * makes the asserts below fail loudly: retime vo-timing.json, re-assemble,
 * done.
 *
 * Timing decisions encoded in vo-timing.json (from the measured read):
 *  - cold-open ends 0.47s after "…somewhere else." — a HARD CUT into the
 *    logo promise (its stolen tail went to the logo beat).
 *  - accent-hero keeps its full 12s recolor; "Not ours." is placed at
 *    57.85s so it lands INSIDE the held near-black variant (~1.8s of
 *    directed silence after "…your salon.").
 *  - close holds ~3.2s of brand after the VO — the poster frame.
 */
import timing from './vo-timing.json';

export const FPS = timing.sheet.fps;

/** 78 seconds — the single total-duration constant. */
export const TOTAL_DURATION_IN_FRAMES = timing.sheet.total_frames;

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
};

const META: Array<{id: BeatId; script: string; assets: string[]}> = [
    {id: 'cold-open', script: '[0:00–0:14 — Cold open]', assets: []},
    {id: 'logo-promise', script: '[0:14–0:20 — Logo promise]', assets: []},
    {id: 'her-side', script: '[0:20–0:36 — Her side]', assets: ['widget-motion', 'widget-08-confirmed']},
    {
        id: 'your-side',
        script: '[0:36–0:50 — Your side]',
        assets: ['owner-dashboard--marble', 'owner-calendar-week', 'owner-client-profile'],
    },
    {
        id: 'accent-hero',
        script: '[0:50–1:02 — Make it yours]',
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
    },
    {id: 'proof', script: '[1:02–1:12 — Proof]', assets: ['crop-appointment-row', 'crop-stat-tile']},
    {id: 'close', script: '[1:12–1:18 — Close]', assets: []},
];

let cursor = 0;
export const BEATS: Beat[] = META.map(({id, script, assets}) => {
    const durationInFrames = timing.sheet.beats[id];
    if (typeof durationInFrames !== 'number') {
        throw new Error(`vo-timing.json sheet has no duration for beat "${id}"`);
    }
    const built: Beat = {id, script, startFrame: cursor, durationInFrames, assets, built: true};
    cursor += durationInFrames;
    return built;
});

// The sheet must tile the film exactly — no gaps, no overlaps, no drift.
if (cursor !== TOTAL_DURATION_IN_FRAMES) {
    throw new Error(`Timing sheet sums to ${cursor}, expected ${TOTAL_DURATION_IN_FRAMES}`);
}

// Every VO segment must start inside its beat and end before the next
// beat's narration begins — the placement half of the same guarantee.
for (const [segmentId, segment] of Object.entries(timing.segments)) {
    const beatId = (segmentId === 'accent-hero-closer' ? 'accent-hero' : segmentId) as BeatId;
    const beat = BEATS.find((b) => b.id === beatId);
    if (!beat) continue;
    const startFrame = Math.round(segment.start_seconds * FPS);
    const endFrame = Math.round((segment.start_seconds + segment.seconds) * FPS);
    if (startFrame < beat.startFrame || endFrame > beat.startFrame + beat.durationInFrames) {
        throw new Error(`VO segment "${segmentId}" (${startFrame}–${endFrame}) escapes beat "${beatId}" (${beat.startFrame}–${beat.startFrame + beat.durationInFrames})`);
    }
}

export const beat = (id: BeatId): Beat => {
    const found = BEATS.find((b) => b.id === id);
    if (!found) {
        throw new Error(`Unknown beat "${id}"`);
    }
    return found;
};

/** Frames (relative to a beat's start) at which its VO segment begins. */
export const voLeadIn = (id: BeatId | 'accent-hero-closer'): number => {
    const segment = timing.segments[id];
    const parent = beat(id === 'accent-hero-closer' ? 'accent-hero' : id);
    return Math.round(segment.start_seconds * FPS) - parent.startFrame;
};

export const voDurationInFrames = (id: BeatId | 'accent-hero-closer'): number =>
    Math.round(timing.segments[id].seconds * FPS);
