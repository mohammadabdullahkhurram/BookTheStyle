<?php

use App\Enums\AgencyRole;
use App\Models\Availability;
use App\Models\Salon;
use App\Models\User;
use Livewire\Livewire;

/*
| The Voice AI Prompts settings tab: a nested component on the salon
| Settings page, guarded exactly like Integrations (manageGhlConnection —
| salon owner/manager + agency operators; stylists denied; demo denied).
| Fill-once form → four generated KB articles; empty fields prefill as
| badged drafts from real salon records.
*/

/** A salon with two bookable stylists on real hours + a profile address. */
function vapSalon(): Salon
{
    $salon = bookingSalon([
        'address_line1' => '12 Main Street',
        'city' => 'Springfield',
        'postal_code' => '01101',
    ]);

    foreach (['Maya Marchetti', 'Renee Duval'] as $name) {
        $stylist = stylistOf($salon, User::factory()->create(['name' => $name]));
        foreach ([1, 2, 3, 4] as $wd) { // Tue–Fri 9–19
            Availability::factory()->create([
                'salon_id' => $salon->id, 'user_id' => $stylist->id,
                'weekday' => $wd, 'kind' => 'work', 'start_minute' => 9 * 60, 'end_minute' => 19 * 60,
            ]);
        }
        Availability::factory()->create([ // Sat 9–17
            'salon_id' => $salon->id, 'user_id' => $stylist->id,
            'weekday' => 5, 'kind' => 'work', 'start_minute' => 9 * 60, 'end_minute' => 17 * 60,
        ]);
    }

    return $salon;
}

it('shows the tab to owner and manager, renders nested on Settings, and 403s stylists on direct mount', function () {
    $salon = vapSalon();

    foreach ([salonOwnerOf($salon), salonAdminOf($salon)] as $allowed) {
        $this->actingAs($allowed)->get(route('salon.settings', $salon))
            ->assertOk()
            ->assertSee(__('Voice AI Prompts'))
            ->assertSee(__('Fill this in once and save, then copy each article into this salon\'s GHL knowledge base. The agent prompt and trigger below never change per salon.'));
    }

    // Agency operators reach it too (policy before()).
    $operator = User::factory()->create(['agency_id' => $salon->agency_id, 'agency_role' => AgencyRole::Admin]);
    $this->actingAs($operator)->get(route('salon.settings', $salon))->assertOk()->assertSee(__('Voice AI Prompts'));

    // Stylists: no settings page at all, and the component itself 403s.
    $stylist = stylistOf($salon);
    $this->actingAs($stylist)->get(route('salon.settings', $salon))->assertForbidden();
    Livewire::actingAs($stylist)->test('pages::salon.voice-ai-prompts', ['salon' => $salon])->assertForbidden();
});

it('is unreachable in demo context: no tab render, and the component 403s even for an agency operator', function () {
    $demo = demoShowcase();
    $operator = User::factory()->create(['agency_id' => $demo->agency_id, 'agency_role' => AgencyRole::Owner]);

    // The policy denies demo salons for salon members; agency operators
    // pass the before() hook, so the component's own is_demo guard is the
    // backstop — 403 either way. (The demo guest is unauthenticated, so
    // the tab link never renders for them.)
    Livewire::actingAs($operator)->test('pages::salon.voice-ai-prompts', ['salon' => $demo])->assertForbidden();
    expect(salonOwnerOf($demo)->can('manageGhlConnection', $demo))->toBeFalse();
});

it('prefills stylists, spoken address, and spoken hours as badged drafts from real salon records', function () {
    $salon = vapSalon();

    $component = Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.voice-ai-prompts', ['salon' => $salon]);

    // One row per bookable staff member, name filled, rest blank.
    expect(collect($component->get('stylists'))->pluck('name')->all())->toBe(['Maya Marchetti', 'Renee Duval']);
    expect($component->get('stylists')[0]['specialties'])->toBe('');

    // Address: number + street + city, no zip.
    expect($component->get('address_spoken'))->toBe("We're at 12 Main Street in Springfield");

    // Hours: the exact grouped casual phrasing.
    expect($component->get('hours_spoken'))
        ->toBe('Tuesday to Friday nine to seven, Saturdays nine to five, closed Sunday and Monday');

    // All three flagged as drafts → the badge renders.
    expect($component->get('drafts'))->toContain('stylists')->toContain('address_spoken')->toContain('hours_spoken');
    $component->assertSee(__('from your salon profile — check the wording'));
});

