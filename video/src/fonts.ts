/**
 * Loads the app's exact font binaries (synced by scripts/sync-fonts.mjs
 * into public/fonts — byte-identical to what the product serves; see that
 * script's header). delayRender/continueRender gates the first frame until
 * every face is ready, so no frame ever renders in a fallback font.
 */
import {continueRender, delayRender, staticFile} from 'remotion';

const FACES: Array<[family: string, file: string, weight: number]> = [
    ['Fraunces', 'fraunces-500.woff2', 500],
    ['Fraunces', 'fraunces-600.woff2', 600],
    ['Fraunces', 'fraunces-700.woff2', 700],
    ['Hanken Grotesk', 'hanken-grotesk-400.woff2', 400],
    ['Hanken Grotesk', 'hanken-grotesk-500.woff2', 500],
    ['Hanken Grotesk', 'hanken-grotesk-600.woff2', 600],
    ['Hanken Grotesk', 'hanken-grotesk-700.woff2', 700],
];

const handle = delayRender('Loading brand fonts');

Promise.all(
    FACES.map(([family, file, weight]) =>
        new FontFace(family, `url(${staticFile(`fonts/${file}`)}) format('woff2')`, {
            weight: String(weight),
        })
            .load()
            // TS's DOM lib misses FontFaceSet.add; the runtime has it everywhere Remotion renders.
            .then((face) => (document.fonts as unknown as {add: (f: FontFace) => void}).add(face)),
    ),
)
    .then(() => continueRender(handle))
    .catch((error) => {
        // A missing font must fail the render loudly, not ship a fallback face.
        console.error('Brand fonts failed to load — did scripts/sync-fonts.mjs run?', error);
        throw error;
    });
