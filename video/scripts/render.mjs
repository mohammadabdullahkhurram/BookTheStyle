#!/usr/bin/env node
/**
 * Renders the film OUTSIDE the repo — video binaries never enter git.
 *
 *   npm run render                          → all four finals → ~/Movies/BookTheStyle-Launch/
 *   npm run render -- --only=master         → one target (master|social|vertical|square)
 *   npm run render -- --draft               → half-res draft for fast iteration
 *   npm run render -- --out=/abs/path       → anywhere else (BTS_RENDER_OUT env works too)
 *
 * Targets: launch-master (1920×1080 CRF 17) · launch-social (1080p H.264 CRF 23,
 * upload-sized) · launch-vertical (1080×1920) · launch-square (1080×1080).
 */
import {execFileSync} from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const videoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

const args = Object.fromEntries(
    process.argv.slice(2).filter((a) => a.startsWith('--')).map((a) => {
        const [k, ...v] = a.replace(/^--/, '').split('=');
        return [k, v.join('=') || true];
    }),
);

const outDir = path.resolve(String(args.out ?? process.env.BTS_RENDER_OUT ?? path.join(os.homedir(), 'Movies/BookTheStyle-Launch')));
fs.mkdirSync(outDir, {recursive: true});

const TARGETS = {
    master: {comp: 'LaunchFilm', file: 'launch-master.mp4', flags: ['--crf=17']},
    social: {comp: 'LaunchFilm', file: 'launch-social.mp4', flags: ['--crf=23', '--x264-preset=medium']},
    vertical: {comp: 'LaunchFilmVertical', file: 'launch-vertical.mp4', flags: ['--crf=18']},
    square: {comp: 'LaunchFilmSquare', file: 'launch-square.mp4', flags: ['--crf=18']},
};

const names = args.only ? String(args.only).split(',') : Object.keys(TARGETS);

for (const name of names) {
    const target = TARGETS[name];
    if (!target) {
        console.error(`Unknown target "${name}" — expected one of: ${Object.keys(TARGETS).join(', ')}`);
        process.exit(1);
    }
    const flags = args.draft ? ['--scale=0.5', '--jpeg-quality=60', '--crf=30'] : target.flags;
    const outFile = path.join(outDir, args.draft ? target.file.replace('.mp4', '-draft.mp4') : target.file);
    const started = Date.now();
    console.log(`Rendering ${target.comp} → ${outFile}`);
    execFileSync('npx', ['remotion', 'render', target.comp, outFile, ...flags], {cwd: videoRoot, stdio: 'inherit'});
    console.log(`${name}: ${((Date.now() - started) / 60000).toFixed(1)} min`);
}
