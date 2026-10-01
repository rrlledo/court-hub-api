<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchHoliday;
use App\Models\CheckIn;
use App\Models\CoachAvailability;
use App\Models\CoachingSession;
use App\Models\CoachProfile;
use App\Models\CoachStudent;
use App\Models\Court;
use App\Models\CourtMaintenance;
use App\Models\CourtType;
use App\Models\Facility;
use App\Models\InventoryItem;
use App\Models\Membership;
use App\Models\MembershipCard;
use App\Models\MembershipCheckIn;
use App\Models\MembershipPlan;
use App\Models\MembershipSessionUsage;
use App\Models\NotificationPreference;
use App\Models\OperatingHour;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\PlatformSubscriptionInvoice;
use App\Models\PricingRule;
use App\Models\PushDevice;
use App\Models\RecurringReservation;
use App\Models\Refund;
use App\Models\Rental;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentRegistration;
use App\Models\TournamentTeam;
use App\Models\TournamentTeamMember;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Waitlist;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class CourtHubDemoSeeder extends Seeder
{
    public const PASSWORD = 'DemoPass123!';

    public function run(): void
    {
        foreach (['super-admin', 'court-owner', 'facility-manager', 'front-desk', 'coach', 'event-organizer', 'player'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $tenant = Tenant::updateOrCreate(['slug' => 'court-hub-demo'], [
            'name' => 'Court Hub Demo Sports', 'timezone' => 'Asia/Manila', 'country_code' => 'PH', 'is_active' => true,
            'subscription_plan' => 'growth', 'subscription_status' => 'active', 'subscription_amount' => 2499,
            'subscription_renews_at' => now()->addMonth()->startOfDay(),
        ]);
        $sandboxTenant = Tenant::updateOrCreate(['slug' => 'court-hub-sandbox'], [
            'name' => 'Court Hub Sandbox Club', 'timezone' => 'Asia/Manila', 'country_code' => 'PH', 'is_active' => true,
            'subscription_plan' => 'starter', 'subscription_status' => 'trial', 'subscription_amount' => 0,
            'subscription_renews_at' => now()->addDays(14)->startOfDay(),
        ]);
        $sandboxOrganization = Organization::updateOrCreate(['tenant_id' => $sandboxTenant->id, 'name' => 'Court Hub Sandbox Organization']);
        Facility::updateOrCreate(['tenant_id' => $sandboxTenant->id, 'name' => 'Sandbox Facility'], [
            'organization_id' => $sandboxOrganization->id, 'timezone' => 'Asia/Manila',
            'address' => '200 Sandbox Lane, Quezon City', 'registration_open' => false,
        ]);
        PlatformSubscriptionInvoice::updateOrCreate(['reference' => 'SIM-DEMO-GROWTH-PAID'], ['tenant_id' => $tenant->id, 'status' => 'paid', 'amount' => 2499, 'tax_amount' => 0, 'currency' => 'PHP', 'due_on' => now()->subDays(5)->toDateString(), 'paid_at' => now()->subDays(4), 'metadata' => ['mock' => true, 'notes' => 'Demo platform subscription settlement']]);
        PlatformSubscriptionInvoice::updateOrCreate(['reference' => 'SIM-DEMO-SANDBOX-OVERDUE'], ['tenant_id' => $sandboxTenant->id, 'status' => 'overdue', 'amount' => 999, 'tax_amount' => 0, 'currency' => 'PHP', 'due_on' => now()->subDay()->toDateString(), 'paid_at' => null, 'metadata' => ['mock' => true, 'notes' => 'Demo dunning state']]);
        $organization = Organization::updateOrCreate(['tenant_id' => $tenant->id, 'name' => 'Court Hub Demo Organization']);
        $facility = Facility::updateOrCreate(['tenant_id' => $tenant->id, 'name' => 'Court Hub Central'], ['organization_id' => $organization->id, 'timezone' => 'Asia/Manila', 'address' => '100 Demo Avenue, Quezon City', 'registration_open' => true]);
        $branch = Branch::updateOrCreate(['tenant_id' => $tenant->id, 'name' => 'Central Branch'], ['facility_id' => $facility->id, 'timezone' => 'Asia/Manila', 'address' => '100 Demo Avenue, Quezon City']);
        $northBranch = Branch::updateOrCreate(['tenant_id' => $tenant->id, 'name' => 'North Branch'], ['facility_id' => $facility->id, 'timezone' => 'Asia/Manila', 'address' => '25 North Road, Quezon City']);

        $users = [];
        foreach ([
            'super-admin' => ['Demo Super Admin', 'demo.superadmin@court-hub.test'],
            'court-owner' => ['Demo Court Owner', 'demo.owner@court-hub.test'],
            'facility-manager' => ['Demo Facility Manager', 'demo.manager@court-hub.test'],
            'front-desk' => ['Demo Front Desk', 'demo.desk@court-hub.test'],
            'coach' => ['Demo Coach', 'demo.coach@court-hub.test'],
            'event-organizer' => ['Demo Event Organizer', 'demo.organizer@court-hub.test'],
            'player-one' => ['Demo Player One', 'demo.player1@court-hub.test'],
            'player-two' => ['Demo Player Two', 'demo.player2@court-hub.test'],
        ] as $key => [$name, $email]) {
            $users[$key] = User::updateOrCreate(['email' => $email], ['tenant_id' => $tenant->id, 'home_facility_id' => str_starts_with($key, 'player') ? $facility->id : null, 'name' => $name, 'password' => Hash::make(self::PASSWORD), 'email_verified_at' => now()]);
            $users[$key]->syncRoles([str_starts_with($key, 'player') ? 'player' : $key]);
        }
        SocialAccount::updateOrCreate(['provider' => 'google', 'provider_user_id' => 'demo-google-player-one'], ['user_id' => $users['player-one']->id]);

        $courtOne = Court::updateOrCreate(['branch_id' => $branch->id, 'name' => 'Court One'], ['tenant_id' => $tenant->id, 'sport' => 'pickleball', 'status' => 'active', 'base_price' => 500]);
        $courtTwo = Court::updateOrCreate(['branch_id' => $branch->id, 'name' => 'Court Two'], ['tenant_id' => $tenant->id, 'sport' => 'badminton', 'status' => 'active', 'base_price' => 400]);
        Court::updateOrCreate(['branch_id' => $northBranch->id, 'name' => 'North Court'], ['tenant_id' => $tenant->id, 'sport' => 'tennis', 'status' => 'active', 'base_price' => 650]);
        CourtType::updateOrCreate(['tenant_id' => $tenant->id, 'name' => 'Indoor Pickleball'], ['sport' => 'pickleball', 'description' => 'Climate-controlled demo court type.']);
        foreach (range(0, 6) as $day) {
            OperatingHour::updateOrCreate(['branch_id' => $branch->id, 'day_of_week' => $day], ['tenant_id' => $tenant->id, 'opens_at' => '06:00', 'closes_at' => '22:00', 'is_closed' => false]);
        }
        BranchHoliday::updateOrCreate(['branch_id' => $branch->id, 'name' => 'Demo holiday closure'], ['tenant_id' => $tenant->id, 'holiday_date' => now()->addMonths(2)->toDateString(), 'is_closed' => true]);
        CourtMaintenance::updateOrCreate(['court_id' => $courtTwo->id, 'starts_at' => now()->addDays(14)->startOfHour()], ['tenant_id' => $tenant->id, 'ends_at' => now()->addDays(14)->addHours(2)->startOfHour(), 'status' => 'scheduled', 'reason' => 'Demo surface maintenance']);
        PricingRule::updateOrCreate(['court_id' => $courtOne->id, 'name' => 'Demo evening peak'], ['tenant_id' => $tenant->id, 'day_of_week' => now()->dayOfWeek, 'starts_at' => '18:00', 'ends_at' => '21:00', 'price' => 650, 'is_active' => true]);

        $confirmed = $this->booking($tenant, $courtOne, $users['player-one'], 'DEMO-CONFIRMED', now()->addDays(2)->setTime(10, 0), 'confirmed', 'online');
        $reserved = $this->booking($tenant, $courtTwo, $users['player-one'], 'DEMO-RESERVED', now()->addDays(3)->setTime(11, 0), 'reserved', 'online', now()->addMinutes(10));
        $checkedIn = $this->booking($tenant, $courtOne, $users['player-two'], 'DEMO-CHECKED-IN', now()->subDay()->setTime(9, 0), 'confirmed', 'qr');
        CheckIn::updateOrCreate(['booking_id' => $checkedIn->id], ['tenant_id' => $tenant->id, 'user_id' => $users['player-two']->id, 'checked_in_by' => $users['front-desk']->id, 'method' => 'qr', 'checked_in_at' => now()->subDay()->setTime(9, 5)]);
        Waitlist::updateOrCreate(['court_id' => $courtOne->id, 'user_id' => $users['player-two']->id, 'starts_at' => now()->addDays(4)->setTime(18, 0)], ['tenant_id' => $tenant->id, 'ends_at' => now()->addDays(4)->setTime(19, 0), 'status' => 'waiting']);
        RecurringReservation::updateOrCreate(['court_id' => $courtTwo->id, 'user_id' => $users['player-one']->id, 'day_of_week' => now()->addWeek()->dayOfWeek], ['tenant_id' => $tenant->id, 'starts_at' => '19:00', 'duration_minutes' => 60, 'starts_on' => now()->addWeek()->toDateString(), 'ends_on' => now()->addMonths(2)->toDateString(), 'status' => 'active']);

        $gold = MembershipPlan::updateOrCreate(['tenant_id' => $tenant->id, 'name' => 'Demo Gold Membership'], ['plan_type' => 'standard', 'billing_period' => 'monthly', 'price' => 1500, 'currency' => 'PHP', 'duration_days' => 30, 'session_count' => null, 'discount_percent' => 10, 'priority_booking' => true, 'is_active' => true]);
        $sessions = MembershipPlan::updateOrCreate(['tenant_id' => $tenant->id, 'name' => 'Demo Session Pack'], ['plan_type' => 'session-package', 'billing_period' => 'package', 'price' => 900, 'currency' => 'PHP', 'duration_days' => 90, 'session_count' => 8, 'discount_percent' => 0, 'priority_booking' => false, 'is_active' => true]);
        $membership = Membership::updateOrCreate(['tenant_id' => $tenant->id, 'membership_plan_id' => $gold->id, 'user_id' => $users['player-one']->id], ['starts_on' => now()->subDays(5)->toDateString(), 'ends_on' => now()->addDays(25)->toDateString(), 'status' => 'active', 'auto_renew' => true, 'remaining_sessions' => null]);
        $package = Membership::updateOrCreate(['tenant_id' => $tenant->id, 'membership_plan_id' => $sessions->id, 'user_id' => $users['player-two']->id], ['starts_on' => now()->subDays(2)->toDateString(), 'ends_on' => now()->addDays(88)->toDateString(), 'status' => 'active', 'auto_renew' => false, 'remaining_sessions' => 7]);
        MembershipCard::updateOrCreate(['membership_id' => $membership->id], ['tenant_id' => $tenant->id, 'card_number' => 'DEMO-GOLD-001', 'qr_code' => 'DEMO-MEMBER-GOLD-001', 'is_active' => true]);
        MembershipCard::updateOrCreate(['membership_id' => $package->id], ['tenant_id' => $tenant->id, 'card_number' => 'DEMO-PACK-001', 'qr_code' => 'DEMO-MEMBER-PACK-001', 'is_active' => true]);
        MembershipSessionUsage::updateOrCreate(['membership_id' => $package->id, 'used_at' => now()->subDay()->setTime(10, 0)], ['tenant_id' => $tenant->id, 'used_by' => $users['front-desk']->id, 'notes' => 'Demo session use']);
        MembershipCheckIn::updateOrCreate(['membership_id' => $membership->id, 'checked_in_at' => now()->setTime(8, 0)], ['tenant_id' => $tenant->id, 'user_id' => $users['player-one']->id, 'checked_in_by' => $users['front-desk']->id, 'method' => 'qr']);

        $payment = Payment::updateOrCreate(['reference' => 'DEMO-PAY-BOOKING'], ['tenant_id' => $tenant->id, 'booking_id' => $confirmed->id, 'membership_id' => null, 'user_id' => $users['player-one']->id, 'invoice_number' => 'DEMO-INV-BOOKING', 'method' => 'gcash', 'provider' => 'xendit', 'provider_reference' => 'xendit_demo_booking', 'status' => 'paid', 'paid_at' => now()->subDay(), 'amount' => $confirmed->amount, 'currency' => 'PHP', 'provider_payload' => ['mock' => true]]);
        Payment::updateOrCreate(['reference' => 'DEMO-PAY-MEMBERSHIP'], ['tenant_id' => $tenant->id, 'booking_id' => null, 'membership_id' => $membership->id, 'user_id' => $users['player-one']->id, 'invoice_number' => 'DEMO-INV-MEMBERSHIP', 'method' => 'maya', 'provider' => 'xendit', 'provider_reference' => 'xendit_demo_membership', 'status' => 'paid', 'paid_at' => now()->subDays(5), 'amount' => $gold->price, 'currency' => 'PHP', 'provider_payload' => ['mock' => true]]);
        PaymentRefund::updateOrCreate(['payment_id' => $payment->id, 'requested_by' => $users['facility-manager']->id], ['tenant_id' => $tenant->id, 'amount' => 100, 'status' => 'completed', 'reason' => 'Demo partial refund']);
        Refund::updateOrCreate(['booking_id' => $confirmed->id, 'user_id' => $users['player-one']->id], ['tenant_id' => $tenant->id, 'amount' => 100, 'status' => 'completed', 'reason' => 'Demo booking refund']);

        $paddle = InventoryItem::updateOrCreate(['branch_id' => $branch->id, 'name' => 'Demo Paddle'], ['tenant_id' => $tenant->id, 'sku' => 'DEMO-PADDLE', 'quantity_total' => 12, 'quantity_available' => 9, 'rental_price' => 100, 'deposit_amount' => 300, 'is_active' => true]);
        $activeRental = Rental::updateOrCreate(['inventory_item_id' => $paddle->id, 'user_id' => $users['player-one']->id, 'rented_at' => now()->setTime(9, 0)], ['tenant_id' => $tenant->id, 'quantity' => 1, 'due_at' => now()->addDay()->setTime(18, 0), 'status' => 'active', 'amount' => 100, 'deposit_amount' => 300, 'notes' => 'Demo active rental']);
        $activeRental->update(['damage_fee' => 0]);
        $overdue = Rental::updateOrCreate(['inventory_item_id' => $paddle->id, 'user_id' => $users['player-two']->id, 'rented_at' => now()->subDays(3)->setTime(9, 0)], ['tenant_id' => $tenant->id, 'quantity' => 1, 'due_at' => now()->subDay()->setTime(18, 0), 'status' => 'active', 'amount' => 100, 'deposit_amount' => 300, 'notes' => 'Demo overdue rental']);
        $overdue->update(['damage_fee' => 50]);

        $coach = CoachProfile::updateOrCreate(['tenant_id' => $tenant->id, 'user_id' => $users['coach']->id], ['name' => $users['coach']->name, 'email' => $users['coach']->email, 'phone' => '09170000001', 'bio' => 'Demo certified coach', 'hourly_rate' => 900, 'is_active' => true]);
        CoachStudent::updateOrCreate(['coach_profile_id' => $coach->id, 'user_id' => $users['player-one']->id], ['tenant_id' => $tenant->id, 'revenue_share_percent' => 35, 'notes' => 'Demo recurring student']);
        CoachAvailability::updateOrCreate(['coach_profile_id' => $coach->id, 'day_of_week' => now()->addDays(2)->dayOfWeek, 'starts_at' => '09:00'], ['tenant_id' => $tenant->id, 'ends_at' => '17:00']);
        CoachingSession::updateOrCreate(['coach_profile_id' => $coach->id, 'user_id' => $users['player-one']->id, 'starts_at' => now()->addDays(2)->setTime(14, 0)], ['tenant_id' => $tenant->id, 'court_id' => $courtOne->id, 'ends_at' => now()->addDays(2)->setTime(15, 0), 'amount' => 900, 'status' => 'scheduled', 'notes' => 'Demo coaching session']);

        $tournament = Tournament::updateOrCreate(['tenant_id' => $tenant->id, 'name' => 'Demo Weekend Open'], ['branch_id' => $branch->id, 'format' => 'singles', 'starts_at' => now()->addDays(7)->setTime(9, 0), 'ends_at' => now()->addDays(7)->setTime(18, 0), 'entry_fee' => 250, 'capacity' => 16, 'status' => 'open']);
        $firstRegistration = TournamentRegistration::updateOrCreate(['tournament_id' => $tournament->id, 'user_id' => $users['player-one']->id], ['tenant_id' => $tenant->id, 'status' => 'registered']);
        $secondRegistration = TournamentRegistration::updateOrCreate(['tournament_id' => $tournament->id, 'user_id' => $users['player-two']->id], ['tenant_id' => $tenant->id, 'status' => 'registered']);
        $team = TournamentTeam::updateOrCreate(['tournament_id' => $tournament->id, 'name' => 'Demo Doubles'], ['tenant_id' => $tenant->id]);
        TournamentTeamMember::firstOrCreate(['tournament_team_id' => $team->id, 'user_id' => $users['player-one']->id]);
        TournamentTeamMember::firstOrCreate(['tournament_team_id' => $team->id, 'user_id' => $users['player-two']->id]);
        $firstRegistration->update(['tournament_team_id' => $team->id, 'checked_in_at' => now()->addDays(7)->setTime(8, 30), 'checked_in_by' => $users['event-organizer']->id]);
        $secondRegistration->update(['tournament_team_id' => $team->id]);
        TournamentMatch::updateOrCreate(['tournament_id' => $tournament->id, 'round_number' => 1, 'match_number' => 1], ['tenant_id' => $tenant->id, 'court_id' => $courtOne->id, 'player_one_registration_id' => $firstRegistration->id, 'player_two_registration_id' => $secondRegistration->id, 'winner_registration_id' => $firstRegistration->id, 'starts_at' => now()->addDays(7)->setTime(10, 0), 'status' => 'scheduled', 'score' => null]);

        NotificationPreference::updateOrCreate(['user_id' => $users['player-one']->id], ['email_enabled' => true, 'sms_enabled' => false, 'push_enabled' => true]);
        UserNotification::updateOrCreate(['user_id' => $users['player-one']->id, 'title' => 'Demo booking confirmed'], ['tenant_id' => $tenant->id, 'channel' => 'in_app', 'type' => 'booking_confirmed', 'message' => 'Your demo reservation is ready for check-in.', 'data' => ['booking_id' => $confirmed->id], 'read_at' => null]);
        PushDevice::updateOrCreate(['token_hash' => hash('sha256', 'demo-fcm-token-player-one')], ['tenant_id' => $tenant->id, 'user_id' => $users['player-one']->id, 'token' => 'demo-fcm-token-player-one', 'platform' => 'android', 'device_name' => 'Demo Android', 'last_seen_at' => now()]);
        ActivityLog::updateOrCreate(['tenant_id' => $tenant->id, 'actor_id' => $users['court-owner']->id, 'event' => 'demo_data_seeded'], ['subject_type' => Tenant::class, 'subject_id' => $tenant->id, 'properties' => ['command' => 'court-hub:demo-data'], 'ip_address' => '127.0.0.1']);
    }

    private function booking(Tenant $tenant, Court $court, User $user, string $reference, $startsAt, string $status, string $source, $expiresAt = null): Booking
    {
        return Booking::updateOrCreate(['reference' => $reference], ['tenant_id' => $tenant->id, 'court_id' => $court->id, 'user_id' => $user->id, 'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addHour(), 'status' => $status, 'source' => $source, 'amount' => $court->base_price, 'currency' => 'PHP', 'expires_at' => $expiresAt, 'notes' => 'Demo booking fixture', 'qr_code' => 'QR-'.Str::upper($reference)]);
    }
}
