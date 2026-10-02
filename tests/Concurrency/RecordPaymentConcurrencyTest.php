<?php

declare(strict_types=1);

use App\Actions\Payments\RecordPayment;
use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Payment;
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
function onRecordPaymentConnection(string $name, callable $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($name);

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

function recordPaymentMysqlError(QueryException $e): int
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

test('two record payment calls on one booking wait 1205 never 1213 and confirm once', function (): void {
    $actor = adminUser();
    $departure = ReservationFixtures::anamaraDeparture('2028-05-14');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'status' => BookingStatus::PendingPayment,
        'reference' => 'ANK-2026-0699',
        'total' => 26600,
        'deposit_pct' => 10,
    ]);

    $observed = 'none';

    onRecordPaymentConnection('mysql', function () use ($booking, $actor): void {
        DB::beginTransaction();
        app(RecordPayment::class)->handle($booking, [
            'kind' => PaymentKind::Deposit,
            'method' => PaymentMethod::CardStripe,
            'amount' => 2660,
        ], $actor);
    });

    onRecordPaymentConnection('mysql_lock', function () use ($booking, $actor, &$observed): void {
        try {
            app(RecordPayment::class)->handle($booking, [
                'kind' => PaymentKind::Deposit,
                'method' => PaymentMethod::CardStripe,
                'amount' => 500,
            ], $actor);
            expect(false)->toBeTrue('the second record should wait on the held locks (1205)');
        } catch (QueryException $e) {
            $code = recordPaymentMysqlError($e);
            expect($code)->toBe(1205);
            expect($code)->not->toBe(1213);
            $observed = (string) $code;
        }
    });

    onRecordPaymentConnection('mysql', function (): void {
        DB::commit();
    });

    onRecordPaymentConnection('mysql_lock', function () use ($booking, $actor): void {
        app(RecordPayment::class)->handle($booking, [
            'kind' => PaymentKind::Deposit,
            'method' => PaymentMethod::CardStripe,
            'amount' => 500,
        ], $actor);
    });

    expect($observed)->toBe('1205');
    expect(Payment::query()->where('booking_id', $booking->id)->count())->toBe(2);
    expect(Payment::query()->where('booking_id', $booking->id)->pluck('reference')->unique()->count())->toBe(2);
    expect($booking->fresh()?->status)->toBe(BookingStatus::Confirmed);
    expect(ChangeHistory::query()
        ->where('subject_id', $booking->id)
        ->where('event', 'booking.status_changed')
        ->where('after->status', BookingStatus::Confirmed->value)
        ->count())->toBe(1);
});
