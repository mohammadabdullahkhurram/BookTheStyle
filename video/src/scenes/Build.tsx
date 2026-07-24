import React from 'react';
import {AbsoluteFill, Sequence, interpolate, useCurrentFrame} from 'remotion';
import {localBeat} from '../beats';
import {useAspect} from './kinetic';
import {cam, cameraPath, Particles, Plate3D, Stage3D, Void, whip} from './space';
import {RemindersVignette, RentalVignette, ReportsVignette} from './vignettes';

/**
 * Build (track 16.51–20.06 — the riser, beats 32–38). Three rapid-fire
 * vignettes on HARD cuts — rental (the differentiator), reminders, reports
 * — each jolting in with its own camera, streaks thickening and the whole
 * stage creeping in as the riser climbs into the drop.
 */

// Each cut jolts in from a DIFFERENT direction: rental snap-zooms straight
// in with a dutch kick, reminders rises from below, reports slides in from
// the left and keeps panning past. Same beats, three characters.
const CUTS: Array<{vignette: React.FC<{t: number}>; keys: (typeof cam extends (...a: never[]) => infer R ? Array<{f: number; cam: R; ease?: (t: number) => number}> : never)}> = [
    {
        vignette: RentalVignette,
        keys: [
            {f: 0, cam: cam([0, 0, 240], -6, 0, 6)},
            {f: 5, cam: cam([0, 0, -6], 0, 0, 0), ease: whip},
            {f: 60, cam: cam([0, 0, -84], 1.5)},
        ],
    },
    {
        vignette: RemindersVignette,
        keys: [
            {f: 0, cam: cam([0, 300, 70], 0, -14, -2)},
            {f: 6, cam: cam([0, 0, 6], 0, 0, 0), ease: whip},
            {f: 60, cam: cam([0, -14, -66], 0, 1.5)},
        ],
    },
    {
        vignette: ReportsVignette,
        keys: [
            {f: 0, cam: cam([-380, 0, 60], -9, 0, -3)},
            {f: 6, cam: cam([-6, 0, 0], 0, 0, 0), ease: whip},
            {f: 60, cam: cam([44, 0, -60], 2.5)},
        ],
    },
];

const Cut: React.FC<{vignette: React.FC<{t: number}>; keys: (typeof CUTS)[number]['keys']; index: number}> = ({vignette: Vignette, keys, index}) => {
    const frame = useCurrentFrame(); // local to this cut's Sequence
    const camera = cameraPath(frame, keys);
    return (
        <Stage3D camera={camera}>
            <Particles camera={camera} frame={frame} seed={`build-${index}`} min={[-800, -560, -700]} max={[800, 560, 300]} count={70} />
            <Plate3D pos={[0, 0, 0]} yaw={0} camera={camera} dof={false}>
                <Vignette t={frame} />
            </Plate3D>
        </Stage3D>
    );
};

export const Build: React.FC = () => {
    const frame = useCurrentFrame();
    const aspect = useAspect();
    const b = (n: number) => localBeat('build', n);
    const end = b(39);
    const bounds = [0, b(34), b(36), end];

    // The riser: the whole stage creeps forward, streaks thicken.
    const ramp = interpolate(frame, [0, end], [0, 1]);

    return (
        <AbsoluteFill>
            <Void />
            <AbsoluteFill style={{scale: String(1 + ramp * (aspect === 'wide' ? 0.05 : 0.035))}}>
                {CUTS.map(({vignette, keys}, i) => (
                    <Sequence key={i} name={`build cut ${i + 1}`} from={bounds[i]} durationInFrames={bounds[i + 1] - bounds[i]}>
                        <Cut vignette={vignette} keys={keys} index={i} />
                    </Sequence>
                ))}
            </AbsoluteFill>
        </AbsoluteFill>
    );
};
