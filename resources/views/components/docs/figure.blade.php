{{-- Diagram frame: title + inline-SVG slot, scrollable when narrow. --}}
@props(['title'])

<figure class="mb-5 rounded-[10px] border border-divider bg-white p-4">
    <figcaption class="bts-overline mb-3">{{ $title }}</figcaption>
    <div class="overflow-x-auto">{{ $slot }}</div>
</figure>
