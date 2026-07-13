<?php

use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\User;
use App\Models\Widget;
use App\Support\ThemeRegistry;
use App\Support\WidgetBranding;
use App\Support\WidgetTypeRegistry;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| The theme system + the multi-widget infrastructure. Themes are named token
| sets applied via data-theme on <body> (ThemeRegistry); a salon picks its
| APP theme in Settings → Branding; the agency console always renders
| Glacier; each booking widget row has its own branding/theme/embed id and
| the public widget page resolves a SPECIFIC widget by public id.
*/

// ---------------------------------------------------------------------------
// Theme registry
// ---------------------------------------------------------------------------

it('registers Marble (the default), Classic and Glacier as available; coming-soon stays locked', function () {
    expect(ThemeRegistry::THEMES['marble']['status'])->toBe('available');
    expect(ThemeRegistry::THEMES['classic']['status'])->toBe('available');
    expect(ThemeRegistry::THEMES['glacier']['status'])->toBe('available');
    expect(ThemeRegistry::DEFAULT_APP)->toBe('marble');

    $comingSoon = collect(ThemeRegistry::THEMES)->where('status', 'coming_soon');
    expect($comingSoon->count())->toBeGreaterThanOrEqual(3);

    expect(ThemeRegistry::selectable('marble', ThemeRegistry::SCOPE_APP))->toBeTrue();
    expect(ThemeRegistry::selectable('marble', ThemeRegistry::SCOPE_WIDGET))->toBeTrue();
    expect(ThemeRegistry::selectable('classic', ThemeRegistry::SCOPE_APP))->toBeTrue();
    // Glacier is the AGENCY console language — never picker-offered.
    expect(ThemeRegistry::selectable('glacier', ThemeRegistry::SCOPE_WIDGET))->toBeFalse();
    expect(ThemeRegistry::selectable('glacier', ThemeRegistry::SCOPE_APP))->toBeFalse();
    // Locked and unknown keys are never selectable anywhere.
    foreach ($comingSoon->keys() as $key) {
        expect(ThemeRegistry::selectable($key, ThemeRegistry::SCOPE_APP))->toBeFalse();
    }
    expect(ThemeRegistry::selectable('does-not-exist', ThemeRegistry::SCOPE_APP))->toBeFalse();

    // data-theme values: Classic (and the pre-rollout stored 'default', and
    // locked keys) render the BASE token set — the original look — as null.
    expect(ThemeRegistry::bodyTheme('classic'))->toBeNull();
    expect(ThemeRegistry::bodyTheme('default'))->toBeNull();
    expect(ThemeRegistry::bodyTheme('marble'))->toBe('marble');
    expect(ThemeRegistry::bodyTheme('velvet'))->toBeNull();
});

it('ships real token blocks for Marble and Glacier in the stylesheet', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain("body[data-theme='marble']");
    expect($css)->toContain('--color-paper: #fff8ef');
    expect($css)->toContain('--accent: #bc4a28');
    expect($css)->toContain("body[data-theme='glacier']");
    expect($css)->toContain('rgb(91 146 189'); // the glacier sky bloom
});

// ---------------------------------------------------------------------------
// Salon app theme (Settings → Branding)
// ---------------------------------------------------------------------------

it('defaults salons to Marble and lets a manager switch to Classic and back', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    // Marble is the default — no choice needed.
    expect($salon->app_theme)->toBe('marble');
    $this->actingAs($owner)->get(route('salon.settings', $salon))
        ->assertOk()
        ->assertSee('data-theme="marble"', false);

    // Classic reproduces the original look: the BASE token set, no attribute.
    Livewire::actingAs($owner)
        ->test('pages::salon.settings', ['salon' => $salon])
        ->call('saveAppTheme', 'classic');
    expect($salon->fresh()->app_theme)->toBe('classic');
    $this->actingAs($owner)->get(route('salon.settings', $salon))
        ->assertOk()
        ->assertDontSee('data-theme=', false);

    // And back to Marble.
    Livewire::actingAs($owner)
        ->test('pages::salon.settings', ['salon' => $salon])
        ->call('saveAppTheme', 'marble');
    expect($salon->fresh()->app_theme)->toBe('marble');
});

