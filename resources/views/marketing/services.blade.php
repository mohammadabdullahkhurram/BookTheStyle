<x-marketing.layout
    :title="'Services — Bluejaypro'"
    :description="'BookTheStyle salon booking, the Loopflo CRM, and expert local SEO — what each does and why it works.'">

    <section class="mx-auto max-w-[720px] px-6 pt-14 text-center sm:px-8">
        <h1 class="font-display text-[36px] font-extrabold leading-[1.06] tracking-[-0.02em] text-ink sm:text-[48px]">{{ __('What we do') }}</h1>
        <p class="mx-auto mt-5 max-w-[560px] text-[17px] leading-[1.55] text-body">
            {{ __('Three offerings, one goal: a calendar that fills itself. Pick one, or let them work together.') }}
        </p>
    </section>

    {{-- BookTheStyle --}}
    <section id="bookthestyle" class="mx-auto max-w-[1040px] scroll-mt-8 px-6 pt-[64px] sm:px-8">
        <div class="grid items-center gap-10 md:grid-cols-2">
            <div>
                <div class="mb-4 inline-flex items-center gap-2 rounded-full border border-border bg-card px-3.5 py-1.5 text-[13px] font-semibold text-secondary shadow-xs">
                    <span class="size-[7px] rounded-full bg-accent"></span>{{ __('Product') }}
                </div>
                <h2 class="font-display text-[30px] font-extrabold tracking-[-0.02em] text-ink">BookTheStyle</h2>
                <p class="mt-3 text-[16px] leading-[1.6] text-body">
                    {{ __('The salon booking platform. Clients book through a widget on your website or by simply calling — the AI receptionist answers, checks live availability, and books. Everything lands on one per-stylist calendar, synced with your CRM for reminders, and staff check clients in with a tap.') }}
                </p>
                <ul class="mt-5 flex flex-col gap-2.5 text-[15px] text-body">
                    <li class="flex gap-2.5"><span class="mt-1 size-[7px] shrink-0 rounded-full bg-accent"></span>{{ __('Online booking widget with live, per-stylist availability') }}</li>
                    <li class="flex gap-2.5"><span class="mt-1 size-[7px] shrink-0 rounded-full bg-accent"></span>{{ __('AI voice and phone booking — missed calls become appointments') }}</li>
                    <li class="flex gap-2.5"><span class="mt-1 size-[7px] shrink-0 rounded-full bg-accent"></span>{{ __('CRM-synced scheduling: reminders, contacts, and calendars stay in step') }}</li>
                    <li class="flex gap-2.5"><span class="mt-1 size-[7px] shrink-0 rounded-full bg-accent"></span>{{ __('Client book with allergies, formulas, and visit history') }}</li>
                    <li class="flex gap-2.5"><span class="mt-1 size-[7px] shrink-0 rounded-full bg-accent"></span>{{ __('Reports that show where every booking came from') }}</li>
                </ul>
                <div class="mt-6 flex flex-wrap gap-3">
                    <x-ui.button :href="route('marketing.features')">{{ __('See the features') }}</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('book-call')">{{ __('Book a demo') }}</x-ui.button>
                </div>
            </div>
            <div>
                @include('marketing.partials.mock-dashboard')
            </div>
        </div>
    </section>

    {{-- Loopflo --}}
    <section id="loopflo" class="mx-auto max-w-[1040px] scroll-mt-8 px-6 pt-[72px] sm:px-8">
        <div class="grid items-center gap-10 md:grid-cols-2">
            <div class="order-2 md:order-1">
                <div>
                    <div class="text-[12px] font-semibold uppercase tracking-[0.06em] text-secondary">{{ __('A Loopflo week') }}</div>
                    <div class="mt-2 flex flex-col divide-y divide-divider">
                        @foreach ([
                            [__('New lead from Google'), __('Tagged, pipelined, first text sent in 90 seconds'), '#3E5C3A'],
                            [__('No-show recovery'), __('Automatic rebooking sequence — 2 of 3 recovered'), '#8A5A1E'],
                            [__('Review request'), __('Sent after every completed visit — 4.9 average'), '#4B3F9E'],
                        ] as [$title, $meta, $ink])
                            <div class="py-4">
                                <div class="flex items-center gap-2.5">
                                    <span class="size-[9px] rounded-full" style="background-color: {{ $ink }};"></span>
                                    <span class="text-[15px] font-semibold text-ink">{{ $title }}</span>
                                </div>
                                <p class="mt-1 ps-[19.5px] text-[13.5px] text-body">{{ $meta }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="order-1 md:order-2">
                <div class="mb-4 inline-flex items-center gap-2 rounded-full border border-border bg-card px-3.5 py-1.5 text-[13px] font-semibold text-secondary shadow-xs">
                    <span class="size-[7px] rounded-full bg-accent"></span>{{ __('Product') }}
                </div>
                <h2 class="font-display text-[30px] font-extrabold tracking-[-0.02em] text-ink">Loopflo CRM</h2>
                <p class="mt-3 text-[16px] leading-[1.6] text-body">
                    {{ __('Our CRM product, run for you. Loopflo keeps every lead warm and every client coming back — we build and maintain the pipelines, automations, and follow-up sequences while you watch the calendar fill.') }}</p>
                <ul class="mt-5 flex flex-col gap-2.5 text-[15px] text-body">
                    <li class="flex gap-2.5"><span class="mt-1 size-[7px] shrink-0 rounded-full bg-accent"></span>{{ __('Full CRM setup, branded to your business, with ongoing management') }}</li>
                    <li class="flex gap-2.5"><span class="mt-1 size-[7px] shrink-0 rounded-full bg-accent"></span>{{ __('Lead pipelines and automated follow-up (text, email, voicemail)') }}</li>
                    <li class="flex gap-2.5"><span class="mt-1 size-[7px] shrink-0 rounded-full bg-accent"></span>{{ __('Review generation and reputation management') }}</li>
                    <li class="flex gap-2.5"><span class="mt-1 size-[7px] shrink-0 rounded-full bg-accent"></span>{{ __('Reactivation campaigns for quiet client lists') }}</li>
                </ul>
                <div class="mt-6"><x-ui.button :href="route('book-call')">{{ __('Talk about Loopflo') }}</x-ui.button></div>
            </div>
        </div>
    </section>

    {{-- SEO --}}
    <section id="seo" class="mx-auto max-w-[1040px] scroll-mt-8 px-6 pt-[72px] sm:px-8">
        <div class="grid items-center gap-10 md:grid-cols-2">
            <div>
                <div class="mb-4 inline-flex items-center gap-2 rounded-full border border-border bg-card px-3.5 py-1.5 text-[13px] font-semibold text-secondary shadow-xs">
                    <span class="size-[7px] rounded-full bg-accent"></span>{{ __('Service') }}
                </div>
                <h2 class="font-display text-[30px] font-extrabold tracking-[-0.02em] text-ink">{{ __('Local SEO') }}</h2>
                <p class="mt-3 text-[16px] leading-[1.6] text-body">
                    {{ __('Expert local search that puts your business in front of people already looking for it: the map pack, the reviews, and the pages that convert searches into calls.') }}
                </p>
                <ul class="mt-5 flex flex-col gap-2.5 text-[15px] text-body">
                    <li class="flex gap-2.5"><span class="mt-1 size-[7px] shrink-0 rounded-full bg-accent"></span>{{ __('Google Business Profile optimisation and posting') }}</li>
                    <li class="flex gap-2.5"><span class="mt-1 size-[7px] shrink-0 rounded-full bg-accent"></span>{{ __('Citations, local links, and technical cleanup') }}</li>
                    <li class="flex gap-2.5"><span class="mt-1 size-[7px] shrink-0 rounded-full bg-accent"></span>{{ __('Location and service pages written to rank and convert') }}</li>
                    <li class="flex gap-2.5"><span class="mt-1 size-[7px] shrink-0 rounded-full bg-accent"></span>{{ __('Monthly reporting you can actually read') }}</li>
                </ul>
                <div class="mt-6 flex flex-wrap items-center gap-4">
                    <x-ui.button :href="route('book-call')">{{ __('Talk about SEO') }}</x-ui.button>
                    <a href="tel:+19168948575" class="text-[14.5px] font-semibold text-accent transition hover:text-accent-hover">(916) 894-8575</a>
                </div>
            </div>
            <div>
                <div>
                    <div class="text-[12px] font-semibold uppercase tracking-[0.06em] text-secondary">{{ __('Search: "hair salon near me"') }}</div>
                    <div class="mt-2 flex flex-col divide-y divide-divider">
                        @foreach ([[__('Your salon'), '4.9', true], [__('Competitor A'), '4.4', false], [__('Competitor B'), '4.1', false]] as [$name, $stars, $you])
                            <div class="flex items-center gap-3 py-4 {{ $you ? 'rounded-[13px] bg-accent-tint px-4' : '' }}">
                                <span class="text-[15px] font-semibold {{ $you ? 'text-accent-ink' : 'text-ink' }}">{{ $name }}</span>
                                <div class="flex-1"></div>
                                <span class="text-[13.5px] {{ $you ? 'font-semibold text-accent-ink' : 'text-secondary' }}">{{ $stars }} ★ {{ $you ? '· '.__('Top of the map pack') : '' }}</span>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-4 text-[13px] text-faint">{{ __('Illustrative — rankings vary by market and effort.') }}</p>
                </div>
            </div>
        </div>
    </section>
</x-marketing.layout>
