import React from 'react';
import {AbsoluteFill} from 'remotion';
import type {Beat} from '../beats';
import {color, type} from '../theme';

/** Placeholder for beats not yet built — the timing sheet made visible. */
export const Slate: React.FC<{beat: Beat}> = ({beat}) => (
    <AbsoluteFill
        style={{
            backgroundColor: color.paper,
            justifyContent: 'center',
            alignItems: 'center',
            gap: 18,
        }}
    >
        <div style={{...type.overline, fontSize: 22, color: color.accentInk}}>{beat.script}</div>
        <div style={{...type.display, fontSize: 84, color: color.ink}}>{beat.id}</div>
        <div style={{...type.body, fontSize: 26, color: color.muted}}>
            {beat.durationInFrames} frames · assets: {beat.assets.length ? beat.assets.join(', ') : 'none'}
        </div>
    </AbsoluteFill>
);
