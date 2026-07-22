#!/usr/bin/env node
/**
 * Renders the film OUTSIDE the repo — video binaries never enter git.
 *
 *   npm run render                        → full-res Preview → ~/Movies/BookTheStyle-Launch/
 *   npm run render -- --draft             → half-res draft for fast iteration
 *   npm run render -- --comp=LaunchFilm   → the full 78s timeline (slates included)
 *   npm run render -- --out=/abs/path     → anywhere else (BTS_RENDER_OUT env works too)
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

const composition = String(args.comp ?? 'Preview');
const draft = Boolean(args.draft);
const outFile = path.join(outDir, `${composition.toLowerCase()}${draft ? '-draft' : ''}.mp4`);

const renderArgs = [
    'remotion', 'render', composition, outFile,
    ...(draft ? ['--scale=0.5', '--jpeg-quality=60', '--crf=30'] : ['--crf=17']),
];

console.log(`Rendering ${composition} ${draft ? '(draft)' : '(full res)'} → ${outFile}`);
execFileSync('npx', renderArgs, {cwd: videoRoot, stdio: 'inherit'});