it('saves, persists, reloads with saved values winning, and regenerates the previews', function () {
    $salon = vapSalon();
    $owner = salonOwnerOf($salon);

    $component = Livewire::actingAs($owner)
        ->test('pages::salon.voice-ai-prompts', ['salon' => $salon])
        ->set('dont_offer', 'nails, lash extensions')
        ->set('referral', 'Polished Nail Bar two doors down')
        ->set('pair_need', 'vivid color')
        ->set('pair_stylist', 'Maya Marchetti')
        ->call('save')
        ->assertHasNoErrors();

    // Persisted…
    $saved = $salon->refresh()->voice_ai_settings;
    expect($saved['dont_offer'])->toBe('nails, lash extensions');
    expect($saved['payments'])->toBe(['card', 'cash', 'mobile payments like Apple Pay']);
    expect(collect($saved['stylists'])->pluck('name')->all())->toBe(['Maya Marchetti', 'Renee Duval']);

    // …previews regenerated with the new content, placeholders gone entirely
    // (address + hours prefilled, dont_offer now saved).
    $component->assertSee('The services we do NOT offer are: nails, lash extensions')
        ->assertSee("If you're after vivid color, we'd usually suggest Maya Marchetti.")
        ->assertDontSee('[PLACEHOLDER]')
        ->assertDontSee(__('Still missing:'));

    // A fresh load shows SAVED values, no draft badges.
    $fresh = Livewire::actingAs($owner)->test('pages::salon.voice-ai-prompts', ['salon' => $salon]);
    expect($fresh->get('dont_offer'))->toBe('nails, lash extensions');
    expect($fresh->get('drafts'))->toBe([]);
    $fresh->assertDontSee(__('from your salon profile — check the wording'));
});

it('lists blank required fields in the amber notice while previews still render with placeholders', function () {
    $salon = bookingSalon(['address_line1' => '', 'city' => '']); // no staff, no address → everything missing

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.voice-ai-prompts', ['salon' => $salon])
        ->assertSee(__('Still missing:'))
        ->assertSee(__('at least one stylist'))
        ->assertSee(__('the spoken address'))
        ->assertSee(__('the spoken hours'))
        ->assertSee(__('the services you don\'t offer'))
        ->assertSee('[PLACEHOLDER]')
        ->assertSee('Our team of stylists is happy to help with any service.');
});

it('refills a single spoken field from salon data without touching anything else', function () {
    $salon = vapSalon();

    $component = Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.voice-ai-prompts', ['salon' => $salon])
        ->set('address_spoken', 'Custom wording the owner wrote')
        ->set('hours_spoken', 'Custom hours wording')
        ->call('refill', 'address_spoken');

    expect($component->get('address_spoken'))->toBe("We're at 12 Main Street in Springfield");
    expect($component->get('hours_spoken'))->toBe('Custom hours wording'); // untouched
});

it('appends missing staff without removing or overwriting user-written rows', function () {
    $salon = vapSalon();
    $owner = salonOwnerOf($salon);

    $component = Livewire::actingAs($owner)
        ->test('pages::salon.voice-ai-prompts', ['salon' => $salon])
        ->set('stylists', [['name' => 'Maya Marchetti', 'specialties' => 'balayage', 'days' => 'Tue–Fri']])
        ->call('addMissingStylists');

    $rows = $component->get('stylists');
    expect(collect($rows)->pluck('name')->all())->toBe(['Maya Marchetti', 'Renee Duval']);
    expect($rows[0]['specialties'])->toBe('balayage'); // user row untouched
});

it('renders the three static texts read-only with their exact section labels', function () {
    $salon = vapSalon();

    $this->actingAs(salonOwnerOf($salon))->get(route('salon.settings', $salon))
        ->assertOk()
        ->assertSee(__('Joy — agent prompt · Paste into Voice AI → Agent Goals → Advanced Mode'))
        ->assertSee(__('Knowledge base trigger · Paste into \'When to use this knowledge base\' (GHL max 500 chars — the real text is 493)'))
        ->assertSee(__('KB article: How to handle advice questions and unknowns · add as title + body'))
        ->assertSee(config('voice_ai_prompts.agent_prompt'));
});
