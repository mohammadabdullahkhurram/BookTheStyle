import React from 'react';
import {AbsoluteFill, interpolate, spring, useCurrentFrame, useVideoConfig} from 'remotion';
import {voDurationInFrames, voLeadIn} from '../beats';
import {color, type} from '../theme';
import {BrandLockup, DarkField} from './Brand';

/**
 * Beat — Close (~1:11–1:18). End on brand: lockup, the ask, the address.
 * Everything is in place by ~1:15 and then HOLDS DEAD STILL — the final
 * ~2.5s is the film's poster frame, composed to be screenshotted.
 */
export const Close: React.FC = () => {
    const frame = useCurrentFrame();
    const {fps} = useVideoConfig();

    const voStart = voLeadIn('close');
    const voFrames = voDurationInFrames('close');
    const at = (word: number) => voStart + Math.round((word / 5) * voFrames);

    const lockupIn = spring({frame: frame - 4, fps, config: {damping: 28, stiffness: 140, mass: 0.8}});
    const askIn = spring({frame: frame - at(1), fps, config: {damping: 30, stiffness: 120}});
    const urlIn = spring({frame: frame - at(4), fps, config: {damping: 30, stiffness: 120}});

    return (
        <AbsoluteFill>
            <DarkField />
            <AbsoluteFill style={{justifyContent: 'center', alignItems: 'center', gap: 48}}>
                <div style={{transform: `scale(${interpolate(lockupIn, [0, 1], [0.86, 1])})`, opacity: lockupIn}}>
                    <BrandLockup iconSize={132} wordSize={31} />
                </div>
                <div
                    style={{
                        ...type.display,
                        fontSize: 118,
                        color: color.marble.paper,
                        opacity: askIn,
                        transform: `translateY(${interpolate(askIn, [0, 1], [26, 0])}px)`,
                        textAlign: 'center',
                    }}
                >
                    Fill every chair.
                </div>
                <div style={{display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 26, opacity: urlIn}}>
                    <div style={{width: 72, height: 1, backgroundColor: color.marble.paper, opacity: 0.22}} />
                    <div style={{...type.overline, fontSize: 27, color: color.marble.butter}}>
                        bookthestyle.com
                    </div>
                </div>
            </AbsoluteFill>
        </AbsoluteFill>
    );
};
