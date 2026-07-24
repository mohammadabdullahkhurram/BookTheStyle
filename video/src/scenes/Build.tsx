import React from 'react';
import {AbsoluteFill, Sequence, interpolate, useCurrentFrame} from 'remotion';
import {localBeat} from '../beats';
import {useAspect} from './kinetic';
import {cam, cameraPath, Particles, Plate3D, SpeedStreaks, Stage3D, Void, whip} from './space';
import {RemindersVignette, RentalVignette, ReportsVignette} from './vignettes';

/**
 * Build (track 16.51–20.06 — the riser, beats 32–38). Three rapid-fire
 * vignettes on HARD cuts — rental (the differentiator), reminders, reports
 * — each jolting in with its own camera, streaks thickening and the whole
 * stage creeping in as the riser climbs into the drop.
 */

const CUTS: Array<{vignette: React.FC<{t: number}>; yaw: number}> = [
    {vignette: RentalVignette, yaw: -3},
    {vignette: RemindersVignette, yaw: 3},
    {vignette: ReportsVignette, yaw: -2},
];

const Cut: React.FC<{vignette: React.FC<{t: number}>; yaw: number; index: number}> = ({vignette: Vignette, yaw, index}) => {
    const frame = useCurrentFrame(); // local to this cut's Sequence
    const camera = cameraPath(frame, [
        {f: 0, cam: cam([0, 0, 130], yaw * 2, 0, yaw)},
        {f: 6, cam: cam([0, 0, 10], yaw, 0, 0), ease: whip},
        {f: 60, cam: cam([0, 0, -70], yaw * 0.6)},
    ]);
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
                {CUTS.map(({vignette, yaw}, i) => (
                    <Sequence key={i} name={`build cut ${i + 1}`} from={bounds[i]} durationInFrames={bounds[i + 1] - bounds[i]}>
                        <Cut vignette={vignette} yaw={yaw} index={i} />
                    </Sequence>
                ))}
            </AbsoluteFill>
            <SpeedStreaks intensity={0.12 + ramp * 0.4} seed="riser" />
        </AbsoluteFill>
    );
};
