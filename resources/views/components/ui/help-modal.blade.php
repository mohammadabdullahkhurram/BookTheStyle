@props(['doc'])

{{-- How-to popup. Two panes visible at once: the video and an actionable
     content slot (e.g. the subscribe link + steps), so the user follows the
     video while using the link. Side-by-side on desktop, stacked on mobile
     (video top, content below) — both reachable without closing.

     Expects an ancestor Alpine scope with a boolean `helpOpen` (provided by
     x-ui.help-trigger). Accessibility: Esc + click-outside close, focus trap +
     scroll-lock via x-trap (Flux's bundled Alpine focus plugin), which also
     returns focus to the trigger on close. The <video> is only rendered while
     open (x-if), so it never loads until opened and pauses/resets on close. --}}
<div x-show="helpOpen" x-cloak
     @keydown.escape.window="helpOpen = false"
     class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6">

    <div x-show="helpOpen" x-transition.opacity
         class="absolute inset-0 bg-[#1C1B1A]/45"
         @click="helpOpen = false" aria-hidden="true"></div>

    <div x-show="helpOpen" x-transition x-trap.noscroll="helpOpen"
         role="dialog" aria-modal="true" aria-labelledby="help-title-{{ $doc->key }}"
         class="relative z-10 flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-[20px] border border-border bg-card shadow-[0_24px_60px_rgba(28,27,26,.18)]">

        <div class="flex shrink-0 items-start justify-between gap-4 border-b border-divider px-6 py-4">
            <div class="min-w-0">
                <h2 id="help-title-{{ $doc->key }}" class="bts-card-title">{{ $doc->title }}</h2>
                @if ($doc->caption)
                    <p class="mt-1 text-[14px] text-secondary">{{ $doc->caption }}</p>
                @endif
            </div>
            <button type="button" @click="helpOpen = false" aria-label="{{ __('Close') }}"
                    class="-mr-1.5 shrink-0 rounded-md p-1.5 text-fainter transition hover:bg-muted hover:text-ink">
                <flux:icon.x-mark variant="micro" />
            </button>
        </div>

        <div class="grid flex-1 grid-cols-1 overflow-y-auto md:grid-cols-2">
            {{-- Video pane (top on mobile, left on desktop). --}}
            <div class="border-b border-divider bg-muted p-5 md:border-b-0 md:border-e">
                <template x-if="helpOpen">
                    <div>
                        @if ($doc->hasVideo())
                            <video controls preload="none"
                                   class="aspect-video w-full rounded-[12px] bg-[#1C1B1A]"
                                   @if ($doc->posterUrl()) poster="{{ $doc->posterUrl() }}" @endif>
                                @foreach ($doc->videoSources() as $source)
                                    <source src="{{ $source['url'] }}" type="{{ $source['type'] }}">
                                @endforeach
                                {{ __('Your browser does not support embedded video.') }}
                            </video>
                        @else
                            <div class="flex aspect-video w-full flex-col items-center justify-center gap-2 rounded-[12px] border border-dashed border-input-border bg-field px-4 text-center">
                                <flux:icon.video-camera variant="outline" class="size-7 text-fainter" />
                                <p class="text-[14px] font-medium text-body">{{ __('Video coming soon') }}</p>
                                <p class="text-[13px] text-secondary">{{ __('Follow the written steps for now.') }}</p>
                            </div>
                        @endif
                    </div>
                </template>
            </div>

            {{-- Actionable content pane (below on mobile, right on desktop). --}}
            <div class="p-6">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
