import React from 'react';
import {AbsoluteFill, Img, interpolate, useCurrentFrame} from 'remotion';
import {getAsset} from '../manifest';
import {color, type} from '../theme';

/**
 * Beat — Your side (~0:35–0:49). The morning after, on the desktop: the
 * booking is just THERE. Three product moments arrive in the read's order —
 * today's dashboard ("there in the morning"), her profile ("in her
 * profile"), and the master week ("the week fills itself in") — under the
 * accent-hero's dark-field-over-product treatment, so beats B and D share
 * one visual language. Motion is the data arriving: each moment pushes in
 * slowly; rows never sit still-frame-static.
 */

const MOMENTS = [
    {key: 'owner-dashboard--marble', label: 'There in the morning', from: 0, to: 105},
    {key: 'owner-client-profile', label: 'In her profile', from: 105, to: 240},
    {key: 'owner-calendar-week', label: 'The week fills itself in', from: 240, to: 426},
];

const FADE = 16;

export const YourSide: React.FC = () => {
    const frame = useCurrentFrame();

    return (
        <AbsoluteFill style={{backgroundColor: color.marble.paper}}>
            {MOMENTS.map(({key, label, from, to}, index) => {
                const asset = getAsset(key);
                const opacity = Math.min(
                    index === 0 ? 1 : interpolate(frame, [from - FADE / 2, from + FADE / 2], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'}),
                    index === MOMENTS.length - 1 ? 1 : interpolate(frame, [to - FADE / 2, to + FADE / 2], [1, 0], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'}),
                );
                // Each moment gets its own slow push — restarting the motion
                // makes the arrival read as "new data", not a slideshow.
                const zoom = interpolate(frame, [from, to], [1.04, 1.095], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
                const labelIn = interpolate(frame, [from + 14, from + 34], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});

                return (
                    <AbsoluteFill key={key} style={{opacity}}>
                        <AbsoluteFill style={{transform: `scale(${zoom})`, transformOrigin: '50% 34%'}}>
                            <Img src={asset.src} style={{width: '100%', height: '100%', objectFit: 'cover'}} />
                        </AbsoluteFill>
                        {/* The shared dark feather — type stays legible, the
                            screenshot's own sidebar disappears. */}
                        <AbsoluteFill
                            style={{
                                background: 'linear-gradient(94deg, rgba(36,28,34,0.92) 0%, rgba(36,28,34,0.78) 20%, rgba(36,28,34,0.28) 38%, rgba(36,28,34,0) 54%)',
                            }}
                        />
                        {/* Anchor the label zone: without this the cream type
                            crosses into the light calendar area and drowns. */}
                        <AbsoluteFill
                            style={{
                                background: 'linear-gradient(24deg, rgba(36,28,34,0.94) 0%, rgba(36,28,34,0.6) 20%, rgba(36,28,34,0) 44%)',
                            }}
                        />
                        <div style={{position: 'absolute', left: 110, bottom: 110, opacity: labelIn}}>
                            <div style={{...type.overline, fontSize: 23, color: color.marble.butter, marginBottom: 14}}>
                                Your side
                            </div>
                            <div style={{...type.display, fontFamily: type.display.fontFamily, fontSize: 66, color: color.marble.paper}}>
                                {label}
                            </div>
                        </div>
                    </AbsoluteFill>
                );
            })}
        </AbsoluteFill>
    );
};
