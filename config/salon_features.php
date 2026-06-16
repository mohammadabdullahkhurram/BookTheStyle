<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Per-salon feature flags
    |--------------------------------------------------------------------------
    |
    | The catalogue of toggles a salon owner/admin can flip in salon settings.
    | Values are stored per salon in salons.feature_flags and read via
    | Salon::hasFeature(). Later phases gate behaviour on these so salons can
    | diverge. Key => human label.
    |
    */

    'online_booking' => 'Public online booking',
    'voice_ai' => 'Voice AI booking',
    'chat_widget' => 'Chat widget booking',

];
