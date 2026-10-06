<?php

declare(strict_types=1);

use App\Enums\AgencyStatus;
use App\Enums\AgencyUserStatus;
use App\Enums\ContactType;
use App\Enums\MainChannel;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Contact;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('a manager can register an agency and a sales exec cannot', function (): void {
    $this->actingAs(salesExecUser())
        ->postJson('/api/rms/agencies', [
            'name' => 'Andes Luxe',
            'contact' => 'P. Ibanez',
            'email' => 'p.ibanez@andes.test',
        ])
        ->assertForbidden();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/agencies', [
            'name' => 'Andes Luxe',
            'contact' => 'P. Ibanez',
            'email' => 'p.ibanez@andes.test',
            'country' => 'CL',
            'network' => 'Signature',
        ])
        ->assertCreated()
        ->assertJsonPath('status', AgencyStatus::Pending->value)
        ->assertJsonPath('commission_pct', 10)
        ->assertJsonPath('users.0.status', AgencyUserStatus::InviteOnApproval->value)
        ->assertJsonPath('users.0.invite_sent_at', null)
        ->assertJsonPath('users.0.invite_expires_at', null)
        ->assertJsonPath('users.0.last_login_at', null)
        ->assertJsonPath('portal_suspended_by', null)
        ->assertJsonMissingPath('users.0.password')
        ->assertJsonMissingPath('users.0.invite_token_hash')
        ->assertJsonPath('sla_breached', false);

    expect(ChangeHistory::query()->where('event', 'agency.registered')->count())->toBe(1);

    $agencyContact = Contact::query()->where('email', 'p.ibanez@andes.test')->first();
    expect($agencyContact)->not->toBeNull();
    expect($agencyContact?->type)->toBe(ContactType::TravelAgent);
    expect($agencyContact?->name)->toBe('P. Ibanez');
});

test('agency sla is breached at three business days and two is the boundary', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00', 'Pacific/Galapagos'));

    $three = Agency::factory()->pending()->create([
        'name' => 'Three days',
        'requested_at' => CarbonImmutable::parse('2026-09-18 10:00:00', 'Pacific/Galapagos'),
    ]);
    $two = Agency::factory()->pending()->create([
        'name' => 'Two days',
        'requested_at' => CarbonImmutable::parse('2026-09-21 10:00:00', 'Pacific/Galapagos'),
    ]);

    $rows = $this->actingAs(managerUser())
        ->getJson('/api/rms/agencies')
        ->assertOk()
        ->json('data');

    $byId = collect($rows)->keyBy('id');
    expect($byId[$three->id]['sla_business_days_elapsed'])->toBe(3);
    expect($byId[$three->id]['sla_breached'])->toBeTrue();
    expect($byId[$two->id]['sla_business_days_elapsed'])->toBe(2);
    expect($byId[$two->id]['sla_breached'])->toBeTrue();
});

test('approval invites users and rejection requires a reason', function (): void {
    $agency = Agency::factory()->pending()->create();
    $agency->users()->create([
        'name' => $agency->contact,
        'email' => $agency->email,
        'status' => AgencyUserStatus::InviteOnApproval,
    ]);

    $this->actingAs(managerUser())
        ->postJson('/api/rms/agencies/'.$agency->id.'/decide', [
            'decision' => AgencyStatus::Rejected->value,
        ])
        ->assertUnprocessable();

    $approved = $this->actingAs(managerUser())
        ->postJson('/api/rms/agencies/'.$agency->id.'/decide', [
            'decision' => AgencyStatus::Approved->value,
        ])
        ->assertOk()
        ->assertJsonPath('status', AgencyStatus::Approved->value)
        ->assertJsonPath('users.0.status', AgencyUserStatus::InviteOnPortalLaunch->value);

    $user = $approved->json('users.0');
    expect($user['invite_sent_at'])->toBeString();
    expect($user['invite_expires_at'])->toBeString();
    expect($user['last_login_at'])->toBeNull();
    expect($user)->not->toHaveKeys(['password', 'invite_token_hash', 'remember_token']);

    expect(ChangeHistory::query()->where('event', 'agency.approved')->count())->toBe(1);
});

