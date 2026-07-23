import React from 'react';
import {Composition} from 'remotion';
import {FPS, TOTAL_DURATION_IN_FRAMES} from './beats';
import {LaunchFilm} from './LaunchFilm';
import './fonts';

/** Three aspects of the same cut — the scenes reframe themselves via
 *  useAspect() (kinetic.tsx), no letterboxing anywhere. */
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
    </>
);
