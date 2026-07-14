<x-marketing.layout
    :title="'Contact — Bluejaypro'"
    :description="'Talk to Bluejaypro: book a call, send a message, or reach us directly in Elk Grove, CA.'">

    <section class="mx-auto max-w-[720px] px-6 pt-14 text-center sm:px-8">
        <h1 class="font-display text-[36px] font-extrabold leading-[1.06] tracking-[-0.02em] text-ink sm:text-[48px]">{{ __('Talk to us') }}</h1>
        <p class="mx-auto mt-5 max-w-[520px] text-[17px] leading-[1.55] text-body">
            {{ __('Book a call below, drop us a message, or reach us directly — whichever suits you.') }}
        </p>
    </section>

    <section class="mx-auto max-w-[1040px] px-6 pt-12 sm:px-8">
        <div class="grid items-start gap-10 md:grid-cols-[320px_minmax(0,1fr)]">
            {{-- Direct details --}}
            <div class="flex flex-col gap-6">
                <div class="rounded-[16px] border border-border bg-card p-6 shadow-card">
                    <h2 class="text-[13px] font-semibold uppercase tracking-[0.06em] text-secondary">{{ __('Visit') }}</h2>
                    <address class="mt-2 text-[15px] leading-[1.6] not-italic text-body">
                        9447 Crystal Shore Ln, Floor 2<br>Elk Grove, CA 95758
                    </address>
                </div>
                <div class="rounded-[16px] border border-border bg-card p-6 shadow-card">
                    <h2 class="text-[13px] font-semibold uppercase tracking-[0.06em] text-secondary">{{ __('Call') }}</h2>
                    <p class="mt-2 text-[15px] leading-[1.7] text-body">
                        <a href="tel:+12793464889" class="font-semibold text-accent transition hover:text-accent-hover">(279) 346-4889</a><br>
                        <span class="text-[13.5px] text-secondary">{{ __('SEO enquiries:') }}</span>
                        <a href="tel:+19168948575" class="font-semibold text-accent transition hover:text-accent-hover">(916) 894-8575</a>
                    </p>
                </div>
                <div class="rounded-[16px] border border-border bg-card p-6 shadow-card">
                    <h2 class="text-[13px] font-semibold uppercase tracking-[0.06em] text-secondary">{{ __('Write') }}</h2>
                    <p class="mt-2 text-[15px] text-body">
                        <a href="mailto:justin@bluejaypro.com" class="font-semibold text-accent transition hover:text-accent-hover">justin@bluejaypro.com</a>
                    </p>
                </div>
            </div>

            <div class="flex flex-col gap-10">
                {{-- Message form --}}
                <div>
                    <h2 class="font-display text-[24px] font-extrabold tracking-[-0.02em] text-ink">{{ __('Send a message') }}</h2>
                    {{--
                        GHL CONTACT FORM EMBED SLOT — paste the LeadConnector
                        form iframe (plus its form_embed.js script tag if not
                        already on the page) INSIDE the div below, replacing
                        the placeholder paragraph. The page CSP already
                        permits app.bluejaypro.com frames and scripts.
                    --}}
                    <div id="contact-form-embed" data-embed-slot="ghl-contact-form"
                         class="mt-5 rounded-[20px] border border-dashed border-input-border bg-card px-6 py-10 text-center">
                        <p class="text-[14.5px] text-secondary">
                            {{ __('Our contact form is on its way. Meanwhile, email') }}
                            <a href="mailto:justin@bluejaypro.com" class="font-semibold text-accent transition hover:text-accent-hover">justin@bluejaypro.com</a>
                            {{ __('or book a call below.') }}
                        </p>
                    </div>
                </div>

                {{-- Book a call --}}
                <div id="book" class="scroll-mt-8">
                    <h2 class="font-display text-[24px] font-extrabold tracking-[-0.02em] text-ink">{{ __('Book a call') }}</h2>
                    <div class="mt-5">
                        @include('marketing.partials.embed-booking')
                    </div>
                </div>
            </div>
        </div>
    </section>
</x-marketing.layout>
