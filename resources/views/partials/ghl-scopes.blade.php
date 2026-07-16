{{-- The GHL Private Integration required-scopes disclosure — shown at EVERY
     point a PIT can be entered (settings/agency connection card, the agency
     new-salon form; the setup wizard renders its own copy-field variant of
     the same config list). Source of truth: config/ghl.php. --}}
<details class="group rounded-[11px] border border-input-border bg-field">
    <summary class="flex cursor-pointer select-none items-center justify-between gap-2 px-4 py-3 text-[14px] font-medium text-body">
        {{ __('Required scopes') }}
        <flux:icon.chevron-down variant="micro" class="shrink-0 text-faint transition group-open:rotate-180" />
    </summary>
    <div class="flex flex-col gap-3 border-t border-row px-4 pb-4 pt-3">
        <p class="text-[13.5px] text-secondary">
            {{ __('When creating your Private Integration in GoHighLevel (sub-account → Settings → Private Integrations), grant these scopes:') }}
        </p>
        <ul class="flex flex-col gap-1.5">
            @foreach (config('ghl.required_scopes') as $scope => $label)
                <li class="text-[13px] leading-[1.5] text-body">
                    {{ $label }} — <span class="font-mono text-secondary">{{ $scope }}</span>
                </li>
            @endforeach
        </ul>
        <x-ui.copy-field :label="__('Scope list (paste into your notes while ticking)')" :value="implode(', ', array_keys(config('ghl.required_scopes')))" />
        <p class="text-[13px] text-faint">
            {{ __('GoHighLevel shows the token only once — copy it immediately and paste it here.') }}
        </p>
    </div>
</details>
