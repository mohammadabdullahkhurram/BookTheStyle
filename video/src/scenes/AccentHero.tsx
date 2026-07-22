import React from 'react';
import {AbsoluteFill, Img, interpolate, spring, useCurrentFrame, useVideoConfig} from 'remotion';
import {wordsToFrames} from '../beats';
import {getAsset} from '../manifest';
import {color, font, overlayShadow, type} from '../theme';

/**
 * Beat B — Accent hero (0:50–1:02), "Make it yours". The SAME dashboard,
 * the SAME phone widget, cross-dissolving through the four captured accent
 * variants — the recolor is real product footage (identical screen, data,
 * and scroll; only the accent differs — that determinism is the whole
 * reason the capture harness exists). The salon's own logo rides in on the
 * widget frames; a swatch rail tracks the live hex so the eye reads
 * "one color in, whole product out."
 *
 * VO (22 words ≈ 8.1s inside the 12s beat): type stays minimal — the UI
 * carries the moment.
 */

const VARIANTS = ['01', '02', '03', '04'].map((n) => ({
    dashboard: getAsset(`owner-dashboard--accent-${n}`),
    widget: getAsset(`widget-calendar--accent-${n}`),
}));

const CROSSFADE = 18; // frames of dissolve between variants

/** Opacity of variant i at `frame`, given equal segments across `total`. */
const variantOpacity = (index: number, frame: number, total: number): number => {
    const segment = total / VARIANTS.length;
    const start = index * segment;
    const end = start + segment;

    const fadeIn = index === 0
        ? 1 // the first variant is already on screen when the beat opens
        : interpolate(frame, [start - CROSSFADE / 2, start + CROSSFADE / 2], [0, 1], {
            extrapolateLeft: 'clamp',
            extrapolateRight: 'clamp',
        });
    const fadeOut = index === VARIANTS.length - 1
        ? 1 // the last variant holds to the cut
        : interpolate(frame, [end - CROSSFADE / 2, end + CROSSFADE / 2], [1, 0], {
            extrapolateLeft: 'clamp',
            extrapolateRight: 'clamp',
        });

    return Math.min(fadeIn, fadeOut);
};

