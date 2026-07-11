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

    /*
    |--------------------------------------------------------------------------
    | Public booking widget
    |--------------------------------------------------------------------------
    |
    | The embeddable widget's public endpoints are identified by salon slug
    | (no secret can live in a visitor's browser), so they carry their own
    | per-IP+salon rate limit and a bot gate on the book submit: a hidden
    | honeypot field plus a signed page token that must be at least
    | widget_min_seconds old (humans read the form; bots submit instantly)
    | and at most widget_token_ttl_hours old.
    |
    */

    // Requests per minute per IP + salon for the widget endpoints.
    'widget_rate_limit' => env('BOOKING_WIDGET_RATE_LIMIT', 30),

    // A widget booking may not be submitted sooner than this after page load.
    'widget_min_seconds' => env('BOOKING_WIDGET_MIN_SECONDS', 4),

    // ... nor later than this (stale/replayed page tokens are refused).
    'widget_token_ttl_hours' => env('BOOKING_WIDGET_TOKEN_TTL_HOURS', 12),

    // How many days ahead the widget's date picker lets visitors browse
    // (independently capped by each salon's own max-advance policy).
    'widget_days_ahead' => env('BOOKING_WIDGET_DAYS_AHEAD', 30),

];
