<?php

use App\Services\VoiceAi\SpokenHours;
use App\Services\VoiceAi\VoiceAiPromptGenerator;

/*
| Pure unit coverage: the hours-to-spoken conversion (grouping, closed
| days, casual time wording) and the article generator (defaults-only,
| fully-filled, and each optional omitted). No DB, no HTTP.
*/

// ---------------------------------------------------------------------------
// SpokenHours
// ---------------------------------------------------------------------------

it('phrases the required sample exactly: Tue–Fri 9–19, Sat 9–17, closed Sun/Mon', function () {
    $week = [
        1 => ['start' => 9 * 60, 'end' => 19 * 60],
        2 => ['start' => 9 * 60, 'end' => 19 * 60],
        3 => ['start' => 9 * 60, 'end' => 19 * 60],
        4 => ['start' => 9 * 60, 'end' => 19 * 60],
        5 => ['start' => 9 * 60, 'end' => 17 * 60],
    ];

    expect(SpokenHours::phrase($week))
        ->toBe('Tuesday to Friday nine to seven, Saturdays nine to five, closed Sunday and Monday');
});

it('speaks times casually: on-the-hour drops minutes, halves read naturally, am/pm only when ambiguous', function () {
    expect(SpokenHours::time(9 * 60))->toBe('nine');
    expect(SpokenHours::time(9 * 60 + 30))->toBe('nine thirty');
    expect(SpokenHours::time(19 * 60))->toBe('seven');
    expect(SpokenHours::time(11 * 60 + 30, withSuffix: true))->toBe('eleven thirty am');
    expect(SpokenHours::time(12 * 60, withSuffix: true))->toBe('twelve pm');
    expect(SpokenHours::time(9 * 60 + 45))->toBe('nine forty-five');
    expect(SpokenHours::time(9 * 60 + 5))->toBe('nine oh five');

    // Crossing noon disambiguates — drop am/pm; same-half ranges keep it.
    expect(SpokenHours::range(9 * 60, 19 * 60))->toBe('nine to seven');
    expect(SpokenHours::range(11 * 60 + 30, 19 * 60))->toBe('eleven thirty to seven');
    expect(SpokenHours::range(9 * 60, 11 * 60))->toBe('nine am to eleven am');
});

it('groups only consecutive identical days and lists a lone open day pluralized', function () {
    // Mon+Wed same hours but Tue differs: no illegal grouping.
    $week = [
        0 => ['start' => 9 * 60, 'end' => 17 * 60],
        1 => ['start' => 10 * 60, 'end' => 18 * 60],
        2 => ['start' => 9 * 60, 'end' => 17 * 60],
    ];

    expect(SpokenHours::phrase($week))
        ->toBe('Mondays nine to five, Tuesdays ten to six, Wednesdays nine to five, closed Sunday, Thursday, Friday and Saturday');
});

it('phrases an every-day-open week with no closed clause', function () {
    $week = [];
    foreach (range(0, 6) as $d) {
        $week[$d] = ['start' => 9 * 60, 'end' => 17 * 60];
    }

    expect(SpokenHours::phrase($week))->toBe('Monday to Sunday nine to five');
});

// ---------------------------------------------------------------------------
// VoiceAiPromptGenerator
// ---------------------------------------------------------------------------

function generatorDefaults(array $overrides = []): array
{
    return array_merge([
        'stylists' => [],
        'pair_need' => '', 'pair_stylist' => '',
        'cancel_notice' => '24 hours', 'cancel_fee' => '50% of the service price', 'late_grace' => '15 minutes',
        'deposits' => 'none', 'deposit_detail' => '',
        'payments' => ['card', 'cash', 'mobile payments like Apple Pay'],
        'walkins' => 'yes', 'kids' => 'cuts',
        'address_spoken' => '', 'parking' => '', 'transit' => '', 'hours_spoken' => '', 'holiday_note' => '',
        'dont_offer' => '', 'referral' => '',
    ], $overrides);
}

