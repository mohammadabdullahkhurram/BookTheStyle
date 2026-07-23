/**
 * SCRIPT.md → clean per-beat narration segments. Shared by both VO
 * generators (say + ElevenLabs) so the split — including the accent-hero
 * cut before "Not ours." that makes the directed pause a placement
 * decision — is identical regardless of the engine.
 */
import fs from 'node:fs';
import path from 'node:path';

export const BEAT_IDS = ['cold-open', 'logo-promise', 'her-side', 'your-side', 'accent-hero', 'proof', 'close'];

/** @returns {Array<{id: string, text: string}>} */
export const parseSegments = (videoRoot) => {
    const script = fs.readFileSync(path.join(videoRoot, 'SCRIPT.md'), 'utf8');
    const paragraphs = [...script.matchAll(/^\[\d+:\d+–\d+:\d+ — [^\]]+\] (.+)$/gm)].map((m) => m[1].trim());

    if (paragraphs.length !== BEAT_IDS.length) {
        throw new Error(`SCRIPT.md parsed into ${paragraphs.length} beats, expected ${BEAT_IDS.length}.`);
    }

    const segments = [];
    for (const [index, id] of BEAT_IDS.entries()) {
        const text = paragraphs[index];
        if (id === 'accent-hero') {
            const splitAt = text.lastIndexOf('Not ours.');
            if (splitAt === -1) {
                throw new Error('SCRIPT.md accent-hero beat no longer ends with "Not ours." — update this split.');
            }
            segments.push({id: 'accent-hero', text: text.slice(0, splitAt).trim()});
            segments.push({id: 'accent-hero-closer', text: text.slice(splitAt).trim()});
        } else {
            segments.push({id, text});
        }
    }
    return segments;
};
