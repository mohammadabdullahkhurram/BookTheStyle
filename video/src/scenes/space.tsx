import React from 'react';
import {AbsoluteFill, Easing, interpolate, random, useVideoConfig} from 'remotion';
import {color} from '../theme';
import {useFilmLight} from './mode';

/**
 * The film's 3D world — a CSS-perspective camera rig. The camera is the
 * mathematical inverse of the world transform (rotate(-cam) · translate(-cam)
 * under a fixed `perspective`), which is exactly a view matrix: real depth,
 * real parallax, real travel. Card faces stay DOM (crisp Fraunces, real
 * frosted glass via backdrop-filter) instead of WebGL textures — that is the
 * documented approach choice, see docs/launch-video/README.md.
 *
 * Conventions: +x right, +y down (CSS), −z into the screen. An object whose
 * position equals the camera's position renders face-on at scale 1 (the
 * perspective container already seats the viewer PERSPECTIVE px in front).
 */

export type Vec3 = readonly [number, number, number];

export type CameraState = {
    pos: Vec3;
    /** Degrees. Yaw turns right, pitch looks down, roll banks clockwise. */
    yaw: number;
    pitch: number;
    roll: number;
};

export const PERSPECTIVE = 1100;

export const cam = (pos: Vec3, yaw = 0, pitch = 0, roll = 0): CameraState => ({pos, yaw, pitch, roll});

/** Camera keyframe. `ease` shapes the segment ARRIVING at this key. */
export type CamKey = {f: number; cam: CameraState; ease?: (t: number) => number};

/** The whip — near-still, violent middle, hard settle. The travel signature. */
export const whip = Easing.bezier(0.82, 0, 0.1, 1);

const lerp = (a: number, b: number, t: number): number => a + (b - a) * t;

/** Evaluate a keyframed camera path at a frame. Holds outside the ends. */
export const cameraPath = (frame: number, keys: CamKey[]): CameraState => {
    if (frame <= keys[0].f) return keys[0].cam;
    const last = keys[keys.length - 1];
    if (frame >= last.f) return last.cam;
    let i = 0;
    while (keys[i + 1].f <= frame) i++;
    const a = keys[i];
    const b = keys[i + 1];
    const t = (b.ease ?? Easing.linear)((frame - a.f) / (b.f - a.f));
    return {
        pos: [lerp(a.cam.pos[0], b.cam.pos[0], t), lerp(a.cam.pos[1], b.cam.pos[1], t), lerp(a.cam.pos[2], b.cam.pos[2], t)],
        yaw: lerp(a.cam.yaw, b.cam.yaw, t),
        pitch: lerp(a.cam.pitch, b.cam.pitch, t),
        roll: lerp(a.cam.roll, b.cam.roll, t),
    };
};

/** World-units the camera moved this frame — drives streaks + blur garnish. */
export const cameraSpeed = (frame: number, keys: CamKey[]): number => {
    const a = cameraPath(frame - 1, keys);
    const b = cameraPath(frame, keys);
    return Math.hypot(b.pos[0] - a.pos[0], b.pos[1] - a.pos[1], b.pos[2] - a.pos[2]);
};

export const distance = (a: Vec3, b: Vec3): number =>
    Math.hypot(a[0] - b[0], a[1] - b[1], a[2] - b[2]);

/** The viewport + inverse-transformed world. Everything inside is in world space. */
export const Stage3D: React.FC<{camera: CameraState; children: React.ReactNode}> = ({camera, children}) => (
    <AbsoluteFill style={{perspective: PERSPECTIVE, perspectiveOrigin: '50% 50%', overflow: 'hidden'}}>
        <AbsoluteFill
            style={{
                transformStyle: 'preserve-3d',
                transform:
                    `rotateZ(${-camera.roll}deg) rotateX(${-camera.pitch}deg) rotateY(${-camera.yaw}deg) `
                    + `translate3d(${-camera.pos[0]}px, ${-camera.pos[1]}px, ${-camera.pos[2]}px)`,
            }}
        >
            {children}
        </AbsoluteFill>
    </AbsoluteFill>
);

