<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Voice-AI Booking API (Stage 2)
    |--------------------------------------------------------------------------
    |
    | Tuning for the token-authenticated endpoints GHL Voice AI calls
    | mid-conversation. Responses stay small so the AI can read options
    | aloud; days_ahead bounds how far the default availability scan looks.
    |
    */

    // When no date is given, scan this many days starting today.
    'days_ahead' => env('BOOKING_API_DAYS_AHEAD', 3),

    // At most this many slots are returned per day (earliest first).
    'max_slots_per_day' => env('BOOKING_API_MAX_SLOTS_PER_DAY', 6),

    // Alternatives offered when a requested slot is no longer available.
    'alternatives' => env('BOOKING_API_ALTERNATIVES', 3),

    // Requests per minute per token (falls back to per-IP pre-auth).
    'rate_limit' => env('BOOKING_API_RATE_LIMIT', 60),

];
