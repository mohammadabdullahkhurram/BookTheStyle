<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" href="/favicon-32.png" sizes="32x32">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])

{{-- Per-salon brand accent: fills the theme-agnostic --brand-accent* slot
     on salon-scoped pages (currentSalon is bound by ResolveSalon). Every
     theme's accent variables read var(--brand-accent*, <theme default>), so
     the salon's accent recolours WHICHEVER theme is active — Marble, Classic
     or future ones — including the derived readable on-accent text. --}}
@php($__accent = \App\Support\AccentPalette::resolve(app()->bound('currentSalon') ? app('currentSalon')?->accentColor() : null))
@if ($__accent)
    <style>:root{--brand-accent: {{ $__accent['accent'] }};--brand-accent-hover: {{ $__accent['hover'] }};--brand-accent-tint: {{ $__accent['tint'] }};--brand-accent-ink: {{ $__accent['ink'] }};--brand-accent-foreground: {{ $__accent['foreground'] }};}</style>
@endif
