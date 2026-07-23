import React from 'react';
import {AbsoluteFill, Img, interpolate, spring, useCurrentFrame, useVideoConfig} from 'remotion';
import {voDurationInFrames, voLeadIn} from '../beats';
import {getAsset} from '../manifest';
import {color, radius, type} from '../theme';
import {DarkField} from './Brand';

/**
 * Beat — Proof (~1:01–1:11). The rational close after the emotional arc:
 * three claims, each landing on its own phrase of the read, each carried by
 * a REAL product crop from the capture manifest — an actual booking row, an
 * actual stat tile, the actual embed snippet. Clean, confident, numeric.
 */

const ROWS: Array<{claim: string; assetKey: string | null; word: number; imgWidth: number}> = [
    // Word offsets in the 14-word segment where each phrase begins. The
    // embed row renders a styled snippet in-scene rather than the captured
    // crop — the crop carries the local dev URL (lvh.me:8000), which must
    // never appear in the film.
    {claim: 'Booked while you slept.', assetKey: 'crop-appointment-row', word: 0, imgWidth: 830},
    {claim: 'No-shows, down.', assetKey: 'crop-stat-tile', word: 5, imgWidth: 240},
    {claim: 'One line to go live.', assetKey: null, word: 8, imgWidth: 700},
];

const EmbedSnippet: React.FC<{width: number}> = ({width}) => (
    <div
        style={{
            width,
            backgroundColor: color.card,
            border: `1px solid ${color.border}`,
            padding: '26px 30px',
            fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
            fontSize: 22,
            lineHeight: 1.55,
            color: color.ink,
            textAlign: 'left',
        }}
    >
        {'<iframe src='}
        <span style={{color: color.accentInk}}>"https://yoursalon.bookthestyle.com/widget"</span>
        {'></iframe>'}
    </div>
);

export const Proof: React.FC = () => {
    const frame = useCurrentFrame();
    const {fps} = useVideoConfig();

    const voStart = voLeadIn('proof');
    const voFrames = voDurationInFrames('proof');
    const at = (word: number) => voStart + Math.round((word / 14) * voFrames);

    return (
        <AbsoluteFill>
            <DarkField />
            <AbsoluteFill style={{justifyContent: 'center'}}>
                <div style={{display: 'flex', flexDirection: 'column', gap: 58, paddingLeft: 170, paddingRight: 150}}>
                    {ROWS.map(({claim, assetKey, word, imgWidth}) => {
                        const rowIn = spring({frame: frame - at(word), fps, config: {damping: 32, stiffness: 110}});
                        return (
                            <div
                                key={assetKey}
                                style={{
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'space-between',
                                    gap: 60,
                                    opacity: rowIn,
                                    transform: `translateY(${interpolate(rowIn, [0, 1], [30, 0])}px)`,
                                }}
                            >
                                <div style={{...type.display, fontSize: 64, color: color.marble.paper, whiteSpace: 'nowrap'}}>
                                    {claim}
                                </div>
                                {/* The real thing, lifted straight from the product. */}
                                <div
                                    style={{
                                        borderRadius: radius.listCard,
                                        overflow: 'hidden',
                                        boxShadow: '0 14px 36px rgba(0,0,0,0.4)',
                                        border: `1px solid rgba(255,248,239,0.14)`,
                                        flexShrink: 0,
                                    }}
                                >
                                    {assetKey === null ? (
                                        <EmbedSnippet width={imgWidth} />
                                    ) : (
                                        <Img src={getAsset(assetKey).src} style={{width: imgWidth, display: 'block'}} />
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </AbsoluteFill>
        </AbsoluteFill>
    );
};
