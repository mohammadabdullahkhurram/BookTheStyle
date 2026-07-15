<?php

use App\Models\Salon;
use App\Support\HelpDoc;
use App\Support\HelpDocs;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

/*
| The reusable how-to documentation system: the registry, the graceful
| missing-video path, and the help trigger/modal wired into the calendar panel.
*/

it('resolves a known help doc with its fields', function () {
    $doc = HelpDocs::find('calendar-sync');

    expect($doc)->toBeInstanceOf(HelpDoc::class);
    expect($doc->key)->toBe('calendar-sync');
    expect($doc->title)->toBe('Add your bookings to your phone calendar');
    expect($doc->caption)->not->toBeNull();
    expect(HelpDocs::all())->toHaveKey('calendar-sync');
});

it('returns null for an unknown help key', function () {
    expect(HelpDocs::find('does-not-exist'))->toBeNull();
});

it('reports no video and empty sources when the file is absent (graceful)', function () {
    // No video is committed (git-ignored media), so this is the default state.
    $doc = HelpDocs::find('calendar-sync');

    expect($doc->hasVideo())->toBeFalse();
    expect($doc->videoSources())->toBe([]);
    expect($doc->posterUrl())->toBeNull();
});

it('detects the video and builds same-origin sources once the file exists', function () {
    $path = public_path('how-to-documentation/calendar-sync/video.mp4');

    file_put_contents($path, 'fake-mp4');

    try {
        $doc = HelpDocs::find('calendar-sync');

        expect($doc->hasVideo())->toBeTrue();
        $sources = $doc->videoSources();
        expect($sources)->not->toBeEmpty();
        expect($sources[0]['type'])->toBe('video/mp4');
        // Root-relative → same-origin on any host (no CSP change).
        expect($sources[0]['url'])->toBe('/how-to-documentation/calendar-sync/video.mp4');
    } finally {
        @unlink($path);
    }
});

it('renders the highlighted trigger and a two-pane modal for a known doc', function () {
    $html = Blade::render('<x-ui.help-trigger doc="calendar-sync" :label="\'Watch this\'"><p>content</p></x-ui.help-trigger>');

    expect($html)->toContain('helpOpen');               // Alpine open state
    expect($html)->toContain('Watch this');             // the label
    expect($html)->toContain('bg-accent-tint');         // highlighted pill (tokens)
    expect($html)->toContain('role="dialog"');          // the modal
    expect($html)->toContain('aria-modal="true"');
    expect($html)->toContain('x-trap');                 // focus trap
    expect($html)->toContain('content');                // the actionable slot
});

it('renders nothing for an unknown doc key (safe)', function () {
    $html = Blade::render('<x-ui.help-trigger doc="nope">x</x-ui.help-trigger>');

    expect(trim($html))->toBe('');
});

it('renders the help trigger and both modal regions on the calendar panel', function () {
    $salon = Salon::factory()->create();
    $this->actingAs(stylistOf($salon));

    $html = Livewire::test('pages::settings.calendar-feed')->html();

    // Trigger present and highlighted.
    expect($html)->toContain('Watch: how to connect your calendar');

    // Video region — graceful placeholder, since no footage is present.
    expect($html)->toContain('Video coming soon');

    // Content region — the per-provider steps sit beside the video.
    expect($html)->toContain('Apple / iPhone');
    expect($html)->toContain('Google');
    expect($html)->toContain('Outlook');

    // It is an accessible modal.
    expect($html)->toContain('role="dialog"');
    expect($html)->toContain('aria-modal="true"');
});

it('surfaces the subscribe link inside the help modal once generated', function () {
    $salon = Salon::factory()->create();
    $this->actingAs(stylistOf($salon));

    $html = Livewire::test('pages::settings.calendar-feed')
        ->call('generate')
        ->html();

    expect($html)->toContain('Your calendar link');
    expect($html)->toContain('Watch: how to connect your calendar');
    expect($html)->toContain('Apple / iPhone');
});