export const AccentHero: React.FC<{durationInFrames: number}> = ({durationInFrames}) => {
    const frame = useCurrentFrame();
    const {fps} = useVideoConfig();
    const segment = durationInFrames / VARIANTS.length;
    const active = Math.min(VARIANTS.length - 1, Math.floor(frame / segment));

    // A slow push-in gives the stills life without reading as a slideshow.
    const zoom = interpolate(frame, [0, durationInFrames], [1.03, 1.085]);

    // The phone arrives on a spring once the beat has established the screen.
    const phoneIn = spring({frame: frame - 26, fps, config: {damping: 32, stiffness: 90, mass: 1.1}});
    const phoneX = interpolate(phoneIn, [0, 1], [340, 0]);

    // Type: "One color." lands with the VO's "One color" (~word 5); the
    // closing "Not ours." with word 20.
    const titleAt = wordsToFrames(5);
    const titleIn = spring({frame: frame - titleAt, fps, config: {damping: 30, stiffness: 120}});
    const closerAt = wordsToFrames(20);
    const closerIn = spring({frame: frame - closerAt, fps, config: {damping: 30, stiffness: 120}});

    return (
        <AbsoluteFill style={{backgroundColor: color.marble.paper, fontFamily: font.body}}>
            {/* The product, full bleed — all four accent variants stacked. */}
            <AbsoluteFill style={{transform: `scale(${zoom})`, transformOrigin: '42% 38%'}}>
                {VARIANTS.map(({dashboard}, index) => (
                    <AbsoluteFill key={dashboard.key} style={{opacity: variantOpacity(index, frame, durationInFrames)}}>
                        <Img src={dashboard.src} style={{width: '100%', height: '100%', objectFit: 'cover'}} />
                    </AbsoluteFill>
                ))}
            </AbsoluteFill>

            {/* The left third is Beat A's dark Marble field, feathered onto the
                product — the film's type voice continues, and cream/butter
                text stays AA-legible instead of drowning in the light UI.
                It also swallows the screenshot's own sidebar, so the frame
                never shows two competing navs. */}
            <AbsoluteFill
                style={{
                    background: 'linear-gradient(94deg, rgba(36,28,34,0.97) 0%, rgba(36,28,34,0.94) 26%, rgba(36,28,34,0.55) 40%, rgba(36,28,34,0) 56%)',
                }}
            />

            {/* The phone — the salon's OWN branded widget, matching every recolor. */}
            <div
                style={{
                    position: 'absolute',
                    right: 110,
                    top: '50%',
                    transform: `translateY(-50%) translateX(${phoneX}px) rotate(${interpolate(phoneIn, [0, 1], [3.5, 0])}deg)`,
                    opacity: phoneIn,
                    width: 372,
                    height: 806,
                    borderRadius: 54,
                    padding: 12,
                    backgroundColor: color.sidebarDarkPlum,
                    boxShadow: overlayShadow,
                }}
            >
                <div style={{width: '100%', height: '100%', borderRadius: 44, overflow: 'hidden', position: 'relative'}}>
                    {VARIANTS.map(({widget}, index) => (
                        <Img
                            key={widget.key}
                            src={widget.src}
                            style={{
                                position: 'absolute',
                                inset: 0,
                                width: '100%',
                                objectFit: 'cover',
                                objectPosition: 'top',
                                opacity: variantOpacity(index, frame, durationInFrames),
                            }}
                        />
                    ))}
                </div>
            </div>

            {/* Type block, left third. */}
            <div style={{position: 'absolute', left: 110, top: 300, maxWidth: 640}}>
                <div
                    style={{
                        ...type.overline,
                        fontSize: 24,
                        color: color.marble.butter,
                        opacity: 0.9 * titleIn,
                        marginBottom: 26,
                    }}
                >
                    Make it yours
                </div>
                <div
                    style={{
                        ...type.display,
                        fontFamily: font.display,
                        fontSize: 108,
                        color: color.marble.paper,
                        opacity: titleIn,
                        transform: `translateY(${interpolate(titleIn, [0, 1], [28, 0])}px)`,
                    }}
                >
                    One color.
                    <br />
                    The whole thing.
                </div>
                <div
                    style={{
                        ...type.heading,
                        fontFamily: font.display,
                        fontSize: 44,
                        color: color.marble.butter,
                        marginTop: 30,
                        opacity: closerIn,
                        transform: `translateY(${interpolate(closerIn, [0, 1], [22, 0])}px)`,
                    }}
                >
                    Yours. Not ours.
                </div>
            </div>

            {/* Swatch rail — the live hex, tracking each recolor. */}
            <div
                style={{
                    position: 'absolute',
                    left: 110,
                    bottom: 96,
                    display: 'flex',
                    alignItems: 'center',
                    gap: 22,
                    opacity: interpolate(frame, [10, 30], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'}),
                }}
            >
                {VARIANTS.map(({dashboard}, index) => (
                    <div
                        key={dashboard.key}
                        style={{
                            width: index === active ? 54 : 34,
                            height: index === active ? 54 : 34,
                            borderRadius: '50%',
                            backgroundColor: dashboard.accent,
                            border: `3px solid ${index === active ? '#FFFFFF' : 'rgba(255,255,255,0.45)'}`,
                            transition: 'none',
                            boxShadow: index === active ? '0 4px 18px rgba(33,28,24,0.35)' : 'none',
                        }}
                    />
                ))}
                <div style={{...type.overline, fontSize: 21, color: '#FFFFFF', opacity: 0.9, marginLeft: 10}}>
                    {VARIANTS[active].dashboard.accent}
                </div>
            </div>
        </AbsoluteFill>
    );
};
