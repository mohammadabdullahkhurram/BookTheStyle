<?php

namespace App\Services\Demo;

use App\Enums\AvailabilityKind;
use App\Enums\BookedByType;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\SalonRole;
use App\Enums\SalonType;
use App\Enums\StaffType;
use App\Enums\StylistArrangement;
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
use Illuminate\Support\Str;

/**
 * Populates a salon with the complete, lived-in demo dataset (extracted from
 * DemoSalonSeeder so the public demo provisioner shares ONE data engine):
 * staff with varied hours + time off, a priced menu with per-stylist
 * overrides, a rich client book (~36 clients, allergies, formulas), and
 * three weeks of bookings around "now" — every status and source, today
 * mid-flow — all relative dates, never stale.
 *
 * Parameterized by email domain + password so the local seeder keeps its
 * memorable logins while production demo salons get per-salon unique
 * addresses on a reserved non-routable domain (nothing can ever be mailed)
 * and random passwords (the visitor is session-authenticated, never told
 * credentials). On MIX salons two stylists become booth renters so the
 * arrangement surface shows.
 */
class DemoSalonBuilder
{
    public function __construct(
        private string $emailDomain = 'demo.test',
        private string $password = 'password',
    ) {}

    private function email(string $handle): string
    {
        return $handle.'@'.$this->emailDomain;
    }

    /**
     * @return array{owner: User, stylists: list<User>, services: int, clients: int, bookings: int}
     */
    public function populate(Salon $salon): array
    {
        [$owner, $frontDesk, $stylists] = $this->staff($salon);
        $services = $this->services($salon, $stylists);
        $this->availability($salon, $stylists);
        $clients = $this->clients($salon, $stylists);
        $bookings = $this->bookings($salon, $owner, $stylists, $services, $clients);

        // Mix salons show both arrangements: Jonah and Elise rent booths.
        if ($salon->salon_type === SalonType::Mix) {
            $salon->memberships()
                ->whereIn('user_id', [$stylists[2]->id, $stylists[3]->id])
                ->update(['arrangement' => StylistArrangement::BoothRental->value]);
        }

        return [
            'owner' => $owner,
            'stylists' => $stylists,
            'services' => count($services),
            'clients' => count($clients),
            'bookings' => $bookings,
        ];
    }

    /**
     * @return array{0: User, 1: User, 2: list<User>}
     */
    private function staff(Salon $salon): array
    {
        $make = function (string $email, string $name): User {
            return User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => $this->password,
                'email_verified_at' => now(),
                'must_change_password' => false,
            ]);
        };

        $owner = $make($this->email('owner'), 'Olivia Owner');
        SalonMembership::firstOrCreate(
            ['salon_id' => $salon->id, 'user_id' => $owner->id],
            ['salon_role' => SalonRole::Owner, 'staff_type' => null],
        );

        $frontDesk = $make($this->email('frontdesk'), 'Fern Frontdesk');
        SalonMembership::firstOrCreate(
            ['salon_id' => $salon->id, 'user_id' => $frontDesk->id],
            ['salon_role' => SalonRole::Manager, 'staff_type' => null],
        );

        $stylists = [];
        $bios = [
            [$this->email('maya'), 'Maya Marchetti', 'Precision cuts and lived-in colour. Ten years behind the chair.'],
            [$this->email('sofia'), 'Sofia Reyes', 'Balayage and blondes — soft, sun-kissed dimension.'],
            [$this->email('jonah'), 'Jonah Park', 'Barbering, fades, and classic tailored cuts.'],
            [$this->email('elise'), 'Elise Trân', 'Nails, extensions, and event styling.'],
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

        // Procedural extras: real books are long. Names vary, a few carry
        // notes, every stylist gets regulars — Clients feels like a business.
        $first = ['Rosa', 'Ben', 'Chloe', 'Dev', 'Emma', 'Felix', 'Gina', 'Hugo', 'Iris', 'Jack', 'Kara', 'Liam', 'Mona', 'Noah', 'Opal', 'Pete', 'Quinn', 'Ruth'];
        $last = ['Alvarez', 'Brooks', 'Chan', 'Dawson', 'Egan', 'Flores', 'Grant', 'Hale', 'Ibrahim', 'Jones', 'Klein', 'Lund', 'Mori', 'Nash', 'Ortiz', 'Pratt', 'Qureshi', 'Rossi'];
        foreach ($first as $i => $firstName) {
            $clients[] = Client::create([
                'salon_id' => $salon->id,
                'name' => $firstName.' '.$last[$i],
                'phone' => '+1415555'.str_pad((string) (300 + $i), 4, '0', STR_PAD_LEFT),
                'email' => $i % 4 === 3 ? null : Str::slug($firstName.' '.$last[$i], '.').'@example.test',
                'allergies' => $i === 5 ? 'Sulfate sensitivity' : null,
                'formula_notes' => $i % 6 === 2 ? 'Standing 6-week rebook' : null,
                'preferred_stylist_id' => $stylists[$i % 4]->id,
                'birthday' => sprintf('19%02d-%02d-%02d', 75 + ($i % 24), 1 + ($i % 12), 1 + ($i % 27)),
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
}
