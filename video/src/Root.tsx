import React from 'react';
import {Composition} from 'remotion';
import {FPS, TOTAL_DURATION_IN_FRAMES} from './beats';
import {LaunchFilm} from './LaunchFilm';
import './fonts';

/** Three aspects × two palettes of the same cut — the scenes reframe via
 *  useAspect() (kinetic.tsx) and recolor via the film mode (scenes/mode.tsx).
 *  No letterboxing, no forked scene tree. */
export const Root: React.FC = () => (
    <>
        <Composition
            id="LaunchFilm"
            component={LaunchFilm}
            durationInFrames={TOTAL_DURATION_IN_FRAMES}
            fps={FPS}
            width={1920}
            height={1080}
        />
        <Composition
            id="LaunchFilmVertical"
            component={LaunchFilm}
            durationInFrames={TOTAL_DURATION_IN_FRAMES}
            fps={FPS}
            width={1080}
            height={1920}
        />
        <Composition
            id="LaunchFilmSquare"
            component={LaunchFilm}
            durationInFrames={TOTAL_DURATION_IN_FRAMES}
            fps={FPS}
            width={1080}
            height={1080}
        />
        <Composition
            id="LaunchFilmLight"
            component={LaunchFilm}
            defaultProps={{mode: 'light' as const}}
            durationInFrames={TOTAL_DURATION_IN_FRAMES}
            fps={FPS}
            width={1920}
            height={1080}
        />
        <Composition
            id="LaunchFilmLightVertical"
            component={LaunchFilm}
            defaultProps={{mode: 'light' as const}}
            durationInFrames={TOTAL_DURATION_IN_FRAMES}
            fps={FPS}
            width={1080}
            height={1920}
        />
        <Composition
            id="LaunchFilmLightSquare"
            component={LaunchFilm}
            defaultProps={{mode: 'light' as const}}
            durationInFrames={TOTAL_DURATION_IN_FRAMES}
            fps={FPS}
            width={1080}
            height={1080}
        />
    </>
);
