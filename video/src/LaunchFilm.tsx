import React from 'react';
import {AbsoluteFill, Sequence} from 'remotion';
import {BEATS, type BeatId} from './beats';
import {Soundtrack} from './Soundtrack';
import {AccentHero} from './scenes/AccentHero';
import {Close} from './scenes/Close';
import {ColdOpen} from './scenes/ColdOpen';
import {HerSide} from './scenes/HerSide';
import {LogoPromise} from './scenes/LogoPromise';
import {Proof} from './scenes/Proof';
import {YourSide} from './scenes/YourSide';
import './fonts';

const SCENES: Record<BeatId, (durationInFrames: number) => React.ReactNode> = {
    'cold-open': () => <ColdOpen />,
    'logo-promise': () => <LogoPromise />,
    'her-side': () => <HerSide />,
    'your-side': () => <YourSide />,
    'accent-hero': (d) => <AccentHero durationInFrames={d} />,
    proof: () => <Proof />,
    close: () => <Close />,
};

/** The full 78s film — every beat mounted from the timing sheet, VO from
 *  the assembled read (music slot silent until part 4). */
export const LaunchFilm: React.FC = () => (
    <AbsoluteFill style={{backgroundColor: '#241C22'}}>
        {BEATS.map((beat) => (
            <Sequence
                key={beat.id}
                name={beat.id}
                from={beat.startFrame}
                durationInFrames={beat.durationInFrames}
            >
                {SCENES[beat.id](beat.durationInFrames)}
            </Sequence>
        ))}
        <Soundtrack />
    </AbsoluteFill>
);
