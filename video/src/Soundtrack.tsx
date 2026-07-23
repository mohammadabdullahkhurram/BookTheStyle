import {getStaticFiles} from '@remotion/studio';
import React from 'react';
import {Audio, staticFile} from 'remotion';

/**
 * The music cut has ONE audio source: the track (public/audio/music.mp3,
 * gitignored — drop it in after a clean clone; the beat grid in
 * src/beat-map.json was measured from it). No voiceover: the retired VO
 * pipeline (generate-vo*.mjs, vo-timing.json, voiceover-final.mp3) stays on
 * disk but is deliberately unwired from the composition.
 */
const has = (name: string): boolean => {
    try {
        return getStaticFiles().some((file) => file.name === name);
    } catch {
        return false;
    }
};

export const Soundtrack: React.FC = () => (
    has('audio/music.mp3') ? <Audio src={staticFile('audio/music.mp3')} /> : null
);
