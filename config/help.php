<?php

return [

    /*
    |--------------------------------------------------------------------------
    | How-to documentation registry
    |--------------------------------------------------------------------------
    |
    | Maps a doc key → its title, video path (relative to public/), and optional
    | poster + caption. Resolved by App\Support\HelpDocs and surfaced through the
    | x-ui.help-trigger / x-ui.help-modal components.
    |
    | Add a new how-to: drop public/how-to-documentation/<key>/video.mp4 (see the
    | README in that folder) and add one entry here. Missing video files degrade
    | gracefully to a "video coming soon" placeholder.
    |
    */

    'docs' => [

        'calendar-sync' => [
            'title' => 'Add your bookings to your phone calendar',
            'caption' => 'A short walkthrough of subscribing on Apple, Google, and Outlook.',
            'video' => 'how-to-documentation/calendar-sync/video.mp4',
            'video_webm' => 'how-to-documentation/calendar-sync/video.webm',
            'poster' => 'how-to-documentation/calendar-sync/poster.jpg',
        ],

    ],

];
