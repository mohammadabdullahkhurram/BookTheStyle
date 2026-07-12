@props([
    'heading' => null,
    'subheading' => null,
])

@php($hasHeader = $heading || isset($header) || isset($pill))

{{--
    Shared modal. Wraps <flux:modal> so every dialog gets a consistent header
    zone. Flux renders its close (×) button absolutely in the top-right
    (top-0 end-0 mt-4 me-4, a ~32px ghost button), so the header keeps end
    padding (pe-12 = 48px) — wider than the button's footprint — and lays the
    title, subheading and any status pills out on the left. The × therefore
    never overlaps the title, subheading or first content row at any name or
    status length, on mobile or desktop. All attributes (wire:model, name,
    class, :show, focusable, @close, …) forward straight through to flux:modal.

    Mobile: w-full lets the dialog fill a phone viewport (the browser's native
    dialog margins and each call site's max-w-* still cap it), and the
    max-height + overflow keep tall dialogs scrollable instead of clipped.
--}}
<flux:modal {{ $attributes->merge(['class' => 'w-full max-h-[calc(100dvh-2rem)] overflow-y-auto']) }}>
    <div class="flex flex-col gap-5">
        @if ($hasHeader)
            <div class="flex flex-col gap-2.5 pe-12">
                @isset($header)
                    {{ $header }}
                @elseif ($heading)
                    <div class="flex flex-col gap-1">
                        <h2 class="bts-card-title">{{ $heading }}</h2>
                        @if ($subheading)
                            <p class="text-[14px] text-secondary">{{ $subheading }}</p>
                        @endif
                    </div>
                @endisset

                @isset($pill)
                    <div class="flex flex-wrap items-center gap-2">{{ $pill }}</div>
                @endisset
            </div>
        @endif

        {{ $slot }}
    </div>
</flux:modal>
