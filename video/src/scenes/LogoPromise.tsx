import React from 'react';
import {AbsoluteFill, interpolate, spring, useCurrentFrame, useVideoConfig} from 'remotion';
import {voDurationInFrames, voLeadIn} from '../beats';
import {color, type} from '../theme';
import {BrandLockup, DarkField} from './Brand';

/**
 * Beat — Logo promise (~0:13–0:19). Catches the cold open's HARD CUT: the
 * brand is present from frame 2 (fast, confident spring — no fade-in), the
 * promise lands with the read, then a held breath before the product
 * enters. "Even at 11pm." gets the butter — it is the promise.
 */
export const LogoPromise: React.FC = () => {
    const frame = useCurrentFrame();
    const {fps} = useVideoConfig();

    const voStart = voLeadIn('logo-promise');
    const voFrames = voDurationInFrames('logo-promise');
    const at = (word: number) => voStart + Math.round((word / 7) * voFrames);

    // Momentum: the lockup arrives immediately and hard.
    const lockupIn = spring({frame: frame - 2, fps, config: {damping: 26, stiffness: 190, mass: 0.7}});
    const lineIn = spring({frame: frame - at(1), fps, config: {damping: 30, stiffness: 120}});
    const promiseIn = spring({frame: frame - at(4), fps, config: {damping: 30, stiffness: 120}});

    return (
        <AbsoluteFill>
            <DarkField />
            <AbsoluteFill style={{justifyContent: 'center', alignItems: 'center', gap: 44}}>
                <div
                    style={{
                        transform: `scale(${interpolate(lockupIn, [0, 1], [0.82, 1])})`,
                        opacity: lockupIn,
                    }}
                >
                    <BrandLockup iconSize={148} wordSize={34} />
                </div>
                <div style={{textAlign: 'center'}}>
                    <div
                        style={{
                            ...type.display,
                            fontSize: 84,
                            color: color.marble.paper,
                            opacity: lineIn,
                            transform: `translateY(${interpolate(lineIn, [0, 1], [26, 0])}px)`,
                        }}
                    >
                        Your chairs, booked.
                    </div>
                    <div
                        style={{
                            ...type.display,
                            fontSize: 84,
                            color: color.marble.butter,
                            marginTop: 10,
                            opacity: promiseIn,
                            transform: `translateY(${interpolate(promiseIn, [0, 1], [26, 0])}px)`,
                        }}
                    >
                        Even at 11pm.
                    </div>
                </div>
            </AbsoluteFill>
        </AbsoluteFill>
    );
};
