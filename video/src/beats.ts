/**
 * THE EDIT GRID — the music-driven cut's spine. src/beat-map.json (librosa
 * beat grid + curated sections, see scripts/analyze-track.py) replaces the
 * retired VO timing sheet: every scene boundary and every in-scene cut
 * derives from DETECTED beats, and the self-checks below refuse boundaries
 * that drift off the grid. The track's end is the film's end.
 *
 * The old VO pipeline (vo-timing.json, generate-vo*.mjs, the mp3s) stays on
 * disk, unwired — this cut has no voiceover; the track is the only voice.
 */
import beatMap from './beat-map.json';

export const FPS = beatMap.fps;
export const BPM = beatMap.bpm;

/** The whole film = the whole track. 992 frames ≈ 33.07s. */
export const TOTAL_DURATION_IN_FRAMES = beatMap.total_frames;

/** Frame of detected beat n (0-based). */
export const beatFrame = (n: number): number => {
    const frame = beatMap.beat_frames[n];
    if (typeof frame !== 'number') {
        throw new Error(`Track has ${beatMap.beat_frames.length} beats — no beat ${n}`);
    }
    return frame;
};

/** Frames per beat at the detected tempo (fractional — do not accumulate). */
export const FRAMES_PER_BEAT = (60 / BPM) * FPS;

export type SceneId = 'intro' | 'showcase' | 'build' | 'drop' | 'outro';

export type Scene = {
    id: SceneId;
    startFrame: number;
    durationInFrames: number;
    /** First beat of the scene's section — in-scene cuts count from here. */
    fromBeat: number;
    toBeat: number;
};

const SECTION_ORDER: SceneId[] = ['intro', 'showcase', 'build', 'drop', 'outro'];

export const SCENES: Scene[] = SECTION_ORDER.map((id, index) => {
    const section = beatMap.sections[id];
    // The film opens at frame 0 and ends with the track — the outer edges
    // are the track's edges; every INTERIOR boundary is a detected beat.
    const startFrame = index === 0 ? 0 : beatFrame(section.from_beat);
    const endFrame = index === SECTION_ORDER.length - 1
        ? TOTAL_DURATION_IN_FRAMES
        : beatFrame(beatMap.sections[SECTION_ORDER[index + 1]].from_beat);
    return {id, startFrame, durationInFrames: endFrame - startFrame, fromBeat: section.from_beat, toBeat: section.to_beat};
});

// Self-checks: the grid must tile the track exactly, and every interior
// scene boundary must sit ON a detected beat (±2 frames tolerance).
{
    let cursor = 0;
    for (const scene of SCENES) {
        if (scene.startFrame !== cursor) {
            throw new Error(`Edit grid gap/overlap at "${scene.id}": starts ${scene.startFrame}, expected ${cursor}`);
        }
        cursor += scene.durationInFrames;
    }
    if (cursor !== TOTAL_DURATION_IN_FRAMES) {
        throw new Error(`Edit grid sums to ${cursor}, expected ${TOTAL_DURATION_IN_FRAMES}`);
    }
    for (const scene of SCENES.slice(1)) {
        const onBeat = beatMap.beat_frames.some((frame) => Math.abs(frame - scene.startFrame) <= 2);
        if (!onBeat) {
            throw new Error(`Scene "${scene.id}" starts at ${scene.startFrame} — not on a detected beat`);
        }
    }
}

export const scene = (id: SceneId): Scene => {
    const found = SCENES.find((s) => s.id === id);
    if (!found) {
        throw new Error(`Unknown scene "${id}"`);
    }
    return found;
};

/** Beat n's frame RELATIVE to a scene's start — for in-scene cuts. */
export const localBeat = (sceneId: SceneId, n: number): number =>
    beatFrame(n) - scene(sceneId).startFrame;
