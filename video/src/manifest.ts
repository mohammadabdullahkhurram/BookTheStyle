/**
 * Typed access to the capture manifest — the committed record of every
 * regenerable asset (docs/launch-video/manifest.json, produced by
 * scripts/capture-launch-assets.mjs at the repo root).
 *
 * Scenes reference assets by MANIFEST KEY (the filename without extension,
 * e.g. `owner-dashboard--accent-01`) — never by raw filename — so a
 * re-capture that changes dimensions or accents flows through here, and a
 * missing asset fails loudly at build time instead of rendering a broken
 * <Img>. The binaries themselves are gitignored; video/public/assets is a
 * symlink into docs/launch-video/assets served via staticFile().
 */
import {staticFile} from 'remotion';
import manifestJson from '../../docs/launch-video/manifest.json';

type ManifestAsset = {
    file: string;
    beat: string;
    route: string;
    viewport: 'desktop' | 'mobile';
    theme: string;
    accent: string;
    width: number;
    height: number;
    crop?: boolean;
    type?: 'video';
    duration_ms?: number;
};

export type Asset = ManifestAsset & {
    key: string;
    /** staticFile() URL, ready for <Img>/<OffthreadVideo>. */
    src: string;
};

const byKey = new Map<string, Asset>(
    (manifestJson.assets as ManifestAsset[]).map((asset) => {
        const key = asset.file.replace(/\.[a-z0-9]+$/i, '');
        return [key, {...asset, key, src: staticFile(`assets/${asset.file}`)}];
    }),
);

export const getAsset = (key: string): Asset => {
    const asset = byKey.get(key);
    if (!asset) {
        throw new Error(
            `Manifest has no asset "${key}" — re-run the capture (npm run capture:launch at the repo root) `
            + `or check the key against docs/launch-video/manifest.json (${byKey.size} assets known).`,
        );
    }
    return asset;
};

export const fixture = manifestJson.fixture;
