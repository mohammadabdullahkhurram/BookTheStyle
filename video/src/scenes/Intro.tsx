import React from 'react';
import {AbsoluteFill, interpolate, useCurrentFrame} from 'remotion';
import {localBeat} from '../beats';
import {BrandLockup, DarkField} from './Brand';
import {KineticCard, slam, useAspect} from './kinetic';

/**
 * Intro (track 0:00–4.30 — sparse open). Two hits only: the lockup slams on
 * beat 0, the hook card takes over on beat 4. Hard cut into the groove.
 */
export const Intro: React.FC = () => {
    const frame = useCurrentFrame();
    const aspect = useAspect();
    const b0 = localBeat('intro', 0);
    const b4 = localBeat('intro', 4);

    const lockup = slam(frame, b0, 1.18);
    const lockupOut = interpolate(frame, [b4 - 5, b4], [1, 0], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});

    return (
        <AbsoluteFill>
            <DarkField />
            <AbsoluteFill style={{justifyContent: 'center', alignItems: 'center'}}>
                <div style={{transform: `scale(${lockup.scale})`, opacity: lockup.opacity * lockupOut}}>
                    <BrandLockup iconSize={aspect === 'tall' ? 200 : 164} wordSize={aspect === 'tall' ? 40 : 36} />
                </div>
            </AbsoluteFill>
            <KineticCard at={b4} center size={aspect === 'wide' ? 96 : 76} position={{bottom: '46%'}}>
                Empty chair at 2pm?
            </KineticCard>
        </AbsoluteFill>
    );
};
