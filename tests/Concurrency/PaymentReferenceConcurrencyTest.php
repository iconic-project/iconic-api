<?php

declare(strict_types=1);

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Support\Bookings\BookingMutationLock;
use App\Support\Payments\InsertLedgerRow;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookings\ReservationFixtures;

/**
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function onPaymentReferenceConnection(string $name, callable $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($name);

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

function paymentReferenceMysqlError(QueryException $e): int
{
    return (int) ($e->errorInfo[1] ?? 0);
}

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);

    config(['database.connections.mysql_lock' => config('database.connections.mysql')]);
    DB::purge('mysql_lock');
    DB::connection('mysql_lock')->statement('SET SESSION innodb_lock_wait_timeout = 1');
});

test('two payment draws on one booking wait 1205 never 1213 and stay distinct', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2028-05-07');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'reference' => 'ANK-2026-0501',
    ]);

    $first = onPaymentReferenceConnection('mysql', function () use ($booking): string {
        DB::beginTransaction();
        $locked = BookingMutationLock::acquire($booking);

        return app(InsertLedgerRow::class)->handle($locked, [
            'kind' => PaymentKind::Deposit,
            'method' => PaymentMethod::CardStripe,
            'amount' => 1000,
            'status' => PaymentStatus::Settled,
        ])->reference;
    });

    expect($first)->toBe('ANK-2026-0501-D01');

    onPaymentReferenceConnection('mysql_lock', function () use ($booking): void {
        DB::beginTransaction();

        try {
            $locked = BookingMutationLock::acquire($booking);
            app(InsertLedgerRow::class)->handle($locked, [
                'kind' => PaymentKind::Deposit,
                'method' => PaymentMethod::CardStripe,
                'amount' => 500,
                'status' => PaymentStatus::Settled,
            ]);
            expect(false)->toBeTrue('the second draw should wait, not succeed');
        } catch (QueryException $e) {
            expect(paymentReferenceMysqlError($e))->toBe(1205);
            expect(paymentReferenceMysqlError($e))->not->toBe(1213);
            DB::rollBack();
        }
    });

    onPaymentReferenceConnection('mysql', function (): void {
        DB::commit();
    });

    $second = onPaymentReferenceConnection('mysql_lock', function () use ($booking): string {
        DB::beginTransaction();
        $locked = BookingMutationLock::acquire($booking);
        $reference = app(InsertLedgerRow::class)->handle($locked, [
            'kind' => PaymentKind::Deposit,
            'method' => PaymentMethod::CardStripe,
            'amount' => 500,
            'status' => PaymentStatus::Settled,
        ])->reference;
        DB::commit();

        return $reference;
    });

    expect($second)->toBe('ANK-2026-0501-D02');
    expect(Payment::query()->where('booking_id', $booking->id)->pluck('reference')->all())
        ->toEqualCanonicalizing(['ANK-2026-0501-D01', 'ANK-2026-0501-D02']);
});
