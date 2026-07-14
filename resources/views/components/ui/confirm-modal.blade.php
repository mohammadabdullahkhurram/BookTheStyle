{{--
    The ONE themed confirmation dialog for every destructive action —
    replaces the native browser confirm() that wire:confirm used. Driven by
    the global Alpine `confirm` store (resources/js/app.js): call sites open
    it via $store.confirm.ask({...}, () => $wire.action()). Token-driven so
    it renders on-theme under Marble, Classic, and the agency palette.
    Danger is never colour-alone: the warning icon + the verb label carry it.
    a11y: role="dialog" + aria-modal, labelled/described, x-trap moves focus
    in (and restores it on close), Esc and the scrim cancel.

    Converting a wire:confirm call site (this modal renders once, in
    layouts/app/sidebar — call sites only need the x-on:click):
      before  <button wire:confirm="{{ __('Delete X? …') }}" wire:click="deleteX({{ $id }})">
      after   <button x-on:click="$store.confirm.ask({
                  title: {{ Js::from(__('Delete X')) }},      [short imperative heading]
                  message: {{ Js::from(__('Delete X? …')) }}, [keep the wire:confirm copy verbatim]
                  confirmLabel: {{ Js::from(__('Delete')) }}, [the verb, never "OK"]
                  danger: true,                               [false for non-destructive confirms]
              }, () => $wire.deleteX({{ $id }}))">
    Drop wire:confirm AND wire:click — the action must only run via the callback.
    Escape strings with Js::from, NOT @js: inside <x-…> component-tag attributes
    Blade never compiles @js (it reaches Alpine literally and throws), and any
    double quote inside the attribute value breaks the tag compiler — so use
    {{ Js::from(__('single-quoted copy')) }} and keep <x-…> attributes on one line.
--}}
<div x-data x-show="$store.confirm.show" x-cloak
     class="fixed inset-0 z-[90] flex items-center justify-center p-4"
     @keydown.escape.window="$store.confirm.show && $store.confirm.cancel()">
    <div class="bts-scrim absolute inset-0" style="background-color: rgb(31 22 17 / 0.35);"
         @click="$store.confirm.cancel()" aria-hidden="true"></div>

    <div role="dialog" aria-modal="true" aria-labelledby="bts-confirm-title" aria-describedby="bts-confirm-message"
         x-trap.noscroll="$store.confirm.show"
         class="bts-surface relative w-full max-w-md rounded-[var(--radius-modal)] border border-border bg-card p-6 shadow-[var(--shadow-overlay)]">
        <div class="flex items-start gap-4">
            {{-- Severity icon: warning triangle for danger, question mark otherwise. --}}
            <span class="flex size-11 shrink-0 items-center justify-center rounded-full"
                  :class="$store.confirm.danger ? 'bg-[#F8E3E3] text-danger' : 'bg-accent-tint text-accent-ink'">
                <template x-if="$store.confirm.danger">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-6" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                </template>
                <template x-if="!$store.confirm.danger">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-6" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />
                    </svg>
                </template>
            </span>
            <div class="min-w-0 flex-1">
                <h2 id="bts-confirm-title" x-text="$store.confirm.title" class="font-display text-[17px] font-semibold leading-snug text-ink"></h2>
                <p id="bts-confirm-message" x-text="$store.confirm.message" class="mt-1.5 text-[14px] leading-relaxed text-body"></p>
            </div>
        </div>
        <div class="mt-6 flex flex-wrap justify-end gap-3">
            <button type="button" class="bts-btn bts-btn-secondary" @click="$store.confirm.cancel()">{{ __('Cancel') }}</button>
            <button type="button" class="bts-btn"
                    :class="$store.confirm.danger ? 'bts-btn-danger-solid' : 'bts-btn-primary'"
                    @click="$store.confirm.proceed()" x-text="$store.confirm.confirmLabel"></button>
        </div>
    </div>
</div>
