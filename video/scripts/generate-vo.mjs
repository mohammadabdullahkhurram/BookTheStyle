#!/usr/bin/env node
/**
 * Generates the launch-film voiceover from video/SCRIPT.md via ElevenLabs.
 *
 *   ELEVENLABS_API_KEY=… node scripts/generate-vo.mjs            # generate segments + measure
 *   node scripts/generate-vo.mjs --dry-run                       # show the cleaned segments, no API
 *   node scripts/generate-vo.mjs --assemble                      # segments → public/audio/voiceover-final.mp3
 *
 * The read is generated PER BEAT (eight segments — the accent-hero beat is
 * split before "Not ours."), because the film's two directed pauses are
 * PLACEMENT decisions, not performance decisions: "Not ours." must land
 * late inside the 12s recolor visual, and the cold open needs a hard stop
 * after "somewhere else." Per-segment assembly at explicit offsets (from
 * src/vo-timing.json, the single timing source beats.ts also reads) gives
 * deterministic control that SSML <break> tags do not.
 *
 * Segment requests carry previous_text/next_text so ElevenLabs keeps the
 * prosody continuous across the cuts — it reads as one take.
 *
 * The API key comes ONLY from the ELEVENLABS_API_KEY environment variable —
 * required for REgeneration only; rendering needs just the mp3 on disk.
 * Segments and the assembled track live under public/audio/ (gitignored).
 */
import {execFileSync} from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const videoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const audioDir = path.join(videoRoot, 'public/audio');
const segmentsDir = path.join(audioDir, 'segments');
const timingPath = path.join(videoRoot, 'src/vo-timing.json');

const args = new Set(process.argv.slice(2));

// ---------------------------------------------------------------------------
// SCRIPT.md → clean narration segments (beat markers and headers stripped)
// ---------------------------------------------------------------------------

const BEAT_IDS = ['cold-open', 'logo-promise', 'her-side', 'your-side', 'accent-hero', 'proof', 'close'];

const script = fs.readFileSync(path.join(videoRoot, 'SCRIPT.md'), 'utf8');
const paragraphs = [...script.matchAll(/^\[\d+:\d+–\d+:\d+ — [^\]]+\] (.+)$/gm)].map((m) => m[1].trim());

if (paragraphs.length !== BEAT_IDS.length) {
    console.error(`SCRIPT.md parsed into ${paragraphs.length} beats, expected ${BEAT_IDS.length}.`);
    process.exit(1);
}

/** @type {Array<{id: string, text: string}>} */
const segments = [];
for (const [index, id] of BEAT_IDS.entries()) {
    const text = paragraphs[index];
    if (id === 'accent-hero') {
        // The directed pause: "Not ours." is its own segment, placed late.
        const splitAt = text.lastIndexOf('Not ours.');
        if (splitAt === -1) {
            console.error('SCRIPT.md accent-hero beat no longer ends with "Not ours." — update this split.');
            process.exit(1);
        }
        segments.push({id: 'accent-hero', text: text.slice(0, splitAt).trim()});
        segments.push({id: 'accent-hero-closer', text: text.slice(splitAt).trim()});
    } else {
        segments.push({id, text});
    }
}

if (args.has('--dry-run')) {
    for (const {id, text} of segments) {
        console.log(`\n[${id}] (${text.split(/\s+/).length} words)\n${text}`);
    }
    process.exit(0);
}

// ---------------------------------------------------------------------------
// Assemble mode: segments → one aligned voiceover-final.mp3
// ---------------------------------------------------------------------------

const ffmpeg = (ffArgs) => execFileSync('npx', ['remotion', 'ffmpeg', ...ffArgs], {cwd: videoRoot, stdio: ['ignore', 'pipe', 'pipe']});
const ffprobeDuration = (file) => parseFloat(
    execFileSync('npx', ['remotion', 'ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', file], {cwd: videoRoot, encoding: 'utf8'}).trim(),
);

if (args.has('--assemble')) {
    const timing = JSON.parse(fs.readFileSync(timingPath, 'utf8'));
    const placed = segments.map(({id}) => {
        const seg = timing.segments?.[id];
        if (!seg || typeof seg.start_seconds !== 'number') {
            console.error(`src/vo-timing.json has no placement (start_seconds) for "${id}" — retime the sheet first.`);
            process.exit(1);
        }
        return {id, file: path.join(segmentsDir, `${id}.mp3`), delayMs: Math.round(seg.start_seconds * 1000)};
    });
    for (const {file} of placed) {
        if (!fs.existsSync(file)) {
            console.error(`Missing segment ${file} — run generation first.`);
            process.exit(1);
        }
    }

    const inputs = placed.flatMap(({file}) => ['-i', file]);
    const delays = placed
        .map(({delayMs}, i) => `[${i}:a]adelay=${delayMs}|${delayMs}[d${i}]`)
        .join(';');
    const mix = placed.map((_, i) => `[d${i}]`).join('');
    const filter = `${delays};${mix}amix=inputs=${placed.length}:normalize=0[out]`;

    const outFile = path.join(audioDir, 'voiceover-final.mp3');
    ffmpeg([...inputs, '-filter_complex', filter, '-map', '[out]', '-codec:a', 'libmp3lame', '-q:a', '2', '-y', outFile]);
    console.log(`Assembled ${outFile} (${ffprobeDuration(outFile).toFixed(2)}s) from ${placed.length} segments at sheet offsets.`);
    process.exit(0);
}

