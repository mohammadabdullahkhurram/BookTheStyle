import React from 'react';
import {Composition} from 'remotion';
import {FPS, TOTAL_DURATION_IN_FRAMES} from './beats';
import {LaunchFilm} from './LaunchFilm';
import {Look1Editorial} from './looks/Look1Editorial';
import {PopFilm} from './pop/PopFilm';
import {Look2Aurora} from './looks/Look2Aurora';
import {Look3Pop} from './looks/Look3Pop';
import {SAMPLE_DURATION} from './looks/common';
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
        {/* The POP film — the chosen look, full 33s, three aspects. */}
        <Composition id="PopFilm" component={PopFilm} durationInFrames={TOTAL_DURATION_IN_FRAMES} fps={FPS} width={1920} height={1080} />
        <Composition id="PopFilmVertical" component={PopFilm} durationInFrames={TOTAL_DURATION_IN_FRAMES} fps={FPS} width={1080} height={1920} />
        <Composition id="PopFilmSquare" component={PopFilm} durationInFrames={TOTAL_DURATION_IN_FRAMES} fps={FPS} width={1080} height={1080} />
        {/* Aesthetic-direction look tests (src/looks) — scratch lab, never shipped. */}
        <Composition id="Look1Sample" component={Look1Editorial} durationInFrames={SAMPLE_DURATION} fps={FPS} width={1920} height={1080} />
        <Composition id="Look2Sample" component={Look2Aurora} durationInFrames={SAMPLE_DURATION} fps={FPS} width={1920} height={1080} />
        <Composition id="Look3Sample" component={Look3Pop} durationInFrames={SAMPLE_DURATION} fps={FPS} width={1920} height={1080} />
    </>
);
