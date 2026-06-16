<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])

{{-- Per-salon brandable accent: overrides the accent token on salon-scoped
     pages (currentSalon is bound by ResolveSalon). Guarded to a hex value. --}}
@php($__salonAccent = app()->bound('currentSalon') ? app('currentSalon')?->accentColor() : null)
@if ($__salonAccent && preg_match('/^#[0-9a-fA-F]{6}$/', $__salonAccent))
    <style>:root{--color-accent: {{ $__salonAccent }};--color-accent-content: {{ $__salonAccent }};}</style>
@endif
