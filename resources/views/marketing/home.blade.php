<x-marketing.layout
    :title="'Bluejaypro — booking, CRM and local SEO for local businesses'"
    :description="'Bluejaypro helps local businesses grow: BookTheStyle salon booking, the Loopflo CRM, and expert local SEO.'">

    {{-- Hero --}}
    <section class="relative overflow-hidden">
        <div class="pointer-events-none absolute inset-0" style="background: radial-gradient(80% 70% at 50% -8%, var(--accent-tint) 0%, rgba(255,255,255,0) 60%);"></div>
        <div class="relative mx-auto max-w-[860px] px-6 pb-7 pt-14 text-center sm:px-8">
            <div class="mb-6 inline-flex items-center gap-2 rounded-full border border-border bg-card px-3.5 py-1.5 text-[13px] font-semibold text-secondary shadow-xs">
                <span class="size-[7px] rounded-full bg-accent"></span>{{ __('Bookings · CRM · Local SEO') }}
            </div>
            <h1 class="font-display text-[40px] font-extrabold leading-[1.04] tracking-[-0.025em] text-ink text-balance sm:text-[60px]">
                {{ __('Growth that runs your local business, not the other way around') }}
            </h1>
            <p class="mx-auto mt-6 max-w-[600px] text-[18px] leading-[1.55] text-body">
                {{ __('Bluejaypro builds the systems local businesses grow on: BookTheStyle for salon booking, Loopflo for CRM and automation, and local SEO that fills the calendar.') }}
            </p>
            <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                <x-ui.button href="#book">{{ __('Book a call') }}</x-ui.button>
                <x-ui.button variant="secondary" :href="route('marketing.services')">{{ __('See what we do') }}</x-ui.button>
            </div>
        </div>
    </section>

    {{-- Product band: the BookTheStyle calendar --}}
    <section class="mx-auto mt-5 max-w-[1040px] px-6 sm:px-8" aria-label="{{ __('BookTheStyle app preview') }}">
        @include('marketing.partials.mock-calendar')
    </section>

    {{-- The three offerings --}}
    <section class="mx-auto max-w-[1040px] px-6 pt-[72px] sm:px-8">
        <h2 class="font-display text-center text-[30px] font-extrabold tracking-[-0.02em] text-ink sm:text-[36px]">{{ __('Three ways we grow local businesses') }}</h2>
        {{-- Open editorial trio: icon anchors + whitespace, no boxes. --}}
        <div class="mt-12 grid gap-x-10 gap-y-12 sm:grid-cols-3">
            <a href="{{ route('marketing.services') }}#bookthestyle" class="group block">
                <div class="mb-4 flex size-[46px] items-center justify-center rounded-[13px] bg-accent-tint text-accent">
                    <flux:icon.calendar-days variant="outline" class="size-6" />
                </div>
                <h3 class="font-display text-[19px] font-bold text-ink transition group-hover:text-accent">BookTheStyle</h3>
                <p class="mt-1.5 text-[14.5px] leading-[1.55] text-body">{{ __('The salon booking platform: an online booking widget, AI voice and phone booking, and CRM-synced scheduling for every chair.') }}</p>
                <span class="mt-3 inline-block text-[14px] font-semibold text-accent">{{ __('Explore BookTheStyle') }}</span>
            </a>
            <a href="{{ route('marketing.services') }}#loopflo" class="group block">
                <div class="mb-4 flex size-[46px] items-center justify-center rounded-[13px] bg-accent-tint text-accent">
                    <flux:icon.arrow-path-rounded-square variant="outline" class="size-6" />
                </div>
                <h3 class="font-display text-[19px] font-bold text-ink transition group-hover:text-accent">Loopflo</h3>
                <p class="mt-1.5 text-[14.5px] leading-[1.55] text-body">{{ __('Our CRM product: pipelines, automations, follow-up, and reviews — set up and managed for you.') }}</p>
                <span class="mt-3 inline-block text-[14px] font-semibold text-accent">{{ __('Explore Loopflo') }}</span>
            </a>
            <a href="{{ route('marketing.services') }}#seo" class="group block">
                <div class="mb-4 flex size-[46px] items-center justify-center rounded-[13px] bg-accent-tint text-accent">
                    <flux:icon.magnifying-glass variant="outline" class="size-6" />
                </div>
                <h3 class="font-display text-[19px] font-bold text-ink transition group-hover:text-accent">{{ __('Local SEO') }}</h3>
                <p class="mt-1.5 text-[14.5px] leading-[1.55] text-body">{{ __('Expert local search: Google Business Profile, reviews, citations, and content that puts you on the map — literally.') }}</p>
                <span class="mt-3 inline-block text-[14px] font-semibold text-accent">{{ __('Explore SEO') }}</span>
            </a>
        </div>
    </section>

    {{-- BookTheStyle interface showcases --}}
    <section class="mx-auto max-w-[1040px] px-6 pt-[72px] sm:px-8">
        <div class="mx-auto max-w-[620px] text-center">
            <h2 class="font-display text-[30px] font-extrabold tracking-[-0.02em] text-ink sm:text-[36px]">{{ __('See BookTheStyle at work') }}</h2>
            <p class="mt-3 text-[16px] leading-[1.55] text-body">{{ __('The day at a glance, one-tap check-in, and a booking widget your clients use right on your website.') }}</p>
        </div>
        <div class="mt-10 grid items-start gap-7 md:grid-cols-2">
            <div>
                @include('marketing.partials.mock-dashboard')
                <p class="mt-3 text-center text-[13.5px] text-secondary">{{ __('Today: every appointment, checked in with one tap.') }}</p>
            </div>
            <div>
                @include('marketing.partials.mock-widget')
                <p class="mt-3 text-center text-[13.5px] text-secondary">{{ __('The booking widget — live availability, in your brand.') }}</p>
                <div class="mt-9 border-t border-divider pt-6">
                    <h3 class="font-display text-[17px] font-bold text-ink">{{ __('Answered by AI, booked for real') }}</h3>
                    <p class="mt-1.5 text-[14.5px] leading-[1.55] text-body">{{ __('Missed calls become bookings: the AI receptionist answers the phone, checks real availability, and books straight into the calendar.') }}</p>
                    <a href="{{ route('marketing.features') }}" class="mt-3 inline-block text-[14px] font-semibold text-accent transition hover:text-accent-hover">{{ __('All features') }}</a>
                </div>
            </div>
        </div>
    </section>

    {{-- Social proof: live Google reviews --}}
    <section class="mx-auto max-w-[1040px] px-6 pt-[72px] sm:px-8" aria-label="{{ __('Reviews') }}">
        <h2 class="font-display text-center text-[30px] font-extrabold tracking-[-0.02em] text-ink sm:text-[36px]">{{ __('What clients say') }}</h2>
        <div class="mt-8">
            @include('marketing.partials.embed-reviews')
        </div>
    </section>

    {{-- Book a call --}}
    <section id="book" class="mx-auto max-w-[860px] scroll-mt-8 px-6 pt-[72px] sm:px-8">
        <div class="mx-auto max-w-[560px] text-center">
            <h2 class="font-display text-[30px] font-extrabold tracking-[-0.02em] text-ink sm:text-[36px]">{{ __('Talk to us') }}</h2>
            <p class="mt-3 text-[16px] leading-[1.55] text-body">{{ __('Pick a time that works — a friendly look at how Bluejaypro fits your business. No commitment.') }}</p>
        </div>
        <div class="mt-8">
            @include('marketing.partials.embed-booking')
        </div>
        <p class="mt-4 text-center text-[13.5px] text-secondary">
            {{ __('Prefer email or phone?') }}
            <a href="mailto:hello@bluejaypro.com" class="font-semibold text-accent transition hover:text-accent-hover">hello@bluejaypro.com</a> ·
            <a href="tel:+19168948575" class="font-semibold text-accent transition hover:text-accent-hover">(916) 894-8575</a> ·
            <a href="{{ route('book-call') }}" class="font-semibold text-accent transition hover:text-accent-hover">{{ __('Open the booking page') }}</a>
        </p>
    </section>
</x-marketing.layout>
