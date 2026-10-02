<?php

declare(strict_types=1);

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\ConflictException;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\References\ReferenceService;
use App\Support\Payments\InsertLedgerRow;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

function referenceBooking(string $reference = 'ANK-2026-0003'): Booking
{
    $departure = ReservationFixtures::anamaraDeparture();

    return Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'reference' => $reference,
        'request_reference' => null,
    ]);
}

test('payment suffixes increment per booking and kind', function (): void {
    $booking = referenceBooking();

    $first = DB::transaction(fn (): Payment => app(InsertLedgerRow::class)->handle($booking, [
        'kind' => PaymentKind::Deposit,
        'method' => PaymentMethod::CardStripe,
        'amount' => 1000,
        'status' => PaymentStatus::Settled,
    ]));
    $second = DB::transaction(fn (): Payment => app(InsertLedgerRow::class)->handle($booking, [
        'kind' => PaymentKind::Deposit,
        'method' => PaymentMethod::CardStripe,
        'amount' => 500,
        'status' => PaymentStatus::Settled,
    ]));
    $balance = DB::transaction(fn (): Payment => app(InsertLedgerRow::class)->handle($booking, [
        'kind' => PaymentKind::Balance,
        'method' => PaymentMethod::CardStripe,
        'amount' => 2000,
        'status' => PaymentStatus::Settled,
    ]));

    expect($first->reference)->toBe('ANK-2026-0003-D01');
    expect($second->reference)->toBe('ANK-2026-0003-D02');
    expect($balance->reference)->toBe('ANK-2026-0003-B01');
});

test('a request still on its request reference uses that prefix', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', 'S2')?->id,
        'reference' => null,
        'request_reference' => 'ANK-R-2026-0041',
    ]);

    $payment = DB::transaction(fn (): Payment => app(InsertLedgerRow::class)->handle($booking, [
        'kind' => PaymentKind::Deposit,
        'method' => PaymentMethod::Wire,
        'amount' => 1000,
        'status' => PaymentStatus::AwaitingWire,
    ]));

    expect($payment->reference)->toBe('ANK-R-2026-0041-D01');
});

test('a unique reference collision is retried once', function (): void {
    $booking = referenceBooking('ANK-2026-0310');

    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'reference' => 'ANK-2026-0310-D01',
        'amount' => 100,
        'status' => PaymentStatus::Settled,
    ]);

    $payment = DB::transaction(fn (): Payment => app(InsertLedgerRow::class)->handle($booking, [
        'kind' => PaymentKind::Deposit,
        'method' => PaymentMethod::CardStripe,
        'amount' => 200,
        'status' => PaymentStatus::Settled,
    ]));

    expect($payment->reference)->toBe('ANK-2026-0310-D02');
});

test('a second unique collision is a 409', function (): void {
    $booking = referenceBooking('ANK-2026-0311');

    Payment::factory()->create([
        'booking_id' => $booking->id,
        'reference' => 'ANK-2026-0311-D01',
        'amount' => 100,
        'status' => PaymentStatus::Settled,
    ]);
    Payment::factory()->create([
        'booking_id' => $booking->id,
        'reference' => 'ANK-2026-0311-D02',
        'amount' => 100,
        'status' => PaymentStatus::Settled,
    ]);

    expect(fn () => DB::transaction(fn (): Payment => app(InsertLedgerRow::class)->handle($booking, [
        'kind' => PaymentKind::Deposit,
        'method' => PaymentMethod::CardStripe,
        'amount' => 200,
        'status' => PaymentStatus::Settled,
    ])))->toThrow(ConflictException::class, InsertLedgerRow::REFERENCE_CONFLICT);
});

test('extras uses the E letter', function (): void {
    $ref = DB::transaction(fn (): string => app(ReferenceService::class)->nextPayment('ANK-2026-0003', PaymentKind::Extras));

    expect($ref)->toBe('ANK-2026-0003-E01');
});