// ---------------------------------------------------------------------------
// Generation: pick a voice, synthesize each segment, measure real durations
// ---------------------------------------------------------------------------

const apiKey = process.env.ELEVENLABS_API_KEY;
if (!apiKey) {
    console.error('ELEVENLABS_API_KEY is not set. It is required ONLY to (re)generate the voiceover;');
    console.error('rendering needs nothing but the existing mp3. Aborting — no fallback to guessed timing.');
    process.exit(1);
}

const api = async (route, init = {}) => {
    const response = await fetch(`https://api.elevenlabs.io${route}`, {
        ...init,
        headers: {'xi-api-key': apiKey, 'Content-Type': 'application/json', ...(init.headers ?? {})},
    });
    if (!response.ok) {
        throw new Error(`ElevenLabs ${route} → ${response.status}: ${(await response.text()).slice(0, 300)}`);
    }
    return response;
};

// Voice brief: warm, direct, a little dry — owner-to-owner, NOT hype
// announcer. Preference order below picks the first available premade that
// fits that brief; override with ELEVENLABS_VOICE_ID. The chosen id/name is
// recorded in src/vo-timing.json for reproducibility.
const VOICE_SHORTLIST = ['Rachel', 'Matilda', 'Sarah', 'Alice'];

const pickVoice = async () => {
    if (process.env.ELEVENLABS_VOICE_ID) {
        return {voice_id: process.env.ELEVENLABS_VOICE_ID, name: '(ELEVENLABS_VOICE_ID override)'};
    }
    const {voices} = await (await api('/v1/voices')).json();
    for (const name of VOICE_SHORTLIST) {
        const match = voices.find((v) => v.name?.toLowerCase() === name.toLowerCase());
        if (match) return match;
    }
    console.error(`None of [${VOICE_SHORTLIST.join(', ')}] available; pass ELEVENLABS_VOICE_ID. Voices on this account:`);
    for (const v of voices.slice(0, 20)) console.error(`  ${v.voice_id}  ${v.name}`);
    process.exit(1);
};

const MODEL = 'eleven_multilingual_v2';
// Natural, not over-performed: mid stability, low style exaggeration.
const VOICE_SETTINGS = {stability: 0.45, similarity_boost: 0.8, style: 0.2, use_speaker_boost: true};

const voice = await pickVoice();
console.log(`Voice: ${voice.name} (${voice.voice_id}) · model ${MODEL}`);

fs.mkdirSync(segmentsDir, {recursive: true});

const measured = {};
for (const [index, {id, text}] of segments.entries()) {
    process.stdout.write(`  ${id} … `);
    const response = await api(`/v1/text-to-speech/${voice.voice_id}?output_format=mp3_44100_128`, {
        method: 'POST',
        body: JSON.stringify({
            text,
            model_id: MODEL,
            voice_settings: VOICE_SETTINGS,
            // Prosody continuity across the per-beat cuts — reads as one take.
            previous_text: index > 0 ? segments[index - 1].text : undefined,
            next_text: index < segments.length - 1 ? segments[index + 1].text : undefined,
        }),
    });
    const file = path.join(segmentsDir, `${id}.mp3`);
    fs.writeFileSync(file, Buffer.from(await response.arrayBuffer()));
    measured[id] = {seconds: Number(ffprobeDuration(file).toFixed(3))};
    console.log(`${measured[id].seconds}s`);
}

// Merge measurements into vo-timing.json, preserving any placement section
// (start_seconds per segment — set when the sheet is retimed).
let timing = {};
try {
    timing = JSON.parse(fs.readFileSync(timingPath, 'utf8'));
} catch { /* first generation */ }

timing.voice = {id: voice.voice_id, name: voice.name, model: MODEL, settings: VOICE_SETTINGS};
timing.segments = Object.fromEntries(
    Object.entries(measured).map(([id, m]) => [id, {...timing.segments?.[id], ...m}]),
);
fs.writeFileSync(timingPath, JSON.stringify(timing, null, 2) + '\n');

console.log(`\nMeasured durations written to ${timingPath}.`);
console.log('Next: retime src/vo-timing.json start_seconds + beats.ts, then run --assemble.');
