<?php

use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\User;
use App\Support\HexColor;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/*
| The stepped new-salon flow (Basics → Booking policy → Branding →
| GoHighLevel → Review & create): state persists across steps, Next
| validates only the current step, Create validates everything and jumps
| back to the step owning the first invalid field. Plus the accent hex
| normalisation and the app-wide no-native-validation-chrome guard.
*/

function createFlowAdmin(): User
{
    $agency = Agency::factory()->create();

    return User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Admin]);
}

/** @return array<string, string> */
function createFlowProfile(): array
{
    return salonProfileInput(['name' => 'Stepped Salon']);
}

// ---------------------------------------------------------------------------
// The stepped flow
// ---------------------------------------------------------------------------

it('walks Basics → Policy → Branding → GHL → Review with state preserved', function () {
    $this->actingAs(createFlowAdmin());

    $page = Livewire::test('pages::agency.salons.create')
        ->assertSet('step', 'basics')
        ->assertSee('Review & create')     // progress indicator shows the road ahead
        ->set(createFlowProfile())
        ->set('slug', 'stepped-salon')
        ->set('timezone', 'America/New_York')
        ->call('next')->assertSet('step', 'policy')
        ->set('max_advance_days', 120)
        ->call('next')->assertSet('step', 'branding')
        ->set('accent', '1f6f6b')          // normalised live (see below)
        ->call('next')->assertSet('step', 'ghl')
        ->call('next')->assertSet('step', 'review')
        // Review shows what was entered, with the normalised accent.
        ->assertSee('Stepped Salon')
        ->assertSee('stepped-salon.'.config('app.domain'))
        ->assertSee('120 days')
        ->assertSee('#1F6F6B');

    // Back never loses state.
    $page->call('back')->assertSet('step', 'ghl')
        ->call('back')->assertSet('step', 'branding')
        ->assertSet('accent', '#1F6F6B');

    // Create from review persists everything.
    $page->call('goTo', 'review')->call('save')->assertHasNoErrors();

    $salon = Salon::query()->where('slug', 'stepped-salon')->firstOrFail();
    expect($salon->max_advance_days)->toBe(120);
    expect($salon->branding['accent'] ?? null)->toBe('#1F6F6B');
});

it('stops Next on the current step\'s own validation errors', function () {
    $this->actingAs(createFlowAdmin());

    Livewire::test('pages::agency.salons.create')
        ->call('next')
        ->assertHasErrors(['name', 'slug'])
        ->assertSet('step', 'basics');
});

it('returns to the offending step when Create fails — never a silent hidden error', function () {
    $this->actingAs(createFlowAdmin());

    Livewire::test('pages::agency.salons.create')
        ->set(createFlowProfile())
        ->set('slug', 'jump-back')
        ->set('timezone', 'America/New_York')
        ->call('goTo', 'review')
        // Sabotage a policy-step field, then create from the review step.
        ->set('max_advance_days', 0)
        ->call('save')
        ->assertHasErrors(['max_advance_days'])
        ->assertSet('step', 'policy');

    expect(Salon::query()->where('slug', 'jump-back')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Accent hex normalisation (create form + Branding tab + widget branding)
// ---------------------------------------------------------------------------

it('normalises accent input everywhere: 1F6F6B and #1f6f6b become #1F6F6B; garbage stays an error', function () {
    expect(HexColor::normalize('1F6F6B'))->toBe('#1F6F6B');
    expect(HexColor::normalize(' #1f6f6b '))->toBe('#1F6F6B');
    expect(HexColor::normalize('#1F6F6B'))->toBe('#1F6F6B');
    expect(HexColor::normalize('1F6F6'))->toBeNull();     // wrong length
    expect(HexColor::normalize('GGGGGG'))->toBeNull();    // non-hex
    expect(HexColor::normalize(''))->toBeNull();

    // Create form: bare hex saves as canonical.
    $this->actingAs(createFlowAdmin());
    Livewire::test('pages::agency.salons.create')
        ->set(createFlowProfile())
        ->set('slug', 'hex-salon')
        ->set('timezone', 'America/New_York')
        ->set('accent', '1F6F6B')
        ->call('save')
        ->assertHasNoErrors();
    expect(Salon::query()->where('slug', 'hex-salon')->firstOrFail()->branding['accent'])->toBe('#1F6F6B');

    // A genuinely invalid colour still errors.
    Livewire::test('pages::agency.salons.create')
        ->set(createFlowProfile())
        ->set('slug', 'hex-bad')
        ->set('timezone', 'America/New_York')
        ->set('accent', 'not-a-colour')
        ->call('save')
        ->assertHasErrors(['accent']);
});

it('normalises the Branding tab and widget accent inputs the same way', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    Livewire::actingAs($owner)
        ->test('pages::salon.settings', ['salon' => $salon])
        ->set('accent', '2f5d7c')
        ->assertSet('accent', '#2F5D7C')   // live normalisation via updatedAccent
        ->call('saveBranding')
        ->assertHasNoErrors();
    expect($salon->fresh()->branding['accent'])->toBe('#2F5D7C');

    $salon->defaultWidget();
    Livewire::actingAs($owner)
        ->test('pages::salon.widgets', ['salon' => $salon])
        ->set('accent', 'f2d8a0')
        ->call('save')
        ->assertHasNoErrors();
    expect($salon->defaultWidget()->fresh()->branding['accent'] ?? null)->toBe('#F2D8A0');
});

// ---------------------------------------------------------------------------
// No native browser validation chrome (the novalidate policy)
// ---------------------------------------------------------------------------

it('suppresses native browser validation on every form — app validation is the only voice', function () {
    $offenders = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($file) => str_ends_with($file->getFilename(), '.blade.php'))
        ->flatMap(function ($file) {
            preg_match_all('/<form[^>]*>/s', (string) file_get_contents($file->getPathname()), $m);

            return collect($m[0])
                ->reject(fn (string $tag) => str_contains($tag, 'novalidate'))
                ->map(fn (string $tag) => $file->getRelativePathname().': '.trim($tag));
        })
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('ships no native alert/prompt/confirm/beforeunload dialogs anywhere', function () {
    $sources = collect(File::allFiles(resource_path('views')))
        ->merge(File::allFiles(resource_path('js')))
        ->filter(fn ($file) => preg_match('/\.(blade\.php|js)$/', $file->getFilename()) === 1);

    foreach ($sources as $file) {
        $source = (string) preg_replace('/\{\{--.*?--\}\}|^\s*\/\/.*$/ms', '', (string) file_get_contents($file->getPathname()));

        expect(preg_match('/(?<![.\w$])(alert|prompt)\(|window\.confirm|beforeunload/', $source))
            ->toBe(0, 'native dialog found in '.$file->getRelativePathname());
    }
});
