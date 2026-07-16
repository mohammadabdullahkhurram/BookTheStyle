<?php

namespace Database\Seeders;

use App\Enums\AvailabilityKind;
use App\Enums\BookedByType;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Models\Agency;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\Service;
use App\Models\StylistProfile;
use App\Models\TimeOff;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A COMPLETE, realistic demo salon for UI/UX review — every screen has data:
 * staff with varied weekly hours (+ time off and a date-specific day), a
 * priced service menu with per-stylist duration overrides, a rich client
 * book, and three weeks of bookings around "now" (completed / no-shows /
 * cancelled / today's check-ins / upcoming, multi-service visits, every
 * source) so Today, Calendar, Appointments, Check-in, Clients, Services,
 * Staff, Availability, Reports and Widgets all render with real content.
 *
 * STRICTLY ADDITIVE and idempotent: it creates the demo salon under the
 * Bluejaypro agency once (keyed by the slug) and NEVER touches anything
 * else — re-running when the salon exists is a no-op. Never pair with
 * migrate:fresh (forbidden repo-wide; see CLAUDE.md) — run it on whatever
 * database you have:
 *
 *   php artisan db:seed --class=DemoSalonSeeder
 *
 * Login printed at the end: owner@demo.test / password (plus front desk +
 * one stylist account).
 */
class DemoSalonSeeder extends Seeder
{
    use WithoutModelEvents;

    private const PASSWORD = 'password';

    private const SLUG = 'demo';

    public function run(): void
    {
        // LOCAL/DEV ONLY: every demo account ships the literal password
        // "password" with no forced change. Running this against the live
        // database would create weak-credential logins — refuse outright.
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSalonSeeder is local/dev-only — it creates demo accounts with weak passwords and must never run in production.');
        }

        if (Salon::query()->where('slug', self::SLUG)->exists()) {
            $this->say('Demo salon "'.self::SLUG.'" already exists — nothing created (the seeder is additive and idempotent).');

            return;
        }

        $agency = Agency::firstOrCreate(['name' => 'Bluejaypro']);

        $salon = Salon::create([
            'agency_id' => $agency->id,
            'name' => 'Glamour Studio',
            'slug' => self::SLUG,
            'timezone' => 'America/Los_Angeles',
            'currency' => 'USD',
            'active' => true,
            'app_theme' => 'marble',
            'branding' => ['accent' => '#824C71'],
            'legal_business_name' => 'Glamour Studio LLC',
            'business_email' => 'hello@glamourstudio.test',
            'business_phone' => '+1 415 555 0100',
            'address_line1' => '210 Castro Street',
            'city' => 'San Francisco',
            'region' => 'CA',
            'postal_code' => '94114',
            'country' => 'US',
            'contact_name' => 'Olivia Owner',
            'contact_email' => 'owner@demo.test',
            'contact_phone' => '+1 415 555 0101',
        ]);
        // Not fillable (set only by the wizard normally): skip the setup nag.
        $salon->forceFill(['onboarded_at' => now()])->save();

        [$owner, $frontDesk, $stylists] = $this->staff($salon);
        $services = $this->services($salon, $stylists);
        $this->availability($salon, $stylists);
        $clients = $this->clients($salon, $stylists);
        $bookings = $this->bookings($salon, $owner, $stylists, $services, $clients);
        $widget = $salon->defaultWidget();

