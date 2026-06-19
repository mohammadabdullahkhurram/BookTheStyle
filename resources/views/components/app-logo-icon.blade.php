@props([
    'alt' => 'BookTheStyle',
])

{{-- BookTheStyle icon mark (square). The image lives at public/images/icon-logo.png
     — replace that one file (a transparent PNG) to update the mark everywhere.
     Root-relative path keeps it same-origin on every tenant subdomain (CSP-safe).
     Pass alt="" where the mark is decorative next to the wordmark. --}}
<img
    src="/images/icon-logo.png"
    alt="{{ $alt }}"
    width="512"
    height="512"
    {{ $attributes->class('inline-block select-none') }}
/>
