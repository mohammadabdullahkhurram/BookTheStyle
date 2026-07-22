import React from 'react';
import {AbsoluteFill, Sequence} from 'remotion';
import {BEATS} from './beats';
import {Soundtrack} from './Soundtrack';
import {AccentHero} from './scenes/AccentHero';
import {ColdOpen} from './scenes/ColdOpen';
import {Slate} from './scenes/Slate';
import './fonts';

/** The full 78s film — every beat mounted from the timing sheet. Unbuilt
 *  beats render their slate, so the whole timeline is watchable today. */
export const LaunchFilm: React.FC = () => (
    <AbsoluteFill style={{backgroundColor: '#241C22'}}>
        {BEATS.map((beat) => (
            <Sequence
                key={beat.id}
                name={`${beat.id}${beat.provisional ? ' (provisional timing)' : ''}`}
                from={beat.startFrame}
                durationInFrames={beat.durationInFrames}
            >
                {beat.id === 'cold-open' ? (
                    <ColdOpen />
                ) : beat.id === 'accent-hero' ? (
                    <AccentHero durationInFrames={beat.durationInFrames} />
                ) : (
                    <Slate beat={beat} />
                )}
            </Sequence>
        ))}
        <Soundtrack />
    </AbsoluteFill>
);
