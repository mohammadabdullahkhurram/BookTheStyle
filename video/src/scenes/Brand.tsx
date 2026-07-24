import React from 'react';
import {Img, staticFile} from 'remotion';
import {color, font} from '../theme';

/**
 * The film's brand lockup — the same compact treatment the app's sidebar
 * uses (icon + the full word as real text; the raster full-logo's
 * scissors-B stops reading below ~40px). On the dark Marble field the dark
 * glyph rides a cream app-icon tile so the mark itself never has to invert.
 */
export const BrandLockup: React.FC<{iconSize?: number; wordSize?: number; stacked?: boolean}> = ({
    iconSize = 120,
    wordSize = 30,
    stacked = true,
}) => (
    <div
        style={{
            display: 'flex',
            flexDirection: stacked ? 'column' : 'row',
            alignItems: 'center',
            gap: stacked ? 34 : 22,
        }}
    >
        <div
            style={{
                width: iconSize,
                height: iconSize,
                borderRadius: iconSize * 0.24,
                backgroundColor: color.marble.paper,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                boxShadow: '0 16px 40px rgba(0, 0, 0, 0.35)',
            }}
        >
            <Img src={staticFile('brand/icon-logo.png')} style={{width: '72%', height: '72%', objectFit: 'contain'}} />
        </div>
        <div
            style={{
                fontFamily: font.body,
                fontWeight: 600,
                fontSize: wordSize,
                letterSpacing: '0.22em',
                textTransform: 'uppercase',
                color: color.marble.paper,
            }}
        >
            BookTheStyle
        </div>
    </div>
);