/**
 * A flat object at a world position. Fog and depth-of-field derive from TRUE
 * camera distance — framed objects are sharp, everything mid-flight softens.
 */
export const Plate3D: React.FC<{
    pos: Vec3;
    yaw?: number;
    pitch?: number;
    camera: CameraState;
    /** Extra transform applied in the plate's local space (e.g. idle drift). */
    local?: string;
    /** Fog start/end distances; opacity 1 inside, 0 past the end. */
    fog?: [number, number];
    dof?: boolean;
    style?: React.CSSProperties;
    children: React.ReactNode;
}> = ({pos, yaw = 0, pitch = 0, camera, local = '', fog = [1500, 3400], dof = true, style, children}) => {
    const dist = distance(pos, camera.pos);
    const opacity = interpolate(dist, [fog[0], fog[1]], [1, 0], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
    if (opacity <= 0.004) return null;
    const blur = dof
        ? interpolate(dist, [140, 600, 2600], [0, 1.2, 9], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'})
        : 0;
    return (
        <div
            style={{
                position: 'absolute',
                left: '50%',
                top: '50%',
                // Origin 0 0 keeps rotation exactly about the plate's centre
                // (the trailing -50% centring happens inside rotated space).
                transformOrigin: '0 0',
                transform:
                    `translate3d(${pos[0]}px, ${pos[1]}px, ${pos[2]}px) rotateY(${yaw}deg) rotateX(${pitch}deg) `
                    + `translate(-50%, -50%) ${local}`,
                opacity,
                filter: blur > 0.25 ? `blur(${blur}px)` : undefined,
                ...style,
            }}
        >
            {children}
        </div>
    );
};

/** The branded void, in either mode — warm Marble dark with accent fog, or
 *  the bright Marble field with a soft accent breath and an edge vignette.
 *  `breath` is the accent fog's hex alpha — the drop turns it up. */
export const Void: React.FC<{accent?: string; light?: boolean; breath?: string}> = ({accent = color.accent, light: lightProp, breath = '30'}) => {
    const light = lightProp ?? useFilmLight();

    return light ? (
        <AbsoluteFill>
            <AbsoluteFill
                style={{
                    background: `radial-gradient(120% 90% at 50% 44%, ${color.marble.paper} 0%, #F3E9DC 70%, #E9DCCC 100%)`,
                }}
            />
            {/* The accent breath survives on light too — softer, higher. */}
            <AbsoluteFill
                style={{
                    background: `radial-gradient(75% 60% at 50% 40%, ${accent}1c 0%, ${accent}00 62%)`,
                }}
            />
            <AbsoluteFill
                style={{
                    background: 'radial-gradient(130% 105% at 50% 50%, rgba(74,56,46,0) 66%, rgba(74,56,46,0.12) 100%)',
                }}
            />
        </AbsoluteFill>
    ) : (
        <AbsoluteFill>
            <AbsoluteFill
                style={{
                    background: `radial-gradient(120% 90% at 50% 42%, ${color.marble.sidebarDark} 0%, ${color.sidebarDarkPlum} 66%, #171116 100%)`,
                }}
            />
            {/* Accent breath in the fog — the only colour before the drop pays it off. */}
            <AbsoluteFill
                style={{
                    background: `radial-gradient(75% 60% at 50% 58%, ${accent}${breath} 0%, ${accent}00 62%)`,
                }}
            />
            <AbsoluteFill
                style={{
                    background: 'radial-gradient(120% 100% at 50% 50%, rgba(0,0,0,0) 58%, rgba(10,6,10,0.55) 100%)',
                }}
            />
        </AbsoluteFill>
    );
};

/** Faint floor grid receding to the horizon — the strongest dolly-parallax cue. */
export const GroundGrid: React.FC<{center: Vec3; light?: boolean; extent?: number}> = ({center, light: lightProp, extent = 7000}) => {
    const light = lightProp ?? useFilmLight();
    // Kept faint on light — through the frosted cards a stronger grid moirés.
    const line = light ? 'rgba(74,56,46,0.07)' : 'rgba(255,248,239,0.055)';
    return (
        <div
            style={{
                position: 'absolute',
                left: '50%',
                top: '50%',
                width: extent,
                height: extent * 0.72,
                transformOrigin: '0 0',
                transform: `translate3d(${center[0]}px, ${center[1]}px, ${center[2]}px) rotateX(90deg) translate(-50%, -50%)`,
                backgroundImage:
                    `repeating-linear-gradient(0deg, ${line} 0 1.5px, transparent 1.5px 130px),`
                    + `repeating-linear-gradient(90deg, ${line} 0 1.5px, transparent 1.5px 130px)`,
                maskImage: 'radial-gradient(closest-side, black 18%, transparent 72%)',
                WebkitMaskImage: 'radial-gradient(closest-side, black 18%, transparent 72%)',
            }}
        />
    );
};

type ParticleSpec = {pos: Vec3; size: number; phase: number; accent: boolean};

const makeParticles = (seed: string, count: number, min: Vec3, max: Vec3): ParticleSpec[] =>
    Array.from({length: count}, (_, i) => ({
        pos: [
            lerp(min[0], max[0], random(`${seed}-x-${i}`)),
            lerp(min[1], max[1], random(`${seed}-y-${i}`)),
            lerp(min[2], max[2], random(`${seed}-z-${i}`)),
        ] as const,
        size: 2.5 + random(`${seed}-s-${i}`) * 4.5,
        phase: random(`${seed}-p-${i}`) * Math.PI * 2,
        accent: random(`${seed}-a-${i}`) > 0.8,
    }));

/** Dust suspended in the void. Deterministic (seeded), slow idle bob. */
export const Particles: React.FC<{
    camera: CameraState;
    frame: number;
    seed: string;
    count?: number;
    min: Vec3;
    max: Vec3;
    accent?: string;
    light?: boolean;
}> = ({camera, frame, seed, count = 110, min, max, accent = color.accent, light: lightProp}) => {
    const light = lightProp ?? useFilmLight();
    const specs = React.useMemo(() => makeParticles(seed, count, min, max), [seed, count, min, max]);
    return (
        <>
            {specs.map((p, i) => {
                const dist = distance(p.pos, camera.pos);
                const opacity = interpolate(dist, [120, 420, 2000, 3200], [0, 0.85, 0.55, 0], {
                    extrapolateLeft: 'clamp',
                    extrapolateRight: 'clamp',
                });
                if (opacity <= 0.01) return null;
                const bob = Math.sin(frame * 0.035 + p.phase) * 16;
                // Light keeps the accent share (saturated, no glow) so the
                // drop's recolor still touches the atmosphere.
                const tint = light
                    ? p.accent
                        ? accent
                        : 'rgba(74,56,46,0.5)'
                    : p.accent
                        ? accent
                        : 'rgba(255,248,239,0.75)';
                return (
                    <div
                        key={i}
                        style={{
                            position: 'absolute',
                            left: '50%',
                            top: '50%',
                            width: p.size,
                            height: p.size,
                            borderRadius: '50%',
                            backgroundColor: tint,
                            boxShadow: light ? 'none' : `0 0 ${p.size * 3}px ${p.accent ? accent : 'rgba(255,248,239,0.4)'}`,
                            transform: `translate3d(${p.pos[0]}px, ${p.pos[1] + bob}px, ${p.pos[2]}px)`,
                            opacity,
                        }}
                    />
                );
            })}
        </>
    );
};

export type PanelSpec = {pos: Vec3; w: number; h: number; yaw?: number};

/** The decorative slab look, per mode: paper-ghost glass on dark; on light,
 *  a barely-there white pane whose SHADOW does the depth work glow did. */
const panelStyle = (light: boolean, w: number, h: number): React.CSSProperties => ({
    width: w,
    height: h,
    borderRadius: 22,
    border: light ? '1px solid rgba(74,56,46,0.12)' : '1px solid rgba(255,248,239,0.10)',
    background: light
        ? 'linear-gradient(160deg, rgba(255,255,255,0.55), rgba(255,248,239,0.25))'
        : 'linear-gradient(160deg, rgba(255,248,239,0.05), rgba(255,248,239,0.012))',
    boxShadow: light ? '0 22px 50px rgba(52,33,45,0.10)' : 'none',
});

/** Hand-placed decorative slabs — for spaces where random placement would
 *  drift panels across the content cards (the groove flight path). */
export const PlacedPanels: React.FC<{camera: CameraState; specs: PanelSpec[]}> = ({camera, specs}) => {
    const light = useFilmLight();

    return (
        <>
            {specs.map((g, i) => (
                <Plate3D key={i} pos={g.pos} yaw={g.yaw ?? 0} camera={camera} fog={[1100, 2600]}>
                    <div style={panelStyle(light, g.w, g.h)} />
                </Plate3D>
            ))}
        </>
    );
};

/** Decorative empty glass slabs to fly past — depth extras, never content. */
export const GhostPanels: React.FC<{camera: CameraState; seed: string; count?: number; min: Vec3; max: Vec3}> = ({
    camera,
    seed,
    count = 7,
    min,
    max,
}) => {
    const specs = React.useMemo(
        () =>
            Array.from({length: count}, (_, i) => ({
                pos: [
                    lerp(min[0], max[0], random(`${seed}-gx-${i}`)),
                    lerp(min[1], max[1], random(`${seed}-gy-${i}`)),
                    lerp(min[2], max[2], random(`${seed}-gz-${i}`)),
                ] as const,
                w: 200 + random(`${seed}-gw-${i}`) * 260,
                h: 140 + random(`${seed}-gh-${i}`) * 160,
                yaw: -24 + random(`${seed}-gr-${i}`) * 48,
            })),
        [seed, count, min, max],
    );
    const light = useFilmLight();

    return (
        <>
            {specs.map((g, i) => (
                <Plate3D key={i} pos={g.pos} yaw={g.yaw} camera={camera} fog={[1100, 2600]}>
                    <div style={panelStyle(light, g.w, g.h)} />
                </Plate3D>
            ))}
        </>
    );
};

/** Screen-space light streaks in the travel direction — garnish over the real
 *  motion blur, only visible while the camera is actually fast. */
export const SpeedStreaks: React.FC<{intensity: number; seed?: string}> = ({intensity, seed = 'streaks'}) => {
    const light = useFilmLight();
    if (intensity <= 0.02) return null;
    // Light streaks on dark; ink streaks (softer) on light — white-on-light
    // simply vanishes.
    const tone = light ? '74,56,46' : '255,248,239';
    const peak = light ? 0.28 : 0.5;
    return (
        <AbsoluteFill style={{overflow: 'hidden', opacity: Math.min(1, intensity)}}>
            {Array.from({length: 9}, (_, i) => {
                const y = 6 + random(`${seed}-y-${i}`) * 88;
                const w = 220 + random(`${seed}-w-${i}`) * 480;
                const x = random(`${seed}-x-${i}`) * 100;
                return (
                    <div
                        key={i}
                        style={{
                            position: 'absolute',
                            top: `${y}%`,
                            left: `${x - 20}%`,
                            width: w,
                            height: 2,
                            borderRadius: 2,
                            background: `linear-gradient(90deg, rgba(${tone},0) 0%, rgba(${tone},${peak}) 50%, rgba(${tone},0) 100%)`,
                        }}
                    />
                );
            })}
        </AbsoluteFill>
    );
};

/** Mix two hexes — used to "light" the accent for strokes/type on the dark field. */
export const mix = (a: string, b: string, t: number): string => {
    const pa = [1, 3, 5].map((i) => parseInt(a.slice(i, i + 2), 16));
    const pb = [1, 3, 5].map((i) => parseInt(b.slice(i, i + 2), 16));
    return `#${pa.map((v, i) => Math.round(lerp(v, pb[i], t)).toString(16).padStart(2, '0')).join('')}`;
};

/** The accent as it reads on the dark void — lifted toward paper. */
export const lit = (accent: string): string => mix(accent, color.marble.paper, 0.5);
