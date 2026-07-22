import {getStaticFiles} from '@remotion/studio';
import React from 'react';
import {Audio, staticFile} from 'remotion';

/**
 * The audio bed. Everything under public/audio is a gitignored drop-slot:
 *
 *   audio/voiceover-final.mp3   — the real read (wins when present)
 *   audio/voiceover-scratch.mp3 — any scratch read for timing work
 *   audio/music.mp3             — the bed (sourced separately; slot only)
 *
 * The composition renders silently until files exist — no code change
 * needed when the read lands, just drop the file in.
 */
const has = (name: string): boolean => {
    try {
        return getStaticFiles().some((file) => file.name === name);
    } catch {
        return false;
    }
};

export const Soundtrack: React.FC = () => {
    const voiceover = ['audio/voiceover-final.mp3', 'audio/voiceover-scratch.mp3'].find(has);
    const music = has('audio/music.mp3');

    return (
        <>
            {voiceover ? <Audio src={staticFile(voiceover)} /> : null}
            {music ? <Audio src={staticFile('audio/music.mp3')} volume={0.16} /> : null}
        </>
    );
};