test('patching the agency rate does not change a sold booking', function (): void {
    $agency = Agency::factory()->create(['commission_pct' => 10]);
    $departure = ReservationFixtures::anamaraDeparture();

    $id = $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($departure, [
            'main_channel' => MainChannel::B2BTravelAdvisor->value,
            'channel_of_origin' => 'Travel Advisor',
            'agency_id' => $agency->id,
        ]))
        ->assertCreated()
        ->json('bookings.0.id');

    $this->actingAs(managerUser())
        ->patchJson('/api/rms/agencies/'.$agency->id, ['commission_pct' => 15])
        ->assertOk()
        ->assertJsonPath('commission_pct', 15);

    expect(Booking::query()->findOrFail($id)->commission_pct)->toBe(10);
});

test('agency show returns an empty year table and the stay rate document', function (): void {
    $agency = Agency::factory()->create(['commission_pct' => 10]);

    $this->actingAs(managerUser())
        ->getJson('/api/rms/agencies/'.$agency->id)
        ->assertOk()
        ->assertJsonPath('portal_preview.commission_pct', 10)
        ->assertJsonPath('portal_preview.net_rates', []);
});

test('agency index windowed stats and kpis count only bookings arriving in from to', function (): void {
    $agency = Agency::factory()->create(['commission_pct' => 10]);
    $inside = ReservationFixtures::anamaraDeparture('2026-12-21');
    $outside = ReservationFixtures::anamaraDeparture('2026-12-28');

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($inside, [
            'cabins' => [['cabin_code' => 'S1', 'adults' => 2, 'children' => 0]],
            'main_channel' => MainChannel::B2BTravelAdvisor->value,
            'channel_of_origin' => 'Travel Advisor',
            'agency_id' => $agency->id,
            'commission_pct' => 10,
        ]))
        ->assertCreated();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($outside, [
            'cabins' => [['cabin_code' => 'S1', 'adults' => 2, 'children' => 0]],
            'main_channel' => MainChannel::B2BTravelAdvisor->value,
            'channel_of_origin' => 'Travel Advisor',
            'agency_id' => $agency->id,
            'commission_pct' => 10,
        ]))
        ->assertCreated();

    $insideBooking = Booking::query()->where('agency_id', $agency->id)
        ->whereDate('check_in', '2026-12-21')
        ->firstOrFail();
    $outsideBooking = Booking::query()->where('agency_id', $agency->id)
        ->whereDate('check_in', '2026-12-28')
        ->firstOrFail();
    $allRevenue = $insideBooking->total + $outsideBooking->total;
    $allAccrued = $insideBooking->commissionAmount() + $outsideBooking->commissionAmount();
    $allTime = $this->actingAs(managerUser())
        ->getJson('/api/rms/agencies')
        ->assertOk();
    $allRow = collect($allTime->json('data'))->firstWhere('id', $agency->id);

    expect($allRow['bookings_count'])->toBe(2);
    expect($allRow['revenue'])->toBe($allRevenue);
    expect($allRow['commission_accrued'])->toBe($allAccrued);
    expect($allTime->json('meta.kpis.agency_revenue'))->toBe($allRevenue);
    expect($allTime->json('meta.kpis.commission_accrued'))->toBe($allAccrued);
    expect($allTime->json('meta.kpis.agency_approval_business_days'))->toBe(2);
    expect($allTime->json('meta.kpis.commission_default_pct'))->toBe(10);

    $windowed = $this->actingAs(managerUser())
        ->getJson('/api/rms/agencies?from=2026-12-21&to=2026-12-25')
        ->assertOk();
    $windowRow = collect($windowed->json('data'))->firstWhere('id', $agency->id);

    expect($windowRow['bookings_count'])->toBe(1);
    expect($windowRow['revenue'])->toBe($insideBooking->total);
    expect($windowRow['commission_accrued'])->toBe($insideBooking->commissionAmount());
    expect($windowRow['held_bookings_count'])->toBe(0);
    expect($windowed->json('meta.kpis.agency_revenue'))->toBe($insideBooking->total);
    expect($windowed->json('meta.kpis.commission_accrued'))->toBe($insideBooking->commissionAmount());
    expect($windowed->json('meta.kpis.approved_agencies'))->toBe($allTime->json('meta.kpis.approved_agencies'));
});

test('form options list only approved agencies', function (): void {
    $approved = Agency::factory()->create(['name' => 'Blue Latitude']);
    Agency::factory()->pending()->create(['name' => 'Andes Pending']);

    $this->actingAs(salesExecUser())
        ->getJson('/api/rms/bookings/form-options')
        ->assertOk()
        ->assertJsonPath('agencies.0.id', $approved->id)
        ->assertJsonCount(1, 'agencies');
});
