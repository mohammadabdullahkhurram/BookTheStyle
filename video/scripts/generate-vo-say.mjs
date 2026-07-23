#!/usr/bin/env node
/**
 * Voiceover via macOS's built-in `say` — free, local, no API key. The
 * DEFAULT VO path; scripts/generate-vo.mjs (ElevenLabs, warmer, needs
 * ELEVENLABS_API_KEY) regenerates into the same slot if a key ever exists.
 *
 *   node scripts/generate-vo-say.mjs               # generate + measure
 *   node scripts/generate-vo.mjs --assemble        # → public/audio/voiceover-final.mp3
 *
 * Same contract as the ElevenLabs path: SCRIPT.md → eight segments (shared
 * parser — accent-hero split before "Not ours."), aiff via say → mp3 via
 * Remotion's bundled ffmpeg, REAL durations measured with ffprobe into
 * src/vo-timing.json. The rate flag only shapes the read; the measured
 * numbers are what drive beats.ts.
 *
 * Voice preference (override with VO_VOICE): Ava (Premium) → Zoe (Premium)
 * → Ava (Enhanced) → Samantha → any en_US voice. Premium/enhanced voices
 * install via System Settings → Accessibility → Spoken Content → System
 * Voice → Manage Voices; re-running this script then upgrades the read.
 */
import {execFileSync} from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {parseSegments} from './lib/segments.mjs';

const videoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const segmentsDir = path.join(videoRoot, 'public/audio/segments');
const timingPath = path.join(videoRoot, 'src/vo-timing.json');

const RATE_WPM = 172; // natural, unhurried read

const pickVoice = () => {
    if (process.env.VO_VOICE) return process.env.VO_VOICE;
    const installed = execFileSync('say', ['-v', '?'], {encoding: 'utf8'});
    const names = installed.split('\n').map((line) => line.split(/ {2,}/)[0]?.trim()).filter(Boolean);
    for (const preferred of ['Ava (Premium)', 'Zoe (Premium)', 'Ava (Enhanced)', 'Samantha']) {
        if (names.includes(preferred)) return preferred;
    }
    const anyUs = installed.split('\n').find((line) => /en[_-]US/.test(line));
    if (!anyUs) throw new Error('No en_US voice installed for `say`.');
    return anyUs.split(/ {2,}/)[0].trim();
};

const ffprobeDuration = (file) => parseFloat(
    execFileSync('npx', ['remotion', 'ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', file], {cwd: videoRoot, encoding: 'utf8'}).trim(),
);

const voice = pickVoice();
console.log(`Voice: ${voice} · ${RATE_WPM} wpm (macOS say)`);
fs.mkdirSync(segmentsDir, {recursive: true});

const measured = {};
for (const {id, text} of parseSegments(videoRoot)) {
    process.stdout.write(`  ${id} … `);
    // WAV, not aiff: Remotion's trimmed ffmpeg build has no aiff demuxer.
    const wav = path.join(segmentsDir, `${id}.wav`);
    const mp3 = path.join(segmentsDir, `${id}.mp3`);
    execFileSync('say', ['-v', voice, '-r', String(RATE_WPM), '-o', wav, '--data-format=LEI16@44100', text]);
    execFileSync('npx', ['remotion', 'ffmpeg', '-i', wav, '-codec:a', 'libmp3lame', '-q:a', '2', '-ar', '44100', '-y', mp3], {cwd: videoRoot, stdio: ['ignore', 'ignore', 'pipe']});
    fs.rmSync(wav);
    measured[id] = {seconds: Number(ffprobeDuration(mp3).toFixed(3))};
    console.log(`${measured[id].seconds}s`);
}

let timing = {};
try {
    timing = JSON.parse(fs.readFileSync(timingPath, 'utf8'));
} catch { /* first generation */ }

timing.voice = {engine: 'macos-say', name: voice, rate_wpm: RATE_WPM};
timing.segments = Object.fromEntries(
    Object.entries(measured).map(([id, m]) => [id, {...timing.segments?.[id], ...m}]),
);
fs.writeFileSync(timingPath, JSON.stringify(timing, null, 2) + '\n');

console.log(`\nMeasured durations → ${timingPath}`);
console.log('Next: set start_seconds placements there (see beats.ts), then `node scripts/generate-vo.mjs --assemble`.');
