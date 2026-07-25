<?php

use App\Models\Salon;
use App\Models\Widget;
use App\Support\ThemeRegistry;
use App\Support\WidgetBranding;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
 * Widgets — a salon's embeddable booking widgets (one per website/location).
 * Each widget is fully independent: its own name, its own branding
 * (colors/logo/font — overriding the salon defaults from Settings →
 * Branding), its own THEME, and its own embed code (public id). Owner/admin,
 * tenant-scoped: every read/write goes through $salon->widgets().
 */
new #[Title('Widgets')] class extends Component {
    use WithFileUploads;

    public Salon $salon;

    public int $selectedId = 0;

    /** The "which type of widget?" modal shown before creating one. */
    public bool $showTypePicker = false;

    public string $name = '';

    public string $accent = '';

    public string $secondary = '';

    public string $surface = '';

    public string $font = '';

    public $logo = null;

    /** Customizer draft: the theme picked client-side, persisted on Save. */
    public string $pendingTheme = '';

    public function mount(Salon $salon): void
    {
        $this->authorize('manage', $salon);
        $this->salon = $salon;

        $this->select($salon->defaultWidget()->id);
    }

    public function select(int $widgetId): void
    {
        $widget = $this->widget($widgetId);

        $this->selectedId = $widget->id;
        $this->name = $widget->name;
        $this->accent = (string) ($widget->branding['accent'] ?? '');
        $this->secondary = (string) ($widget->branding['secondary'] ?? '');
        $this->surface = (string) ($widget->branding['surface'] ?? '');
        $this->font = (string) ($widget->branding['font'] ?? '');
        $this->logo = null;
        $this->resetErrorBag();
    }

    /**
     * Create a widget of a picked TYPE (the type-picker modal's choice).
     * Only registry-available types are creatable — the coming-soon cards
     * are locked previews, and the guard holds server-side too.
     */
    public function createWidget(string $type = \App\Support\WidgetTypeRegistry::DEFAULT): void
    {
        $this->authorize('manage', $this->salon);

        if (\App\Support\DemoMode::blocksWrite($this->salon, __('Saving widgets is disabled in the demo.'))) {
            return;
        }

        if (! \App\Support\WidgetTypeRegistry::selectable($type)) {
            Flux::toast(variant: 'danger', text: __('That widget type is not available yet.'));

            return;
        }

        $widget = $this->salon->widgets()->create([
            'name' => __('New widget'),
            'type' => $type,
            'public_id' => Widget::newPublicId(),
            'branding' => null,
            'theme' => 'marble',
        ]);

        $this->showTypePicker = false;
        $this->select($widget->id);
        Flux::toast(variant: 'success', text: __('Widget created — name it and set its look.'));
    }

    public function save(): void
    {
        $this->authorize('manage', $this->salon);

        if (\App\Support\DemoMode::blocksWrite($this->salon, __('Saving widgets is disabled in the demo — the live preview shows your draft.'))) {
            return;
        }

        // Accept 1F6F6B / #1f6f6b / whitespace — canonical #RRGGBB. Only
        // genuinely invalid colours reach the regex rule below.
        foreach (['accent', 'secondary', 'surface'] as $colour) {
            $this->{$colour} = \App\Support\HexColor::tryNormalize($this->{$colour});
        }

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'surface' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font' => ['nullable', 'in:'.implode(',', array_keys(WidgetBranding::FONTS))],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:1024'],
        ]);

        $widget = $this->widget($this->selectedId);
        $branding = $widget->branding ?? [];

        $branding['accent'] = $this->accent ?: null;
        $branding['secondary'] = $this->secondary ?: null;
        $branding['surface'] = $this->surface ?: null;
        $branding['font'] = $this->font ?: null;

        if ($this->logo !== null) {
            $old = $branding['logo_path'] ?? null;
            $branding['logo_path'] = $this->logo->store('branding/'.$this->salon->id.'/widgets', 'public');
            if (is_string($old) && $old !== $branding['logo_path']) {
                Storage::disk('public')->delete($old);
            }
        }

        $data = ['name' => $this->name, 'branding' => $branding];
        if ($this->pendingTheme !== '' && ThemeRegistry::selectable($this->pendingTheme, ThemeRegistry::SCOPE_WIDGET)) {
            $data['theme'] = $this->pendingTheme;
        }
        $widget->update($data);
        $this->logo = null;

        Flux::toast(variant: 'success', text: __('Widget saved.'));
    }

    /** Pick this widget's theme — only registry-available widget themes. */
    public function saveTheme(string $key): void
    {
        $this->authorize('manage', $this->salon);

        if (\App\Support\DemoMode::blocksWrite($this->salon, __('Saving widgets is disabled in the demo — the live preview shows your draft.'))) {
            return;
        }

        if (! ThemeRegistry::selectable($key, ThemeRegistry::SCOPE_WIDGET)) {
            Flux::toast(variant: 'danger', text: __('That theme is not available yet.'));

            return;
        }

        $this->widget($this->selectedId)->update(['theme' => $key]);
        Flux::toast(variant: 'success', text: __('Widget theme updated.'));
    }

    public function removeLogo(): void
    {
        $this->authorize('manage', $this->salon);

        if (\App\Support\DemoMode::blocksWrite($this->salon, __('Saving widgets is disabled in the demo — the live preview shows your draft.'))) {
            return;
        }

        $widget = $this->widget($this->selectedId);
        $branding = $widget->branding ?? [];

        if (is_string($branding['logo_path'] ?? null)) {
            Storage::disk('public')->delete($branding['logo_path']);
        }
        unset($branding['logo_path']);
        $widget->update(['branding' => $branding ?: null]);

        Flux::toast(variant: 'success', text: __('Logo removed.'));
    }

    public function deleteWidget(int $widgetId): void
    {
        $this->authorize('manage', $this->salon);

        if (\App\Support\DemoMode::blocksWrite($this->salon, __('Saving widgets is disabled in the demo.'))) {
            return;
        }

        if ($this->salon->widgets()->count() <= 1) {
            Flux::toast(variant: 'danger', text: __('A salon keeps at least one widget.'));

            return;
        }

        $widget = $this->widget($widgetId);
        if (is_string($widget->branding['logo_path'] ?? null)) {
            Storage::disk('public')->delete($widget->branding['logo_path']);
        }
        $widget->delete();

        $this->select($this->salon->defaultWidget()->id);
        Flux::toast(variant: 'success', text: __('Widget deleted. Embeds using its id stop rendering.'));
    }

    /** Resolve a widget id STRICTLY within this salon — never trust the id alone. */
    private function widget(int $widgetId): Widget
    {
        return $this->salon->widgets()->findOrFail($widgetId);
    }

    /**
     * THE PROMOTE SWITCH — the two-pane live customizer currently renders
     * for DEMO salons only; real salons keep the classic page below as the
     * untouched fallback. To promote the customizer to everyone, change
     * this to `return true;` (one line) — the classic branch then becomes
     * dead code to delete at leisure.
     */
    private function customizerEnabled(): bool
    {
        return $this->salon->is_demo;
    }

    public function with(): array
    {
        $widget = $this->widget($this->selectedId);

        return [
            'widgets' => $this->salon->widgets()->orderBy('id')->get(),
            'current' => $widget,
            'theme' => WidgetBranding::for($this->salon, null, $widget),
            'customizer' => $this->customizerEnabled(),
            // Salon-level effective branding — the live preview's fallback
            // for any field the draft leaves blank.
            'salonDefaults' => WidgetBranding::for($this->salon),
        ];
    }
}; ?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 sm:p-6">
    <div>
        <h1 class="bts-page-title">{{ __('Widgets') }}</h1>
        <p class="mt-1 text-[14px] text-secondary">{{ __('Embeddable booking widgets for your websites — each with its own name, look, theme and embed code. Bookings land here like any other, tagged "Booking widget".') }}</p>
    </div>

    @if ($customizer)
    {{-- ============== THE LIVE CUSTOMIZER (demo-gated) ==============
         Two panes: controls left, sticky live preview right. Every
         appearance edit is pushed into the preview iframe over
         postMessage (same-origin, origin-scoped) — no save, no reload.
         The classic page below stays intact for real salons until the
         PROMOTE SWITCH (customizerEnabled) flips. --}}
    <div data-customizer
         x-data="{
             pane: 'edit',
             draftTheme: @js($current->theme),
             draftLogo: null,
             logoCleared: false,
             fallback: {
                 accent: @js($salonDefaults['accent']['accent']),
                 secondary: @js($salonDefaults['secondary']),
                 surface: @js($salonDefaults['surface']),
                 font: @js($salonDefaults['font']['key']),
                 logo: @js($theme['logo_url']),
             },
             hex(v, fb) {
                 v = (v || '').trim();
                 if (v && !v.startsWith('#')) v = '#' + v;
                 return /^#[0-9a-fA-F]{6}$/.test(v) ? v : fb;
             },
             push() {
                 const frame = this.$refs.preview;
                 if (!frame || !frame.contentWindow) return;
                 frame.contentWindow.postMessage({
                     type: 'bts-widget-draft',
                     accent: this.hex(this.$wire.accent, this.fallback.accent),
                     secondary: this.hex(this.$wire.secondary, this.fallback.secondary),
                     surface: this.hex(this.$wire.surface, this.fallback.surface),
                     font: this.$wire.font || this.fallback.font,
                     theme: this.draftTheme,
                     logoUrl: this.logoCleared ? null : (this.draftLogo || this.fallback.logo),
                 }, window.location.origin);
             },
             pickTheme(key) { this.draftTheme = key; this.$wire.pendingTheme = key; this.push(); },
             pickLogo(ev) {
                 const file = ev.target.files && ev.target.files[0];
                 if (!file) return;
                 this.draftLogo = URL.createObjectURL(file);
                 this.logoCleared = false;
                 this.push();
             },
             clearLogo() { this.draftLogo = null; this.logoCleared = true; this.push(); },
         }"
         x-on:input.debounce.150ms="push()"
         x-on:change="push()">

        {{-- Mobile: tab between the panes; lg+: both visible. --}}
        <div class="mb-4 flex gap-1 rounded-[13px] border border-border bg-muted p-1 lg:hidden" role="tablist" aria-label="{{ __('Customizer panes') }}">
            <button type="button" role="tab" :aria-selected="pane === 'edit'" x-on:click="pane = 'edit'"
                    class="flex-1 rounded-[10px] px-3 py-2 text-[13.5px] font-semibold transition"
                    :class="pane === 'edit' ? 'bg-card text-ink shadow-sm' : 'text-secondary'">{{ __('Customize') }}</button>
            <button type="button" role="tab" :aria-selected="pane === 'preview'" x-on:click="pane = 'preview'; $nextTick(() => push())"
                    class="flex-1 rounded-[10px] px-3 py-2 text-[13.5px] font-semibold transition"
                    :class="pane === 'preview' ? 'bg-card text-ink shadow-sm' : 'text-secondary'">{{ __('Preview') }}</button>
        </div>

        <div class="grid items-start gap-6 lg:grid-cols-[400px_minmax(0,1fr)]">
            {{-- LEFT — the controls. --}}
            <div class="flex flex-col gap-5 max-lg:w-full" :class="pane !== 'edit' && 'max-lg:hidden'">
                {{-- Widget switcher. --}}
                <div class="flex flex-wrap items-center gap-2">
                    @foreach ($widgets as $widget)
                        <button type="button" wire:click="select({{ $widget->id }})"
                                aria-current="{{ $widget->id === $selectedId ? 'page' : 'false' }}"
                                class="rounded-full border px-3.5 py-1.5 text-[13px] font-semibold transition {{ $widget->id === $selectedId ? 'border-accent bg-accent-tint text-accent-ink' : 'border-input-border bg-field text-secondary hover:border-faint' }}">
                            {{ $widget->name }}
                        </button>
                    @endforeach
                    <button type="button" wire:click="$set('showTypePicker', true)"
                            class="rounded-full border border-dashed border-input-border px-3.5 py-1.5 text-[13px] font-semibold text-accent-ink transition hover:border-accent">
                        + {{ __('New') }}
                    </button>
                </div>

                {{-- Appearance — every field here re-themes the preview live. --}}
                <x-ui.card class="flex flex-col gap-5">
                    <div>
                        <h2 class="bts-card-title">{{ __('Appearance') }}</h2>
                        <p class="mt-1 text-[13.5px] text-secondary">{{ __('Everything you change shows in the preview instantly.') }}</p>
                    </div>
                    <form wire:submit="save" class="flex flex-col gap-5" novalidate>
                        <flux:input wire:model="name" :label="__('Name')" :description="__('Internal only — e.g. \'Main site\' or \'Downtown location\'.')" />
                        <div class="grid gap-4 sm:grid-cols-3">
                            <flux:input wire:model="accent" :label="__('Accent color')" placeholder="{{ $salon->accentColor() ?? '#824C71' }}" :description="__('Blank = the salon brand accent.')" />
                            <flux:input wire:model="secondary" :label="__('Secondary color')" placeholder="{{ \App\Support\WidgetBranding::DEFAULT_SECONDARY }}" :description="__('Blank = salon default.')" />
                            <flux:input wire:model="surface" :label="__('Background color')" placeholder="{{ \App\Support\WidgetBranding::DEFAULT_SURFACE }}" :description="__('Blank = salon default.')" />
                        </div>
                        <flux:select wire:model="font" :label="__('Font')" :description="__('The type this widget renders in. Blank = salon default.')">
                            <flux:select.option value="">{{ __('Salon default') }}</flux:select.option>
                            @foreach (\App\Support\WidgetBranding::FONTS as $key => $fontOption)
                                <flux:select.option value="{{ $key }}">{{ $fontOption['label'] }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <div class="flex flex-col gap-2">
                            <div class="bts-field-label">{{ __('Logo') }}</div>
                            <input type="file" wire:model="logo" x-on:change="pickLogo($event)" accept="image/png,image/jpeg,image/webp,image/svg+xml"
                                   class="text-[14px] file:mr-3 file:rounded-[9px] file:border file:border-input-border file:bg-field file:px-3 file:py-1.5 file:text-[13px] file:font-semibold file:text-body" />
                            <div class="flex items-center gap-3">
                                <p class="text-[12.5px] text-faint">{{ __('PNG, JPG, WebP or SVG, up to 1 MB.') }}</p>
                                <button type="button" x-on:click="clearLogo()" class="text-[12.5px] font-medium text-secondary transition hover:text-danger">{{ __('Hide logo in preview') }}</button>
                            </div>
                            @error('logo') <p class="text-[13px] text-danger">{{ $message }}</p> @enderror
                        </div>

                        {{-- Theme — draft-picked client-side, persisted on Save. --}}
                        <div class="flex flex-col gap-2">
                            <div class="bts-field-label">{{ __('Widget theme') }}</div>
                            <div class="grid gap-2.5 sm:grid-cols-2">
                                @foreach (\App\Support\ThemeRegistry::picker(\App\Support\ThemeRegistry::SCOPE_WIDGET) as $themeKey => $themeMeta)
                                    @if ($themeMeta['status'] === 'available')
                                        <button type="button" x-on:click="pickTheme('{{ $themeKey }}')"
                                                :aria-pressed="draftTheme === '{{ $themeKey }}' ? 'true' : 'false'"
                                                class="flex flex-col gap-1.5 rounded-[13px] border p-3 text-left transition"
                                                :class="draftTheme === '{{ $themeKey }}' ? 'border-accent bg-accent-tint' : 'border-input-border bg-field hover:border-faint'">
                                            <span class="flex items-center gap-1.5" aria-hidden="true">
                                                @foreach ($themeMeta['swatches'] as $swatch)
                                                    <span class="size-4 rounded-full border border-border" style="background-color: {{ $swatch }}"></span>
                                                @endforeach
                                            </span>
                                            <span class="text-[14px] font-semibold text-ink">{{ $themeMeta['name'] }}</span>
                                        </button>
                                    @endif
                                @endforeach
                            </div>
                        </div>

                        <div class="flex flex-col gap-2">
                            <div><x-ui.button type="submit" loading="save">{{ __('Save widget') }}</x-ui.button></div>
                            @if ($salon->is_demo)
                                <p class="text-[12.5px] text-faint">{{ __('Saving is disabled in the demo — the preview is your playground.') }}</p>
                            @endif
                        </div>
                    </form>
                </x-ui.card>

                {{-- Embed code — the reason this page exists. --}}
                <x-ui.card class="flex flex-col gap-4">
                    <h2 class="bts-card-title">{{ __('Embed this widget') }}</h2>
                    @if ($salon->is_demo)
                        <p class="text-[13px] text-body">{{ __('Embeds are switched off in the demo. In your real salon, this code drops the booking form into any website.') }}</p>
                    @endif
                    @php($tag = 'scr'.'ipt')
                    @php($scriptSnippet = '<div data-bookthestyle-salon="'.$salon->slug.'" data-bookthestyle-widget="'.$current->public_id.'"></div>'.PHP_EOL.'<'.$tag.' src="'.route('widget.script').'" async></'.$tag.'>')
                    @php($iframeSnippet = '<iframe src="'.route('salon.widget', ['salon' => $salon, 'widget' => $current->public_id]).'" style="width:100%;border:0;min-height:640px" title="Book an appointment"></iframe>')
                    <div class="flex flex-col gap-2">
                        <h3 class="text-[14px] font-semibold text-ink">{{ __('Recommended: script embed (auto-sizes to content)') }}</h3>
                        <x-ui.copy-field :label="__('Paste where the form should appear')" :value="$scriptSnippet" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <h3 class="text-[14px] font-semibold text-ink">{{ __('Alternative: plain iframe') }}</h3>
                        <x-ui.copy-field :label="__('For builders that only accept an iframe')" :value="$iframeSnippet" />
                    </div>
                </x-ui.card>

                @if ($widgets->count() > 1)
                    <div>
                        <button type="button"
                                x-on:click="$store.confirm.ask({
                                    title: {{ Js::from(__('Delete widget')) }},
                                    message: {{ Js::from(__('Delete this widget? Sites embedding it stop showing a booking form. Existing bookings are kept.')) }},
                                    confirmLabel: {{ Js::from(__('Delete')) }},
                                    danger: true,
                                }, () => $wire.deleteWidget({{ $current->id }}))"
                                class="text-[13.5px] font-medium text-secondary transition hover:text-danger">{{ __('Delete this widget') }}</button>
                    </div>
                @endif
            </div>

            {{-- RIGHT — the sticky live preview. --}}
            <div class="max-lg:w-full lg:sticky lg:top-6" :class="pane !== 'preview' && 'max-lg:hidden'">
                <x-ui.card class="flex flex-col gap-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="bts-card-title">{{ __('Live preview') }}</h2>
                        <p class="text-[13px] text-faint">
                            @if ($salon->is_demo)
                                {{ __('Fully interactive — bookings you make here land in your demo calendar.') }}
                            @else
                                {{ __('Fully interactive — the final step never creates a real booking from here.') }}
                            @endif
                        </p>
                    </div>
                    <iframe x-ref="preview" x-on:load="push()"
                            src="{{ route('salon.widget.preview', ['salon' => $salon, 'widget' => $current->public_id]) }}"
                            title="{{ __('Widget preview') }}"
                            class="w-full rounded-[14px] border border-input-border"
                            style="min-height: 720px"></iframe>
                </x-ui.card>
            </div>
        </div>
    </div>
    @else
    <div class="grid gap-6 lg:grid-cols-[240px_minmax(0,1fr)]">
        {{-- Widget list: name + its TYPE, so mixed-type lists read at a glance --}}
        <nav aria-label="{{ __('Your widgets') }}" class="flex flex-col gap-1">
            @foreach ($widgets as $widget)
                <button type="button" wire:click="select({{ $widget->id }})"
                        aria-current="{{ $widget->id === $selectedId ? 'page' : 'false' }}"
                        class="bts-nav-item flex-col !items-start gap-0.5 text-left {{ $widget->id === $selectedId ? 'bts-nav-item-active' : '' }}">
                    <span class="block">{{ $widget->name }}</span>
                    <span class="flex items-center gap-1 text-[12px] font-medium text-faint">
                        <flux:icon :name="\App\Support\WidgetTypeRegistry::icon($widget->type)" variant="micro" class="size-3.5 shrink-0" />
                        {{ __(\App\Support\WidgetTypeRegistry::name($widget->type)) }}
                    </span>
                </button>
            @endforeach
            <button type="button" wire:click="$set('showTypePicker', true)" class="bts-nav-item text-left text-accent-ink">
                + {{ __('New widget') }}
            </button>
        </nav>

        {{-- Editor for the selected widget --}}
        <div class="flex flex-col gap-6">
            <x-ui.card class="flex flex-col gap-5">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="bts-card-title">{{ $current->name }}</h2>
                    <a href="#widget-preview" class="bts-btn bts-btn-secondary bts-btn-sm">{{ __('Preview') }}</a>
                </div>

                <form wire:submit="save" class="flex flex-col gap-5" novalidate>
                    <flux:input wire:model="name" :label="__('Name')" :description="__('Internal only — e.g. \'Main site\' or \'Downtown location\'.')" />

                    <div class="grid gap-4 sm:grid-cols-3">
                        <flux:input wire:model="accent" :label="__('Accent color')" placeholder="{{ $salon->accentColor() ?? '#824C71' }}" :description="__('Blank = the salon brand accent.')" />
                        <flux:input wire:model="secondary" :label="__('Secondary color')" placeholder="{{ \App\Support\WidgetBranding::DEFAULT_SECONDARY }}" :description="__('Blank = salon default.')" />
                        <flux:input wire:model="surface" :label="__('Background color')" placeholder="{{ \App\Support\WidgetBranding::DEFAULT_SURFACE }}" :description="__('Blank = salon default.')" />
                    </div>

                    <flux:select wire:model="font" :label="__('Font')" :description="__('The type this widget renders in. Blank = salon default.')">
                        <flux:select.option value="">{{ __('Salon default') }}</flux:select.option>
                        @foreach (\App\Support\WidgetBranding::FONTS as $key => $fontOption)
                            <flux:select.option value="{{ $key }}">{{ $fontOption['label'] }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <div class="flex flex-col gap-2">
                        <div class="bts-field-label">{{ __('Logo') }}</div>
                        @if ($logo && $logo->isPreviewable())
                            <img src="{{ $logo->temporaryUrl() }}" alt="{{ __('Logo preview') }}" class="max-h-14 w-auto max-w-[220px] rounded-[8px] border border-border object-contain p-1" />
                            <p class="text-[12.5px] text-faint">{{ __('Preview — save to apply.') }}</p>
                        @elseif (is_string($current->branding['logo_path'] ?? null) && $theme['logo_url'])
                            <div class="flex items-center gap-3">
                                <img src="{{ $theme['logo_url'] }}" alt="{{ __('Current logo') }}" class="max-h-14 w-auto max-w-[220px] rounded-[8px] border border-border object-contain p-1" />
                                {{-- Themed confirm (replaces wire:confirm). --}}
                                <button type="button"
                                        x-on:click="$store.confirm.ask({
                                            title: {{ Js::from(__('Remove logo')) }},
                                            message: {{ Js::from(__('Remove this widget\'s logo?')) }},
                                            confirmLabel: {{ Js::from(__('Remove')) }},
                                            danger: true,
                                        }, () => $wire.removeLogo())"
                                        class="text-[13px] font-medium text-secondary transition hover:text-danger">{{ __('Remove') }}</button>
                            </div>
                        @elseif ($theme['logo_url'])
                            <p class="text-[12.5px] text-faint">{{ __('Inheriting the salon logo — upload one to override it for this widget.') }}</p>
                        @endif
                        <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml"
                               class="text-[14px] file:mr-3 file:rounded-[9px] file:border file:border-input-border file:bg-field file:px-3 file:py-1.5 file:text-[13px] file:font-semibold file:text-body" />
                        <p class="text-[12.5px] text-faint">{{ __('PNG, JPG, WebP or SVG, up to 1 MB.') }}</p>
                        @error('logo') <p class="text-[13px] text-danger">{{ $message }}</p> @enderror
                        <div wire:loading wire:target="logo" class="text-[12.5px] text-faint">{{ __('Uploading…') }}</div>
                    </div>

                    <div><x-ui.button type="submit" loading="save">{{ __('Save widget') }}</x-ui.button></div>
                </form>
            </x-ui.card>

            {{-- Widget theme: live cards select; coming-soon are locked previews. --}}
            <x-ui.card class="flex flex-col gap-5">
                <div>
                    <h2 class="bts-card-title">{{ __('Widget theme') }}</h2>
                    <p class="mt-1 text-[14px] text-secondary">{{ __('The design language THIS widget renders in, on top of its colors.') }}</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach (\App\Support\ThemeRegistry::picker(\App\Support\ThemeRegistry::SCOPE_WIDGET) as $themeKey => $themeMeta)
                        @if ($themeMeta['status'] === 'available')
                            <button type="button" wire:click="saveTheme('{{ $themeKey }}')"
                                    aria-pressed="{{ $current->theme === $themeKey ? 'true' : 'false' }}"
                                    class="flex flex-col gap-2 rounded-[14px] border p-4 text-left transition {{ $current->theme === $themeKey ? 'border-accent bg-accent-tint' : 'border-input-border bg-field hover:border-faint' }}">
                                <span class="flex items-center gap-1.5" aria-hidden="true">
                                    @foreach ($themeMeta['swatches'] as $swatch)
                                        <span class="size-5 rounded-full border border-border" style="background-color: {{ $swatch }}"></span>
                                    @endforeach
                                </span>
                                <span class="text-[15px] font-semibold text-ink">{{ $themeMeta['name'] }}
                                    @if ($current->theme === $themeKey)
                                        <span class="ms-1 text-[12px] font-semibold text-accent-ink">{{ __('· Active') }}</span>
                                    @endif
                                </span>
                                <span class="text-[13px] text-secondary">{{ $themeMeta['description'] }}</span>
                            </button>
                        @else
                            <div class="relative overflow-hidden rounded-[14px] border border-border bg-field p-4" aria-disabled="true">
                                <div class="blur-[2px] opacity-60" aria-hidden="true">
                                    <span class="flex items-center gap-1.5">
                                        @foreach ($themeMeta['swatches'] as $swatch)
                                            <span class="size-5 rounded-full border border-border" style="background-color: {{ $swatch }}"></span>
                                        @endforeach
                                    </span>
                                    <p class="mt-2 text-[15px] font-semibold text-ink">{{ $themeMeta['name'] }}</p>
                                    <p class="text-[13px] text-secondary">{{ $themeMeta['description'] }}</p>
                                </div>
                                <span class="absolute right-3 top-3 rounded-full bg-muted px-2.5 py-1 text-[11.5px] font-semibold uppercase tracking-wide text-secondary">{{ __('Coming soon') }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>
            </x-ui.card>

            {{-- Inline preview — the REAL widget for the CURRENT salon,
                 rendered by the in-app preview route (resolve.salon supplies
                 the tenant, so this works identically in demo and real).
                 Real-salon submits are non-committal; demo submits land in
                 the demo calendar, inert like everything else here. --}}
            <x-ui.card id="widget-preview" class="flex flex-col gap-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="bts-card-title">{{ __('Preview') }}</h2>
                    <p class="text-[13px] text-faint">
                        @if ($salon->is_demo)
                            {{ __('Fully interactive — bookings you make here land in your demo calendar.') }}
                        @else
                            {{ __('Fully interactive — the final step never creates a real booking from here.') }}
                        @endif
                    </p>
                </div>
                <iframe src="{{ route('salon.widget.preview', ['salon' => $salon, 'widget' => $current->public_id]) }}"
                        title="{{ __('Widget preview') }}"
                        class="w-full rounded-[14px] border border-input-border"
                        style="min-height: 680px"></iframe>
            </x-ui.card>

            {{-- Embed code — unique to THIS widget. --}}
            <x-ui.card class="flex flex-col gap-5">
                <h2 class="bts-card-title">{{ __('Embed this widget') }}</h2>
                @if ($salon->is_demo)
                    <p class="text-[13px] text-body">{{ __('Embeds are switched off in the demo. In your real salon, this code drops the booking form into any website.') }}</p>
                @endif
                {{-- Inline php directives only — a literal script tag never
                     appears inside this single-file component. --}}
                @php($tag = 'scr'.'ipt')
                @php($scriptSnippet = '<div data-bookthestyle-salon="'.$salon->slug.'" data-bookthestyle-widget="'.$current->public_id.'"></div>'.PHP_EOL.'<'.$tag.' src="'.route('widget.script').'" async></'.$tag.'>')
                @php($iframeSnippet = '<iframe src="'.route('salon.widget', ['salon' => $salon, 'widget' => $current->public_id]).'" style="width:100%;border:0;min-height:640px" title="Book an appointment"></iframe>')

                <div class="flex flex-col gap-2">
                    <h3 class="text-[14px] font-semibold text-ink">{{ __('Recommended: script embed (auto-sizes to content)') }}</h3>
                    <x-ui.copy-field :label="__('Paste where the form should appear')" :value="$scriptSnippet" />
                </div>

                <div class="flex flex-col gap-2">
                    <h3 class="text-[14px] font-semibold text-ink">{{ __('Alternative: plain iframe') }}</h3>
                    <x-ui.copy-field :label="__('For builders that only accept an iframe')" :value="$iframeSnippet" />
                </div>

                <div class="rounded-[11px] bg-muted px-3 py-2.5 text-[13px] text-body">
                    <p class="font-semibold text-ink">{{ __('Options (script embed)') }}</p>
                    <ul class="mt-1 list-disc space-y-1 ps-4">
                        <li>{{ __('data-accent="#RRGGBB" — one-off accent override for that page.') }}</li>
                        <li>{{ __('data-service="ID" — pre-select a service and skip straight to stylist choice.') }}</li>
                    </ul>
                </div>
            </x-ui.card>

            @if ($widgets->count() > 1)
                <div>
                    {{-- Themed confirm (replaces wire:confirm). --}}
                    <button type="button"
                            x-on:click="$store.confirm.ask({
                                title: {{ Js::from(__('Delete widget')) }},
                                message: {{ Js::from(__('Delete this widget? Sites embedding it stop showing a booking form. Existing bookings are kept.')) }},
                                confirmLabel: {{ Js::from(__('Delete')) }},
                                danger: true,
                            }, () => $wire.deleteWidget({{ $current->id }}))"
                            class="text-[13.5px] font-medium text-secondary transition hover:text-danger">{{ __('Delete this widget') }}</button>
                </div>
            @endif
        </div>
    </div>
    @endif

    {{-- Type picker: the first step of creating a widget. Available types
         are selectable cards; coming-soon ones are locked previews (same
         pattern as the theme pickers). --}}
    <x-ui.modal wire:model="showTypePicker" class="max-w-xl"
                :heading="__('What kind of widget?')"
                :subheading="__('Each type embeds on your website with its own look and its own code.')">
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach (\App\Support\WidgetTypeRegistry::TYPES as $typeKey => $type)
                @if ($type['status'] === 'available')
                    <button type="button" wire:click="createWidget('{{ $typeKey }}')"
                            class="flex flex-col gap-2 rounded-[14px] border border-input-border bg-field p-4 text-left transition hover:border-accent">
                        <flux:icon :name="$type['icon']" class="size-6 text-accent" />
                        <span class="text-[15px] font-semibold text-ink">{{ __($type['name']) }}</span>
                        <span class="text-[13px] text-secondary">{{ __($type['description']) }}</span>
                    </button>
                @else
                    <div class="relative overflow-hidden rounded-[14px] border border-border bg-field p-4" aria-disabled="true">
                        <div class="blur-[2px] opacity-60" aria-hidden="true">
                            <flux:icon :name="$type['icon']" class="size-6 text-faint" />
                            <p class="mt-2 text-[15px] font-semibold text-ink">{{ __($type['name']) }}</p>
                            <p class="text-[13px] text-secondary">{{ __($type['description']) }}</p>
                        </div>
                        <span class="absolute right-3 top-3 rounded-full bg-muted px-2.5 py-1 text-[11.5px] font-semibold uppercase tracking-wide text-secondary">{{ __('Coming soon') }}</span>
                    </div>
                @endif
            @endforeach
        </div>
    </x-ui.modal>
</div>
