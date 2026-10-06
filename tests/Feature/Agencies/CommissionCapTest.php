<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\ClaimKind;
use App\Enums\MainChannel;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Role;
use App\Models\User;
use App\Support\Bookings\Transitions;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function tradeBooking(Agency $agency, int $commissionPct, array $overrides = []): Booking
{
    $departure = $overrides['departure'] ?? ReservationFixtures::anamaraDeparture();
    unset($overrides['departure']);
    $cabin = $overrides['cabin_code'] ?? 'S1';
    unset($overrides['cabin_code']);

    $id = test()->actingAs(managerUser())
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($departure, [
            'cabins' => [['cabin_code' => $cabin, 'adults' => 2, 'children' => 0]],
            'main_channel' => MainChannel::B2BTravelAdvisor->value,
            'channel_of_origin' => 'Travel Advisor',
            'agency_id' => $agency->id,
            'commission_pct' => $commissionPct,
        ]))
        ->assertCreated()
        ->json('bookings.0.id');

    return Booking::query()->findOrFail($id);
}

test('a 10 percent trade booking accrues and the same rate on d2c is 422', function (): void {
    $agency = Agency::factory()->create(['commission_pct' => 10]);
    $departure = ReservationFixtures::anamaraDeparture();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($departure, [
            'main_channel' => MainChannel::D2C->value,
            'agency_id' => $agency->id,
            'commission_pct' => 10,
        ]))
        ->assertUnprocessable();

    $booking = tradeBooking($agency, 10, ['cabin_code' => 'S2']);

    expect($booking->status)->toBe(BookingStatus::PendingPayment);
    expect($booking->commission_approved)->toBeTrue();
    expect($booking->commissionAmount())->toBe(2660);
});

test('a pending agency cannot be sold against', function (): void {
    $agency = Agency::factory()->pending()->create();
    $departure = ReservationFixtures::anamaraDeparture();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($departure, [
            'main_channel' => MainChannel::B2BTravelAdvisor->value,
            'channel_of_origin' => 'Travel Advisor',
            'agency_id' => $agency->id,
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.agency_id.0', 'The agency must be approved before it can be sold against.');
});

test('15 percent creates ON_HOLD_AGENCY, holds the cabin, and hides CONFIRMED', function (): void {
    $agency = Agency::factory()->create(['commission_pct' => 15]);
    $booking = tradeBooking($agency, 15);

    expect($booking->status)->toBe(BookingStatus::OnHoldAgency);
    expect($booking->commission_approved)->toBeFalse();
    expect($booking->claims()->whereNull('released_at')->where('kind', ClaimKind::Booking)->pluck('room_id')->unique())->toHaveCount(1);

    $held = ChangeHistory::query()
        ->where('subject_id', $booking->id)
        ->where('event', 'booking.commission_held')
        ->firstOrFail();
    expect($held->after['what'] ?? '')->toBe('HELD — commission 15 % above 12 % cap · Director alert sent (FIN-005)');

    $targets = array_map(
        fn (array $row): string => $row['to'],
        Transitions::allowedFor($booking, adminUser()),
    );
    expect($targets)->toContain(BookingStatus::Released->value);
    expect($targets)->toContain(BookingStatus::Cancelled->value);
    expect($targets)->not->toContain(BookingStatus::Confirmed->value);

    $this->actingAs(adminUser())
        ->getJson('/api/rms/bookings/'.$booking->id)
        ->assertOk()
        ->assertJsonPath('status', BookingStatus::OnHoldAgency->value);

    $allowed = $this->actingAs(adminUser())
        ->getJson('/api/rms/bookings/'.$booking->id)
        ->json('allowed_transitions');

    expect(collect($allowed)->pluck('to')->all())->toBe([
        BookingStatus::Released->value,
        BookingStatus::Cancelled->value,
    ]);
});

test('a settled deposit on an unapproved hold does not confirm and writes one blocked entry per episode', function (): void {
    $agency = Agency::factory()->create(['commission_pct' => 15]);
    $booking = tradeBooking($agency, 15);
    $director = directorUser();

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/payments', [
            'kind' => PaymentKind::Deposit->value,
            'method' => PaymentMethod::CardStripe->value,
            'amount' => 2660,
        ])
        ->assertCreated()
        ->assertJsonPath('booking.status', BookingStatus::OnHoldAgency->value);

    expect(ChangeHistory::query()
        ->where('subject_id', $booking->id)
        ->where('event', 'booking.confirmed_blocked')
        ->count())->toBe(1);
    expect(ChangeHistory::query()
        ->where('event', 'booking.confirmed_blocked')
        ->value('after')['what'] ?? '')->toBe(
            'Deposit settled — CONFIRMED blocked: commission 15 % above the 12 % cap (FIN-005)',
        );

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/payments', [
            'kind' => PaymentKind::Balance->value,
            'method' => PaymentMethod::CardStripe->value,
            'amount' => 100,
        ])
        ->assertCreated();

    expect(ChangeHistory::query()
        ->where('subject_id', $booking->id)
        ->where('event', 'booking.confirmed_blocked')
        ->count())->toBe(1);

    $this->actingAs($director)
        ->postJson('/api/rms/bookings/'.$booking->id.'/commission-approval', [
            'approve' => false,
            'reason' => 'Rate still too high',
        ])
        ->assertOk()
        ->assertJsonPath('status', BookingStatus::OnHoldAgency->value);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/payments', [
            'kind' => PaymentKind::Balance->value,
            'method' => PaymentMethod::CardStripe->value,
            'amount' => 50,
        ])
        ->assertCreated()
        ->assertJsonPath('booking.status', BookingStatus::OnHoldAgency->value);

    expect(ChangeHistory::query()
        ->where('subject_id', $booking->id)
        ->where('event', 'booking.confirmed_blocked')
        ->count())->toBe(2);
});

