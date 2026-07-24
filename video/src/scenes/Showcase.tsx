import {CameraMotionBlur} from '@remotion/motion-blur';
import React from 'react';
import {AbsoluteFill, Easing, useCurrentFrame} from 'remotion';
import {FRAMES_PER_BEAT, localBeat} from '../beats';
import {useAspect} from './kinetic';
import {
    cam,
    cameraPath,
    GroundGrid,
    PlacedPanels,
    Particles,
    Plate3D,
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
 * clients. Arrivals land ON beats 8/14/20/26 (timing untouched) — but every
 * hold and every transition has its OWN camera character now:
 *
 *   booking   crane-down reveal, then a slow push
 *   → voice   hard lateral whip · hold is a slow ORBIT around the orb
 *   → calendar rise-OVER the void (crest between stations) · hold PULLS BACK
 *   → clients  dive with a dutch tip · hold is a lateral dolly drift
 *   → build    whip out with a roll kick
 *
 * The booking confirmation still lands on beat 12 — the 6.34s accent hit.
 */

type Station = {pos: Vec3; yaw: number; vignette: React.FC<{t: number}>};

const STATIONS: Station[] = [
    {pos: [0, 0, 0], yaw: 0, vignette: BookingVignette},
    {pos: [1560, -70, -780], yaw: 12, vignette: VoiceVignette},
    {pos: [3160, 90, -1360], yaw: -9, vignette: CalendarVignette},
    {pos: [4680, -50, -640], yaw: 7, vignette: ClientsVignette},
];

/** Camera on a small arc in front of a station: `deg` swings the view around
 *  the card (0 = face-on), `r` is the standoff, `dy` lifts/lowers. The card
 *  stays framed; the world parallaxes past it. */
const aim = (s: Station, deg: number, r: number, pull: number, dy = 0, roll = 0, pitch = 0): CameraState => {
    const rad = ((deg + s.yaw) * Math.PI) / 180;
    const standoff = r + pull;
    return cam(
        [s.pos[0] + Math.sin(rad) * standoff, s.pos[1] + dy, s.pos[2] + Math.cos(rad) * standoff],
        s.yaw - deg,
        pitch,
        roll,
    );
};

const glide = Easing.bezier(0.4, 0, 0.2, 1);
const floatEase = Easing.bezier(0.33, 0, 0.2, 1);

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
    const B = FRAMES_PER_BEAT;
    const [s0, s1, s2, s3] = STATIONS;

    const keys: CamKey[] = [
        // BOOKING — crane-down: open high and tilted, settle face-on inside
        // the first beat, then a slow floating push through the hold.
        {f: arrivals[0], cam: aim(s0, 0, 170, pull, -300, -1.5, 14)},
        {f: arrivals[0] + Math.round(B * 0.9), cam: aim(s0, 0, 52, pull), ease: glide},
        {f: departs[0], cam: aim(s0, 2, -18, pull), ease: floatEase},

        // → VOICE: the hard lateral whip (the travel signature), landing into
        // a slow ORBIT — the camera arcs around the orb through the hold.
        {f: arrivals[1], cam: aim(s1, -11, 56, pull, 0, 0.8), ease: whip},
        {f: departs[1], cam: aim(s1, 11, 44, pull, -14)},

        // → CALENDAR: rise OVER the void between stations (crest key), drop
        // in close, then PULL BACK through the hold to reveal the whole grid.
        {f: Math.round((departs[1] + arrivals[2]) / 2), cam: cam([2360, -430, -560 + pull], 0, 16, -2), ease: glide},
        {f: arrivals[2], cam: aim(s2, 0, 16, pull), ease: whip},
        {f: departs[2], cam: aim(s2, -4, 108, pull, -26), ease: floatEase},

        // → CLIENTS: dive with a dutch tip (trough key), then a lateral dolly
        // drift across the constellation — the only sideways hold.
        {f: Math.round((departs[2] + arrivals[3]) / 2), cam: cam([3980, 300, -840 + pull], 4, -10, -6), ease: glide},
        {f: arrivals[3], cam: aim(s3, 8, 58, pull, 0, 2), ease: whip},
        {f: departs[3], cam: aim(s3, -7, 52, pull, 8)},

        // → BUILD: whip out with a roll kick.
        {f: localBeat('showcase', 32), cam: cam([5500, -40, 420], -4, -3, 2.5), ease: whip},
    ];

    const camera = cameraPath(frame, keys);

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
        </AbsoluteFill>
    );
};
