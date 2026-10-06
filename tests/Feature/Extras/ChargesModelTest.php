<?php

declare(strict_types=1);

use App\Actions\Extras\AddBookingExtra;
use App\Actions\Extras\UpdateBookingFees;
use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PngCategory;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Payment;
use App\Support\BusinessTime;
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

function chargesCabin(array $overrides = []): Booking
{
    $departure = $overrides['departure'] ?? ReservationFixtures::anamaraDeparture('2028-05-07');
    unset($overrides['departure']);

    return Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', 'S3')?->id,
        'status' => BookingStatus::Confirmed,
        'total' => 26600,
        'deposit_pct' => 10,
        ...$overrides,
    ]);
}

function sqlBalance(Booking $booking): int
{
    [$sql, $bindings] = Booking::balanceSql();

    return (int) Booking::query()
        ->whereKey($booking->id)
        ->toBase()
        ->selectRaw($sql.' as balance', $bindings)
        ->value('balance');
}

test('php balance equals balanceSql with extras fees and a refund', function (): void {
    $booking = chargesCabin();
    $actor = adminUser();

    app(AddBookingExtra::class)->handle($booking, ['code' => 'FLT', 'qty' => 2], $actor);

    Guest::factory()->create([
        'booking_id' => $booking->id,
        'position' => 1,
        'is_lead' => true,
        'dob' => '1979-02-14',
        'nationality' => 'DE',
        'png_category' => PngCategory::ForeignOver12,
        'png_fee' => 200,
    ]);

    app(UpdateBookingFees::class)->handle($booking, ['png_collected' => true], $actor);

    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'status' => PaymentStatus::Settled,
        'amount' => $booking->depositAmount(),
        'reference' => 'ANK-2026-0801-D01',
    ]);
    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Refund,
        'status' => PaymentStatus::Refunded,
        'amount' => -100,
        'reference' => 'ANK-2026-0801-R01',
    ]);

    $fresh = $booking->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh?->extrasTotal())->toBe(840);
    expect($fresh?->feesCollectedTotal())->toBe(200);
    expect($fresh?->chargesTotal())->toBe(27640);
    expect($fresh?->depositAmount())->toBe(2660);
    expect($fresh?->balance())->toBe($fresh?->chargesTotal() - 2660 + 100);
    expect($fresh?->balance())->toBe(sqlBalance($fresh));
});

test('deposit is unchanged by extras and collected fees', function (): void {
    $booking = chargesCabin();
    $deposit = $booking->depositAmount();

    app(AddBookingExtra::class)->handle($booking, ['code' => 'HPRE', 'qty' => 1], adminUser());

    expect($booking->fresh()?->depositAmount())->toBe($deposit);
});

test('a booking with the cruise paid and an extra unpaid past T-120 is not overdue', function (): void {
    $booking = chargesCabin([
        'status' => BookingStatus::Confirmed,
        'balance_due_date_override' => '2020-01-01',
    ]);

    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Balance,
        'status' => PaymentStatus::Settled,
        'amount' => $booking->total,
        'reference' => 'ANK-2026-0802-B01',
    ]);

    app(AddBookingExtra::class)->handle($booking, ['code' => 'FLT', 'qty' => 1], adminUser());

    $this->travelTo(CarbonImmutable::parse('2026-08-15 00:30:00', BusinessTime::zone()));

    $fresh = $booking->fresh();
    expect($fresh?->balance())->toBe(420);
    expect($fresh?->stayOutstanding())->toBe(0);
    expect($fresh?->isOverdue())->toBeFalse();

    $admin = adminUser();
    $this->actingAs($admin)
        ->getJson('/api/rms/bookings?overdue=1')
        ->assertOk();

    $ids = collect($this->actingAs($admin)->getJson('/api/rms/bookings?overdue=1')->json('data'))->pluck('id');
    expect($ids)->not->toContain($booking->id);
});

test('adding an extra to a fully paid booking reopens the balance without moving status', function (): void {
    $actor = adminUser();
    $booking = chargesCabin(['status' => BookingStatus::FullyPaid]);

    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Balance,
        'status' => PaymentStatus::Settled,
        'amount' => $booking->total,
        'reference' => 'ANK-2026-0803-B01',
    ]);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/extras', [
            'code' => 'FLT',
            'qty' => 1,
        ])
        ->assertCreated();

    $this->actingAs($actor)
        ->getJson('/api/rms/bookings/'.$booking->id)
        ->assertOk()
        ->assertJsonPath('status', BookingStatus::FullyPaid->value)
        ->assertJsonPath('balance', 420)
        ->assertJsonPath('stay_outstanding', 0);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/payments', [
            'kind' => PaymentKind::Extras->value,
            'method' => PaymentMethod::CardStripe->value,
            'amount' => 420,
        ])
        ->assertCreated()
        ->assertJsonPath('booking.status', BookingStatus::FullyPaid->value)
        ->assertJsonPath('booking.balance', 0);
});

