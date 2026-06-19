<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" type="image/png" href="/favicon-32.png" sizes="32x32">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])

{{-- Per-salon brandable accent: overrides the four swappable accent tokens on
     salon-scoped pages (currentSalon is bound by ResolveSalon). A salon's chosen
     accent (preset name or hex) resolves to accent / hover / tint / ink. --}}
@php($__accent = \App\Support\AccentPalette::resolve(app()->bound('currentSalon') ? app('currentSalon')?->accentColor() : null))
@if ($__accent)
    <style>:root{--accent: {{ $__accent['accent'] }};--accent-hover: {{ $__accent['hover'] }};--accent-tint: {{ $__accent['tint'] }};--accent-ink: {{ $__accent['ink'] }};}</style>
@endif