it('refuses coming-soon and unknown app themes', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $component = Livewire::actingAs($owner)->test('pages::salon.settings', ['salon' => $salon]);

    $component->call('saveAppTheme', 'velvet');
    expect($salon->fresh()->app_theme)->toBe('marble');

    $component->call('saveAppTheme', 'nope');
    expect($salon->fresh()->app_theme)->toBe('marble');

    // The picker shows Marble + Classic live and the locked previews.
    $component->assertSee('Coming soon')->assertSee('Velvet')->assertSee('Classic');
});

it('renders Marble across the salon app by default — every screen, one theme', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $this->actingAs($owner);

    foreach (['salon.show', 'salon.calendar', 'salon.appointments', 'salon.clients', 'salon.services', 'salon.staff', 'salon.availability', 'salon.reports', 'salon.settings', 'salon.widgets', 'salon.account'] as $routeName) {
        $this->get(route($routeName, $salon))
            ->assertOk()
            ->assertSee('data-theme="marble"', false);
    }
});

it('holds WCAG AA under Marble: every text token on its surfaces, white on the coral', function () {
    $contrast = fn (string $a, string $b): float => WidgetBranding::contrast($a, $b);

    // Text ramp on Marble paper (#FFF8EF) and card (#FFFDF8).
    foreach (['#FFF8EF', '#FFFDF8'] as $surface) {
        expect($contrast('#4A382E', $surface))->toBeGreaterThanOrEqual(4.5); // ink
        expect($contrast('#6B5546', $surface))->toBeGreaterThanOrEqual(4.5); // body
        expect($contrast('#7A6250', $surface))->toBeGreaterThanOrEqual(4.5); // secondary
        expect($contrast('#846D5A', $surface))->toBeGreaterThanOrEqual(4.5); // faint (real content)
    }
    // Actions: white on the coral accent; accent ink on the butter tint.
    expect($contrast('#FFFFFF', '#BC4A28'))->toBeGreaterThanOrEqual(4.5);
    expect($contrast('#9C3F22', '#FBE9D7'))->toBeGreaterThanOrEqual(4.5);

    // And the shipped CSS carries exactly these values.
    $css = file_get_contents(resource_path('css/app.css'));
    foreach (['#4a382e', '#6b5546', '#7a6250', '#846d5a', '#bc4a28', '#fbe9d7'] as $hex) {
        expect($css)->toContain($hex);
    }
    // The component voice ships too: pressed-clay surfaces + button press.
    expect($css)->toContain("body[data-theme='marble'] .bts-surface")
        ->toContain("body[data-theme='marble'] .bts-btn-primary:active")
        ->toContain("body[data-theme='marble'] dialog");
});

it('removes the UI/UX gallery: no route, no nav item, no page', function () {
    expect(Route::has('salon.uiux'))->toBeFalse();
    expect(file_exists(resource_path('views/pages/salon/uiux.blade.php')))->toBeFalse();

    $salon = Salon::factory()->create();
    $this->actingAs(salonOwnerOf($salon))->get(route('salon.show', $salon))
        ->assertOk()
        ->assertDontSee('aria-label="UI/UX"', false);
});

// ---------------------------------------------------------------------------
// Agency console = the BRAND (landing) palette
// ---------------------------------------------------------------------------

it('renders the agency console under the BRAND palette, not Glacier', function () {
    $agency = Agency::factory()->create();
    $owner = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);

    foreach (['agency.overview', 'agency.salons.index', 'agency.reports', 'agency.users.index'] as $routeName) {
        $this->actingAs($owner)->get(route($routeName))
            ->assertOk()
            ->assertSee('data-theme="brand"', false)
            ->assertDontSee('data-theme="glacier"', false);
    }
});

it('captures the EXACT landing palette as the brand theme: base tokens on white, AA throughout', function () {
    // The landing page is built on the base token set — assert the brand
    // block only lifts the ground to white, and that the base values it
    // inherits are the landing's exact colours.
    $css = file_get_contents(resource_path('css/app.css'));
    expect($css)->toContain("body[data-theme='brand']")
        ->toContain("body[data-theme='brand'] .bts-glass-panel")
        ->toContain('0 24px 60px rgb(28 27 26 / 0.1)') // the landing card shadow
        ->toContain('--accent: #824c71')   // landing accent (base token)
        ->toContain('--color-ink: #211c18')
        ->toContain('--color-body: #57504a');

    // AA on the white brand ground.
    $contrast = fn (string $a, string $b): float => WidgetBranding::contrast($a, $b);
    foreach (['#211C18', '#57504A', '#6C645C', '#746C62'] as $text) {
        expect($contrast($text, '#FFFFFF'))->toBeGreaterThanOrEqual(4.5);
    }
    expect($contrast('#FFFFFF', '#824C71'))->toBeGreaterThanOrEqual(4.5); // buttons
    expect($contrast('#6B3358', '#F5EAF0'))->toBeGreaterThanOrEqual(4.5); // tint pills

    // Brand is context-applied (front door + agency), never picker-offered.
    expect(ThemeRegistry::THEMES['brand']['status'])->toBe('available');
    expect(ThemeRegistry::selectable('brand', ThemeRegistry::SCOPE_APP))->toBeFalse();
    expect(ThemeRegistry::selectable('brand', ThemeRegistry::SCOPE_WIDGET))->toBeFalse();
    // Glacier stays a registry option, just no longer wired to the agency.
    expect(ThemeRegistry::THEMES['glacier']['status'])->toBe('available');
});

