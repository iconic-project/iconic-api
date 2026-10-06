<?php

declare(strict_types=1);

use App\Actions\Extras\AddBookingExtra;
use App\Actions\Extras\UpdateBookingFees;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\DocumentKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\PngCategory;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Payment;
use App\Support\Documents\Snapshots\SnapshotFactory;
use App\Support\Payments\Ledger;
use App\Support\SensitiveFields;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

function snapshotCabin(array $overrides = []): Booking
{
    $departure = $overrides['departure'] ?? ReservationFixtures::anamaraDeparture('2028-09-03');
    unset($overrides['departure']);

    return Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S4')?->id,
        'status' => BookingStatus::Confirmed,
        'total' => 26600,
        'deposit_pct' => 10,
        ...$overrides,
    ]);
}

test('snapshot totals match the booking API figures for a cabin booking', function (): void {
    $booking = snapshotCabin();
    $snapshot = SnapshotFactory::build($booking, DocumentKind::Invoice);

    expect($snapshot['totals']['vessel'])->toBe($booking->total);
    expect($snapshot['totals']['charges_total'])->toBe($booking->chargesTotal());
    expect($snapshot['totals']['paid'])->toBe(Ledger::paid($booking));
    expect($snapshot['totals']['balance'])->toBe($booking->balance());
    expect($snapshot['totals']['stay_outstanding'])->toBe($booking->stayOutstanding());
    expect(SensitiveFields::keysIn($snapshot))->toBe([]);
});

test('snapshot totals match a charter and a booking with extras fees refund and information-only', function (): void {
    $actor = adminUser();
    $charterDep = ReservationFixtures::anamaraDeparture('2028-09-10');
    $charter = Booking::factory()->create([
        'room_id' => null,
        'type' => BookingType::Charter,
        'status' => BookingStatus::Confirmed,
        'total' => 199500,
        'deposit_pct' => 20,
    ]);
    $charterSnap = SnapshotFactory::build($charter, DocumentKind::Invoice);
    expect($charterSnap['totals']['vessel'])->toBe($charter->total);
    expect($charterSnap['totals']['charges_total'])->toBe($charter->chargesTotal());
    expect($charterSnap['stay']['entire_property'])->toBeTrue();

    $booking = snapshotCabin();
    app(AddBookingExtra::class)->handle($booking, ['code' => 'FLT', 'qty' => 2], $actor);
    Guest::factory()->create([
        'booking_id' => $booking->id,
        'position' => 1,
        'is_lead' => true,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'dob' => '1979-02-14',
        'nationality' => 'DE',
        'png_fee' => 200,
    ]);
    Guest::factory()->create([
        'booking_id' => $booking->id,
        'position' => 2,
        'is_lead' => false,
        'first_name' => 'Alan',
        'last_name' => 'Turing',
        'dob' => '1982-06-23',
        'nationality' => 'GB',
        'png_fee' => 200,
    ]);
    app(UpdateBookingFees::class)->handle($booking, ['png_collected' => true, 'tct_collected' => true], $actor);
    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'status' => PaymentStatus::Settled,
        'amount' => $booking->depositAmount(),
        'reference' => 'ANK-2026-0901-D01',
    ]);
    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Refund,
        'status' => PaymentStatus::Settled,
        'amount' => -500,
        'reference' => 'ANK-2026-0901-R01',
    ]);

    $booking->refresh();
    $withFees = SnapshotFactory::build($booking, DocumentKind::Invoice, fresh: true);
    expect($withFees['totals']['extras'])->toBe($booking->extrasTotalFresh());
    expect($withFees['totals']['fees_collected'])->toBe($booking->feesCollectedFresh());
    expect($withFees['totals']['charges_total'])->toBe($booking->chargesTotalFresh());
    expect($withFees['totals']['paid'])->toBe($booking->chargesTotalFresh() - $booking->balanceFresh());
    expect($withFees['totals']['balance'])->toBe($booking->balanceFresh());
    expect(SensitiveFields::keysIn($withFees))->toBe([]);

    $infoOnly = snapshotCabin(['reference' => 'ANK-2026-0902']);
    Guest::factory()->create([
        'booking_id' => $infoOnly->id,
        'position' => 1,
        'is_lead' => true,
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
        'png_fee' => 200,
    ]);
    $infoSnap = SnapshotFactory::build($infoOnly, DocumentKind::Invoice);
    expect($infoSnap['totals']['fees_collected'])->toBe(0);
    expect($infoSnap['fees']['information'])->not->toBe([]);
    expect($infoSnap['totals']['information_total'])->toBeGreaterThan(0);
    expect(SensitiveFields::keysIn($infoSnap))->toBe([]);
});

test('the receipt balance is the balance after that payment not today', function (): void {
    $booking = snapshotCabin();
    $first = Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'status' => PaymentStatus::Settled,
        'amount' => 2660,
        'paid_at' => '2028-01-01',
        'reference' => 'ANK-2026-0903-D01',
    ]);
    $second = Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Balance,
        'status' => PaymentStatus::Settled,
        'amount' => 5000,
        'paid_at' => '2028-02-01',
        'reference' => 'ANK-2026-0903-B01',
    ]);

    $afterFirst = SnapshotFactory::build($booking, DocumentKind::Receipt, $first);
    $afterSecond = SnapshotFactory::build($booking, DocumentKind::Receipt, $second);

    expect($afterFirst['balance_after'])->toBe($booking->chargesTotal() - 2660);
    expect($afterSecond['balance_after'])->toBe($booking->chargesTotal() - 7660);
    expect($afterFirst['balance_after'])->not->toBe($booking->balance());
});
