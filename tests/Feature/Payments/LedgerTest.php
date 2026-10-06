<?php

declare(strict_types=1);

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Support\Payments\InsertLedgerRow;
use App\Support\Payments\Ledger;
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

function ledgerBooking(int $total = 26600, int $depositPct = 10): Booking
{
    $departure = ReservationFixtures::anamaraDeparture();

    return Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
        'total' => $total,
        'deposit_pct' => $depositPct,
        'reference' => 'ANK-2026-0300',
    ]);
}

test('a settled deposit reduces the balance', function (): void {
    $booking = ledgerBooking();
    $deposit = $booking->depositAmount();

    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'amount' => $deposit,
        'status' => PaymentStatus::Settled,
        'reference' => 'ANK-2026-0300-D01',
    ]);

    expect(Ledger::paid($booking))->toBe($deposit);
    expect(Ledger::pledged($booking))->toBe(0);
    expect($booking->balance())->toBe($booking->total - $deposit);
});

test('an awaiting wire is pledged not paid', function (): void {
    $booking = ledgerBooking();
    $deposit = $booking->depositAmount();

    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'method' => PaymentMethod::Wire,
        'amount' => $deposit,
        'status' => PaymentStatus::AwaitingWire,
        'reference' => 'ANK-2026-0300-D01',
    ]);

    expect(Ledger::paid($booking))->toBe(0);
    expect(Ledger::pledged($booking))->toBe($deposit);
    expect($booking->balance())->toBe($booking->total);
});

test('a fully paid booking has a zero balance', function (): void {
    $booking = ledgerBooking();
    $deposit = $booking->depositAmount();

    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'amount' => $deposit,
        'status' => PaymentStatus::Settled,
        'reference' => 'ANK-2026-0300-D01',
    ]);
    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Balance,
        'amount' => $booking->total - $deposit,
        'status' => PaymentStatus::Settled,
        'reference' => 'ANK-2026-0300-B01',
    ]);

    expect(Ledger::paid($booking))->toBe($booking->total);
    expect($booking->balance())->toBe(0);
});

test('a refunded negative row drops paid and raises the balance', function (): void {
    $booking = ledgerBooking();
    $deposit = $booking->depositAmount();

    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'amount' => $deposit,
        'status' => PaymentStatus::Settled,
        'reference' => 'ANK-2026-0300-D01',
    ]);
    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Refund,
        'amount' => -1000,
        'status' => PaymentStatus::Refunded,
        'reference' => 'ANK-2026-0300-R01',
    ]);

    $direct = Booking::query()->findOrFail($booking->id);
    $aggregated = Booking::query()->withLedgerAggregates()->findOrFail($booking->id);

    expect(Ledger::paid($direct))->toBe($deposit - 1000);
    expect($direct->balance())->toBe($booking->total - $deposit + 1000);
    expect(Ledger::paid($aggregated))->toBe($deposit - 1000);
    expect($aggregated->balance())->toBe($booking->total - $deposit + 1000);
    expect((int) $aggregated->payments_paid_sum)->toBe($deposit - 1000);
});

test('inserting after withSum invalidates the aggregate so paid reflects the new row', function (): void {
    $booking = Booking::query()->withLedgerAggregates()->findOrFail(ledgerBooking()->id);

    expect(Ledger::paid($booking))->toBe(0);

    $inserted = DB::transaction(fn (): Payment => app(InsertLedgerRow::class)->handle($booking, [
        'kind' => PaymentKind::Deposit,
        'method' => PaymentMethod::CardStripe,
        'amount' => $booking->depositAmount(),
        'status' => PaymentStatus::Settled,
    ]));

    expect(Ledger::paid($booking))->toBe($booking->depositAmount());
    expect(Ledger::paidFresh($booking))->toBe($booking->depositAmount());
    expect($inserted->paid_at->toDateString())->toBeString();
});
