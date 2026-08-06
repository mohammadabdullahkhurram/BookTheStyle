{{-- A doc section: anchored h2 + body. The id feeds the on-page nav. --}}
@props(['id', 'title'])

<section id="{{ $id }}" class="bts-doc-section scroll-mt-24">
    <h2 class="group">
        {{ $title }}
        <a href="#{{ $id }}" class="heading-permalink" aria-hidden="true" tabindex="-1">#</a>
    </h2>
    {{ $slot }}
</section>
