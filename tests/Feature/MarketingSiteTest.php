<?php

use App\Models\Salon;
use Illuminate\Support\Str;

/*
| The Bluejaypro marketing site: a multi-page public site (Home, Services,
| Features, Contact) on the apex domain in the landing visual language,
| presenting the three offerings (BookTheStyle, Loopflo, SEO) with app
| interface showcases and Bluejaypro's live GHL embeds. Public only — the
| app, agency and tenant hosts are untouched.
*/

it('renders all four marketing pages with the shared nav and footer', function () {
    foreach (['home', 'marketing.services', 'marketing.features', 'marketing.contact'] as $routeName) {
        $response = $this->get(route($routeName))->assertOk();

        // Shared shell: the Bluejaypro nav (all four pages + CTA)…
        $response->assertSee('Bluejaypro')
            ->assertSee(route('marketing.services'), false)
            ->assertSee(route('marketing.features'), false)
            ->assertSee(route('marketing.contact'), false)
            ->assertSee(route('book-call'), false)
            ->assertSee('Skip to content')
            // …and the footer with the real contact details.
            ->assertSee('9447 Crystal Shore Ln')
            ->assertSee('(279) 346-4889')
            ->assertSee('justin@bluejaypro.com');
    }
});

it('presents the three offerings with the BookTheStyle app showcases', function () {
    // Home: the value prop, the offering trio, and app previews.
    $this->get(route('home'))->assertOk()
        ->assertSee('BookTheStyle')
        ->assertSee('Loopflo')
        ->assertSee('Local SEO')
        ->assertSee('app.bookthestyle.com', false) // the framed app mockups
        ->assertSee('Select date &amp; time', false); // the widget mockup

    // Services: the three sections with anchors.
    $this->get(route('marketing.services'))->assertOk()
        ->assertSee('id="bookthestyle"', false)
        ->assertSee('id="loopflo"', false)
        ->assertSee('id="seo"', false)
        ->assertSee('(916) 894-8575'); // the SEO line

    // Features: the deep product grid + both showcases.
    $this->get(route('marketing.features'))->assertOk()
        ->assertSee('AI voice and phone booking')
        ->assertSee('One-tap check-in')
        ->assertSee('GoHighLevel sync')
        ->assertSee('app.bookthestyle.com/today', false);
});

it('wires the GHL embeds: booking calendar, reviews widget, and the contact-form slot', function () {
    // The booking calendar on Home and Contact.
    foreach (['home', 'marketing.contact'] as $routeName) {
        $this->get(route($routeName))->assertOk()
            ->assertSee('https://app.bluejaypro.com/widget/booking/me0hVAzF5a4VJqQ16UeX', false)
            ->assertSee('https://app.bluejaypro.com/js/form_embed.js', false);
    }

    // The Google reviews widget on Home.
    $this->get(route('home'))->assertOk()
        ->assertSee('reputation/widgets/review_widget/4Wis4URhfvUAp2SPLGYA', false)
        ->assertSee('review-widget.js', false);

    // The clearly-marked contact-form slot, ready for the iframe paste.
    $this->get(route('marketing.contact'))->assertOk()
        ->assertSee('data-embed-slot="ghl-contact-form"', false)
        ->assertSee('id="contact-form-embed"', false);
});

it('permits the Bluejaypro embed origins in the CSP on marketing hosts only', function () {
    // Apex marketing pages: frames AND scripts from app.bluejaypro.com.
    $csp = $this->get(route('home'))->assertOk()
        ->headers->get('Content-Security-Policy');
    expect($csp)->toContain('https://app.bluejaypro.com');
    foreach (['frame-src', 'script-src'] as $directive) {
        expect(Str::of($csp)->explode('; ')->first(fn (string $part): bool => str_starts_with($part, $directive)))
            ->toContain('https://app.bluejaypro.com');
    }

    // The app host stays strict — no marketing origins leak into the app.
    $appCsp = $this->get(route('login'))->assertOk()->headers->get('Content-Security-Policy');
    expect($appCsp)->not->toContain('bluejaypro.com');

    // Tenant hosts too.
    $salon = Salon::factory()->create();
    $tenantCsp = $this->get(route('salon.widget', $salon))->assertOk()->headers->get('Content-Security-Policy');
    expect($tenantCsp)->not->toContain('bluejaypro.com');
});

it('leaves the app, agency and tenant routing untouched', function () {
    $salon = Salon::factory()->create();

    $this->get(route('login'))->assertOk();
    $this->get(route('book-call'))->assertOk();
    $this->actingAs(salonOwnerOf($salon))->get(route('salon.show', $salon))->assertOk();
});
