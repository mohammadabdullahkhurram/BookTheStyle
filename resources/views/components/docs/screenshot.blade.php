{{-- Screenshot placeholder: a tidy slot the team fills with a capture.
     `capture` says exactly what to shoot. Swap for <img> when captured. --}}
@props(['capture'])

<div class="mb-4 flex items-center gap-3 rounded-[10px] border border-dashed border-input-border bg-muted/40 px-4 py-5">
    <flux:icon.camera variant="mini" class="shrink-0 text-faint" />
    <div class="min-w-0">
        <p class="text-[12px] font-semibold uppercase tracking-[0.08em] text-faint">{{ __('Screenshot to add') }}</p>
        <p class="text-[13.5px] leading-relaxed text-secondary">{{ $capture }}</p>
    </div>
</div>