// ---------------------------------------------------------------------------
// Widgets: multiple independent booking widgets per salon
// ---------------------------------------------------------------------------

it('migrates the existing widget: the default widget inherits the salon branding live', function () {
    $salon = Salon::factory()->create(['branding' => ['accent' => '#2F5D7C', 'font' => 'modern']]);

    $widget = $salon->defaultWidget();

    expect($widget->name)->toBe('Booking widget');
    expect($widget->branding)->toBeNull(); // no overrides — inherits the salon's
    expect($widget->theme)->toBe('marble');
    expect(strlen($widget->public_id))->toBe(20);

    // Idempotent: the default widget is ONE row, not one per call.
    $salon->defaultWidget();
    expect($salon->widgets()->count())->toBe(1);

    // The pre-multi-widget embed URL (no id) renders this default widget.
    $this->get(route('salon.widget', $salon))
        ->assertOk()
        ->assertSee('--accent: #2F5D7C', false)
        ->assertSee('data-widget="'.$widget->public_id.'"', false)
        ->assertSee('data-theme="marble"', false);
});

it('supports multiple widgets, each with its own branding, theme and embed id', function () {
    $salon = Salon::factory()->create(['branding' => ['accent' => '#824C71']]);
    $owner = salonOwnerOf($salon);

    $component = Livewire::actingAs($owner)
        ->test('pages::salon.widgets', ['salon' => $salon])
        ->call('createWidget')
        ->set('name', 'Downtown site')
        ->set('accent', '#2F5D7C')
        ->set('surface', '#1F2A44')
        ->call('save')
        ->assertHasNoErrors();

    expect($salon->widgets()->count())->toBe(2);

    [$first, $second] = $salon->widgets()->orderBy('id')->get();
    expect($second->name)->toBe('Downtown site');
    expect($second->public_id)->not->toBe($first->public_id);

    // Each public page renders ITS widget's branding: the second its navy +
    // steel accent, the first the inherited salon plum.
    $this->get(route('salon.widget', ['salon' => $salon, 'widget' => $second->public_id]))
        ->assertOk()
        ->assertSee('--accent: #2F5D7C', false)
        ->assertSee('--wb-surface: #1F2A44', false)
        ->assertSee('--wb-ink: #FFFFFF', false);

    $this->get(route('salon.widget', ['salon' => $salon, 'widget' => $first->public_id]))
        ->assertOk()
        ->assertSee('--accent: #824C71', false);

    // The editor shows the per-widget embed snippet (id in the copy field).
    $component->assertSee($second->public_id);
});

it('keeps widgets tenant-isolated and the widgets page manager-gated', function () {
    $salonA = Salon::factory()->create();
    $salonB = Salon::factory()->create();
    $widgetB = $salonB->defaultWidget();

    // Salon B's widget id under salon A's slug: 404, never cross-rendered.
    $this->get(route('salon.widget', ['salon' => $salonA, 'widget' => $widgetB->public_id]))
        ->assertNotFound();

    // Staff cannot open the Widgets area.
    Livewire::actingAs(stylistOf($salonA))
        ->test('pages::salon.widgets', ['salon' => $salonA])
        ->assertForbidden();

    // A manager of A cannot edit B's widget through A's page — the lookup
    // is scoped to the salon's own widgets, so the id simply does not exist.
    $component = Livewire::actingAs(salonOwnerOf($salonA))
        ->test('pages::salon.widgets', ['salon' => $salonA]);
    expect(fn () => $component->call('select', $widgetB->id))
        ->toThrow(ModelNotFoundException::class);
    expect($widgetB->fresh()->salon_id)->toBe($salonB->id);
});

