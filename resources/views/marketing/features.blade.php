<x-marketing.layout
    :title="'Features — BookTheStyle'"
    :description="'BookTheStyle features: booking widget, AI voice booking, per-stylist calendar, one-tap check-in, client book, reports. Plus Loopflo and SEO.'">

    <section class="mx-auto max-w-[720px] px-6 pt-14 text-center sm:px-8">
        <h1 class="font-display text-[36px] font-extrabold leading-[1.06] tracking-[-0.02em] text-ink sm:text-[48px]">{{ __('Everything a busy salon needs') }}</h1>
        <p class="mx-auto mt-5 max-w-[560px] text-[17px] leading-[1.55] text-body">
            {{ __('BookTheStyle in detail — how bookings arrive, how the day runs, and what the numbers say.') }}
        </p>
    </section>

    {{-- The calendar, hero-sized --}}
    <section class="mx-auto mt-10 max-w-[1040px] px-6 sm:px-8" aria-label="{{ __('Calendar preview') }}">
        @include('marketing.partials.mock-calendar')
        <p class="mt-3 text-center text-[13.5px] text-secondary">{{ __('The per-stylist day view — every chair in one calm column.') }}</p>
    </section>

    {{-- Feature grid — icon-forward and scannable: a big icon anchor, the
         name, one short line. The visuals above and below do the talking. --}}
    <section class="mx-auto max-w-[1040px] px-6 pt-[64px] sm:px-8">
        <div class="grid gap-x-8 gap-y-11 text-center sm:grid-cols-3">
            @foreach ([
                ['calendar-days', __('Online booking widget'), __('Live availability on your own website.')],
                ['phone-arrow-down-left', __('AI voice and phone booking'), __('The phone answers itself — and books.')],
                ['arrow-path', __('CRM sync'), __('Contacts, reminders, and calendars in step.')],
                ['check-circle', __('One-tap check-in'), __('Arrived, in service, complete — one tap each.')],
                ['users', __('The client book'), __('Allergies, formulas, birthdays, history.')],
                ['chart-bar', __('Reports'), __('See exactly what the AI and widget bring in.')],
                ['clock', __('Real availability'), __('Hours, breaks, buffers — only true openings.')],
                ['paint-brush', __('Your brand'), __('Your logo, colours, and type on the widget.')],
                ['calendar', __('Personal calendar feeds'), __("Every stylist's bookings on their own phone.")],
            ] as [$icon, $title, $body])
                <div class="flex flex-col items-center">
                    <div class="mb-4 flex size-[58px] items-center justify-center rounded-[17px] bg-accent-tint text-accent">
                        <flux:icon :name="$icon" variant="outline" class="size-7" />
                    </div>
                    <h2 class="font-display text-[17px] font-bold text-ink">{{ $title }}</h2>
                    <p class="mt-1 max-w-[240px] text-[13.5px] leading-[1.5] text-secondary">{{ $body }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Dashboard + widget showcases — the dashboard leads, larger. --}}
    <section class="mx-auto max-w-[1040px] px-6 pt-[72px] sm:px-8">
        <div class="grid items-start gap-8 md:grid-cols-[3fr_2fr]">
            <div>
                @include('marketing.partials.mock-dashboard')
                <p class="mt-3 text-center text-[13.5px] text-secondary">{{ __('Today: the whole day — stats, check-in, and where bookings came from.') }}</p>
            </div>
            <div>
                @include('marketing.partials.mock-widget')
                <p class="mt-3 text-center text-[13.5px] text-secondary">{{ __('The booking widget: live availability on your own site.') }}</p>
            </div>
        </div>
    </section>

    {{-- Beyond the app --}}
    <section class="mx-auto max-w-[1040px] px-6 pt-[72px] sm:px-8">
        <h2 class="font-display text-center text-[28px] font-extrabold tracking-[-0.02em] text-ink">{{ __('And around the product') }}</h2>
        <div class="mt-10 grid gap-x-14 gap-y-10 sm:grid-cols-2">
            <div class="border-t border-divider pt-5">
                <h3 class="font-display text-[18px] font-bold text-ink">Loopflo</h3>
                <p class="mt-1.5 text-[14.5px] leading-[1.55] text-body">{{ __('Our CRM product: automated follow-up, review generation, no-show recovery, and reactivation campaigns — set up and managed for you.') }}</p>
                <a href="{{ route('marketing.services') }}#loopflo" class="mt-3 inline-block text-[14px] font-semibold text-accent transition hover:text-accent-hover">{{ __('About Loopflo') }}</a>
            </div>
            <div class="border-t border-divider pt-5">
                <h3 class="font-display text-[18px] font-bold text-ink">{{ __('Local SEO') }}</h3>
                <p class="mt-1.5 text-[14.5px] leading-[1.55] text-body">{{ __('Google Business Profile, reviews, citations, and pages that rank — so the people searching nearby find you first.') }}</p>
                <a href="{{ route('marketing.services') }}#seo" class="mt-3 inline-block text-[14px] font-semibold text-accent transition hover:text-accent-hover">{{ __('About SEO') }}</a>
            </div>
        </div>
    </section>

    {{-- CTA --}}
    <section class="mx-auto max-w-[720px] px-6 pt-[72px] text-center sm:px-8">
        <h2 class="font-display text-[28px] font-extrabold tracking-[-0.02em] text-ink">{{ __('See it on your own salon') }}</h2>
        <p class="mx-auto mt-3 max-w-[480px] text-[16px] leading-[1.55] text-body">{{ __('A short call, your questions answered, and a look at the product with your services in it.') }}</p>
        <div class="mt-6"><x-ui.button :href="route('book-call')">{{ __('Book a call') }}</x-ui.button></div>
    </section>
</x-marketing.layout>
