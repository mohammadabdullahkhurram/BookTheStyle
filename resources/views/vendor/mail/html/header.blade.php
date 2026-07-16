@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{--
    The brand logo instead of the wordmark text. Email constraints: an
    ABSOLUTE URL (relative paths don't resolve in mail clients), PNG (Gmail
    won't render SVG), fixed display height with the natural 891×189 PNG
    (~4.7× the rendered size, so retina-crisp), and alt text carrying the
    brand for the many clients that block images by default.
--}}
<img src="{{ asset('images/full-logo.png') }}" class="logo" alt="BookTheStyle" height="38" style="height: 38px; width: auto; max-width: 220px;">
</a>
</td>
</tr>