test('approving an over-cap booking whose deposit settled confirms it', function (): void {
    $agency = Agency::factory()->create(['commission_pct' => 15]);
    $booking = tradeBooking($agency, 15);
    $director = directorUser();

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/payments', [
            'kind' => PaymentKind::Deposit->value,
            'method' => PaymentMethod::CardStripe->value,
            'amount' => 2660,
        ])
        ->assertCreated();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/commission-approval', [
            'approve' => true,
            'reason' => 'Director exception',
        ])
        ->assertForbidden();

    $this->actingAs($director)
        ->postJson('/api/rms/bookings/'.$booking->id.'/commission-approval', [
            'approve' => true,
            'reason' => 'Director exception',
        ])
        ->assertOk()
        ->assertJsonPath('status', BookingStatus::Confirmed->value)
        ->assertJsonPath('commission_approved', true)
        ->assertJsonPath('commission_pct', 15);

    expect($booking->fresh()?->status)->toBe(BookingStatus::Confirmed);
});

test('releasing a held sale uses sold-booking wording on the audit', function (): void {
    $agency = Agency::factory()->create(['commission_pct' => 15]);
    $booking = tradeBooking($agency, 15);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/transition', [
            'to' => BookingStatus::Released->value,
            'reason' => 'Sale fell through',
        ])
        ->assertOk()
        ->assertJsonPath('status', BookingStatus::Released->value);

    expect($booking->fresh()?->claims()->whereNull('released_at')->count())->toBe(0);

    $this->actingAs(adminUser())
        ->getJson('/api/rms/bookings/audit')
        ->assertOk()
        ->assertJsonPath('data.0.what', 'Reservation released — cabin returned to inventory')
        ->assertJsonPath('data.0.why', 'Sale fell through')
        ->assertJsonPath('data.0.reference', $booking->reference);
});

test('cancelling a held sale releases the claim and requires a reason', function (): void {
    $agency = Agency::factory()->create(['commission_pct' => 15]);
    $booking = tradeBooking($agency, 15);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/transition', [
            'to' => BookingStatus::Cancelled->value,
        ])
        ->assertUnprocessable();

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/transition', [
            'to' => BookingStatus::Cancelled->value,
            'reason' => 'Client withdrew',
        ])
        ->assertOk()
        ->assertJsonPath('status', BookingStatus::Cancelled->value);

    expect($booking->fresh()?->claims()->whereNull('released_at')->count())->toBe(0);
});

test('a move that changes the total changes commission amount only', function (): void {
    $agency = Agency::factory()->create(['commission_pct' => 10]);
    $booking = tradeBooking($agency, 10);
    $festive = ReservationFixtures::anamaraDeparture('2027-12-19', festive: true);

    $preview = $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/move/preview', [
            'cabin_code' => 'S2',
        ])
        ->assertOk()
        ->json();

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/move', [
            'cabin_code' => 'S2',
            'confirm_total' => $preview['new_total'],
        ])
        ->assertOk()
        ->assertJsonPath('total', 28100)
        ->assertJsonPath('commission_pct', 10)
        ->assertJsonPath('commission_amount', 2810)
        ->assertJsonPath('commission_approved', true);
});

function directorUser(): User
{
    $role = Role::factory()->create([
        'permissions' => [
            Permission::PanelRms,
            Permission::BookingsViewAll,
            Permission::CommissionsOverrideCap,
        ],
    ]);

    return User::factory()->create(['role_id' => $role->id]);
}
