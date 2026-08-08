{{-- Extracted body of the Salon settings "Branding" tab. Included with the
     parent component's full scope — every wire binding and method call
     behaves exactly as before the extraction. --}}
        <x-ui.card class="flex flex-col gap-5">
            <h2 class="bts-card-title">{{ __('Branding') }}</h2>
            @if ($salon->is_demo)
                <p class="text-[13px] text-faint">{{ __('Play with the accent and themes freely — saving is disabled in the demo.') }}</p>
            @endif
            <form wire:submit="saveBranding" class="flex flex-col gap-5" novalidate>
                {{-- Accent: a colour-wheel swatch + hex, synced both ways.
                     The swatch is the styled trigger; the picker itself is
                     the OS colour wheel. --}}
                <div>
                    <div class="bts-field-label mb-2">{{ __('Accent color') }}</div>
                    <div class="flex items-center gap-3" x-data>
                        <label class="relative inline-flex size-11 shrink-0 cursor-pointer items-center justify-center overflow-hidden rounded-full border-2 border-input-border shadow-[inset_0_1px_2px_rgb(0_0_0/0.06)] transition hover:border-faint focus-within:outline focus-within:outline-2 focus-within:outline-[var(--focus-ring)] focus-within:outline-offset-2"
                               style="background-color: {{ preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? $accent : '#824C71' }};">
                            <input type="color" wire:model.live="accent"
                                   value="{{ preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? $accent : '#824C71' }}"
                                   aria-label="{{ __('Pick the accent color') }}"
                                   class="absolute inset-0 size-full cursor-pointer opacity-0">
                        </label>
                        <div class="w-40">
                            <flux:input wire:model.live.debounce.400ms="accent" placeholder="#824C71" aria-label="{{ __('Accent hex') }}" />
                        </div>
                    </div>
                    <p class="mt-2 text-[12.5px] text-faint">{{ __('Your brand color — buttons, highlights, and selected states across the app and your booking widgets, on top of whichever theme is active.') }}</p>
                </div>

                @if ($this->brandingContrastWarning)
                    <p class="rounded-[10px] px-3 py-2 text-[13.5px]" style="background:#FBEFD6;color:#8A5A1E">{{ $this->brandingContrastWarning }}</p>
                @endif

                {{-- Logo: upload with preview; used on the booking widget. --}}
                <div class="flex flex-col gap-2">
                    <div class="bts-field-label">{{ __('Logo') }}</div>
                    @php($brandingTheme = \App\Support\WidgetBranding::for($salon))
                    @if ($logo && $logo->isPreviewable())
                        <img src="{{ $logo->temporaryUrl() }}" alt="{{ __('Logo preview') }}" class="max-h-14 w-auto max-w-[220px] rounded-[8px] border border-border object-contain p-1" />
                        <p class="text-[12.5px] text-faint">{{ __('Preview — save to apply.') }}</p>
                    @elseif ($brandingTheme['logo_url'])
                        <div class="flex items-center gap-3">
                            <img src="{{ $brandingTheme['logo_url'] }}" alt="{{ __('Current logo') }}" class="max-h-14 w-auto max-w-[220px] rounded-[8px] border border-border object-contain p-1" />
                            {{-- Themed confirm (replaces wire:confirm). --}}
                            <button type="button"
                                    x-on:click="$store.confirm.ask({
                                        title: {{ Js::from(__('Remove logo')) }},
                                        message: {{ Js::from(__('Remove the logo? The widget shows the salon name alone until a new one is uploaded.')) }},
                                        confirmLabel: {{ Js::from(__('Remove')) }},
                                        danger: true,
                                    }, () => $wire.removeLogo())"
                                    class="text-[13px] font-medium text-secondary transition hover:text-danger">{{ __('Remove') }}</button>
                        </div>
                    @endif
                    <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml"
                           class="text-[14px] file:mr-3 file:rounded-[9px] file:border file:border-input-border file:bg-field file:px-3 file:py-1.5 file:text-[13px] file:font-semibold file:text-body" />
                    <p class="text-[12.5px] text-faint">{{ __('PNG, JPG, WebP or SVG, up to 1 MB. Shown at the top of your booking widget.') }}</p>
                    @error('logo') <p class="text-[13px] text-danger">{{ $message }}</p> @enderror
                    <div wire:loading wire:target="logo" class="text-[12.5px] text-faint">{{ __('Uploading…') }}</div>
                </div>

                <div><x-ui.button type="submit" loading="saveBranding">{{ __('Save branding') }}</x-ui.button></div>
            </form>
        </x-ui.card>

        {{-- App theme: which design language this salon's app renders in.
             Live themes are selectable cards; coming-soon ones are locked
             previews. Booking-widget theming lives per widget, in Widgets. --}}
        <x-ui.card class="flex flex-col gap-5">
            <div>
                <h2 class="bts-card-title">{{ __('App theme') }}</h2>
                <p class="mt-1 text-[14px] text-secondary">{{ __('The design language your salon app renders in. Your booking widgets have their own theme, set per widget in Widgets.') }}</p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach (\App\Support\ThemeRegistry::picker(\App\Support\ThemeRegistry::SCOPE_APP) as $themeKey => $theme)
                    @if ($theme['status'] === 'available')
                        <button type="button" wire:click="saveAppTheme('{{ $themeKey }}')"
                                aria-pressed="{{ $salon->app_theme === $themeKey ? 'true' : 'false' }}"
                                class="flex flex-col gap-2 rounded-[14px] border p-4 text-left transition {{ $salon->app_theme === $themeKey ? 'border-accent bg-accent-tint' : 'border-input-border bg-field hover:border-faint' }}">
                            <span class="flex items-center gap-1.5" aria-hidden="true">
                                @foreach ($theme['swatches'] as $swatch)
                                    <span class="size-5 rounded-full border border-border" style="background-color: {{ $swatch }}"></span>
                                @endforeach
                            </span>
                            <span class="text-[15px] font-semibold text-ink">{{ $theme['name'] }}
                                @if ($salon->app_theme === $themeKey)
                                    <span class="ms-1 text-[12px] font-semibold text-accent-ink">{{ __('· Active') }}</span>
                                @endif
                            </span>
                            <span class="text-[13px] text-secondary">{{ $theme['description'] }}</span>
                        </button>
                    @else
                        <div class="relative overflow-hidden rounded-[14px] border border-border bg-field p-4" aria-disabled="true">
                            <div class="blur-[2px] opacity-60" aria-hidden="true">
                                <span class="flex items-center gap-1.5">
                                    @foreach ($theme['swatches'] as $swatch)
                                        <span class="size-5 rounded-full border border-border" style="background-color: {{ $swatch }}"></span>
                                    @endforeach
                                </span>
                                <p class="mt-2 text-[15px] font-semibold text-ink">{{ $theme['name'] }}</p>
                                <p class="text-[13px] text-secondary">{{ $theme['description'] }}</p>
                            </div>
                            <span class="absolute right-3 top-3 rounded-full bg-muted px-2.5 py-1 text-[11.5px] font-semibold uppercase tracking-wide text-secondary">{{ __('Coming soon') }}</span>
                        </div>
                    @endif
                @endforeach
            </div>
        </x-ui.card>