        $this->say('Seeded the demo salon "'.$salon->name.'" — everything below is ADDITIVE (nothing was reset).');
        $this->table(['What', 'Detail'], [
            ['Salon', $salon->name.' · '.self::SLUG.'.'.config('app.domain').' ('.$salon->timezone.')'],
            ['App URL', 'http://'.self::SLUG.'.'.config('app.domain')],
            ['Widget', 'http://'.self::SLUG.'.'.config('app.domain').'/widget/'.$widget->public_id],
            ['Stylists', (string) count($stylists)],
            ['Services', (string) count($services)],
            ['Clients', (string) count($clients)],
            ['Bookings', $bookings.' (past + today + upcoming; all statuses and sources)'],
        ]);
        $this->table(['Email', 'Password', 'Role'], [
            ['owner@demo.test', self::PASSWORD, 'Salon owner'],
            ['frontdesk@demo.test', self::PASSWORD, 'Front desk'],
            ['maya@demo.test', self::PASSWORD, 'Stylist'],
        ]);
    }

    /**
     * @return array{0: User, 1: User, 2: list<User>}
     */
    private function staff(Salon $salon): array
    {
        $make = function (string $email, string $name): User {
            return User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => self::PASSWORD,
                'email_verified_at' => now(),
                'must_change_password' => false,
            ]);
        };

        $owner = $make('owner@demo.test', 'Olivia Owner');
        SalonMembership::firstOrCreate(
            ['salon_id' => $salon->id, 'user_id' => $owner->id],
            ['salon_role' => SalonRole::Owner, 'staff_type' => null],
        );

        $frontDesk = $make('frontdesk@demo.test', 'Fern Frontdesk');
        SalonMembership::firstOrCreate(
            ['salon_id' => $salon->id, 'user_id' => $frontDesk->id],
            ['salon_role' => SalonRole::Manager, 'staff_type' => null],
        );

        $stylists = [];
        $bios = [
            ['maya@demo.test', 'Maya Marchetti', 'Precision cuts and lived-in colour. Ten years behind the chair.'],
            ['sofia@demo.test', 'Sofia Reyes', 'Balayage and blondes — soft, sun-kissed dimension.'],
            ['jonah@demo.test', 'Jonah Park', 'Barbering, fades, and classic tailored cuts.'],
            ['elise@demo.test', 'Elise Trân', 'Nails, extensions, and event styling.'],
        ];
        foreach ($bios as [$email, $name, $bio]) {
            $stylist = $make($email, $name);
            SalonMembership::firstOrCreate(
                ['salon_id' => $salon->id, 'user_id' => $stylist->id],
                ['salon_role' => SalonRole::Stylist, 'staff_type' => StaffType::Stylist],
            );
            StylistProfile::firstOrCreate(
                ['salon_id' => $salon->id, 'user_id' => $stylist->id],
                ['bio' => $bio],
            );
            $stylists[] = $stylist;
        }

        return [$owner, $frontDesk, $stylists];
    }

    /**
     * @param  list<User>  $stylists
     * @return list<Service>
     */
    private function services(Salon $salon, array $stylists): array
    {
        [$maya, $sofia, $jonah, $elise] = $stylists;

        $menu = [
            // name, minutes, price cents, qualified stylists
            ['Hair cut', 45, 5500, [$maya, $sofia, $jonah]],
            ['Full colour', 120, 14000, [$maya, $sofia]],
            ['Blowout', 30, 3500, [$maya, $sofia, $elise]],
            ['Nails', 60, 4500, [$elise]],
            ['Extensions', 150, 22000, [$elise, $sofia]],
        ];

        $services = [];
        foreach ($menu as [$name, $minutes, $cents, $qualified]) {
            $service = Service::create([
                'salon_id' => $salon->id,
                'name' => $name,
                'duration_min' => $minutes,
                'price_cents' => $cents,
                'active' => true,
            ]);
            foreach ($qualified as $stylist) {
                $service->stylists()->attach($stylist->id, ['salon_id' => $salon->id]);
            }
            $services[] = $service;
        }

        // Per-stylist duration overrides so multi-duration UI has something
        // to show: Maya cuts faster, Sofia's colour runs longer.
        $services[0]->stylists()->updateExistingPivot($maya->id, ['duration_override' => 35]);
        $services[1]->stylists()->updateExistingPivot($sofia->id, ['duration_override' => 135]);

        return $services;
    }

    /**
     * Weekly hours per stylist (weekday 0 = Monday), varied so the
     * availability screen reads real: different days off, one lunch break,
     * plus a vacation and a date-specific short day.
     *
     * @param  list<User>  $stylists
     */
    private function availability(Salon $salon, array $stylists): void
    {
        [$maya, $sofia, $jonah, $elise] = $stylists;

        $weeks = [
            // Maya: Mon–Fri 9–5 with a lunch break.
            [$maya, [0, 1, 2, 3, 4], 9 * 60, 17 * 60, true],
            // Sofia: Tue–Sat 10–6.
            [$sofia, [1, 2, 3, 4, 5], 10 * 60, 18 * 60, false],
            // Jonah: Wed–Sun 9–4.
            [$jonah, [2, 3, 4, 5, 6], 9 * 60, 16 * 60, false],
            // Elise: Mon–Thu 11–7.
            [$elise, [0, 1, 2, 3], 11 * 60, 19 * 60, false],
        ];

        foreach ($weeks as [$stylist, $days, $start, $end, $lunch]) {
            foreach ($days as $weekday) {
                Availability::create([
                    'salon_id' => $salon->id,
                    'user_id' => $stylist->id,
                    'weekday' => $weekday,
                    'kind' => AvailabilityKind::Work,
                    'start_minute' => $start,
                    'end_minute' => $end,
                ]);
                if ($lunch) {
                    Availability::create([
                        'salon_id' => $salon->id,
                        'user_id' => $stylist->id,
                        'weekday' => $weekday,
                        'kind' => AvailabilityKind::Break,
                        'start_minute' => 12 * 60,
                        'end_minute' => 13 * 60,
                    ]);
                }
            }
        }

        $tz = $salon->timezone;
        $nextMonday = CarbonImmutable::now($tz)->addWeek()->startOfWeek();

        // Jonah is away for two days next week (time off)…
        TimeOff::create([
            'salon_id' => $salon->id,
            'user_id' => $jonah->id,
            'kind' => TimeOff::KIND_OFF,
            'note' => 'Long weekend',
            'starts_at' => $nextMonday->addDays(2)->setTime(0, 0),
            'ends_at' => $nextMonday->addDays(4)->setTime(0, 0),
        ]);
        // …and Maya works a date-specific short day next Saturday.
        TimeOff::create([
            'salon_id' => $salon->id,
            'user_id' => $maya->id,
            'kind' => TimeOff::KIND_HOURS,
            'note' => 'Saturday pop-in',
            'starts_at' => $nextMonday->addDays(5)->setTime(10, 0),
            'ends_at' => $nextMonday->addDays(5)->setTime(14, 0),
        ]);
    }

    /**
     * @param  list<User>  $stylists
     * @return list<Client>
     */
    private function clients(Salon $salon, array $stylists): array
    {
        [$maya, $sofia, $jonah, $elise] = $stylists;

        $book = [
            // name, phone tail, preferred stylist, allergies, formula notes, birthday
            ['Brenda Miles', '0201', $maya, null, '7N + 20vol, 35 min', '1988-03-14'],
            ['Craig Morton', '0202', $jonah, null, null, '1979-11-02'],
            ['Alena Gomez', '0203', $sofia, 'PPD sensitivity — patch test', 'Balayage T18 toner', '1994-06-21'],
            ['Desirae Shaw', '0204', $maya, null, null, '1991-01-08'],
            ['Megan Wu', '0205', $elise, 'Acrylic allergy — gel only', null, '1997-09-30'],
            ['James Holt', '0206', $jonah, null, 'Skin fade, #2 top', '1985-05-17'],
            ['Amy Jenner', '0207', $sofia, null, '8.1 roots, gloss ends', '1990-12-25'],
            ['Priya Natarajan', '0208', $maya, 'Fragrance-free products', null, '1983-04-04'],
            ['Tom Okafor', '0209', $jonah, null, null, '1992-08-12'],
            ['Lucía Fernández', '0210', $elise, null, 'Almond shape, sheer pink', '1996-02-18'],
            ['Hannah Berg', '0211', $sofia, null, null, '1987-07-07'],
            ['Marcus Lee', '0212', $jonah, 'Latex gloves only', null, '1993-10-23'],
            ['Ingrid Solberg', '0213', $maya, null, 'Level 6 ash, no warmth', '1981-06-30'],
            ['Yara Haddad', '0214', $elise, null, null, '1999-03-03'],
            ['Nina Petrova', '0215', $sofia, null, 'Face-framing money piece', '1989-11-11'],
            ['Owen Gallagher', '0216', $jonah, null, null, '1995-01-26'],
            ['Grace Kim', '0217', $maya, 'Nut oil allergy', null, '1984-09-09'],
            ['Fatima Zahra', '0218', $elise, null, 'Builder gel, square', '1998-05-05'],
        ];

        $clients = [];
        foreach ($book as $index => [$name, $tail, $preferred, $allergies, $formula, $birthday]) {
            $clients[] = Client::create([
                'salon_id' => $salon->id,
                'name' => $name,
                'phone' => '+1415555'.$tail,
                // A few clients have no email — real books are patchy.
                'email' => $index % 5 === 4 ? null : Str::slug($name, '.').'@example.test',
                'allergies' => $allergies,
                'formula_notes' => $formula,
                'preferred_stylist_id' => $preferred->id,
                'birthday' => $birthday,
            ]);
        }

        return $clients;
    }

    /**
     * Three weeks of bookings around "now": two past weeks of outcomes
     * (completed / no-shows / cancellations, every source), today's flow
     * (arrived / in service / booked), and two upcoming weeks — plus
     * multi-service visits linked by a visit group.
     *
     * @param  list<User>  $stylists
     * @param  list<Service>  $services
     * @param  list<Client>  $clients
     */
    private function bookings(Salon $salon, User $owner, array $stylists, array $services, array $clients): int
    {
        [$maya, $sofia, $jonah, $elise] = $stylists;
        [$cut, $colour, $blowout, $nails, $extensions] = $services;
        $tz = $salon->timezone;
        $today = CarbonImmutable::now($tz)->startOfDay();

        $count = 0;
        $make = function (Client $client, User $stylist, array $legs, CarbonImmutable $start, BookingStatus $status, BookingSource $source) use ($salon, $owner, &$count): void {
            $visitGroup = count($legs) > 1 ? (string) Str::uuid() : null;
            $cursor = $start;

            foreach ($legs as $service) {
                $booking = Booking::create([
                    'salon_id' => $salon->id,
                    'client_id' => $client->id,
                    'status' => $status,
                    'booked_by_type' => $source === BookingSource::InApp ? BookedByType::SalonOwner : BookedByType::tryFrom($source->value) ?? BookedByType::WebWidget,
                    'booked_by_user_id' => $source === BookingSource::InApp ? $owner->id : null,
                    'source' => $source,
                    'is_walkin' => false,
                    'visit_group_id' => $visitGroup,
                ]);
                $booking->items()->create([
                    'salon_id' => $salon->id,
                    'service_id' => $service->id,
                    'stylist_id' => $stylist->id,
                    'starts_at' => $cursor,
                    'ends_at' => $cursor->addMinutes($service->duration_min),
                    'buffer_min' => 0,
                ]);
                $booking->statusEvents()->create([
                    'salon_id' => $salon->id,
                    'from_status' => null,
                    'to_status' => BookingStatus::Booked,
                ]);
                if ($status !== BookingStatus::Booked) {
                    $booking->statusEvents()->create([
                        'salon_id' => $salon->id,
                        'from_status' => BookingStatus::Booked,
                        'to_status' => $status,
                    ]);
                }
                $cursor = $cursor->addMinutes($service->duration_min);
                $count++;
            }
        };

        $pick = fn (int $i): Client => $clients[$i % count($clients)];

        // Two past weeks of outcomes — Reports gets a real source mix.
        foreach ([14, 13, 12, 9, 8, 7, 6] as $slot => $daysAgo) {
            $day = $today->subDays($daysAgo);
            $make($pick($slot), $maya, [$cut], $day->setTime(9, 0), BookingStatus::Completed, BookingSource::InApp);
            $make($pick($slot + 3), $sofia, [$colour], $day->setTime(11, 0), BookingStatus::Completed, BookingSource::VoiceAi);
            $make($pick($slot + 6), $elise, [$nails], $day->setTime(13, 0), $slot % 3 === 0 ? BookingStatus::NoShow : BookingStatus::Completed, BookingSource::WebWidget);
            if ($slot % 2 === 0) {
                $make($pick($slot + 9), $jonah, [$cut], $day->setTime(10, 0), BookingStatus::Cancelled, BookingSource::ChatWidget);
            }
        }
        // A completed multi-service visit last week (cut then blowout, Maya).
        $make($pick(2), $maya, [$cut, $blowout], $today->subDays(5)->setTime(14, 0), BookingStatus::Completed, BookingSource::VoiceAi);

        // Today: the check-in flow in every state.
        $make($pick(0), $maya, [$cut], $today->setTime(9, 0), BookingStatus::Completed, BookingSource::InApp);
        $make($pick(4), $elise, [$nails], $today->setTime(12, 0), BookingStatus::InService, BookingSource::WebWidget);
        $make($pick(6), $sofia, [$blowout], $today->setTime(13, 0), BookingStatus::Arrived, BookingSource::InApp);
        $make($pick(8), $jonah, [$cut], $today->setTime(15, 0), BookingStatus::Booked, BookingSource::VoiceAi);

        // Upcoming two weeks — Calendar and Appointments look forward.
        foreach ([1, 2, 3, 5, 7, 9, 12] as $slot => $daysAhead) {
            $day = $today->addDays($daysAhead);
            $make($pick($slot + 1), $maya, [$cut], $day->setTime(10, 0), BookingStatus::Booked, BookingSource::InApp);
            $make($pick($slot + 5), $sofia, [$colour], $day->setTime(11, 0), BookingStatus::Booked, BookingSource::WebWidget);
            if ($slot % 2 === 1) {
                $make($pick($slot + 9), $elise, [$extensions], $day->setTime(12, 0), BookingStatus::Booked, BookingSource::VoiceAi);
            }
        }
        // An upcoming multi-service visit (colour then blowout, Sofia).
        $make($pick(10), $sofia, [$colour, $blowout], $today->addDays(4)->setTime(10, 0), BookingStatus::Booked, BookingSource::InApp);

        return $count;
    }

    private function say(string $message): void
    {
        // $command is typed non-nullable but stays uninitialized when the
        // seeder runs outside artisan (tests, programmatic runs).
        // @phpstan-ignore isset.property
        if (isset($this->command)) {
            $this->command->info($message);
        }
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function table(array $headers, array $rows): void
    {
        // @phpstan-ignore isset.property
        if (isset($this->command)) {
            $this->command->table($headers, $rows);
        }
    }
}
