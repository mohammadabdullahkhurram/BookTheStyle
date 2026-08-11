<?php

namespace App\Services\VoiceAi;

/**
 * Builds the four per-salon knowledge-base articles from the saved Voice AI
 * settings — pure string assembly, no external calls, wording tuned for
 * voice retrieval (do not "improve" phrasing casually; the templates are
 * the contract). Optional sentences are omitted ENTIRELY when their field
 * is blank; blank REQUIRED fields render [PLACEHOLDER] so a partial save
 * still yields usable drafts. Output is {title, body} pairs — the shape a
 * future push-to-GHL consumes as-is.
 */
class VoiceAiPromptGenerator
{
    public const PLACEHOLDER = '[PLACEHOLDER]';

    /**
     * @param  array<string, mixed>  $s  the saved/current settings
     * @return list<array{title: string, body: string}>
     */
    public function generate(array $s): array
    {
        return [
            $this->team($s),
            $this->policies($s),
            $this->location($s),
            $this->services($s),
        ];
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array{title: string, body: string}
     */
    private function team(array $s): array
    {
        $stylists = array_values(array_filter(
            (array) ($s['stylists'] ?? []),
            fn ($row): bool => trim((string) ($row['name'] ?? '')) !== '',
        ));

        $lines = [];

        if ($stylists === []) {
            $lines[] = 'Our team of stylists is happy to help with any service.';
        } else {
            $entries = array_map(function (array $row): string {
                $name = trim((string) $row['name']);
                $specialties = trim((string) ($row['specialties'] ?? ''));

                return $specialties !== '' ? "{$name} — {$specialties}" : $name;
            }, $stylists);
            $lines[] = 'Our stylists are: '.implode('; ', $entries).'.';
        }

        $need = trim((string) ($s['pair_need'] ?? ''));
        $who = trim((string) ($s['pair_stylist'] ?? ''));
        if ($need !== '' && $who !== '') {
            $lines[] = "If you're after {$need}, we'd usually suggest {$who}.";
        }

        $lines[] = 'Any of our stylists can take great care of you, and if you have no preference I can check times across the whole team and find the soonest opening.';

        $withDays = array_values(array_filter($stylists, fn ($row): bool => trim((string) ($row['days'] ?? '')) !== ''));
        if ($withDays !== []) {
            $phrases = array_map(
                fn (array $row): string => trim((string) $row['name']).' is usually in '.trim((string) $row['days']),
                $withDays,
            );
            $lines[] = SpokenHours::joinNatural($phrases).'. Exact openings always come from a live availability check.';
        }

        return ['title' => 'Our team — stylists and who to book', 'body' => implode("\n\n", $lines)];
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array{title: string, body: string}
     */
    private function policies(array $s): array
    {
        $notice = trim((string) ($s['cancel_notice'] ?? '')) ?: '24 hours';
        $fee = trim((string) ($s['cancel_fee'] ?? ''));
        $grace = trim((string) ($s['late_grace'] ?? '')) ?: '15 minutes';

        $cancellation = "Cancellations: we ask for at least {$notice} notice to cancel or reschedule.";
        if ($fee !== '') {
            $cancellation .= " Cancellations with less notice may be charged {$fee}.";
        }
        $cancellation .= ' If a caller needs to change a booking, they can do it right on the call.';

        $late = "Late arrivals: we hold appointments for up to {$grace}. Later than that, we may need to shorten the service or rebook so the next client isn't delayed.";

        $deposits = 'Deposits: '.(($s['deposits'] ?? 'none') === 'some' && trim((string) ($s['deposit_detail'] ?? '')) !== ''
            ? trim((string) $s['deposit_detail'])
            : "we don't require deposits.");

        $payments = array_values(array_filter(array_map(trim(...), (array) ($s['payments'] ?? []))));
        if ($payments === []) {
            $payments = ['card', 'cash', 'mobile payments like Apple Pay'];
        }
        $paymentsLine = 'Payments: in the salon we take '.SpokenHours::joinNatural($payments).'.';

        $walkins = ($s['walkins'] ?? 'yes') === 'appointment_only'
            ? "We're appointment-only."
            : "We take walk-ins when there's an opening, but booking ahead guarantees a spot.";
        $kids = ($s['kids'] ?? 'cuts') === 'wait_only'
            ? "Kids are welcome to wait with you, though we don't offer children's services."
            : "Kids are welcome — we do children's cuts.";

        return [
            'title' => 'Our policies — cancellations, late arrivals, deposits, and payments',
            'body' => implode("\n\n", [$cancellation, $late, $deposits, $paymentsLine, "Walk-ins and kids: {$walkins} {$kids}"]),
        ];
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array{title: string, body: string}
     */
    private function location(array $s): array
    {
        $address = trim((string) ($s['address_spoken'] ?? '')) ?: self::PLACEHOLDER;
        $hours = trim((string) ($s['hours_spoken'] ?? '')) ?: self::PLACEHOLDER;
        $parking = trim((string) ($s['parking'] ?? ''));
        $transit = trim((string) ($s['transit'] ?? ''));
        $holiday = trim((string) ($s['holiday_note'] ?? ''));

        $first = "We're at {$address}.";
        if ($address !== self::PLACEHOLDER && str_starts_with($address, "We're ")) {
            // The spoken draft already carries "We're at …" — don't double it.
            $first = rtrim($address, '.').'.';
        }
        if ($parking !== '') {
            $first .= ' '.$parking;
        }
        if ($transit !== '') {
            $first .= ' '.$transit;
        }

        $lines = [$first, "Our hours: {$hours}."];

        if ($holiday !== '') {
            $lines[] = $holiday;
        }

        return ['title' => 'Where we are, parking, and opening hours', 'body' => implode("\n\n", $lines)];
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array{title: string, body: string}
     */
    private function services(array $s): array
    {
        $dontOffer = trim((string) ($s['dont_offer'] ?? '')) ?: self::PLACEHOLDER;
        $referral = trim((string) ($s['referral'] ?? ''));

        $first = 'Our full service list and details are on our website, which you can search for answers about what each service involves.'
            ." The services we do NOT offer are: {$dontOffer} — if asked for one of these, say so kindly";
        $first .= $referral !== '' ? " and suggest {$referral}." : '.';

        $second = 'Prices vary by stylist and hair length, so never quote an exact price unless it appears in the website content.'
            .' If it\'s not there, say: "Prices vary a little by stylist and hair length — the salon will confirm the exact price when they confirm your booking." Then offer to check available times.';

        $third = 'Rough durations are fine to share if they appear on the website; otherwise say most appointments run "around an hour, longer for color" and the salon will confirm.';

        return ['title' => 'Services and pricing — how to answer', 'body' => implode("\n\n", [$first, $second, $third])];
    }
}