it('renders four articles from untouched defaults — placeholders ONLY for address, hours, and don\'t-offer', function () {
    $articles = (new VoiceAiPromptGenerator)->generate(generatorDefaults());

    expect($articles)->toHaveCount(4);
    expect(array_column($articles, 'title'))->toBe([
        'Our team — stylists and who to book',
        'Our policies — cancellations, late arrivals, deposits, and payments',
        'Where we are, parking, and opening hours',
        'Services and pricing — how to answer',
    ]);

    [$team, $policies, $location, $services] = $articles;

    // No stylists → the friendly fallback line; no pairing, no days sentence.
    expect($team['body'])->toContain('Our team of stylists is happy to help with any service.')
        ->toContain('soonest opening')
        ->not->toContain('usually suggest')
        ->not->toContain('usually in');

    // Defaults flow into the policy sentences; natural payments join.
    expect($policies['body'])->toContain('at least 24 hours notice')
        ->toContain('may be charged 50% of the service price')
        ->toContain('up to 15 minutes')
        ->toContain("Deposits: we don't require deposits.")
        ->toContain('we take card, cash and mobile payments like Apple Pay.')
        ->toContain("walk-ins when there's an opening")
        ->toContain("we do children's cuts");

    // The only placeholders across all four articles: address, hours, dont_offer.
    expect($location['body'])->toContain("We're at [PLACEHOLDER].")
        ->toContain('Our hours: [PLACEHOLDER].');
    expect($services['body'])->toContain('The services we do NOT offer are: [PLACEHOLDER]');
    expect(substr_count(implode("\n", array_column($articles, 'body')), '[PLACEHOLDER]'))->toBe(3);
});

it('renders every template sentence when fully filled', function () {
    $articles = (new VoiceAiPromptGenerator)->generate(generatorDefaults([
        'stylists' => [
            ['name' => 'Maya', 'specialties' => 'balayage', 'days' => 'Tuesday to Friday'],
            ['name' => 'Renee', 'specialties' => '', 'days' => ''],
        ],
        'pair_need' => 'vivid color', 'pair_stylist' => 'Maya',
        'deposits' => 'some', 'deposit_detail' => 'color services over $150 take a $30 deposit.',
        'payments' => ['card', 'cash', 'gift cards'],
        'walkins' => 'appointment_only', 'kids' => 'wait_only',
        'address_spoken' => "We're at 12 Main Street in Springfield",
        'parking' => 'Free parking behind the building.',
        'transit' => 'Two blocks from Central Station.',
        'hours_spoken' => 'Tuesday to Friday nine to seven, Saturdays nine to five, closed Sunday and Monday',
        'holiday_note' => "We're closed on public holidays.",
        'dont_offer' => 'nails, lash extensions', 'referral' => 'Polished Nail Bar two doors down',
    ]));

    [$team, $policies, $location, $services] = $articles;

    expect($team['body'])->toContain('Our stylists are: Maya — balayage; Renee.')
        ->toContain("If you're after vivid color, we'd usually suggest Maya.")
        ->toContain('Maya is usually in Tuesday to Friday. Exact openings always come from a live availability check.');

    expect($policies['body'])->toContain('Deposits: color services over $150 take a $30 deposit.')
        ->toContain('we take card, cash and gift cards.')
        ->toContain("We're appointment-only.")
        ->toContain("don't offer children's services");

    expect($location['body'])->toContain("We're at 12 Main Street in Springfield. Free parking behind the building. Two blocks from Central Station.")
        ->toContain('Our hours: Tuesday to Friday nine to seven, Saturdays nine to five, closed Sunday and Monday.')
        ->toContain("We're closed on public holidays.");

    expect($services['body'])->toContain('The services we do NOT offer are: nails, lash extensions — if asked for one of these, say so kindly and suggest Polished Nail Bar two doors down.');
    expect(implode('', array_column($articles, 'body')))->not->toContain('[PLACEHOLDER]');
});

it('omits each optional sentence entirely when its field is blank — no gaps', function () {
    $gen = new VoiceAiPromptGenerator;

    // No fee → no fee sentence, and the surrounding sentence stays whole.
    $noFee = $gen->generate(generatorDefaults(['cancel_fee' => '']))[1]['body'];
    expect($noFee)->not->toContain('may be charged')
        ->toContain('notice to cancel or reschedule. If a caller needs to change a booking');

    // Pairing needs BOTH halves.
    $halfPair = $gen->generate(generatorDefaults(['pair_need' => 'vivid color']))[0]['body'];
    expect($halfPair)->not->toContain('usually suggest');

    // No parking/transit/holiday → the location article carries none of them.
    $bare = $gen->generate(generatorDefaults(['address_spoken' => "We're at 5 Elm Way in Dover", 'hours_spoken' => 'Mondays nine to five']))[2]['body'];
    expect($bare)->toBe("We're at 5 Elm Way in Dover.\n\nOur hours: Mondays nine to five.");

    // No referral → the kindly sentence closes with a period.
    $noReferral = $gen->generate(generatorDefaults(['dont_offer' => 'nails']))[3]['body'];
    expect($noReferral)->toContain('say so kindly.')->not->toContain('and suggest');

    // Deposits "some" without detail falls back to the no-deposits line.
    $someNoDetail = $gen->generate(generatorDefaults(['deposits' => 'some']))[1]['body'];
    expect($someNoDetail)->toContain("we don't require deposits.");
});