it('sets a widget theme from the picker, refusing locked ones, and keeps at least one widget', function () {
    Storage::fake('public');
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $component = Livewire::actingAs($owner)->test('pages::salon.widgets', ['salon' => $salon]);
    $widget = $salon->widgets()->firstOrFail();

    $component->call('saveTheme', 'velvet');
    expect($widget->fresh()->theme)->toBe('marble');

    $component->call('saveTheme', 'glacier'); // app-scope only
    expect($widget->fresh()->theme)->toBe('marble');

    $component->call('saveTheme', 'marble');
    expect($widget->fresh()->theme)->toBe('marble');

    // The last widget cannot be deleted.
    $component->call('deleteWidget', $widget->id);
    expect($salon->widgets()->count())->toBe(1);

    // With a second one, deletion works and cleans its logo file.
    $component->call('createWidget')
        ->set('logo', UploadedFile::fake()->image('logo.png'))
        ->call('save')->assertHasNoErrors();
    $second = $salon->widgets()->orderByDesc('id')->firstOrFail();
    $path = $second->branding['logo_path'];
    Storage::disk('public')->assertExists($path);

    $component->call('deleteWidget', $second->id);
    expect($salon->widgets()->count())->toBe(1);
    Storage::disk('public')->assertMissing($path);
});

// ---------------------------------------------------------------------------
// Widget TYPES: booking today; chat / lead form / reviews coming soon
// ---------------------------------------------------------------------------

it('registers widget types: Booking available, the rest locked coming-soon previews', function () {
    expect(WidgetTypeRegistry::TYPES['booking']['status'])->toBe('available');

    $comingSoon = collect(WidgetTypeRegistry::TYPES)->where('status', 'coming_soon');
    expect($comingSoon->count())->toBeGreaterThanOrEqual(3);
    expect($comingSoon->keys()->all())->toContain('chat');

    expect(WidgetTypeRegistry::selectable('booking'))->toBeTrue();
    expect(WidgetTypeRegistry::selectable('chat'))->toBeFalse();
    expect(WidgetTypeRegistry::selectable('nope'))->toBeFalse();
    expect(WidgetTypeRegistry::name('booking'))->toBe('Booking widget');
});

it('defaults every widget to the booking type — existing rows included', function () {
    $salon = Salon::factory()->create();

    // The default column value is the backfill: rows created without an
    // explicit type (as all pre-types rows were) read as booking.
    $id = DB::table('widgets')->insertGetId([
        'salon_id' => $salon->id,
        'name' => 'Legacy widget',
        'public_id' => Widget::newPublicId(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(Widget::query()->findOrFail($id)->type)->toBe('booking');
    expect($salon->defaultWidget()->type)->toBe('booking');
});

it('creates widgets through the type picker: booking proceeds, coming-soon types are refused', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $component = Livewire::actingAs($owner)->test('pages::salon.widgets', ['salon' => $salon]);

    // The picker modal offers the types: booking selectable, the rest locked.
    $component->set('showTypePicker', true)
        ->assertSee('What kind of widget?')
        ->assertSee('Chat widget')
        ->assertSee('Coming soon');

    // Coming-soon and unknown types never create anything.
    $component->call('createWidget', 'chat');
    $component->call('createWidget', 'bogus');
    expect($salon->widgets()->count())->toBe(1); // the default only

    // Booking proceeds into the normal config flow (modal closes, selected).
    $component->call('createWidget', 'booking');
    expect($salon->widgets()->count())->toBe(2);
    expect($salon->widgets()->orderByDesc('id')->first()->type)->toBe('booking');
    expect($component->get('showTypePicker'))->toBeFalse();

    // The list labels each widget with its type.
    $component->assertSee('Booking widget');
});

it('books through a specific widget exactly like before — the flow is widget-agnostic', function () {
    [$salon] = widgetSalonForThemes();

    $widget = $salon->defaultWidget();

    $this->get(route('salon.widget', ['salon' => $salon, 'widget' => $widget->public_id]))
        ->assertOk()
        ->assertSee('wb-shell', false)
        ->assertSee('bts-cal-grid', false)
        ->assertSee('Finalize booking', false);
});

/** A bookable salon (helper local to this file to avoid Pest name clashes). */
function widgetSalonForThemes(): array
{
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);

    return [$salon, $stylist, $service];
}
