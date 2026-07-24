import {CameraMotionBlur} from '@remotion/motion-blur';
import React from 'react';
import {AbsoluteFill, useCurrentFrame} from 'remotion';
import {FRAMES_PER_BEAT, localBeat} from '../beats';
import {useAspect} from './kinetic';
import {
    cam,
    cameraPath,
    cameraSpeed,
    GroundGrid,
    PlacedPanels,
    Particles,
    Plate3D,
    SpeedStreaks,
    Stage3D,
    Void,
    whip,
    type CamKey,
    type CameraState,
    type Vec3,
} from './space';
import {BookingVignette, CalendarVignette, ClientsVignette, VoiceVignette} from './vignettes';

/**
 * Groove (track 4.30–16.51 — beats 8–31). One continuous camera flight
 * between four floating feature cards: booking → voice AI → calendar →
 * clients. Arrivals land ON beats 8/14/20/26; each hold is ~4.5 beats of
 * push-in while the vignette acts out its feature; the whips between
 * stations ride real motion blur. The booking confirmation lands on beat 12
 * — the 6.34s accent hit.
 */

type Station = {pos: Vec3; yaw: number; vignette: React.FC<{t: number}>};

const STATIONS: Station[] = [
    {pos: [0, 0, 0], yaw: 0, vignette: BookingVignette},
    {pos: [1560, -70, -780], yaw: 12, vignette: VoiceVignette},
    {pos: [3160, 90, -1360], yaw: -9, vignette: CalendarVignette},
    {pos: [4680, -50, -640], yaw: 7, vignette: ClientsVignette},
];

const stationCam = (s: Station, dz: number, pull: number): CameraState =>
    cam([s.pos[0], s.pos[1], s.pos[2] + dz + pull], s.yaw);

// Depth extras placed BETWEEN the stations, well off the flight axis and
// always behind the card they neighbour — never across content.
const PANELS: import('./space').PanelSpec[] = [
    {pos: [-560, 280, -700], w: 340, h: 210, yaw: 18},
    {pos: [700, -340, -620], w: 300, h: 190, yaw: -14},
    {pos: [860, 330, -1050], w: 380, h: 230, yaw: 10},
    {pos: [2320, -360, -1150], w: 340, h: 200, yaw: -12},
    {pos: [2460, 340, -820], w: 300, h: 190, yaw: 16},
    {pos: [3920, -320, -1080], w: 360, h: 220, yaw: 12},
    {pos: [4020, 330, -1600], w: 400, h: 240, yaw: -10},
    {pos: [5260, -260, -1150], w: 340, h: 210, yaw: -16},
];

export const Showcase: React.FC = () => {
    const frame = useCurrentFrame();
    const aspect = useAspect();
    // Non-wide frames pull the camera back a touch so 800px cards breathe.
    const pull = aspect === 'wide' ? 0 : aspect === 'square' ? 70 : 130;

    const arrivals = STATIONS.map((_, i) => localBeat('showcase', 8 + 6 * i));
    const departs = arrivals.map((a) => a + Math.round(4.5 * FRAMES_PER_BEAT));

    const keys: CamKey[] = STATIONS.flatMap((s, i) => [
        {f: arrivals[i], cam: stationCam(s, 46, pull), ease: whip},
        {f: departs[i], cam: stationCam(s, -26, pull)},
    ]);
    // Fly-out past the last card, straight into the build cut.
    keys.push({f: localBeat('showcase', 32), cam: cam([5500, -40, 420], -4, 0, 1.5), ease: whip});

    const camera = cameraPath(frame, keys);
    const speed = cameraSpeed(frame, keys);

    return (
        <AbsoluteFill>
            <Void />
            <CameraMotionBlur shutterAngle={240} samples={6}>
                <Stage3D camera={camera}>
                    <GroundGrid center={[2300, 590, -900]} extent={9000} />
                    <Particles camera={camera} frame={frame} seed="groove" min={[-700, -640, -2100]} max={[5600, 640, 700]} count={150} />
                    <PlacedPanels camera={camera} specs={PANELS} />
                    {STATIONS.map((s, i) => {
                        const Vignette = s.vignette;
                        // Gentle idle drift so the card is never dead-still.
                        const bob = Math.sin((frame + i * 40) * 0.045) * 7;
                        const tilt = Math.sin((frame + i * 40) * 0.03) * 0.7;
                        return (
                            <Plate3D
                                key={i}
                                pos={s.pos}
                                yaw={s.yaw}
                                camera={camera}
                                local={`translateY(${bob}px) rotateZ(${tilt}deg)`}
                            >
                                <Vignette t={frame - arrivals[i]} />
                            </Plate3D>
                        );
                    })}
                </Stage3D>
            </CameraMotionBlur>
            <SpeedStreaks intensity={(speed - 14) / 60} />
        </AbsoluteFill>
    );
};
