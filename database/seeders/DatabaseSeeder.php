<?php

namespace Database\Seeders;

use App\Enums\AgencyRole;
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
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Dev seed data. All accounts use the password "password" unless noted.
 * Idempotent: safe to re-run (keyed by email / name + agency).
 *
 * Accounts
 * --------
 *   agency@bookthestyle.test     Agency Owner (BookTheStyle Agency)
 *   admin@bookthestyle.test      Agency Admin (BookTheStyle Agency)
 *   user@bookthestyle.test       Agency User  (assigned to Demo Salon only)
 *   owner@demo-salon.test        Salon Owner    (Demo Salon)
 *   frontdesk@demo-salon.test    Front Desk     (Demo Salon)
 *   stylist@demo-salon.test      Stylist        (Demo Salon)
 *   newhire@demo-salon.test      Stylist on a TEMPORARY password
 *                                (password: "temporary" — forced change on first login)
 *   owner@other-salon.test       Salon Owner    (Other Salon, different agency)
 *
 * The "Other Salon" exists so you can log in as a Demo Salon user and confirm
 * that opening Other Salon's URL returns 403 (tenant isolation).
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // --- Agency + agency owner ------------------------------------------
        $agency = Agency::firstOrCreate(['name' => 'BookTheStyle Agency']);

        $this->user('agency@bookthestyle.test', 'Avery Agency', [
            'agency_id' => $agency->id,
            'agency_role' => AgencyRole::Owner,
        ]);

        $this->user('admin@bookthestyle.test', 'Adira Admin', [
            'agency_id' => $agency->id,
            'agency_role' => AgencyRole::Admin,
        ]);

        // --- Demo salon + its staff -----------------------------------------
        $salon = Salon::firstOrCreate(
            ['agency_id' => $agency->id, 'name' => 'Demo Salon'],
            [
                'slug' => 'demo',
                'timezone' => 'America/New_York',
                'allow_walkins' => true,
                'allow_same_day' => true,
                'max_advance_days' => 60,
                'min_notice_minutes' => 120,
            ]
        );

        $owner = $this->user('owner@demo-salon.test', 'Olivia Owner');
        $this->membership($owner, $salon, SalonRole::Owner);

        $frontDesk = $this->user('frontdesk@demo-salon.test', 'Frankie Front-Desk');
        $this->membership($frontDesk, $salon, SalonRole::User, StaffType::FrontDesk);

        $stylist = $this->user('stylist@demo-salon.test', 'Simone Stylist');
        $this->membership($stylist, $salon, SalonRole::User, StaffType::Stylist);

        // New hire still on the admin-issued temporary password.
        $newHire = $this->user('newhire@demo-salon.test', 'Nadia New-Hire', [
            'password' => 'temporary',
            'must_change_password' => true,
        ]);
        $this->membership($newHire, $salon, SalonRole::User, StaffType::Stylist);

        // An agency_user scoped to the Demo Salon only (their access scope).
        $agencyUser = $this->user('user@bookthestyle.test', 'Uma Agency-User', [
            'agency_id' => $agency->id,
            'agency_role' => AgencyRole::User,
        ]);
        $agencyUser->assignedSalons()->syncWithoutDetaching([$salon->id]);

        // --- Demo salon catalog: services, assignments, availability --------
        $cut = Service::firstOrCreate(
            ['salon_id' => $salon->id, 'name' => 'Cut & Style'],
            ['duration_min' => 45, 'color' => '#1F6F6B', 'active' => true],
        );
        $color = Service::firstOrCreate(
            ['salon_id' => $salon->id, 'name' => 'Color'],
            ['duration_min' => 90, 'color' => '#B7791F', 'active' => true],
        );
        $cut->stylists()->syncWithoutDetaching([$stylist->id => ['salon_id' => $salon->id]]);
        $color->stylists()->syncWithoutDetaching([$stylist->id => ['salon_id' => $salon->id]]);

        StylistProfile::updateOrCreate(
            ['salon_id' => $salon->id, 'user_id' => $stylist->id],
            ['bio' => 'Senior stylist specialising in cuts and colour.'],
        );

        // Mon–Fri, 9–5 with a midday break.
        foreach ([0, 1, 2, 3, 4] as $weekday) {
            Availability::firstOrCreate(
                ['salon_id' => $salon->id, 'user_id' => $stylist->id, 'weekday' => $weekday, 'kind' => AvailabilityKind::Work, 'start_minute' => 9 * 60],
                ['end_minute' => 17 * 60],
            );
            Availability::firstOrCreate(
                ['salon_id' => $salon->id, 'user_id' => $stylist->id, 'weekday' => $weekday, 'kind' => AvailabilityKind::Break, 'start_minute' => 12 * 60],
                ['end_minute' => 13 * 60],
            );
        }

        // --- A few demo bookings for today (dashboard/appointments) ---------
        if ($salon->bookings()->count() === 0) {
            $client = Client::firstOrCreate(
                ['salon_id' => $salon->id, 'name' => 'Demo Client'],
                ['phone' => '+15551234567', 'email' => 'demo.client@example.com'],
            );
            $today = CarbonImmutable::now($salon->timezone);

            $scheduled = Booking::create([
                'salon_id' => $salon->id, 'client_id' => $client->id,
                'status' => BookingStatus::Booked, 'booked_by_type' => BookedByType::SalonOwner,
                'booked_by_user_id' => $owner->id, 'source' => BookingSource::InApp, 'is_walkin' => false,
            ]);
            $scheduled->items()->create([
                'salon_id' => $salon->id, 'service_id' => $cut->id, 'stylist_id' => $stylist->id,
                'starts_at' => $today->setTime(10, 0), 'ends_at' => $today->setTime(10, 45),
            ]);
            $scheduled->statusEvents()->create(['salon_id' => $salon->id, 'to_status' => BookingStatus::Booked, 'actor_user_id' => $owner->id]);

            $walkin = Booking::create([
                'salon_id' => $salon->id, 'client_id' => $client->id,
                'status' => BookingStatus::Arrived, 'booked_by_type' => BookedByType::FrontDesk,
                'booked_by_user_id' => $frontDesk->id, 'source' => BookingSource::InApp, 'is_walkin' => true,
            ]);
            $walkin->items()->create([
                'salon_id' => $salon->id, 'service_id' => $color->id, 'stylist_id' => $stylist->id,
                'starts_at' => $today->setTime(14, 0), 'ends_at' => $today->setTime(15, 30),
            ]);
            $walkin->statusEvents()->create(['salon_id' => $salon->id, 'to_status' => BookingStatus::Arrived, 'actor_user_id' => $frontDesk->id]);
        }

        // --- A second agency + salon (for tenant-isolation checks) ----------
        $otherAgency = Agency::firstOrCreate(['name' => 'Rival Agency']);
        $otherSalon = Salon::firstOrCreate(
            ['agency_id' => $otherAgency->id, 'name' => 'Other Salon'],
            ['slug' => 'other', 'timezone' => 'America/Los_Angeles']
        );

        $otherOwner = $this->user('owner@other-salon.test', 'Owen Other', [
            'agency_id' => $otherAgency->id,
        ]);
        $this->membership($otherOwner, $otherSalon, SalonRole::Owner);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function user(string $email, string $name, array $attributes = []): User
    {
        return User::updateOrCreate(['email' => $email], array_merge([
            'name' => $name,
            'password' => 'password',
            'email_verified_at' => now(),
            'must_change_password' => false,
        ], $attributes));
    }

    private function membership(User $user, Salon $salon, SalonRole $role, ?StaffType $staffType = null): void
    {
        SalonMembership::updateOrCreate(
            ['user_id' => $user->id, 'salon_id' => $salon->id],
            ['salon_role' => $role, 'staff_type' => $staffType, 'active' => true],
        );
    }
}
