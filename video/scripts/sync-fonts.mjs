#!/usr/bin/env node
/**
 * Syncs the app's OWN self-hosted font binaries into video/public/fonts so
 * the film renders with byte-identical faces to the product — no CDN, no
 * approximation. The app builds Fraunces + Hanken Grotesk into
 * public/build/assets with content-hashed names (vite + bunny fonts, see
 * vite.config.js); this resolves the hashes to stable names the video's
 * font loader (src/fonts.ts) can reference. Runs automatically before
 * `npm run dev` and `npm run render`. Output is gitignored.
 */
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const videoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const appBuildAssets = path.resolve(videoRoot, '../public/build/assets');
const outDir = path.join(videoRoot, 'public/fonts');

const WANTED = [
    ['fraunces', [500, 600, 700]],
    ['hanken-grotesk', [400, 500, 600, 700]],
];

if (!fs.existsSync(appBuildAssets)) {
    console.error(`App build assets not found at ${appBuildAssets} — run \`npm run build\` at the repo root first.`);
    process.exit(1);
}

fs.mkdirSync(outDir, {recursive: true});
const built = fs.readdirSync(appBuildAssets);

for (const [family, weights] of WANTED) {
    for (const weight of weights) {
        const match = built.filter((f) => f.startsWith(`${family}-${weight}-normal-`) && f.endsWith('.woff2')).sort()[0];
        if (!match) {
            console.error(`Missing ${family} ${weight} in the app build — run \`npm run build\` at the repo root.`);
            process.exit(1);
        }
        fs.copyFileSync(path.join(appBuildAssets, match), path.join(outDir, `${family}-${weight}.woff2`));
    }
}

console.log(`Fonts synced from the app build → ${outDir}`);
