import React from 'react';
import {AbsoluteFill, Sequence} from 'remotion';
import {SCENES, type SceneId} from './beats';
import {FilmModeContext, type FilmMode} from './scenes/mode';
import {Soundtrack} from './Soundtrack';
import {Build} from './scenes/Build';
import {Drop} from './scenes/Drop';
import {Intro} from './scenes/Intro';
import {Outro} from './scenes/Outro';
import {Showcase} from './scenes/Showcase';
import './fonts';

const COMPONENTS: Record<SceneId, React.ReactNode> = {
    intro: <Intro />,
    showcase: <Showcase />,
    build: <Build />,
    drop: <Drop />,
    outro: <Outro />,
};

/** The music cut: 33.07s, every boundary on a detected beat, the recolor on
 *  the drop. The track is the only voice. `mode` selects the palette — the
 *  original dark void or the bright Marble field — over ONE scene tree. */
export const LaunchFilm: React.FC<{mode?: FilmMode}> = ({mode = 'dark'}) => (
    <FilmModeContext.Provider value={mode}>
        <AbsoluteFill style={{backgroundColor: mode === 'light' ? '#FFF8EF' : '#241C22'}}>
            {SCENES.map((s) => (
                <Sequence key={s.id} name={`${s.id} (b${s.fromBeat}–${s.toBeat})`} from={s.startFrame} durationInFrames={s.durationInFrames}>
                    {COMPONENTS[s.id]}
                </Sequence>
            ))}
            <Soundtrack />
        </AbsoluteFill>
    </FilmModeContext.Provider>
);
