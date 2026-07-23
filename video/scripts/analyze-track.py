#!/usr/bin/env python3
"""Regenerates the beat grid behind src/beat-map.json from the music track.

Method (documented for reproducibility): librosa `beat_track` over a
22050 Hz mono decode of public/audio/music.mp3, plus a per-beat RMS energy
profile used to locate structure (groove entry, riser, THE drop = global
energy peak, outro collapse). The section boundaries in beat-map.json are
CURATED from this script's printout — musical judgment, recorded as data —
so re-running the script prints the evidence but deliberately does not
overwrite the sections.

    python3 -m venv .analysis-venv && .analysis-venv/bin/pip install librosa
    npx remotion ffmpeg -i public/audio/music.mp3 -ar 22050 -ac 1 /tmp/music.wav
    .analysis-venv/bin/python scripts/analyze-track.py /tmp/music.wav
"""
import json
import sys

import librosa
import numpy as np

wav = sys.argv[1] if len(sys.argv) > 1 else '/tmp/music.wav'
y, sr = librosa.load(wav, sr=22050)
duration = len(y) / sr

tempo, beat_frames = librosa.beat.beat_track(y=y, sr=sr, trim=False)
tempo = float(np.atleast_1d(tempo)[0])
beat_times = librosa.frames_to_time(beat_frames, sr=sr)

rms = librosa.feature.rms(y=y)[0]
rms_t = librosa.frames_to_time(np.arange(len(rms)), sr=sr)


def rms_at(t0: float, t1: float) -> float:
    mask = (rms_t >= t0) & (rms_t < t1)
    return float(rms[mask].mean()) if mask.any() else 0.0


beat_rms = [
    rms_at(beat_times[i], beat_times[i + 1] if i + 1 < len(beat_times) else duration)
    for i in range(len(beat_times))
]
peak = max(beat_rms)

print(f'duration={duration:.2f}s tempo={tempo:.1f}bpm beats={len(beat_times)}')
for i, (t, e) in enumerate(zip(beat_times, beat_rms)):
    print(f'{i:3d} {t:6.2f}s {e / peak:5.2f} ' + '#' * int(40 * e / peak))

print('\nJSON for beat-map.json (merge by hand; keep curated sections):')
print(json.dumps({
    'bpm': round(tempo, 1),
    'duration_seconds': round(duration, 3),
    'beat_times': [round(float(t), 4) for t in beat_times],
    'beat_energy': [round(e / peak, 4) for e in beat_rms],
}))