test('balanceFresh sees a newly added extra after aggregates were loaded', function (): void {
    $actor = adminUser();
    $booking = chargesCabin(['status' => BookingStatus::Confirmed]);

    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'status' => PaymentStatus::Settled,
        'amount' => $booking->depositAmount(),
        'reference' => 'ANK-2026-0804-D01',
    ]);

    $loaded = Booking::query()->withLedgerAggregates()->withChargesSummary()->findOrFail($booking->id);
    $before = $loaded->balance();

    app(AddBookingExtra::class)->handle($booking, ['code' => 'FLT', 'qty' => 2], $actor);

    expect($loaded->balanceFresh())->toBe($before + 840);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/payments', [
            'kind' => PaymentKind::Balance->value,
            'method' => PaymentMethod::CardStripe->value,
            'amount' => $loaded->balanceFresh(),
        ])
        ->assertCreated()
        ->assertJsonPath('booking.status', BookingStatus::FullyPaid->value)
        ->assertJsonPath('booking.balance', 0);
});

test('an overpayment warning compares against charges not the cruise total', function (): void {
    $actor = adminUser();
    $booking = chargesCabin(['status' => BookingStatus::Confirmed]);

    app(AddBookingExtra::class)->handle($booking, ['code' => 'FLT', 'qty' => 1], $actor);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/payments', [
            'kind' => PaymentKind::Balance->value,
            'method' => PaymentMethod::CardStripe->value,
            'amount' => 27020,
        ])
        ->assertCreated()
        ->assertJsonPath('warnings', []);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/payments', [
            'kind' => PaymentKind::Balance->value,
            'method' => PaymentMethod::CardStripe->value,
            'amount' => 100,
        ])
        ->assertCreated()
        ->assertJsonPath('warnings.0', 'This takes the booking above its total by USD 100');
});

test('pending kpi sums charges and overdue kpi sums cruise outstanding', function (): void {
    $actor = adminUser();
    $overdue = overdueCabin([
        'reference' => 'ANK-2026-0810',
        'cabin_code' => 'S5',
        'departure' => ReservationFixtures::anamaraDeparture('2028-06-11'),
        'balance_due_date_override' => '2020-01-01',
    ]);
    app(AddBookingExtra::class)->handle($overdue, ['code' => 'FLT', 'qty' => 1], $actor);

    $cruisePaid = chargesCabin([
        'reference' => 'ANK-2026-0811',
        'status' => BookingStatus::Confirmed,
        'balance_due_date_override' => '2020-01-01',
        'departure' => ReservationFixtures::anamaraDeparture('2028-06-18'),
    ]);
    Payment::factory()->create([
        'booking_id' => $cruisePaid->id,
        'kind' => PaymentKind::Balance,
        'status' => PaymentStatus::Settled,
        'amount' => $cruisePaid->total,
        'reference' => 'ANK-2026-0811-B01',
    ]);
    app(AddBookingExtra::class)->handle($cruisePaid, ['code' => 'HPRE', 'qty' => 1], $actor);

    $this->travelTo(CarbonImmutable::parse('2026-08-15 00:30:00', BusinessTime::zone()));

    $overdueFresh = $overdue->fresh();
    $cruiseFresh = $cruisePaid->fresh();
    expect($overdueFresh?->isOverdue())->toBeTrue();
    expect($overdueFresh?->balance())->toBeGreaterThan((int) $overdueFresh?->stayOutstanding());
    expect($cruiseFresh?->isOverdue())->toBeFalse();
    expect($cruiseFresh?->stayOutstanding())->toBe(0);
    expect($cruiseFresh?->balance())->toBe(320);

    $kpis = $this->actingAs($actor)
        ->getJson('/api/rms/payments')
        ->assertOk()
        ->json('meta.kpis');

    $overdueList = $this->actingAs($actor)
        ->getJson('/api/rms/bookings?overdue=1')
        ->assertOk();
    $pendingList = $this->actingAs($actor)
        ->getJson('/api/rms/bookings?pending_payment=1')
        ->assertOk();

    $overdueIds = collect($overdueList->json('data'))->pluck('id');
    $pendingIds = collect($pendingList->json('data'))->pluck('id');

    expect($overdueIds)->toContain($overdue->id);
    expect($overdueIds)->not->toContain($cruisePaid->id);
    expect($pendingIds)->toContain($overdue->id, $cruisePaid->id);

    expect($kpis['overdue_count'])->toBe($overdueList->json('meta.total'));
    expect($kpis['overdue_amount'])->toBe($overdueFresh?->stayOutstanding());
    expect($overdueList->json('meta.kpis.overdue_amount'))->toBe($overdueFresh?->stayOutstanding());
    expect($kpis['pending_count'])->toBe($pendingList->json('meta.total'));
    expect($kpis['pending'])->toBe(
        (int) $overdueFresh?->balance() + (int) $cruiseFresh?->balance(),
    );
});
