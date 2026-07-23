import React from 'react';
import {AbsoluteFill, Img, Sequence, interpolate, useCurrentFrame} from 'remotion';
import {localBeat} from '../beats';
import {getAsset} from '../manifest';
import {DarkField} from './Brand';
import {slam, useAspect} from './kinetic';

/**
 * Build (track 16.51–20.06 — the riser, beats 32–38). One product hit PER
 * BEAT, slamming onto the dark field with alternating tilt — the strongest
 * crops and frames stacked, cuts tightening into the drop. No text: the
 * escalation is the message.
 */

const HITS: Array<{key: string; width: number; tilt: number}> = [
    {key: 'crop-widget-calendar-card', width: 700, tilt: -1.6},
    {key: 'crop-appointment-row', width: 1500, tilt: 1.2},
    {key: 'crop-stat-tile', width: 480, tilt: -1.4},
    {key: 'widget-05-calendar-open', width: 460, tilt: 1.6},
    {key: 'owner-services', width: 1500, tilt: -1.2},
    {key: 'crop-availability-card', width: 640, tilt: 1.4},
    {key: 'owner-calendar-day', width: 1560, tilt: 0},
];

export const Build: React.FC = () => {
    const frame = useCurrentFrame();
    const aspect = useAspect();
    const lb = (n: number) => localBeat('build', n);
    const end = lb(39);

    // The field tightens: a slow push through the whole riser.
    const fieldZoom = interpolate(frame, [0, end], [1, 1.06]);

    return (
        <AbsoluteFill>
            <DarkField />
            <AbsoluteFill style={{transform: `scale(${fieldZoom})`, justifyContent: 'center', alignItems: 'center'}}>
                {HITS.map(({key, width, tilt}, index) => {
                    const from = lb(32 + index);
                    const to = index === HITS.length - 1 ? end : lb(33 + index);
                    const asset = getAsset(key);
                    const scaled = aspect === 'wide' ? width : Math.round(width * 0.62);
                    return (
                        <Sequence key={key} from={from} durationInFrames={to - from} name={`hit@b${32 + index}`} layout="none">
                            <HitCard src={asset.src} width={scaled} tilt={tilt} />
                        </Sequence>
                    );
                })}
            </AbsoluteFill>
        </AbsoluteFill>
    );
};

const HitCard: React.FC<{src: string; width: number; tilt: number}> = ({src, width, tilt}) => {
    const frame = useCurrentFrame();
    const {scale, opacity} = slam(frame, 0, 1.22);
    return (
        <div
            style={{
                transform: `rotate(${tilt}deg) scale(${scale})`,
                opacity,
                borderRadius: 16,
                overflow: 'hidden',
                boxShadow: '0 26px 70px rgba(0,0,0,0.5)',
                border: '1px solid rgba(255,248,239,0.14)',
            }}
        >
            <Img src={src} style={{width, display: 'block'}} />
        </div>
    );
};
