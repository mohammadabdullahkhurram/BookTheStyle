import React from 'react';
import {AbsoluteFill, Img, interpolate, useCurrentFrame} from 'remotion';
import {localBeat} from '../beats';
import {getAsset} from '../manifest';
import {color, type} from '../theme';
import {KineticCard, PhoneFrame, useAspect} from './kinetic';

/**
 * DROP (track 20.06–27.14 — beats 39–52). The film's reason to exist: the
 * SAME screen recoloring through the four captured accent variants, variant
 * changes ON beats 39/42/45/48 — and the near-black finale lands exactly on
 * beat 48 (24.64s), the track's global energy peak, holding through the
 * fall. Phone widget matches every change; the swatch rail carries the hex;
 * "Yours." slams with the peak. This must feel like product behavior at the
 * speed of the music.
 */

const VARIANTS = ['01', '02', '03', '04'].map((n) => ({
    dashboard: getAsset(`owner-dashboard--accent-${n}`),
    widget: getAsset(`widget-calendar--accent-${n}`),
}));

const CROSSFADE = 8; // two frames shy of a sixteenth — a musical snap

export const Drop: React.FC = () => {
    const frame = useCurrentFrame();
    const aspect = useAspect();
    const lb = (n: number) => localBeat('drop', n);
    const boundaries = [0, lb(42), lb(45), lb(48)];
    const end = lb(53);
    const active = boundaries.filter((b) => frame >= b).length - 1;

    const opacityOf = (index: number): number => {
        const start = boundaries[index];
        const stop = index === VARIANTS.length - 1 ? end : boundaries[index + 1];
        const fadeIn = index === 0
            ? 1
            : interpolate(frame, [start - CROSSFADE / 2, start + CROSSFADE / 2], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
        const fadeOut = index === VARIANTS.length - 1
            ? 1
            : interpolate(frame, [stop - CROSSFADE / 2, stop + CROSSFADE / 2], [1, 0], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
        return Math.min(fadeIn, fadeOut);
    };

    const zoom = interpolate(frame, [0, end], [1.04, 1.1]);
    const phoneWidth = aspect === 'tall' ? 620 : aspect === 'square' ? 440 : 402;

    return (
        <AbsoluteFill style={{backgroundColor: color.marble.paper}}>
            <AbsoluteFill style={{transform: `scale(${zoom})`, transformOrigin: '42% 38%'}}>
                {VARIANTS.map(({dashboard}, index) => (
                    <AbsoluteFill key={dashboard.key} style={{opacity: opacityOf(index)}}>
                        <Img
                            src={dashboard.src}
                            style={{width: '100%', height: '100%', objectFit: 'cover', objectPosition: aspect === 'wide' ? '50% 30%' : '30% 25%'}}
                        />
                    </AbsoluteFill>
                ))}
            </AbsoluteFill>

            {/* The dark feather — Beat-A world bleeding over the product. */}
            <AbsoluteFill
                style={{
                    background: 'linear-gradient(94deg, rgba(36,28,34,0.95) 0%, rgba(36,28,34,0.85) 22%, rgba(36,28,34,0.4) 38%, rgba(36,28,34,0) 55%)',
                }}
            />

            {/* Matching branded widget — the recolor is the WHOLE product. */}
            <div
                style={{
                    position: 'absolute',
                    right: aspect === 'wide' ? 120 : '50%',
                    top: '50%',
                    transform: aspect === 'wide' ? 'translateY(-50%)' : 'translate(50%, -46%)',
                }}
            >
                <PhoneFrame width={phoneWidth}>
                    {VARIANTS.map(({widget}, index) => (
                        <Img
                            key={widget.key}
                            src={widget.src}
                            style={{position: 'absolute', inset: 0, width: '100%', objectFit: 'cover', objectPosition: 'top', opacity: opacityOf(index)}}
                        />
                    ))}
                </PhoneFrame>
            </div>

            {/* Swatch rail: the live hex, snapping with each recolor. */}
            <div
                style={{
                    position: 'absolute',
                    left: aspect === 'wide' ? 110 : 70,
                    bottom: aspect === 'tall' ? 170 : 96,
                    display: 'flex',
                    alignItems: 'center',
                    gap: 20,
                }}
            >
                {VARIANTS.map(({dashboard}, index) => (
                    <div
                        key={dashboard.key}
                        style={{
                            width: index === active ? 52 : 32,
                            height: index === active ? 52 : 32,
                            borderRadius: '50%',
                            backgroundColor: dashboard.accent,
                            border: `3px solid ${index === active ? '#FFFFFF' : 'rgba(255,255,255,0.45)'}`,
                        }}
                    />
                ))}
                <div style={{...type.overline, fontSize: 20, color: '#FFFFFF', opacity: 0.92, marginLeft: 8}}>
                    {VARIANTS[active].dashboard.accent}
                </div>
            </div>

            {/* The word, ON the peak. */}
            <KineticCard at={lb(48)} accent size={aspect === 'wide' ? 150 : 110} position={aspect === 'tall' ? {left: 70, top: 200, bottom: 'auto'} : {left: aspect === 'wide' ? 110 : 70, bottom: 220}}>
                Yours.
            </KineticCard>
        </AbsoluteFill>
    );
};
