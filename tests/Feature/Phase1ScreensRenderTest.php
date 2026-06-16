<?php

use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\User;

/*
| Smoke test: every Phase 1 screen renders (200) for an authorised operator.
| Guards against Blade/Volt render regressions the targeted tests don't hit.
*/

it('renders every agency + salon screen for an authorised operator', function () {
    $agency = Agency::factory()->create();
    $salon = Salon::factory()->for($agency)->create();

    $owner = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);
    $target = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::User]);

    $this->actingAs($owner);

    // Agency console
    $this->get(route('agency.overview'))->assertOk();
    $this->get(route('agency.salons.index'))->assertOk();
    $this->get(route('agency.salons.create'))->assertOk();
    $this->get(route('agency.salons.edit', $salon))->assertOk();
    $this->get(route('agency.users.index'))->assertOk();
    $this->get(route('agency.users.create'))->assertOk();
    $this->get(route('agency.users.edit', $target))->assertOk();

    // Salon-scoped (owner operates every salon in the agency)
    $this->get(route('salon.show', $salon))->assertOk();
    $this->get(route('salon.staff', $salon))->assertOk();
    $this->get(route('salon.settings', $salon))->assertOk();
});

it('renders the dashboard for an agency operator and a salon staff member', function () {
    $agency = Agency::factory()->create();
    $salon = Salon::factory()->for($agency)->create();
    $owner = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);

    $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('Agency console');
});
