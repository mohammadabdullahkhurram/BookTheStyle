import React from 'react';
import {AbsoluteFill, Composition, Sequence} from 'remotion';
import {beat, FPS, TOTAL_DURATION_IN_FRAMES} from './beats';
import {LaunchFilm} from './LaunchFilm';
import {AccentHero} from './scenes/AccentHero';
import {ColdOpen} from './scenes/ColdOpen';
import './fonts';

const coldOpen = beat('cold-open');
const accentHero = beat('accent-hero');

/** The two built beats, back to back — real frames to judge, nothing else. */
const Preview: React.FC = () => (
    <AbsoluteFill style={{backgroundColor: '#241C22'}}>
        <Sequence name="Beat A — cold open" durationInFrames={coldOpen.durationInFrames}>
            <ColdOpen />
        </Sequence>
        <Sequence
            name="Beat B — accent hero"
            from={coldOpen.durationInFrames}
            durationInFrames={accentHero.durationInFrames}
        >
            <AccentHero durationInFrames={accentHero.durationInFrames} />
        </Sequence>
    </AbsoluteFill>
);

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
            id="Preview"
            component={Preview}
            durationInFrames={coldOpen.durationInFrames + accentHero.durationInFrames}
            fps={FPS}
            width={1920}
            height={1080}
        />
    </>
);
