# How-to documentation (instructional videos)

Short "watch how" videos attached to parts of the app via the help system
(`x-ui.help-trigger` + `x-ui.help-modal`, registry in `config/help.php`).

## Layout — one subfolder per topic

```
public/how-to-documentation/
  calendar-sync/
    video.mp4        # the footage (preferred; H.264/AAC plays everywhere)
    video.webm       # optional alternate source
    poster.jpg       # optional still shown before the video loads
  <next-topic>/
    video.mp4
    ...
```

The subfolder name is the **doc key** used in the registry (e.g. `calendar-sync`).

## Expected filenames

- `video.mp4` and/or `video.webm` — the player uses whichever exist.
- `poster.jpg` — optional poster image.

If no video file is present, the help modal shows the written steps plus a
tasteful "video coming soon" placeholder — never a broken player. So you can ship
the help content first and drop the footage in later.

## Add a new how-to (two steps)

1. Create `public/how-to-documentation/<key>/` and drop `video.mp4` (and an
   optional `poster.jpg`).
2. Register it in `config/help.php` under `docs` with a `title`, the `video`
   path (relative to `public/`), and an optional `poster` / `caption`.

Then place the trigger anywhere:

```blade
<x-ui.help-trigger doc="<key>" :label="__('Watch: …')">
    {{-- actionable content shown beside the video --}}
</x-ui.help-trigger>
```

## Media is git-ignored

`*.mp4`, `*.mov`, and `*.webm` under this folder are **git-ignored** — videos are
placed here locally for development and uploaded to the same path on the server
for production; they are never committed. The folder structure, this README, and
small `poster.jpg` images stay tracked.

> Drop the calendar-sync footage at:
> **`public/how-to-documentation/calendar-sync/video.mp4`**
